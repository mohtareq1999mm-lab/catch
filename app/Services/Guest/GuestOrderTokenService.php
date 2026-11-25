<?php

namespace App\Services\Guest;

use App\Enums\GuestCredentialDomain;
use App\Services\Tracking\TrackingAggregateService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;

/**
 * Guest order access credentials (public tracking + single-use cancellation).
 *
 * Only keyed SHA-256 hashes are persisted; the raw token is returned to the
 * client exactly once (or re-issued to the owning guest session) and is never
 * logged or stored.
 */
class GuestOrderTokenService
{
    public function __construct(
        private GuestCredentialService $credentials,
        private TrackingAggregateService $aggregates,
    ) {
    }

    public function hash(string $rawToken): string
    {
        return $this->credentials->hash($rawToken, GuestCredentialDomain::Legacy);
    }

    public function isValidTokenFormat(?string $token): bool
    {
        return $this->credentials->isValidFormat($token);
    }

    /** Kept public: tracking-link tests and the concurrency probe mint here. */
    public function generateToken(): string
    {
        return $this->credentials->mint();
    }

    /**
     * Issue (or rotate) the public tracking token.
     * Refuses to undo an explicit admin revocation.
     */
    public function issueTrackingToken(Order $order): ?string
    {
        if ($order->guest_tracking_revoked_at !== null) {
            return null;
        }

        $raw = $this->generateToken();
        $hash = $this->hash($raw);

        Order::query()->whereKey($order->id)->update([
            'guest_tracking_token_hash' => $hash,
            'updated_at' => now(),
        ]);

        $order->setAttribute('guest_tracking_token_hash', $hash);

        return $raw;
    }

    /**
     * Issue (or rotate) the single-use cancellation token when the order is
     * currently cancellable under the existing customer policy.
     */
    public function issueCancellationToken(Order $order): ?string
    {
        if (!$this->isOrderCancellable($order)) {
            return null;
        }

        $raw = $this->generateToken();
        $hash = $this->hash($raw);
        $expiresAt = now()->addHours(max(1, (int) config('guest_checkout.cancellation_link_ttl_hours', 24)));

        Order::query()->whereKey($order->id)->update([
            'guest_cancellation_token_hash' => $hash,
            'guest_cancellation_token_used_at' => null,
            'guest_cancellation_token_expires_at' => $expiresAt,
            'updated_at' => now(),
        ]);

        $order->setAttribute('guest_cancellation_token_hash', $hash);
        $order->setAttribute('guest_cancellation_token_used_at', null);
        $order->setAttribute('guest_cancellation_token_expires_at', $expiresAt);

        return $raw;
    }

    public function findByTrackingToken(string $rawToken): ?Order
    {
        if (!$this->isValidTokenFormat($rawToken)) {
            return null;
        }

        $order = Order::query()
            ->where('guest_tracking_token_hash', $this->hash($rawToken))
            ->first();

        if (!$order || $order->guest_tracking_revoked_at !== null) {
            return null;
        }

        $expiresAt = $this->trackingExpiresAt($order);

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return null;
        }

        return $order;
    }

    /**
     * Tracking follows the order lifecycle, then a 30-day grace period after
     * the terminal timestamp. Active orders have no hard expiry.
     *
     * When shipment aggregates exist they are authoritative for delivery:
     * legacy order mirrors (actual_delivery_at/completed_at) must never
     * manufacture a delivery timestamp for a returned/outstanding aggregate.
     *
     * @param array{status:string,eta:?\Carbon\CarbonInterface,delivered_at:?\Carbon\CarbonInterface,has_shipments:bool}|null $aggregate
     */
    public function trackingExpiresAt(Order $order, ?array $aggregate = null): ?CarbonInterface
    {
        $terminalAt = $this->terminalAt($order, $aggregate);

        if ($terminalAt === null) {
            return null;
        }

        return $terminalAt->copy()->addDays(max(1, (int) config('guest_checkout.tracking_grace_days', 30)));
    }

    public function isOrderCancellable(Order $order): bool
    {
        return in_array($order->status, [Order::ORDER_STATUS_PENDING, Order::ORDER_STATUS_PROCESSING], true)
            && $order->payment_status !== Order::PAYMENT_STATUS_SUCCESS;
    }

    public function revokeTracking(Order $order, ?int $adminId = null): bool
    {
        $updated = Order::query()->whereKey($order->id)->update(
            $this->credentials->trackingRevocation() + ['updated_at' => now()]
        );

        if ($updated > 0) {
            // Safe audit trail: ids only, never the credential.
            Log::info('Guest order tracking revoked', [
                'order_id' => $order->id,
                'admin_id' => $adminId,
            ]);
        }

        return $updated > 0;
    }

    /**
     * @param array{status:string,eta:?\Carbon\CarbonInterface,delivered_at:?\Carbon\CarbonInterface,has_shipments:bool}|null $aggregate
     */
    private function terminalAt(Order $order, ?array $aggregate = null): ?CarbonInterface
    {
        // Cancellation remains an order-lifecycle fact; the aggregate never
        // triggers it, but an explicit cancellation still bounds the link.
        if ($order->status === Order::ORDER_STATUS_CANCELLED) {
            return $order->cancelled_at
                ?? $order->updated_at
                ?? $order->created_at;
        }

        $aggregate ??= $this->aggregates->forOrder($order);

        if ($aggregate['has_shipments']) {
            // Round 12: both delivered and returned shipment aggregates are
            // terminal. A stale order mirror must not manufacture the
            // timestamp, and an outstanding split shipment has no hard expiry.
            return in_array($aggregate['status'], ['delivered', 'returned'], true)
                ? $aggregate['delivered_at']
                : null;
        }

        // Legacy orders without shipment data keep the historical fallback.
        if (in_array($order->status, [Order::ORDER_STATUS_DELIVERED, Order::ORDER_STATUS_COMPLETED], true)) {
            return $order->actual_delivery_at
                ?? $order->completed_at
                ?? $order->updated_at
                ?? $order->created_at;
        }

        return null;
    }
}
