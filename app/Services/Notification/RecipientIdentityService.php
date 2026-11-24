<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Audit\ActivityAuditService;
use App\Models\GuestIdentity;
use App\Models\NotificationIdentityConflict;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;

class RecipientIdentityService
{
    /**
     * @param null|callable(NotificationIdentityConflict,string,int):void $authorizeResolution
     */
    public function __construct(private readonly mixed $authorizeResolution = null)
    {
    }

    /**
     * Resolve a stable recipient from versioned fingerprint aliases.
     *
     * Atomic/locked: creates no orphan recipient and leaves no partial alias
     * attachment on ambiguity. Ambiguity is resolved by conflict case, never by
     * guessing a recipient.
     *
     * @param array<int,array{key_version:string,fingerprint_hash:string}> $aliases
     * @return array{status:'resolved'|'ambiguous', recipient?:NotificationRecipient, conflict?:NotificationIdentityConflict}
     */
    public function resolveRecipient(array $aliases, string $recipientType = 'guest_contact', array $context = []): array
    {
        $aliases = collect($aliases)
            ->filter(fn ($a) => is_array($a) && !empty($a['key_version']) && !empty($a['fingerprint_hash']))
            ->map(fn ($a) => [
                'key_version' => trim((string) $a['key_version']),
                'fingerprint_hash' => strtolower(trim((string) $a['fingerprint_hash'])),
            ])
            ->filter(fn ($a) => preg_match('/^[A-Za-z0-9_.:-]{1,32}$/', $a['key_version']) === 1
                && preg_match('/^[a-f0-9]{64}$/', $a['fingerprint_hash']) === 1)
            ->unique(fn ($a) => $a['key_version'] . ':' . $a['fingerprint_hash'])
            ->values()
            ->all();

        if ($aliases === []) {
            throw ValidationException::withMessages(['fingerprints' => 'At least one valid fingerprint alias is required.']);
        }

        return DB::transaction(function () use ($aliases, $recipientType, $context) {
            $rows = NotificationRecipientFingerprint::query()
                ->where(function ($q) use ($aliases) {
                    foreach ($aliases as $alias) {
                        $q->orWhere(function ($qq) use ($alias) {
                            $qq->where('key_version', $alias['key_version'])
                                ->where('fingerprint_hash', $alias['fingerprint_hash']);
                        });
                    }
                })
                ->lockForUpdate()
                ->get();

            $recipientIds = $rows->pluck('recipient_id')->unique()->values()->map(fn ($id) => (int) $id);

            if ($recipientIds->count() > 1) {
                return $this->ambiguousResult($recipientIds->all(), $aliases, $recipientType, $context);
            }

            $createdRecipientId = null;
            $attachedAliasKeys = [];

            if ($recipientIds->count() === 1) {
                $recipient = NotificationRecipient::query()->lockForUpdate()->findOrFail($recipientIds->first());
            } else {
                $recipient = NotificationRecipient::query()->create([
                    'recipient_type' => $recipientType,
                    'status' => 'active',
                    'user_id' => $context['user_id'] ?? null,
                    'guest_id' => $context['guest_id'] ?? null,
                ]);
                $createdRecipientId = (int) $recipient->id;
            }

            foreach ($aliases as $alias) {
                $key = ['key_version' => $alias['key_version'], 'fingerprint_hash' => $alias['fingerprint_hash']];

                NotificationRecipientFingerprint::query()->firstOrCreate($key, [
                    'recipient_id' => $recipient->id,
                ]);

                $existing = NotificationRecipientFingerprint::query()
                    ->where('key_version', $alias['key_version'])
                    ->where('fingerprint_hash', $alias['fingerprint_hash'])
                    ->lockForUpdate()
                    ->first();

                if (!$existing) {
                    continue;
                }

                if ((int) $existing->recipient_id === (int) $recipient->id) {
                    $attachedAliasKeys[] = $key;
                    continue;
                }

                // Ambiguity detected: rollback any recipient/fingerprint writes made by this tx.
                $this->rollbackResolutionAttempt($createdRecipientId, $attachedAliasKeys);

                return $this->ambiguousResult(
                    array_values(array_unique([(int) $recipient->id, (int) $existing->recipient_id])),
                    $aliases,
                    $recipientType,
                    $context
                );
            }

            return ['status' => 'resolved', 'recipient' => $recipient->fresh()];
        }, 3);
    }

    /**
     * @param array<int,int> $candidateIds
     * @param array<int,array{key_version:string,fingerprint_hash:string}> $aliases
     * @return array{status:'ambiguous', conflict:NotificationIdentityConflict}
     */
    private function ambiguousResult(array $candidateIds, array $aliases, string $recipientType, array $context): array
    {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        sort($candidateIds);
        if ($candidateIds === [] || collect($candidateIds)->contains(fn (int $id) => $id <= 0)) {
            throw ValidationException::withMessages(['candidate_recipient_ids' => 'Invalid conflict candidates.']);
        }

        $conflict = $this->recordConflict($candidateIds, $aliases, $recipientType, $context);

        return ['status' => 'ambiguous', 'conflict' => $conflict];
    }

    /**
     * @param array<int,int> $candidateIds
     * @param array<int,array{key_version:string,fingerprint_hash:string}> $aliases
     */
    private function recordConflict(array $candidateIds, array $aliases, string $recipientType, array $context): NotificationIdentityConflict
    {
        $primary = $aliases[0];
        $metadata = $this->sanitizeConflictMetadata([
            'recipient_type' => $recipientType,
            'aliases' => array_map(fn ($a) => [
                'key_version' => $a['key_version'],
                'fingerprint_hash' => $a['fingerprint_hash'],
            ], $aliases),
            'context' => $context,
        ]);

        $existing = NotificationIdentityConflict::query()
            ->where('status', 'open')
            ->where('key_version', $primary['key_version'])
            ->where('fingerprint_hash', $primary['fingerprint_hash'])
            ->lockForUpdate()
            ->get()
            ->first(function (NotificationIdentityConflict $c) use ($candidateIds) {
                $ids = array_map('intval', $c->candidate_recipient_ids ?? []);
                sort($ids);
                return $ids === $candidateIds;
            });

        if ($existing) {
            $existing->forceFill(['metadata' => $metadata])->save();
            return $existing;
        }

        return NotificationIdentityConflict::query()->create([
            'channel' => $context['channel'] ?? null,
            'key_version' => $primary['key_version'],
            'fingerprint_hash' => $primary['fingerprint_hash'],
            'candidate_recipient_ids' => $candidateIds,
            'status' => 'open',
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param array<int,array{key_version:string,fingerprint_hash:string}> $attachedAliasKeys
     */
    private function rollbackResolutionAttempt(?int $createdRecipientId, array $attachedAliasKeys): void
    {
        if ($createdRecipientId === null) {
            return;
        }

        if ($attachedAliasKeys !== []) {
            NotificationRecipientFingerprint::query()
                ->where('recipient_id', $createdRecipientId)
                ->where(function ($q) use ($attachedAliasKeys) {
                    foreach ($attachedAliasKeys as $alias) {
                        $q->orWhere(function ($qq) use ($alias) {
                            $qq->where('key_version', $alias['key_version'])
                                ->where('fingerprint_hash', $alias['fingerprint_hash']);
                        });
                    }
                })
                ->delete();
        }

        NotificationRecipient::query()->whereKey($createdRecipientId)->delete();
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
        if ($target->guest_id === null) {
            $guestIds = $candidates->pluck('guest_id')->filter()->unique()->values();
            if ($guestIds->count() === 1) {
                $target->guest_id = (int) $guestIds->first();
            }
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
        $context = $metadata['context'] ?? [];
        if (is_array($context)) {
            $allowed = ['channel', 'source', 'reason', 'order_id', 'shipment_id', 'guest_id', 'user_id'];
            $clean = [];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $context) && !is_scalar($context[$key]) && $context[$key] !== null) {
                    continue;
                }
                if (array_key_exists($key, $context)) {
                    $clean[$key] = $context[$key];
                }
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
                    NotificationIdentityConflict::query()->create([
                        'channel' => null,
                        'key_version' => null,
                        'fingerprint_hash' => null,
                        'candidate_recipient_ids' => [(int) $recipient->id],
                        'status' => 'open',
                        'metadata' => $this->sanitizeConflictMetadata([
                            'recipient_type' => 'guest_contact',
                            'aliases' => [],
                            'context' => [
                                'reason' => 'guest_claim_user_mismatch',
                                'guest_id' => $guest->id,
                                'user_id' => $user->id,
                            ],
                        ]),
                    ]);
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
