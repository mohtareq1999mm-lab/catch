<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Audit\ActivityAuditService;
use App\Models\GuestIdentity;
use App\Models\NotificationIdentityConflict;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientFingerprint;
use App\Support\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;

class RecipientIdentityService
{
    /**
     * @param null|callable(NotificationIdentityConflict,string,int):void $authorizeResolution
     * @param null|callable(array,string,array,array):void $authorizeResolutionLookup
     */
    public function __construct(
        private readonly mixed $authorizeResolution = null,
        private readonly mixed $authorizeResolutionLookup = null,
    ) {
    }

    /**
     * Resolve a stable recipient from versioned fingerprint aliases.
     *
     * Atomic/locked: creates no orphan recipient and leaves no partial alias
     * attachment on ambiguity. Ambiguity is resolved by conflict case, never by
     * guessing a recipient. Identity may only come from authoritative `user`
     * or `guest` model instances in context; scalar user_id/guest_id values are
     * never trusted.
     *
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     * @return array{status:'resolved'|'ambiguous', recipient?:NotificationRecipient, conflict?:NotificationIdentityConflict}
     */
    public function resolveRecipient(array $aliases, string $recipientType = 'guest_contact', array $context = []): array
    {
        $aliases = $this->normalizeAliasSet($aliases);
        if ($aliases === []) {
            throw ValidationException::withMessages(['fingerprints' => 'At least one valid fingerprint alias is required.']);
        }

        $versionHashes = $this->assertOneHashPerVersion($aliases);
        $identity = $this->authoritativeIdentity($context);
        $this->authorizeRecipientResolution($aliases, $recipientType, $context, $identity);

        return DB::transaction(function () use ($aliases, $versionHashes, $recipientType, $context, $identity) {
            // Lock every row for the supplied versions, including rows with a
            // different hash. Without this, a divergent same-version alias is
            // silently grafted onto the matched recipient or hits a UNIQUE
            // violation with no conflict case.
            $rows = NotificationRecipientFingerprint::query()
                ->where('channel', $this->singleAliasChannel($aliases))
                ->whereIn('key_version', $this->aliasVersions($aliases))
                ->lockForUpdate()
                ->get();

            $candidateIds = $rows->pluck('recipient_id')->unique()->values()->map(fn ($id) => (int) $id)->all();

            if (count($candidateIds) > 1) {
                return $this->ambiguousResult($candidateIds, $aliases, $recipientType, $context);
            }

            $recipient = null;
            $createdRecipientId = null;
            $createdAliasIds = [];

            // #180: when a channel-scoped contact is supplied, that contact is
            // the identity and user_id is only an account association. Without
            // this, one user id would collapse email and phone identities.
            $stableKey = $this->singleAliasChannel($aliases) !== ''
                ? null
                : $this->stableKeyForIdentity($recipientType, $identity);
            $contactEncrypted = $this->normalizeContactEncrypted($context['contact_encrypted'] ?? null);
            $recipientMetadata = $this->normalizeSmallMetadata($context['metadata'] ?? null);

            if ($candidateIds === []) {
                $recipient = NotificationRecipient::query()->create([
                    'recipient_type' => $recipientType,
                    'status' => 'active',
                    'recipient_key' => $stableKey,
                    'contact_encrypted' => $contactEncrypted,
                    'metadata' => $recipientMetadata,
                    'user_id' => $identity['user']?->id,
                    'guest_id' => $identity['guest']?->id,
                ]);
                $createdRecipientId = (int) $recipient->id;
            } else {
                $recipient = NotificationRecipient::query()->lockForUpdate()->findOrFail($candidateIds[0]);

                if (!$this->identityMatchesRecipient($recipient, $identity)) {
                    return $this->ambiguousResult([(int) $recipient->id], $aliases, $recipientType, $context, 'recipient_ownership_mismatch');
                }

                $divergent = $rows->first(function ($row) use ($versionHashes, $recipient) {
                    $key = $row->channel . "\x1f" . $row->key_version;

                    return (int) $row->recipient_id === (int) $recipient->id
                        && isset($versionHashes[$key])
                        && $versionHashes[$key] !== $row->fingerprint_hash;
                });

                if ($divergent) {
                    return $this->ambiguousResult([(int) $recipient->id], $aliases, $recipientType, $context);
                }

                $keyConflict = $this->stableKeyConflict($recipient, $recipientType, $stableKey, $aliases, $context);
                if ($keyConflict !== null) {
                    return $keyConflict;
                }

                if ($stableKey !== null && $recipient->recipient_key === null) {
                    $recipient->forceFill(['recipient_key' => $stableKey])->save();
                }
                if ($contactEncrypted !== null && $recipient->contact_encrypted === null) {
                    $recipient->forceFill(['contact_encrypted' => $contactEncrypted])->save();
                }
                if ($recipientMetadata !== null && $recipient->metadata === null) {
                    $recipient->forceFill(['metadata' => $recipientMetadata])->save();
                }
            }

            foreach ($aliases as $alias) {
                $stored = $rows->first(fn ($row) => $row->channel === $alias['channel']
                    && $row->key_version === $alias['key_version']
                    && $row->fingerprint_hash === $alias['fingerprint_hash']);

                if ($stored && (int) $stored->recipient_id === (int) $recipient->id) {
                    continue;
                }

                // insertOrIgnore makes the losing side of a UNIQUE race visible
                // as a divergence instead of a 500; no alias is attached before
                // its ownership is verified below.
                $inserted = DB::table('notification_recipient_fingerprints')->insertOrIgnore([
                    'recipient_id' => $recipient->id,
                    'channel' => $alias['channel'],
                    'key_version' => $alias['key_version'],
                    'fingerprint_hash' => $alias['fingerprint_hash'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 1) {
                    $createdAliasIds[] = (int) DB::connection()->getPdo()->lastInsertId();
                }

                $stored = NotificationRecipientFingerprint::query()
                    ->where('channel', $alias['channel'])
                    ->where('key_version', $alias['key_version'])
                    ->where('fingerprint_hash', $alias['fingerprint_hash'])
                    ->lockForUpdate()
                    ->first();

                if (!$stored) {
                    throw new \RuntimeException('Fingerprint alias write was lost.');
                }

                if ((int) $stored->recipient_id !== (int) $recipient->id) {
                    // Roll back every alias created by this attempt before the
                    // conflict is recorded, so no partial write survives.
                    $this->rollbackResolutionAttempt($createdRecipientId, $createdAliasIds);

                    return $this->ambiguousResult(
                        array_values(array_unique([(int) $recipient->id, (int) $stored->recipient_id])),
                        $aliases,
                        $recipientType,
                        $context
                    );
                }
            }

            return ['status' => 'resolved', 'recipient' => $recipient->fresh()];
        }, 3);
    }

    /**
     * Canonical stable-key resolver for #176/#167 consumers. It reuses the
     * same conflict/audit semantics as resolveRecipient and never auto-merges
     * two recipients: divergence always becomes an open conflict.
     *
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     * @return array{status:'resolved'|'ambiguous', recipient?:NotificationRecipient, conflict?:NotificationIdentityConflict}
     */
    public function resolveForRecipientKey(
        string $recipientType,
        ?string $recipientKey,
        array $aliases,
        ?string $contactEncrypted,
        array $context
    ): array {
        $type = trim($recipientType);
        if ($type === '' || mb_strlen($type) > 32) {
            throw ValidationException::withMessages(['recipient_type' => 'Invalid recipient type.']);
        }

        $aliases = $this->normalizeAliasSet($aliases);
        $versionHashes = $this->assertOneHashPerVersion($aliases);
        $identity = $this->authoritativeIdentity($context);
        $channelScoped = $this->singleAliasChannel($aliases) !== '';
        $key = $this->validateRecipientKey(
            $type,
            $channelScoped ? null : $recipientKey,
            $identity,
            $channelScoped,
        );

        if ($key === null && $aliases === []) {
            throw ValidationException::withMessages(['fingerprints' => 'A stable key or fingerprint alias is required.']);
        }

        $contactEncrypted = $this->normalizeContactEncrypted($contactEncrypted);
        $metadata = $this->normalizeSmallMetadata($context['metadata'] ?? null);
        $this->authorizeRecipientResolution($aliases, $type, $context, $identity);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $type,
                    $key,
                    $aliases,
                    $versionHashes,
                    $contactEncrypted,
                    $metadata,
                    $context,
                    $identity
                ) {
                    return $this->resolveForRecipientKeyOnce(
                        $type,
                        $key,
                        $aliases,
                        $versionHashes,
                        $contactEncrypted,
                        $metadata,
                        $context,
                        $identity
                    );
                }, 3);
            } catch (QueryException $e) {
                // The canonical UniqueViolation detector drives retries. A
                // losing key/alias write re-resolves and becomes a conflict if
                // the winner is a different identity.
                if (!UniqueViolation::detect($e) || $attempt === 3) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('Recipient key resolution retry limit reached.');
    }

    /**
     * Rotate one alias version to a new hash on the same recipient. A hash
     * already owned by another recipient is rejected; no silent auto-merge.
     *
     * @return NotificationRecipient
     */
    public function rotateFingerprint(
        NotificationRecipient $recipient,
        string $version,
        string $fingerprintHash,
        ?array $metadata = null,
        ?string $channel = null
    ): NotificationRecipient {
        $version = trim($version);
        $hash = strtolower(trim($fingerprintHash));
        $channel = $this->normalizeFingerprintChannel($channel);
        if (preg_match('/^[A-Za-z0-9_.:-]{1,32}$/', $version) !== 1) {
            throw ValidationException::withMessages(['key_version' => 'Invalid fingerprint version.']);
        }
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw ValidationException::withMessages(['fingerprint_hash' => 'Invalid fingerprint hash.']);
        }
        $cleanMetadata = $this->normalizeSmallMetadata($metadata);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($recipient, $version, $hash, $cleanMetadata, $channel) {
                    $locked = NotificationRecipient::query()->lockForUpdate()->findOrFail($recipient->id);

                    $owner = NotificationRecipientFingerprint::query()
                        ->where('channel', $channel)
                        ->where('key_version', $version)
                        ->where('fingerprint_hash', $hash)
                        ->lockForUpdate()
                        ->first();
                    if ($owner && (int) $owner->recipient_id !== (int) $locked->id) {
                        throw ValidationException::withMessages([
                            'fingerprint_hash' => 'Fingerprint hash belongs to another recipient.',
                        ]);
                    }

                    $existing = NotificationRecipientFingerprint::query()
                        ->where('recipient_id', $locked->id)
                        ->where('key_version', $version)
                        ->lockForUpdate()
                        ->first();

                    // One version belongs to one channel identity on a
                    // recipient; never silently rewrite an existing channel.
                    if ($existing && $existing->channel !== $channel) {
                        throw ValidationException::withMessages([
                            'fingerprint_hash' => 'Recipient already has a fingerprint for another channel.',
                        ]);
                    }

                    if ($existing) {
                        if ($existing->fingerprint_hash === $hash && $cleanMetadata === null) {
                            return $locked->fresh();
                        }
                        $existing->forceFill([
                            'fingerprint_hash' => $hash,
                            'metadata' => $cleanMetadata ?? $existing->metadata,
                        ])->save();

                        return $locked->fresh();
                    }

                    NotificationRecipientFingerprint::query()->create([
                        'recipient_id' => $locked->id,
                        'channel' => $channel,
                        'key_version' => $version,
                        'fingerprint_hash' => $hash,
                        'metadata' => $cleanMetadata,
                    ]);

                    return $locked->fresh();
                }, 3);
            } catch (QueryException $e) {
                if (!UniqueViolation::detect($e) || $attempt === 3) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('Fingerprint rotation retry limit reached.');
    }

    /**
     * @param array{user:?User,guest:?GuestIdentity} $identity
     */
    private function validateRecipientKey(
        string $recipientType,
        ?string $recipientKey,
        array $identity,
        bool $channelScoped = false,
    ): ?string {
        if ($recipientKey === null || trim($recipientKey) === '') {
            // #180: a channel-scoped contact is the identity; the user id is
            // only an association and must not collapse email/sms contacts.
            if ($recipientType === 'user' && ! $channelScoped) {
                throw ValidationException::withMessages(['recipient_key' => 'User recipients require a stable key.']);
            }

            return null;
        }

        $key = trim($recipientKey);
        if (mb_strlen($key) > 191 || $this->hasContactDetails($key)) {
            throw ValidationException::withMessages(['recipient_key' => 'Recipient key must be non-PII and at most 191 characters.']);
        }

        if ($recipientType === 'guest_contact') {
            throw ValidationException::withMessages(['recipient_key' => 'Fingerprint-only guests must not have a stable key.']);
        }

        if ($recipientType === 'user') {
            if (
                preg_match('/^user:(\d+)$/', $key, $matches) !== 1
                || (int) $matches[1] <= 0
                || $identity['user'] === null
                || (int) $matches[1] !== (int) $identity['user']->id
            ) {
                throw ValidationException::withMessages(['recipient_key' => 'User stable key must match authenticated user context.']);
            }
        }

        return $key;
    }

    /**
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     * @param array<string,string> $versionHashes
     * @param array{user:?User,guest:?GuestIdentity} $identity
     * @return array{status:'resolved'|'ambiguous', recipient?:NotificationRecipient, conflict?:NotificationIdentityConflict}
     */
    private function resolveForRecipientKeyOnce(
        string $recipientType,
        ?string $recipientKey,
        array $aliases,
        array $versionHashes,
        ?string $contactEncrypted,
        ?array $metadata,
        array $context,
        array $identity
    ): array {
        $byKey = null;
        if ($recipientKey !== null) {
            $byKey = NotificationRecipient::query()
                ->where('recipient_type', $recipientType)
                ->where('recipient_key', $recipientKey)
                ->lockForUpdate()
                ->first();
        }

        $rows = NotificationRecipientFingerprint::query()
            ->where('channel', $this->singleAliasChannel($aliases))
            ->whereIn('key_version', $this->aliasVersions($aliases))
            ->lockForUpdate()
            ->get();
        $candidateIds = $rows->pluck('recipient_id')->unique()->values()->map(fn ($id) => (int) $id)->all();
        if ($byKey) {
            $candidateIds[] = (int) $byKey->id;
        }
        $candidateIds = array_values(array_unique($candidateIds));

        if (count($candidateIds) > 1) {
            return $this->ambiguousResult($candidateIds, $aliases, $recipientType, $context);
        }

        if ($candidateIds === []) {
            $recipient = NotificationRecipient::query()->create([
                'recipient_type' => $recipientType,
                'status' => 'active',
                'recipient_key' => $recipientKey,
                'contact_encrypted' => $contactEncrypted,
                'metadata' => $metadata,
                'user_id' => $identity['user']?->id,
                'guest_id' => $identity['guest']?->id,
            ]);
        } else {
            $recipient = NotificationRecipient::query()->lockForUpdate()->findOrFail($candidateIds[0]);

            if ($recipient->recipient_type !== $recipientType) {
                return $this->ambiguousResult([(int) $recipient->id], $aliases, $recipientType, $context, 'recipient_type_mismatch');
            }

            $keyConflict = $this->stableKeyConflict($recipient, $recipientType, $recipientKey, $aliases, $context);
            if ($keyConflict !== null) {
                return $keyConflict;
            }

            if (!$this->identityMatchesRecipient($recipient, $identity)) {
                return $this->ambiguousResult([(int) $recipient->id], $aliases, $recipientType, $context, 'recipient_ownership_mismatch');
            }

            $divergent = $rows->first(function ($row) use ($versionHashes, $recipient) {
                $key = $row->channel . "\x1f" . $row->key_version;

                return (int) $row->recipient_id === (int) $recipient->id
                    && isset($versionHashes[$key])
                    && $versionHashes[$key] !== $row->fingerprint_hash;
            });
            if ($divergent) {
                return $this->ambiguousResult([(int) $recipient->id], $aliases, $recipientType, $context);
            }

            $changes = [];
            if ($recipientKey !== null && $recipient->recipient_key === null) {
                $changes['recipient_key'] = $recipientKey;
            }
            if ($contactEncrypted !== null && $recipient->contact_encrypted === null) {
                $changes['contact_encrypted'] = $contactEncrypted;
            }
            if ($metadata !== null && $recipient->metadata === null) {
                $changes['metadata'] = $metadata;
            }
            if ($changes !== []) {
                $recipient->forceFill($changes)->save();
            }
        }

        foreach ($aliases as $alias) {
            $stored = $rows->first(fn ($row) => $row->channel === $alias['channel']
                && $row->key_version === $alias['key_version']
                && $row->fingerprint_hash === $alias['fingerprint_hash']);
            if ($stored && (int) $stored->recipient_id === (int) $recipient->id) {
                continue;
            }

            NotificationRecipientFingerprint::query()->create([
                'recipient_id' => $recipient->id,
                'channel' => $alias['channel'],
                'key_version' => $alias['key_version'],
                'fingerprint_hash' => $alias['fingerprint_hash'],
                'metadata' => $metadata,
            ]);
        }

        return ['status' => 'resolved', 'recipient' => $recipient->fresh()];
    }

    /**
     * Only model instances obtained from the auth/session layer can establish
     * recipient ownership. Raw scalar IDs in context are deliberately ignored.
     *
     * @return array{user:?User,guest:?GuestIdentity}
     */
    private function authoritativeIdentity(array $context): array
    {
        $user = $context['user'] ?? null;
        $guest = $context['guest'] ?? null;

        if ($user === null && $guest === null) {
            throw ValidationException::withMessages(['context' => 'Authoritative user or guest context is required.']);
        }
        if ($user !== null && !$user instanceof User) {
            throw ValidationException::withMessages(['context' => 'User context must be an authenticated user model.']);
        }
        if ($guest !== null && !$guest instanceof GuestIdentity) {
            throw ValidationException::withMessages(['context' => 'Guest context must be a current guest identity model.']);
        }
        if ($user !== null && !User::query()->whereKey((int) $user->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['context' => 'Authenticated user is not active.']);
        }
        if (
            $guest !== null
            && !GuestIdentity::query()->whereKey((int) $guest->id)
                ->whereNull('revoked_at')->where('expires_at', '>', now())->exists()
        ) {
            throw ValidationException::withMessages(['context' => 'Guest identity is not current.']);
        }

        return ['user' => $user, 'guest' => $guest];
    }

    /**
     * @param array{user:?User,guest:?GuestIdentity} $identity
     */
    private function identityMatchesRecipient(NotificationRecipient $recipient, array $identity): bool
    {
        $user = $identity['user'];
        $guest = $identity['guest'];

        if ($user !== null && $recipient->user_id !== null && (int) $recipient->user_id !== (int) $user->id) {
            return false;
        }
        if ($guest !== null && $recipient->guest_id !== null && (int) $recipient->guest_id !== (int) $guest->id) {
            return false;
        }

        return ($user !== null && $recipient->user_id !== null && (int) $recipient->user_id === (int) $user->id)
            || ($guest !== null && $recipient->guest_id !== null && (int) $recipient->guest_id === (int) $guest->id);
    }

    /**
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     * @return array{status:'ambiguous',conflict:NotificationIdentityConflict}|null
     */
    private function stableKeyConflict(
        NotificationRecipient $recipient,
        string $recipientType,
        ?string $stableKey,
        array $aliases,
        array $context
    ): ?array {
        if ($stableKey === null) {
            return null;
        }

        if ($recipient->recipient_key !== null && $recipient->recipient_key !== $stableKey) {
            return $this->ambiguousResult(
                [(int) $recipient->id],
                $aliases,
                $recipientType,
                $context,
                'recipient_key_ownership_mismatch'
            );
        }

        $owner = NotificationRecipient::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_key', $stableKey)
            ->lockForUpdate()
            ->first();

        if (!$owner || (int) $owner->id === (int) $recipient->id) {
            return null;
        }

        return $this->ambiguousResult(
            [(int) $recipient->id, (int) $owner->id],
            $aliases,
            $recipientType,
            $context,
            'recipient_key_ownership_mismatch'
        );
    }

    /**
     * Service-level authorization boundary for recipient lookups, analogous
     * to the conflict-resolution hook.
     *
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     * @param array{user:?User,guest:?GuestIdentity} $identity
     */
    private function authorizeRecipientResolution(array $aliases, string $recipientType, array $context, array $identity): void
    {
        if (is_callable($this->authorizeResolutionLookup)) {
            ($this->authorizeResolutionLookup)($aliases, $recipientType, $context, $identity);
        }
    }

    /**
     * @param array<int,int> $candidateIds
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     * @return array{status:'ambiguous', conflict:NotificationIdentityConflict}
     */
    private function ambiguousResult(
        array $candidateIds,
        array $aliases,
        string $recipientType,
        array $context,
        string $reason = 'fingerprint_alias_divergence'
    ): array {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        sort($candidateIds);
        if ($candidateIds === [] || collect($candidateIds)->contains(fn (int $id) => $id <= 0)) {
            throw ValidationException::withMessages(['candidate_recipient_ids' => 'Invalid conflict candidates.']);
        }

        $conflict = $this->recordConflict($candidateIds, $aliases, $recipientType, $context, $reason);

        return ['status' => 'ambiguous', 'conflict' => $conflict];
    }

    /**
     * @param array<int,int> $candidateIds
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     */
    private function recordConflict(
        array $candidateIds,
        array $aliases,
        string $recipientType,
        array $context,
        string $reason = 'fingerprint_alias_divergence'
    ): NotificationIdentityConflict {
        $normalizedAliases = $this->normalizeAliasSet($aliases);
        // Stable-key-only resolution (no fingerprint aliases) can also be
        // ambiguous, e.g. when the stored recipient belongs to another user.
        // Such a conflict has no alias to anchor on; the candidate set and the
        // reason identify it instead.
        $primary = $normalizedAliases[0] ?? null;
        $metadata = $this->sanitizeConflictMetadata([
            'reason' => $reason,
            'recipient_type' => $recipientType,
            'aliases' => $normalizedAliases,
            'context' => $context,
        ]);

        // Candidate sets and alias sets are order-insensitive. Reversing the
        // caller's alias order must reuse the same open conflict case.
        $existing = NotificationIdentityConflict::query()
            ->where('status', 'open')
            ->when($normalizedAliases !== [], function ($q) use ($normalizedAliases) {
                $q->where(function ($sub) use ($normalizedAliases) {
                    $sub->whereIn('key_version', array_column($normalizedAliases, 'key_version'))
                        ->orWhereIn('fingerprint_hash', array_column($normalizedAliases, 'fingerprint_hash'));
                });
            }, function ($q) {
                // No alias lookup keys: only stable-key conflicts are relevant,
                // and those are already narrow because they key on one owner.
                $q->whereNotNull('candidate_recipient_ids');
            })
            ->lockForUpdate()
            ->get()
            ->first(function (NotificationIdentityConflict $c) use ($candidateIds, $normalizedAliases, $reason) {
                $ids = array_map('intval', $c->candidate_recipient_ids ?? []);
                sort($ids);

                if ($normalizedAliases === [] && ($c->metadata['reason'] ?? null) !== $reason) {
                    return false;
                }

                return $ids === $candidateIds
                    && $this->normalizeAliasSet($c->metadata['aliases'] ?? []) === $normalizedAliases;
            });

        if ($existing) {
            $existing->forceFill(['metadata' => $metadata])->save();
            return $existing;
        }

        return NotificationIdentityConflict::query()->create([
            'channel' => $context['channel'] ?? ($primary['channel'] ?? null),
            'key_version' => $primary['key_version'] ?? null,
            'fingerprint_hash' => $primary['fingerprint_hash'] ?? null,
            'candidate_recipient_ids' => $candidateIds,
            'status' => 'open',
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param array<int,int> $createdAliasIds Every alias inserted by this attempt,
     *                                        including aliases on existing recipients.
     */
    private function rollbackResolutionAttempt(?int $createdRecipientId, array $createdAliasIds): void
    {
        if ($createdAliasIds !== []) {
            NotificationRecipientFingerprint::query()->whereIn('id', $createdAliasIds)->delete();
        }

        if ($createdRecipientId !== null) {
            NotificationRecipient::query()->whereKey($createdRecipientId)->delete();
        }
    }

    public function resolveConflict(
        NotificationIdentityConflict $conflict,
        string $action,
        ?int $resolvedBy = null,
        ?string $note = null,
        ?int $targetRecipientId = null
    ): NotificationIdentityConflict {
        if (!in_array($action, ['link', 'split', 'dismiss'], true)) {
            throw ValidationException::withMessages(['action' => 'Invalid conflict action.']);
        }

        $note = $this->sanitizeResolutionNote($note);

        return DB::transaction(function () use ($conflict, $action, $resolvedBy, $note, $targetRecipientId) {
            $conflict = NotificationIdentityConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            $this->authorizeConflictResolution($conflict, $action, $resolvedBy);

            if ($conflict->status !== 'open') {
                if ($this->isEquivalentResolution($conflict, $action, $resolvedBy, $note, $targetRecipientId)) {
                    return $conflict;
                }
                throw ValidationException::withMessages(['status' => 'Conflict is already resolved.']);
            }

            $candidateIds = array_values(array_unique(array_map('intval', $conflict->candidate_recipient_ids ?? [])));
            if ($candidateIds === []) {
                throw ValidationException::withMessages(['candidate_recipient_ids' => 'Conflict has no candidates.']);
            }

            if ($action === 'link') {
                $targetRecipientId = $this->resolveLinkTarget($candidateIds, $targetRecipientId);
                $this->linkCandidates($candidateIds, $targetRecipientId);
            }

            $metadata = is_array($conflict->metadata) ? $conflict->metadata : [];
            $metadata['resolution'] = [
                'action' => $action,
                'target_recipient_id' => $action === 'link' ? $targetRecipientId : null,
                'resolved_by' => $resolvedBy,
                'resolved_at' => now()->toIso8601String(),
                'note' => $note,
            ];

            $old = ['status' => $conflict->status, 'resolution' => null];
            $conflict->forceFill([
                'status' => $action === 'link' ? 'linked' : ($action === 'split' ? 'split' : 'dismissed'),
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
                'resolution_note' => $note,
                'metadata' => $metadata,
            ])->save();

            ActivityAuditService::recordSubject(
                NotificationIdentityConflict::class,
                (int) $conflict->id,
                'notification_identity_conflict_' . $action,
                'notifications',
                'Notification identity conflict resolved via ' . $action,
                old: $old,
                new: ['status' => $conflict->status, 'resolution' => $metadata['resolution']],
                context: [
                    'conflict_id' => $conflict->id,
                    'action' => $action,
                    'candidate_recipient_ids' => $conflict->candidate_recipient_ids,
                    'target_recipient_id' => $action === 'link' ? $targetRecipientId : null,
                ],
                causerId: $resolvedBy,
                causerType: User::class,
            );

            return $conflict;
        }, 3);
    }

    /**
     * Service-level authorization boundary. Callers must supply an active user
     * ID; the optional callback lets the application layer enforce its own
     * policy/role rules without coupling this service to a specific guard.
     */
    private function authorizeConflictResolution(NotificationIdentityConflict $conflict, string $action, ?int $resolvedBy): void
    {
        if ($resolvedBy === null || !User::query()->whereKey($resolvedBy)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['resolved_by' => 'An active resolver is required.']);
        }

        if (is_callable($this->authorizeResolution)) {
            ($this->authorizeResolution)($conflict, $action, $resolvedBy);
        }
    }

    /**
     * @param array<int,int> $candidateIds
     */
    private function resolveLinkTarget(array $candidateIds, ?int $targetRecipientId): int
    {
        if ($targetRecipientId === null) {
            $targetRecipientId = min($candidateIds);
        }

        $targetRecipientId = (int) $targetRecipientId;
        if (!in_array($targetRecipientId, $candidateIds, true)) {
            throw ValidationException::withMessages(['target_recipient_id' => 'Target recipient must be a conflict candidate.']);
        }

        $target = NotificationRecipient::query()->lockForUpdate()->find($targetRecipientId);
        if (!$target) {
            throw ValidationException::withMessages(['target_recipient_id' => 'Target recipient not found.']);
        }

        return $targetRecipientId;
    }

    /**
     * Link/split semantics: link moves all candidate aliases to a selected canonical
     * recipient, preserving recipient IDs for the target and avoiding resend/state changes.
     * Split/dismiss do not mutate recipient ownership; they record audited operator intent.
     *
     * @param array<int,int> $candidateIds
     */
    private function linkCandidates(array $candidateIds, int $targetRecipientId): void
    {
        $candidates = NotificationRecipient::query()
            ->whereIn('id', $candidateIds)
            ->lockForUpdate()
            ->get();

        $target = $candidates->firstWhere('id', $targetRecipientId);
        if (!$target || $candidates->count() !== count($candidateIds)) {
            throw ValidationException::withMessages(['target_recipient_id' => 'Conflict has missing recipient candidates.']);
        }

        $userIds = $candidates->pluck('user_id')->filter()->unique()->values();
        if ($userIds->count() > 1) {
            throw ValidationException::withMessages(['candidate_recipient_ids' => 'Cannot link candidates owned by different users.']);
        }

        if ($userIds->count() === 1 && $target->user_id !== null && (int) $target->user_id !== (int) $userIds->first()) {
            throw ValidationException::withMessages(['target_recipient_id' => 'Target recipient user mismatch.']);
        }

        // Guests are identities too: never graft aliases from another guest
        // onto the target while silently keeping the target's guest_id.
        $guestIds = $candidates->pluck('guest_id')->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values();
        if ($guestIds->count() > 1) {
            throw ValidationException::withMessages(['candidate_recipient_ids' => 'Cannot link candidates from different guests.']);
        }
        if ($guestIds->count() === 1 && $target->guest_id !== null && (int) $target->guest_id !== (int) $guestIds->first()) {
            throw ValidationException::withMessages(['target_recipient_id' => 'Target recipient guest mismatch.']);
        }

        // Reassign alias provenance to the canonical recipient. One alias version
        // per recipient is allowed; identical target aliases are deduped, while
        // conflicting same-version values remain unresolved.
        $targetAliases = NotificationRecipientFingerprint::query()
            ->where('recipient_id', $targetRecipientId)
            ->lockForUpdate()
            ->get()
            ->keyBy('key_version');

        $moving = NotificationRecipientFingerprint::query()
            ->whereIn('recipient_id', $candidateIds)
            ->where('recipient_id', '!=', $targetRecipientId)
            ->lockForUpdate()
            ->get();

        foreach ($moving as $alias) {
            $targetAlias = $targetAliases->get($alias->key_version);
            if ($targetAlias && $targetAlias->fingerprint_hash !== $alias->fingerprint_hash) {
                throw ValidationException::withMessages([
                    'candidate_recipient_ids' => 'Cannot link recipients with conflicting aliases for one key version.',
                ]);
            }
            if ($targetAlias) {
                $alias->delete();
            }
        }

        NotificationRecipientFingerprint::query()
            ->whereIn('recipient_id', $candidateIds)
            ->where('recipient_id', '!=', $targetRecipientId)
            ->update(['recipient_id' => $targetRecipientId, 'updated_at' => now()]);

        NotificationRecipient::query()
            ->whereIn('id', $candidateIds)
            ->where('id', '!=', $targetRecipientId)
            ->update(['status' => 'merged', 'updated_at' => now()]);

        // Preserve non-conflicting target metadata/ownership; fill missing user/guest only.
        if ($target->user_id === null && $userIds->count() === 1) {
            $target->user_id = (int) $userIds->first();
        }
        if ($target->guest_id === null && $guestIds->count() === 1) {
            $target->guest_id = (int) $guestIds->first();
        }
        $target->save();
    }

    private function isEquivalentResolution(NotificationIdentityConflict $conflict, string $action, ?int $resolvedBy, ?string $note, ?int $targetRecipientId): bool
    {
        $expectedStatus = $action === 'link' ? 'linked' : ($action === 'split' ? 'split' : 'dismissed');
        if ($conflict->status !== $expectedStatus) {
            return false;
        }
        $meta = is_array($conflict->metadata) ? $conflict->metadata : [];
        $resolution = $meta['resolution'] ?? null;
        if (!is_array($resolution)) {
            return false;
        }

        return ($resolution['action'] ?? null) === $action
            && (int) ($conflict->resolved_by ?? 0) === (int) ($resolvedBy ?? 0)
            && (string) ($conflict->resolution_note ?? '') === (string) ($note ?? '')
            && (int) ($resolution['target_recipient_id'] ?? 0) === (int) ($targetRecipientId ?? 0);
    }

    /** @var array<int,string> */
    private const CONTACT_PATTERNS = [
        '/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/',
        '/(?:\+?\d[\d\s().-]{7,}\d)/',
        '/\b(?:phone|mobile|email|address)\s*[:=][^\s,;]*/i',
    ];

    /**
     * Resolution notes are copied into the conflict row and the activity log.
     * Reject contact-like values at the boundary so PII never enters either.
     */
    private function sanitizeResolutionNote(?string $note): ?string
    {
        if ($note === null || trim($note) === '') {
            return null;
        }

        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Resolution note is too long.']);
        }

        if ($this->hasContactDetails($note)) {
            throw ValidationException::withMessages(['note' => 'Resolution note must not contain contact details.']);
        }

        return $note;
    }

    private function hasContactDetails(string $value): bool
    {
        foreach (self::CONTACT_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     * @return array<int,array{channel:string,key_version:string,fingerprint_hash:string}>
     */
    private function normalizeAliasSet(array $aliases): array
    {
        $normalized = collect($aliases)
            ->filter(fn ($a) => is_array($a) && !empty($a['key_version']) && !empty($a['fingerprint_hash']))
            ->map(fn ($a) => [
                'key_version' => trim((string) $a['key_version']),
                'fingerprint_hash' => strtolower(trim((string) $a['fingerprint_hash'])),
                'channel' => $this->normalizeFingerprintChannel($a['channel'] ?? null),
            ])
            ->filter(fn ($a) => preg_match('/^[A-Za-z0-9_.:-]{1,32}$/', $a['key_version']) === 1
                && preg_match('/^[a-f0-9]{64}$/', $a['fingerprint_hash']) === 1)
            ->unique(fn ($a) => $a['channel'] . "\x1f" . $a['key_version'] . "\x1f" . $a['fingerprint_hash'])
            ->values()
            ->all();

        usort($normalized, fn (array $a, array $b) => [$a['channel'], $a['key_version'], $a['fingerprint_hash']]
            <=> [$b['channel'], $b['key_version'], $b['fingerprint_hash']]);

        return $normalized;
    }

    /**
     * One hash per version is a hard input invariant: divergent hashes for
     * the same version cannot both belong to one recipient.
     *
     * @param array<int,array{channel:string,key_version:string,fingerprint_hash:string}> $aliases
     * @return array<string,string>
     */
    private function assertOneHashPerVersion(array $aliases): array
    {
        $versionHashes = [];
        foreach ($aliases as $alias) {
            // #180: channel is part of identity. The empty channel is the
            // legacy sentinel and remains backwards compatible.
            $key = $alias['channel'] . "\x1f" . $alias['key_version'];
            if (array_key_exists($key, $versionHashes) && $versionHashes[$key] !== $alias['fingerprint_hash']) {
                throw ValidationException::withMessages(['fingerprints' => 'Only one fingerprint hash is allowed per key version.']);
            }
            $versionHashes[$key] = $alias['fingerprint_hash'];
        }

        return $versionHashes;
    }

    /**
     * #180: a fingerprint alias set belongs to exactly one channel. Mixing
     * channels in one set would be an identity merge, which is never allowed.
     */
    private function normalizeFingerprintChannel(mixed $channel): string
    {
        if ($channel === null) {
            return '';
        }
        if (!is_string($channel)) {
            throw ValidationException::withMessages(['fingerprints' => 'Invalid fingerprint channel.']);
        }

        $channel = strtolower(trim($channel));
        if ($channel !== '' && preg_match('/^[a-z0-9_.:-]{1,16}$/', $channel) !== 1) {
            throw ValidationException::withMessages(['fingerprints' => 'Invalid fingerprint channel.']);
        }

        return $channel;
    }

    /**
     * @param array<int,array{channel:string}> $aliases
     */
    private function singleAliasChannel(array $aliases): string
    {
        $channels = array_values(array_unique(array_column($aliases, 'channel')));
        if (count($channels) > 1) {
            throw ValidationException::withMessages(['fingerprints' => 'Fingerprints must not mix channels.']);
        }

        return $channels[0] ?? '';
    }

    /**
     * @param array<int,array{key_version:string}> $aliases
     * @return array<int,string>
     */
    private function aliasVersions(array $aliases): array
    {
        return array_values(array_unique(array_column($aliases, 'key_version')));
    }

    /**
     * Stable keys are non-PII. Users are keyed by their authenticated ID;
     * fingerprint-only guests deliberately have no stable key.
     *
     * @param array{user:?User,guest:?GuestIdentity} $identity
     */
    private function stableKeyForIdentity(string $recipientType, array $identity): ?string
    {
        if ($recipientType !== 'user' || $identity['user'] === null) {
            return null;
        }

        return 'user:' . (int) $identity['user']->id;
    }

    private function normalizeContactEncrypted(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ValidationException::withMessages(['contact_encrypted' => 'Encrypted contact must be a string.']);
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 8192) {
            throw ValidationException::withMessages(['contact_encrypted' => 'Encrypted contact is too long.']);
        }

        return $value;
    }

    private function normalizeSmallMetadata(mixed $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }
        if (!is_array($metadata)) {
            throw ValidationException::withMessages(['metadata' => 'Metadata must be an array.']);
        }

        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || strlen($key) > 64 || !is_scalar($value)) {
                continue;
            }
            $text = (string) $value;
            $clean[$key] = $this->hasContactDetails($text)
                ? '[REDACTED]'
                : (mb_strlen($text) > 500 ? mb_substr($text, 0, 500) . '…' : $text);
        }

        return $clean === [] ? null : $clean;
    }

    private function sanitizeAuditText(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw ValidationException::withMessages([$field => 'Free-text audit value must be a scalar.']);
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 500) {
            throw ValidationException::withMessages([$field => 'Free-text audit value is too long.']);
        }

        return $this->redactContactDetails($value);
    }

    /**
     * Operational free text is allowlisted, then contact-like values are
     * redacted before the metadata reaches the conflict row or audit log.
     */
    private function redactContactDetails(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (self::CONTACT_PATTERNS as $pattern) {
            $value = preg_replace($pattern, '[REDACTED]', $value) ?? $value;
        }

        return mb_strlen($value) > 500 ? mb_substr($value, 0, 500) . '…' : $value;
    }

    /**
     * Conflict metadata is retained for audit only. Keep an allowlist of
     * non-sensitive operational context; redact/omit phone, email and other
     * direct identifiers. Apply the configured retention policy when pruning
     * resolved conflicts.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function sanitizeConflictMetadata(array $metadata): array
    {
        foreach (['reason', 'source'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $metadata[$key] = $this->sanitizeAuditText($key, $metadata[$key]);
            }
        }

        $context = $metadata['context'] ?? [];
        if (is_array($context)) {
            $allowed = ['channel', 'source', 'reason', 'order_id', 'shipment_id', 'guest_id', 'user_id'];
            $clean = [];
            foreach ($allowed as $key) {
                if (!array_key_exists($key, $context)) {
                    continue;
                }
                if (in_array($key, ['reason', 'source'], true)) {
                    $clean[$key] = $this->sanitizeAuditText($key, $context[$key]);
                    continue;
                }
                if (!is_scalar($context[$key]) && $context[$key] !== null) {
                    continue;
                }
                $clean[$key] = $context[$key];
            }
            $metadata['context'] = $clean;
        }

        return $metadata;
    }

    /**
     * Link existing guest recipient identity to authenticated user without changing
     * recipient IDs, order ownership, payments, or shipments. No notification resend.
     */
    public function linkGuestRecipientsToUser(GuestIdentity $guest, User $user): int
    {
        return DB::transaction(function () use ($guest, $user) {
            $recipients = NotificationRecipient::query()
                ->where('recipient_type', 'guest_contact')
                ->where('guest_id', $guest->id)
                ->lockForUpdate()
                ->get();

            $updated = 0;
            foreach ($recipients as $recipient) {
                if ($recipient->user_id !== null && (int) $recipient->user_id !== (int) $user->id) {
                    $metadata = $this->sanitizeConflictMetadata([
                        'reason' => 'guest_claim_user_mismatch',
                        'recipient_type' => 'guest_contact',
                        'aliases' => [],
                        'context' => [
                            'reason' => 'guest_claim_user_mismatch',
                            'guest_id' => $guest->id,
                            'user_id' => $user->id,
                        ],
                    ]);

                    // Replays of the same claim must reuse the open case.
                    $existing = NotificationIdentityConflict::query()
                        ->where('status', 'open')
                        ->whereNull('key_version')
                        ->whereNull('fingerprint_hash')
                        ->lockForUpdate()
                        ->get()
                        ->first(function (NotificationIdentityConflict $c) use ($recipient, $guest, $user) {
                            $context = $c->metadata['context'] ?? [];

                            return ($c->metadata['reason'] ?? null) === 'guest_claim_user_mismatch'
                                && (int) ($context['guest_id'] ?? 0) === (int) $guest->id
                                && (int) ($context['user_id'] ?? 0) === (int) $user->id
                                && ($c->candidate_recipient_ids ?? []) === [(int) $recipient->id];
                        });

                    if (!$existing) {
                        $conflict = NotificationIdentityConflict::query()->create([
                            'channel' => null,
                            'key_version' => null,
                            'fingerprint_hash' => null,
                            'candidate_recipient_ids' => [(int) $recipient->id],
                            'status' => 'open',
                            'metadata' => $metadata,
                        ]);

                        ActivityAuditService::recordSubject(
                            NotificationIdentityConflict::class,
                            (int) $conflict->id,
                            'notification_identity_conflict_guest_claim_mismatch',
                            'notifications',
                            'Notification identity conflict created by guest claim',
                            old: null,
                            new: ['status' => 'open', 'metadata' => $metadata],
                            context: [
                                'conflict_id' => $conflict->id,
                                'reason' => 'guest_claim_user_mismatch',
                                'guest_id' => (int) $guest->id,
                                'user_id' => (int) $user->id,
                                'candidate_recipient_ids' => [(int) $recipient->id],
                            ],
                            causerId: (int) $user->id,
                            causerType: User::class,
                        );
                    }
                    continue;
                }

                if ((int) ($recipient->user_id ?? 0) === (int) $user->id) {
                    continue;
                }

                $recipient->forceFill(['user_id' => $user->id])->save();
                $updated++;
            }

            return $updated;
        }, 3);
    }
}
