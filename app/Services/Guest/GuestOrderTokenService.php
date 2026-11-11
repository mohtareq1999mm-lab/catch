<?php

namespace App\Services\Guest;

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
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{20,120}$/';

    public function hash(string $rawToken): string
    {
        return hash_hmac('sha256', $rawToken, (string) config('app.key'));
    }

    public function isValidTokenFormat(?string $token): bool
    {
        return is_string($token) && (bool) preg_match(self::TOKEN_PATTERN, $token);
    }

    public function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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

        Order::query()->whereKey($order->id)->update([
            'guest_tracking_token_hash' => $this->hash($raw),
            'updated_at' => now(),
        ]);

        $order->setAttribute('guest_tracking_token_hash', $this->hash($raw));

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
        $expiresAt = now()->addHours(max(1, (int) config('guest_checkout.cancellation_link_ttl_hours', 72)));

        Order::query()->whereKey($order->id)->update([
            'guest_cancellation_token_hash' => $this->hash($raw),
            'guest_cancellation_token_used_at' => null,
            'guest_cancellation_token_expires_at' => $expiresAt,
            'updated_at' => now(),
        ]);

        $order->setAttribute('guest_cancellation_token_hash', $this->hash($raw));
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

    public function findByCancellationToken(string $rawToken): ?Order
    {
        if (!$this->isValidTokenFormat($rawToken)) {
            return null;
        }

        $order = Order::query()
            ->where('guest_cancellation_token_hash', $this->hash($rawToken))
            ->first();

        if (!$order) {
            return null;
        }

        if ($order->guest_cancellation_token_used_at !== null) {
            return null;
        }

        $expiresAt = $order->guest_cancellation_token_expires_at;

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return null;
        }

        return $order;
    }

    /**
     * Tracking follows the order lifecycle, then a 30-day grace period after
     * the terminal timestamp. Active orders have no hard expiry.
     */
    public function trackingExpiresAt(Order $order): ?CarbonInterface
    {
        $terminalAt = $this->terminalAt($order);

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
        $updated = Order::query()->whereKey($order->id)->update([
            'guest_tracking_token_hash' => null,
            'guest_tracking_revoked_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated > 0) {
            // Safe audit trail: ids only, never the credential.
            Log::info('Guest order tracking revoked', [
                'order_id' => $order->id,
                'admin_id' => $adminId,
            ]);
        }

        return $updated > 0;
    }

    private function terminalAt(Order $order): ?CarbonInterface
    {
        if ($order->status === Order::ORDER_STATUS_CANCELLED) {
            return $order->cancelled_at
                ?? $order->updated_at
                ?? $order->created_at;
        }

        if (in_array($order->status, [Order::ORDER_STATUS_DELIVERED, Order::ORDER_STATUS_COMPLETED], true)) {
            return $order->actual_delivery_at
                ?? $order->completed_at
                ?? $order->updated_at
                ?? $order->created_at;
        }

        return null;
    }
}
