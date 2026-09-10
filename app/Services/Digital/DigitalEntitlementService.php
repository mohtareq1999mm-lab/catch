<?php

namespace App\Services\Digital;

use App\Jobs\LogActivityJob;
use App\Models\DigitalEntitlement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Marvel\Database\Models\User;

/**
 * Admin-side entitlement management (Workstream 6).
 *
 * Business rules live here; the Marvel controller stays CRUD. Revocation
 * reuses the W1 fulfillment service so the D7 refund interlock and the
 * download status gate remain the ONLY revocation authority.
 *
 * Download-limit sentinel: 0 = UNLIMITED. The customer download gate treats
 * limit>0 as a hard cap and limit=0 as unbounded (see
 * DigitalDownloadController). Any positive value is a normal cap.
 */
class DigitalEntitlementService
{
    public const UNLIMITED = 0;

    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return DigitalEntitlement::query()
            ->with(['orderItem.product', 'user'])
            ->when($filters['uuid'] ?? null, fn ($q, $v) => $q->where('uuid', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', (int) $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', (int) $v))
            ->when(($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn ($q, $v) => $q->where('uuid', 'like', "%{$v}%"))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Override the download cap. `null` sentinel input maps to UNLIMITED(0);
     * the previous value is returned for audit properties.
     */
    public function setDownloadLimit(DigitalEntitlement $entitlement, ?int $limit, ?User $actor): array
    {
        $previous = (int) $entitlement->download_limit;
        $new = $limit === null ? self::UNLIMITED : max(0, $limit);

        $entitlement->forceFill(['download_limit' => $new])->save();

        $this->log($entitlement, $actor, 'digital.entitlement.limit_changed', [
            'previous' => $previous,
            'new' => $new,
            'unlimited' => $new === self::UNLIMITED,
        ]);

        return ['previous' => $previous, 'new' => $new];
    }

    /** Revoke — delegates to the single W1 authority (D7-consistent). */
    public function revoke(DigitalEntitlement $entitlement, ?User $actor): void
    {
        if ($entitlement->status === DigitalEntitlement::STATUS_REVOKED) {
            return; // idempotent
        }

        app(DigitalFulfillmentService::class)->revoke($entitlement);

        $this->log($entitlement, $actor, 'digital.entitlement.revoked', [
            'revoked_at' => optional($entitlement->refresh()->revoked_at)->toIso8601String(),
        ]);
    }

    /**
     * Restore a REVOKED entitlement to DELIVERED. Terminal-state transition
     * only (pending/delivered are not restorable); every restore is
     * activity-logged for oversight because it re-opens paid access.
     */
    public function restore(DigitalEntitlement $entitlement, ?User $actor): void
    {
        if ($entitlement->status !== DigitalEntitlement::STATUS_REVOKED) {
            return; // idempotent no-op for non-revoked states
        }

        $entitlement->forceFill([
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'revoked_at' => null,
        ])->save();

        $this->log($entitlement, $actor, 'digital.entitlement.restored', [
            'restored_to' => DigitalEntitlement::STATUS_DELIVERED,
        ]);
    }

    private function log(DigitalEntitlement $entitlement, ?User $actor, string $event, array $properties): void
    {
        LogActivityJob::dispatch(
            DigitalEntitlement::class,
            $entitlement->id,
            $actor?->getAuthIdentifier(),
            $event,
            'digital-entitlements',
            __("activity.{$event}"),
            $properties
        );
    }
}
