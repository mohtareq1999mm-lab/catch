<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\NotificationIdentityConflict;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;
use App\Models\GuestIdentity;

class RecipientIdentityService
{
    /**
     * Resolve a stable recipient from versioned fingerprint aliases.
     *
     * @param array<int,array{key_version:string,fingerprint_hash:string}> $aliases
     * @return array{status:'resolved'|'ambiguous', recipient?:NotificationRecipient, conflict?:NotificationIdentityConflict}
     */
    public function resolveRecipient(array $aliases, string $recipientType = 'guest_contact', array $context = []): array
    {
        $aliases = collect($aliases)
            ->filter(fn ($a) => !empty($a['key_version']) && !empty($a['fingerprint_hash']))
            ->unique(fn ($a) => $a['key_version'] . ':' . $a['fingerprint_hash'])
            ->values()
            ->all();

        if ($aliases === []) {
            throw ValidationException::withMessages(['fingerprints' => 'At least one fingerprint alias is required.']);
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

            $recipientIds = $rows->pluck('recipient_id')->unique()->values();

            if ($recipientIds->count() > 1) {
                $conflict = NotificationIdentityConflict::query()->create([
                    'channel' => $context['channel'] ?? null,
                    'key_version' => $aliases[0]['key_version'] ?? null,
                    'fingerprint_hash' => $aliases[0]['fingerprint_hash'] ?? null,
                    'candidate_recipient_ids' => $recipientIds->all(),
                    'status' => 'open',
                    'metadata' => [
                        'recipient_type' => $recipientType,
                        'aliases' => $aliases,
                        'context' => $context,
                    ],
                ]);

                return ['status' => 'ambiguous', 'conflict' => $conflict];
            }

            $recipient = $recipientIds->count() === 1
                ? NotificationRecipient::query()->lockForUpdate()->findOrFail($recipientIds->first())
                : NotificationRecipient::query()->create([
                    'recipient_type' => $recipientType,
                    'status' => 'active',
                    'user_id' => $context['user_id'] ?? null,
                    'guest_id' => $context['guest_id'] ?? null,
                ]);

            foreach ($aliases as $alias) {
                NotificationRecipientFingerprint::query()->firstOrCreate([
                    'key_version' => $alias['key_version'],
                    'fingerprint_hash' => $alias['fingerprint_hash'],
                ], [
                    'recipient_id' => $recipient->id,
                ]);

                $existing = NotificationRecipientFingerprint::query()
                    ->where('key_version', $alias['key_version'])
                    ->where('fingerprint_hash', $alias['fingerprint_hash'])
                    ->lockForUpdate()
                    ->first();

                if ($existing && (int) $existing->recipient_id !== (int) $recipient->id) {
                    $conflict = NotificationIdentityConflict::query()->create([
                        'channel' => $context['channel'] ?? null,
                        'key_version' => $alias['key_version'],
                        'fingerprint_hash' => $alias['fingerprint_hash'],
                        'candidate_recipient_ids' => array_values(array_unique([(int) $recipient->id, (int) $existing->recipient_id])),
                        'status' => 'open',
                        'metadata' => [
                            'recipient_type' => $recipientType,
                            'aliases' => $aliases,
                            'context' => $context,
                        ],
                    ]);

                    return ['status' => 'ambiguous', 'conflict' => $conflict];
                }
            }

            return ['status' => 'resolved', 'recipient' => $recipient];
        }, 3);
    }

    public function resolveConflict(NotificationIdentityConflict $conflict, string $action, ?int $resolvedBy = null, ?string $note = null): NotificationIdentityConflict
    {
        if (!in_array($action, ['link', 'split', 'dismiss'], true)) {
            throw ValidationException::withMessages(['action' => 'Invalid conflict action.']);
        }

        $conflict->forceFill([
            'status' => $action === 'link' ? 'linked' : ($action === 'split' ? 'split' : 'dismissed'),
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();

        return $conflict;
    }

    /**
     * Link existing guest recipient identity to authenticated user without changing
     * recipient IDs, order ownership, payments, or shipments. No notification resend.
     */
    public function linkGuestRecipientsToUser(GuestIdentity $guest, User $user): int
    {
        return DB::transaction(function () use ($guest, $user) {
            return (int) NotificationRecipient::query()
                ->where('recipient_type', 'guest_contact')
                ->where('guest_id', $guest->id)
                ->where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                })
                ->update([
                    'user_id' => $user->id,
                    'updated_at' => now(),
                ]);
        }, 3);
    }
}
