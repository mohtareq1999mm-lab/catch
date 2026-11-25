<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Audit\ActivityAuditService;
use App\Audit\ActivitySnapshot;
use App\Models\Finance\FinanceAccessGrant;
use App\Support\FinanceAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Marvel\Database\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * #191: explicit, temporary and audited finance access for exceptional cases.
 *
 * The Spatie permission is granted only alongside a durable grant row. The
 * row's expiry is enforced by FinanceAccessGate, so an expired grant cannot
 * authorize a permission even before a cleanup job removes the Spatie pivot.
 */
final class FinanceBreakGlassService
{
    public function grant(
        User $user,
        string $permission,
        DateTimeInterface $expiresAt,
        int $grantedBy,
        string $reason,
    ): FinanceAccessGrant {
        if (! in_array($permission, FinanceAccess::permissions(), true)) {
            throw new InvalidArgumentException(__('finance.invalid_permission'));
        }

        $grantedBy = $this->positiveInt($grantedBy);
        $reason = $this->reason($reason);
        $expiry = CarbonImmutable::instance($expiresAt);
        $maxExpiry = CarbonImmutable::now()->addHours($this->maxHours());

        if (! $expiry->isFuture() || $expiry->gt($maxExpiry)) {
            throw new InvalidArgumentException(__('finance.invalid_expiry'));
        }

        return DB::transaction(function () use ($user, $permission, $expiry, $grantedBy, $reason): FinanceAccessGrant {
            $active = FinanceAccessGrant::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('permission', $permission)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($active) {
                throw new DomainException(__('finance.already_active'));
            }

            $grant = FinanceAccessGrant::create([
                'user_id' => $user->getAuthIdentifier(),
                'permission' => $permission,
                'reason' => $reason,
                'granted_by' => $grantedBy,
                'expires_at' => $expiry,
            ]);

            $user->givePermissionTo($permission);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            ActivityAuditService::record(new ActivitySnapshot(
                logName: 'finance',
                event: 'finance.break_glass_granted',
                description: 'Temporary finance access granted.',
                subjectType: User::class,
                subjectId: (int) $user->getAuthIdentifier(),
                causerType: User::class,
                causerId: $grantedBy,
                new: [
                    'permission' => $permission,
                    'expires_at' => $expiry->toIso8601String(),
                ],
                context: ['reason' => $reason],
                reason: $reason,
            ));

            return $grant;
        });
    }

    public function revoke(FinanceAccessGrant $grant, int $actorId, string $reason): FinanceAccessGrant
    {
        $actorId = $this->positiveInt($actorId);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($grant, $actorId, $reason): FinanceAccessGrant {
            $locked = FinanceAccessGrant::query()->lockForUpdate()->findOrFail($grant->id);
            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $locked->revoked_at = now();
            $locked->save();

            $stillGranted = FinanceAccessGrant::query()
                ->where('user_id', $locked->user_id)
                ->where('permission', $locked->permission)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if (! $stillGranted) {
                $user = User::query()->findOrFail($locked->user_id);
                $user->revokePermissionTo($locked->permission);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            ActivityAuditService::record(new ActivitySnapshot(
                logName: 'finance',
                event: 'finance.break_glass_revoked',
                description: 'Temporary finance access revoked.',
                subjectType: User::class,
                subjectId: (int) $locked->user_id,
                causerType: User::class,
                causerId: $actorId,
                old: ['permission' => $locked->permission],
                context: ['reason' => $reason],
                reason: $reason,
            ));

            return $locked->fresh();
        });
    }

    private function maxHours(): int
    {
        return max(1, (int) config('finance.break_glass.max_hours', 8));
    }

    private function positiveInt(int $value): int
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(__('finance.invalid_actor'));
        }

        return $value;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new InvalidArgumentException(__('finance.reason_required'));
        }

        return $reason;
    }
}
