<?php

namespace App\Services\Guest;

use App\Audit\ActivityAuditService;
use App\Contracts\GuestPhoneVerificationContract;
use App\Models\GuestIdentity;
use App\Models\GuestOrderClaimToken;
use App\ValueObjects\VerifiedPhoneIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

/**
 * Guest order claiming (OTP-gated).
 *
 * This service is deliberately INERT in production:
 *  - config('guest_checkout.order_claim.enabled') defaults to false;
 *  - the default GuestPhoneVerificationContract binding is
 *    NullGuestPhoneVerification (always null).
 * Both gates must be satisfied before any claim path can proceed. No route
 * invokes this service.
 *
 * Ownership evidence is per-order:
 *  1. the claimed order must belong to the CURRENT verified guest identity
 *     (not merely match a phone number);
 *  2. the phone must be verified by the phone-verification contract;
 *  3. the order's stored phone must match the verified phone;
 *  4. a dedicated one-time claim token must match the order, guest and user.
 *
 * Failures are intentionally indistinguishable (`not_eligible` /
 * `invalid_token`) so callers cannot probe other customers' orders.
 */
class GuestOrderClaimService
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{20,120}$/';

    public function __construct(
        private GuestPhoneVerificationContract $verifier,
        private GuestIdentityService $identities,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('guest_checkout.order_claim.enabled', false);
    }

    /**
     * Orders this verified guest identity may claim. Only the caller's own
     * guest identity and verified phone are considered; no other orders are
     * returned.
     */
    public function eligibleOrders(
        User $user,
        Request $request,
        string $verificationReference,
        string $phone,
    ): array {
        $guest = $this->currentGuest($request);
        $proof = $this->verifiedProof($user, $verificationReference, $phone);

        if (!$guest || $proof === null) {
            return ['status' => 'verification_required', 'orders' => []];
        }

        $orders = Order::query()
            ->where('guest_id', $guest->id)
            ->whereNull('user_id')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Order $order) => $this->phoneMatches($order, $proof))
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total_price' => (float) $order->total_price,
                'currency' => $order->currency_code,
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return ['status' => 'ok', 'orders' => $orders];
    }

    /**
     * Issue one dedicated claim token for a single eligible order.
     * Only a verified phone (real verifier) can reach token creation.
     */
    public function issueToken(
        User $user,
        Request $request,
        Order $order,
        string $verificationReference,
        string $phone,
    ): array {
        $guest = $this->currentGuest($request);
        $proof = $this->verifiedProof($user, $verificationReference, $phone);

        if (!$guest || $proof === null) {
            return ['status' => 'verification_required'];
        }

        if (!$this->orderBelongsToGuest($order, $guest, $proof)) {
            return ['status' => 'not_eligible'];
        }

        $raw = $this->generateToken();
        $expiresAt = now()->addHours(max(1, (int) config('guest_checkout.order_claim.token_ttl_hours', 24)));
        $claimable = true;

        DB::transaction(function () use ($guest, $order, $user, $raw, $expiresAt, &$claimable) {
            // Re-read under lock: the order may have been claimed concurrently.
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (!$locked || $locked->user_id !== null || (int) $locked->guest_id !== (int) $guest->id) {
                $claimable = false;

                return;
            }

            // One active token per order/guest; old unused tokens are revoked.
            GuestOrderClaimToken::query()
                ->where('guest_id', $guest->id)
                ->where('order_id', $order->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            GuestOrderClaimToken::create([
                'guest_id' => $guest->id,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'token_hash' => $this->hashToken($raw),
                'expires_at' => $expiresAt,
            ]);
        });

        if (!$claimable) {
            return ['status' => 'not_eligible'];
        }

        return [
            'status' => 'issued',
            'claim_token' => $raw,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Consume a one-time claim token and attach the order to the user.
     * Same-user replay is idempotent; every other failure is generic.
     */
    public function claim(
        User $user,
        Request $request,
        string $claimToken,
        string $verificationReference,
        string $phone,
    ): array {
        $guest = $this->currentGuest($request);
        $proof = $this->verifiedProof($user, $verificationReference, $phone);

        if (!$guest || $proof === null) {
            return ['status' => 'verification_required'];
        }

        if (!preg_match(self::TOKEN_PATTERN, $claimToken)) {
            return ['status' => 'invalid_token'];
        }

        $hash = $this->hashToken($claimToken);

        return DB::transaction(function () use ($user, $guest, $hash, $proof) {
            /** @var GuestOrderClaimToken|null $token */
            $token = GuestOrderClaimToken::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (!$token) {
                return ['status' => 'invalid_token'];
            }

            /** @var Order|null $order */
            $order = Order::query()
                ->whereKey($token->order_id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return ['status' => 'invalid_token'];
            }

            // Already consumed: only the same user may see idempotent success.
            if ($token->used_at !== null) {
                return $this->sameUserReplay($order, $token, $user);
            }

            if (!$token->isUsable()) {
                return ['status' => 'invalid_token'];
            }

            if (
                (int) $token->guest_id !== (int) $guest->id
                || (int) $token->user_id !== (int) $user->id
                || (int) $order->guest_id !== (int) $guest->id
            ) {
                return ['status' => 'invalid_token'];
            }

            if ($order->user_id !== null) {
                return $this->sameUserReplay($order, $token, $user);
            }

            if (!$this->phoneMatches($order, $proof)) {
                return ['status' => 'not_eligible'];
            }

            // A user may hold only ONE pending order (DB unique guard). Claiming
            // another pending guest order would violate it; fail cleanly and
            // leave the claim token unused.
            if ($this->hasOtherPendingOrder($user, $order)) {
                return ['status' => 'pending_order_conflict'];
            }

            // Atomic assignment: only a still-unowned order can be claimed.
            try {
                $assigned = Order::query()
                    ->whereKey($order->id)
                    ->whereNull('user_id')
                    ->update([
                        'user_id' => $user->id,
                        // Guest cancellation/tracking links stop being usable.
                        'guest_cancellation_token_hash' => null,
                        'guest_cancellation_token_used_at' => now(),
                        'guest_tracking_token_hash' => null,
                        'guest_tracking_revoked_at' => now(),
                        'updated_at' => now(),
                    ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->isDuplicateKeyError($e)) {
                    return ['status' => 'pending_order_conflict'];
                }

                throw $e;
            }

            if ($assigned !== 1) {
                $fresh = Order::query()->whereKey($order->id)->first();

                return $this->sameUserReplay($fresh, $token, $user);
            }

            // Guest invoices were nullable by design; a successful claim makes
            // them appear in the account's invoice list.
            \App\Models\Invoice::query()
                ->where('order_id', $order->id)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);

            $consumed = GuestOrderClaimToken::query()
                ->whereKey($token->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['used_at' => now()]);

            if ($consumed !== 1) {
                throw new \RuntimeException('Claim token state changed under lock.');
            }

            ActivityAuditService::recordSubject(
                Order::class,
                (int) $order->id,
                'guest_order_claimed',
                'guest_checkout',
                'Guest order claimed by verified phone',
                old: ['user_id' => null],
                new: ['user_id' => $user->id],
                context: [
                    'guest_id' => $guest->id,
                    'claim_token_id' => $token->id,
                    'phone_verified' => true,
                ],
                causerId: $user->id,
                causerType: get_class($user),
            );

            $order->refresh();

            return [
                'status' => 'claimed',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ];
        });
    }

    private function currentGuest(Request $request): ?GuestIdentity
    {
        $guest = $this->identities->current($request);

        // Expired/revoked identities must never authorize a claim.
        return $guest && $guest->isUsable() ? $guest : null;
    }

    private function verifiedProof(User $user, string $reference, string $phone): ?VerifiedPhoneIdentity
    {        if (!$this->isEnabled() || $reference === '' || trim($phone) === '') {
            return null;
        }

        $proof = $this->verifier->verify($user, $reference, $phone);

        if (!$proof || (int) $proof->userId !== (int) $user->id || $proof->normalizedPhone() === '') {
            // Null binding, wrong user, or unusable proof => fail closed.
            return null;
        }

        return $proof;
    }

    private function orderBelongsToGuest(Order $order, GuestIdentity $guest, VerifiedPhoneIdentity $proof): bool
    {
        return (int) $order->guest_id === (int) $guest->id
            && $order->user_id === null
            && $this->phoneMatches($order, $proof);
    }

    private function phoneMatches(Order $order, VerifiedPhoneIdentity $proof): bool
    {
        $orderPhone = preg_replace('/\D+/', '', (string) $order->user_phone) ?? '';

        return $orderPhone !== '' && hash_equals($orderPhone, $proof->normalizedPhone());
    }

    private function sameUserReplay(?Order $order, GuestOrderClaimToken $token, User $user): array
    {
        if (
            $order
            && (int) $order->user_id === (int) $user->id
            && (int) $token->user_id === (int) $user->id
        ) {
            return [
                'status' => 'already_claimed',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ];
        }

        return ['status' => 'invalid_token'];
    }

    private function hasOtherPendingOrder(User $user, Order $order): bool
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('status', Order::ORDER_STATUS_PENDING)
            ->whereKeyNot($order->id)
            ->exists();
    }

    private function isDuplicateKeyError(\Illuminate\Database\QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];

        return ($errorInfo[1] ?? null) === 1062
            || $e->getCode() === '23000'
            || $e->getCode() === '23505'
            || (string) $e->getCode() === '1062';
    }

    private function hashToken(string $raw): string
    {
        return hash_hmac('sha256', $raw, (string) config('app.key'));
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
