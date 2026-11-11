<?php

namespace Tests\Feature\Guest;

use App\Contracts\GuestPhoneVerificationContract;
use App\Models\GuestIdentity;
use App\Models\GuestOrderClaimToken;
use App\Services\Guest\GuestIdentityService;
use App\Services\Guest\GuestOrderClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

class GuestOrderClaimTest extends GuestCheckoutTestCase
{
    private function makeUser(string $verifiedPhone, string $suffix = ''): User
    {
        return User::create([
            'name' => 'Claim User ' . $suffix,
            'email' => 'claim-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            // users.phone_number is UNIQUE; the verified phone is carried by
            // the proof, not by this column.
            'phone_number' => '+20' . substr(str_replace('.', '', uniqid('', true)), -10),
            'type' => 'user',
            'is_active' => true,
        ]);
    }

    private function bindVerifier(User $user, string $phone, string $reference = 'otp-ref-1'): void
    {
        $this->app->instance(
            GuestPhoneVerificationContract::class,
            new FakeGuestPhoneVerification((int) $user->id, $phone, $reference)
        );
    }

    private function service(): GuestOrderClaimService
    {
        return app(GuestOrderClaimService::class);
    }

    private function claimRequest(?string $rawGuestToken = null): Request
    {
        $request = Request::create('/api/v1/general/guest/orders/track', 'POST');
        $request->cookies->set(
            GuestIdentityService::COOKIE,
            $rawGuestToken ?? $this->guestCookies[GuestIdentityService::COOKIE]
        );

        return $request;
    }

    private function guestOrderContext(): array
    {
        $data = $this->createGuestOrder('cod');
        $guest = GuestIdentity::query()->findOrFail($this->currentGuestId());

        return [$data['order'], $guest];
    }

    /** @test */
    public function verified_phone_with_a_claim_token_claims_the_guest_order(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);
        $this->bindVerifier($user, $order->user_phone);

        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'otp-ref-1', $order->user_phone);
        $this->assertSame('issued', $issued['status']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued['claim_token']);

        // Hash-only persistence.
        $tokenRow = GuestOrderClaimToken::query()->firstOrFail();
        $this->assertNotSame($issued['claim_token'], $tokenRow->token_hash);
        $this->assertDatabaseMissing('guest_order_claim_tokens', ['token_hash' => $issued['claim_token']]);

        $result = $this->service()->claim($user, $this->claimRequest(), $issued['claim_token'], 'otp-ref-1', $order->user_phone);

        $this->assertSame('claimed', $result['status']);
        $this->assertSame((int) $user->id, (int) $order->fresh()->user_id);
        $this->assertNull($order->fresh()->guest_cancellation_token_hash);
        $this->assertNotNull($tokenRow->fresh()->used_at);
    }

    /** @test */
    public function phone_equality_alone_cannot_claim_without_verified_proof(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);

        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'forged-ref', $order->user_phone);
        $this->assertSame('verification_required', $issued['status']);
        $this->assertSame(0, GuestOrderClaimToken::query()->count());

        $claim = $this->service()->claim($user, $this->claimRequest(), str_repeat('a', 43), 'forged-ref', $order->user_phone);
        $this->assertSame('verification_required', $claim['status']);
        $this->assertNull($order->fresh()->user_id);
    }

    /** @test */
    public function missing_otp_verification_cannot_activate_claiming_even_with_a_real_verifier(): void
    {
        config(['guest_checkout.order_claim.enabled' => false]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);
        $this->bindVerifier($user, $order->user_phone);

        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'otp-ref-1', $order->user_phone);

        $this->assertSame('verification_required', $issued['status']);
        $this->assertNull($order->fresh()->user_id);
    }

    /** @test */
    public function invalid_and_reused_claim_tokens_are_rejected(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);
        $this->bindVerifier($user, $order->user_phone);

        $this->assertSame(
            'invalid_token',
            $this->service()->claim($user, $this->claimRequest(), 'short', 'otp-ref-1', $order->user_phone)['status']
        );

        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'otp-ref-1', $order->user_phone);
        $this->assertSame('issued', $issued['status']);

        // Another user cannot reuse the token.
        $other = $this->makeUser($order->user_phone, 'other');
        $this->bindVerifier($other, $order->user_phone);

        $this->assertSame(
            'invalid_token',
            $this->service()->claim(
                $other,
                $this->claimRequest(),
                $issued['claim_token'],
                'otp-ref-1',
                $order->user_phone
            )['status']
        );
        $this->assertNull($order->fresh()->user_id);
    }

    /** @test */
    public function another_guest_identity_cannot_claim_the_order(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);
        $this->bindVerifier($user, $order->user_phone);
        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'otp-ref-1', $order->user_phone);

        $otherRaw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $otherGuest = GuestIdentity::create([
            'token_hash' => app(GuestIdentityService::class)->hashToken($otherRaw),
            'expires_at' => now()->addDay(),
        ]);

        $result = $this->service()->claim(
            $user,
            $this->claimRequest($otherRaw),
            $issued['claim_token'],
            'otp-ref-1',
            $order->user_phone
        );

        $this->assertSame('invalid_token', $result['status']);
        $this->assertNull($order->fresh()->user_id);
    }

    /** @test */
    public function an_order_already_assigned_to_another_user_cannot_be_reassigned(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $owner = $this->makeUser($order->user_phone, 'owner');
        $order->forceFill(['user_id' => $owner->id])->save();

        $claimant = $this->makeUser($order->user_phone, 'claimant');
        $this->bindVerifier($claimant, $order->user_phone);

        $issued = $this->service()->issueToken(
            $claimant,
            $this->claimRequest(),
            $order->fresh(),
            'otp-ref-1',
            $order->user_phone
        );
        $this->assertSame('not_eligible', $issued['status']);
        $this->assertSame((int) $owner->id, (int) $order->fresh()->user_id);
    }

    /** @test */
    public function repeated_claim_with_the_same_token_is_idempotent_and_audited_once(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);
        $this->bindVerifier($user, $order->user_phone);
        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'otp-ref-1', $order->user_phone);

        $first = $this->service()->claim($user, $this->claimRequest(), $issued['claim_token'], 'otp-ref-1', $order->user_phone);
        $second = $this->service()->claim($user, $this->claimRequest(), $issued['claim_token'], 'otp-ref-1', $order->user_phone);

        $this->assertSame('claimed', $first['status']);
        $this->assertSame('already_claimed', $second['status']);
        $this->assertSame((int) $user->id, (int) $order->fresh()->user_id);

        $this->assertSame(1, DB::table('activity_log')
            ->where('event', 'guest_order_claimed')
            ->where('subject_id', $order->id)
            ->count());
    }

    /** @test */
    public function successful_claim_records_an_audit_event_without_raw_credentials(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);
        $this->bindVerifier($user, $order->user_phone);
        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'otp-ref-1', $order->user_phone);
        $this->service()->claim($user, $this->claimRequest(), $issued['claim_token'], 'otp-ref-1', $order->user_phone);

        $activity = DB::table('activity_log')
            ->where('event', 'guest_order_claimed')
            ->where('subject_id', $order->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame((int) $user->id, (int) $activity->causer_id);
        $this->assertStringNotContainsString($issued['claim_token'], (string) $activity->properties);
    }

    /** @test */
    public function phone_mismatch_is_not_claimable(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser('+201999999999');
        $this->bindVerifier($user, '+201999999999');

        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'otp-ref-1', '+201999999999');
        $this->assertSame('not_eligible', $issued['status']);
        $this->assertSame(0, GuestOrderClaimToken::query()->count());
    }

    /** @test */
    public function claiming_a_pending_order_when_the_user_already_has_one_returns_a_safe_conflict(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);
        $this->bindVerifier($user, $order->user_phone);

        // User already owns a different pending order (unique pending guard).
        Order::create([
            'guest_id' => null,
            'user_id' => $user->id,
            'name' => 'Existing Pending',
            'user_phone' => $order->user_phone,
            'user_email' => null,
            'address' => [],
            'total_price' => 10,
            'price' => 10,
            'status' => Order::ORDER_STATUS_PENDING,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'shipping_method' => 'SCHEDULED',
        ]);

        $issued = $this->service()->issueToken($user, $this->claimRequest(), $order, 'otp-ref-1', $order->user_phone);
        $this->assertSame('issued', $issued['status']);

        $result = $this->service()->claim($user, $this->claimRequest(), $issued['claim_token'], 'otp-ref-1', $order->user_phone);

        $this->assertSame('pending_order_conflict', $result['status']);
        $this->assertNull($order->fresh()->user_id);
        $this->assertNull(GuestOrderClaimToken::query()->firstOrFail()->used_at);
    }

    /** @test */
    public function eligible_orders_are_scoped_to_the_guest_identity_and_verified_phone(): void
    {
        config(['guest_checkout.order_claim.enabled' => true]);

        [$order, $guest] = $this->guestOrderContext();
        $user = $this->makeUser($order->user_phone);
        $this->bindVerifier($user, $order->user_phone);

        // Another order for the same guest with a different phone must not leak.
        Order::create([
            'guest_id' => $guest->id,
            'user_id' => null,
            'name' => 'Different Phone',
            'user_phone' => '+201888888888',
            'user_email' => null,
            'address' => [],
            'total_price' => 10,
            'price' => 10,
            'status' => Order::ORDER_STATUS_PENDING,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'shipping_method' => 'SCHEDULED',
        ]);

        $result = $this->service()->eligibleOrders($user, $this->claimRequest(), 'otp-ref-1', $order->user_phone);

        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $result['orders']);
        $this->assertSame($order->order_number, $result['orders'][0]['order_number']);
    }
}
