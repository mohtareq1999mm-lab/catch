<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Models\Shipping\NotificationRecipient;
use App\Models\Shipping\NotificationRecipientFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NotificationRecipientResolver
{
    /**
     * @param array{
     *   recipient_type:string,
     *   recipient_key?:string|null,
     *   fingerprint?:string|null,
     *   fingerprint_version?:string|null,
     *   contact_encrypted?:string|null,
     *   metadata?:array|null,
     *   candidate_recipient_ids?:array<int,int|string>|null
     * } $attributes
     */
    public function resolve(array $attributes): NotificationRecipient
    {
        $type = trim((string) ($attributes['recipient_type'] ?? ''));
        $recipientKey = $this->nullableString($attributes['recipient_key'] ?? null);
        $fingerprint = $this->nullableString($attributes['fingerprint'] ?? null);
        $version = $this->nullableString($attributes['fingerprint_version'] ?? null) ?? 'v1';
        $candidates = array_values(array_unique(array_filter(array_map('intval', $attributes['candidate_recipient_ids'] ?? []))));

        if ($type === '') {
            throw new RuntimeException('recipient_type is required');
        }

        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                return DB::transaction(function () use ($type, $recipientKey, $fingerprint, $version, $candidates, $attributes) {
                    if ($candidates !== []) {
                        $rows = NotificationRecipient::query()
                            ->whereIn('id', $candidates)
                            ->lockForUpdate()
                            ->get();

                        if ($rows->count() > 1) {
                            throw new RuntimeException('Ambiguous recipient candidates; auto-merge is not allowed.');
                        }

                        if ($rows->count() === 1) {
                            $recipient = $rows->first();
                            $this->assertRecipientMatches($recipient, $type, $recipientKey, $fingerprint, $version);
                            $this->attachFingerprint($recipient, $version, $fingerprint, $attributes['metadata'] ?? null);
                            $this->maybeUpdateContact($recipient, $attributes['contact_encrypted'] ?? null);
                            return $recipient;
                        }
                    }

                    if ($recipientKey !== null) {
                        $existing = $this->findByRecipientKeyForUpdate($type, $recipientKey);
                        if ($existing) {
                            $this->assertRecipientMatches($existing, $type, $recipientKey, $fingerprint, $version);
                            $this->attachFingerprint($existing, $version, $fingerprint, $attributes['metadata'] ?? null);
                            $this->maybeUpdateContact($existing, $attributes['contact_encrypted'] ?? null);
                            return $existing;
                        }
                    }

                    if ($fingerprint !== null) {
                        $recipient = $this->resolveByFingerprintForUpdate($type, $recipientKey, $attributes);
                        if ($recipient) {
                            return $recipient;
                        }
                    }

                    $recipient = $this->createRecipient($type, $recipientKey, $attributes);

                    $this->attachFingerprint($recipient, $version, $fingerprint, $attributes['metadata'] ?? null);

                    return $recipient;
                }, 3);
            } catch (QueryException $e) {
                if ($attempts < 3 && $this->isRetryableRecipientConflict($e)) {
                    continue;
                }
                $mapped = $this->mapRecipientConflict($e, $attributes);
                if ($attempts < 3 && $mapped instanceof RuntimeException && str_contains($mapped->getMessage(), 're-resolve required')) {
                    continue;
                }
                throw $mapped;
            }
        }
    }

    public function rotateFingerprint(NotificationRecipient $recipient, string $version, string $fingerprint, ?array $metadata = null): NotificationRecipient
    {
        return DB::transaction(function () use ($recipient, $version, $fingerprint, $metadata) {
            $locked = NotificationRecipient::query()->lockForUpdate()->findOrFail($recipient->id);
            $this->attachFingerprint($locked, $version, $fingerprint, $metadata);
            return $locked;
        }, 3);
    }

    private function resolveByFingerprintForUpdate(string $type, ?string $recipientKey, array $attributes): ?NotificationRecipient
    {
        $fingerprint = $this->nullableString($attributes['fingerprint'] ?? null);
        $version = $this->nullableString($attributes['fingerprint_version'] ?? null) ?? 'v1';
        $matches = NotificationRecipientFingerprint::query()
            ->where('version', $version)
            ->where('fingerprint', $fingerprint)
            ->lockForUpdate()
            ->get();

        $recipientIds = $matches->pluck('notification_recipient_id')->unique()->values();
        if ($recipientIds->count() > 1) {
            throw new RuntimeException('Ambiguous fingerprint match; auto-merge is not allowed.');
        }

        if ($recipientIds->count() !== 1) {
            return null;
        }

        $recipient = NotificationRecipient::query()->lockForUpdate()->findOrFail($recipientIds->first());
        $this->assertRecipientMatches($recipient, $type, $recipientKey, $fingerprint, $version);

        if ($recipientKey !== null && $recipient->recipient_key === null) {
            $recipient->recipient_key = $recipientKey;
            $recipient->save();
        }

        $this->attachFingerprint($recipient, $version, $fingerprint, $attributes['metadata'] ?? null);
        $this->maybeUpdateContact($recipient, $attributes['contact_encrypted'] ?? null);

        return $recipient;
    }

    protected function attachFingerprint(NotificationRecipient $recipient, string $version, ?string $fingerprint, ?array $metadata): void
    {
        if ($fingerprint === null || $fingerprint === '') {
            return;
        }

        try {
            NotificationRecipientFingerprint::query()->create([
                'notification_recipient_id' => $recipient->id,
                'version' => $version,
                'fingerprint' => $fingerprint,
                'metadata' => $metadata,
            ]);
        } catch (QueryException $e) {
            $existing = $this->findFingerprintForUpdate($version, $fingerprint);
            if ($existing && (int) $existing->notification_recipient_id !== (int) $recipient->id) {
                throw new RuntimeException('Ambiguous fingerprint match; auto-merge is not allowed.', 0, $e);
            }

            $existingByPair = NotificationRecipientFingerprint::query()
                ->where('notification_recipient_id', $recipient->id)
                ->where('version', $version)
                ->lockForUpdate()
                ->first();
            if ($existingByPair && $existingByPair->fingerprint !== $fingerprint) {
                throw new RuntimeException('Fingerprint version already assigned to recipient with different value.', 0, $e);
            }

            if (!$existing && !$existingByPair) {
                throw $e;
            }
        }
    }

    private function assertRecipientMatches(NotificationRecipient $recipient, string $type, ?string $recipientKey, ?string $fingerprint, string $version): void
    {
        if ($recipient->recipient_type !== $type) {
            throw new RuntimeException('Ambiguous fingerprint match across recipient types; auto-merge is not allowed.');
        }

        if ($recipientKey !== null && $recipient->recipient_key !== null && $recipient->recipient_key !== $recipientKey) {
            throw new RuntimeException('Ambiguous recipient key/fingerprint match; auto-merge is not allowed.');
        }

        if ($recipientKey !== null && $fingerprint !== null) {
            $fingerprintOwner = $this->findFingerprintForUpdate($version, $fingerprint);
            if ($fingerprintOwner && (int) $fingerprintOwner->notification_recipient_id !== (int) $recipient->id) {
                throw new RuntimeException('Ambiguous recipient key/fingerprint match; auto-merge is not allowed.');
            }
        }
    }

    protected function findByRecipientKeyForUpdate(string $type, string $recipientKey): ?NotificationRecipient
    {
        return NotificationRecipient::query()
            ->where('recipient_type', $type)
            ->where('recipient_key', $recipientKey)
            ->lockForUpdate()
            ->first();
    }

    private function findFingerprintForUpdate(string $version, string $fingerprint): ?NotificationRecipientFingerprint
    {
        return NotificationRecipientFingerprint::query()
            ->where('version', $version)
            ->where('fingerprint', $fingerprint)
            ->lockForUpdate()
            ->first();
    }

    protected function createRecipient(string $type, ?string $recipientKey, array $attributes): NotificationRecipient
    {
        try {
            return NotificationRecipient::query()->create([
                'recipient_type' => $type,
                'recipient_key' => $recipientKey,
                'contact_encrypted' => $attributes['contact_encrypted'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ]);
        } catch (QueryException $e) {
            if ($recipientKey !== null && $this->isRetryableRecipientConflict($e)) {
                $existing = $this->findByRecipientKeyForUpdate($type, $recipientKey);
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    private function isRetryableRecipientConflict(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        return $sqlState === '23000' || $driverCode === 1062 || $sqlState === '23505';
    }

    private function mapRecipientConflict(QueryException $e, array $attributes): \Throwable
    {
        if (!$this->isRetryableRecipientConflict($e)) {
            return $e;
        }

        $type = trim((string) ($attributes['recipient_type'] ?? ''));
        $recipientKey = $this->nullableString($attributes['recipient_key'] ?? null);
        $fingerprint = $this->nullableString($attributes['fingerprint'] ?? null);
        $version = $this->nullableString($attributes['fingerprint_version'] ?? null) ?? 'v1';

        try {
            return DB::transaction(function () use ($type, $recipientKey, $fingerprint, $version, $e) {
                if ($recipientKey !== null) {
                    $existing = $this->findByRecipientKeyForUpdate($type, $recipientKey);
                    if ($existing) {
                        $this->assertRecipientMatches($existing, $type, $recipientKey, $fingerprint, $version);
                        return new RuntimeException('Recipient key conflict is retryable; re-resolve required.', 0, $e);
                    }
                }

                if ($fingerprint !== null) {
                    $existing = $this->findFingerprintForUpdate($version, $fingerprint);
                    if ($existing) {
                        $recipient = NotificationRecipient::query()->lockForUpdate()->findOrFail($existing->notification_recipient_id);
                        $this->assertRecipientMatches($recipient, $type, $recipientKey, $fingerprint, $version);
                        return new RuntimeException('Recipient fingerprint conflict is retryable; re-resolve required.', 0, $e);
                    }
                }

                return $e;
            }, 3);
        } catch (\Throwable $mapped) {
            return $mapped;
        }
    }

    private function maybeUpdateContact(NotificationRecipient $recipient, mixed $contactEncrypted): void
    {
        if (is_string($contactEncrypted) && $contactEncrypted !== '' && $recipient->contact_encrypted !== $contactEncrypted) {
            $recipient->contact_encrypted = $contactEncrypted;
            $recipient->save();
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
