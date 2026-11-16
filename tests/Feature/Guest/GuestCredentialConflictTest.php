<?php

namespace Tests\Feature\Guest;

use App\Models\GuestIdentity;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Http\Request;

/**
 * Credential Conflict + cross-guest isolation (T20 / A10 / Q6).
 */
class GuestCredentialConflictTest extends GuestCheckoutTestCase
{
    private function mobileSessionToken(): string
    {
        $response = $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => '',
            GuestIdentityService::CSRF_COOKIE => '',
        ])->withHeaders(['Authorization' => ''])
            ->postJson(self::PREFIX . '/mobile/session');

        $response->assertCreated();

        return (string) $response->json('data.token');
    }

    public function test_conflicting_cookie_and_bearer_identities_are_rejected(): void
    {
        // Browser identity A.
        $this->startGuestSession();
        $this->assertSame(1, GuestIdentity::query()->count());

        // Mobile identity B.
        $tokenB = $this->mobileSessionToken();
        $this->assertSame(2, GuestIdentity::query()->count());

        // Present A's cookie together with B's Bearer token.
        $response = $this->asGuest()
            ->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
            ->getJson(self::PREFIX . '/cart');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'credential_conflict');
    }

    public function test_same_identity_via_both_transports_is_accepted(): void
    {
        $this->startGuestSession();

        $identity = GuestIdentity::query()->findOrFail($this->currentGuestId());
        $token = app(GuestIdentityService::class)->rotateMobileToken(
            Request::create('/api/v1/general/guest/mobile/session/rotate', 'POST'),
            $identity
        );

        $this->asGuest()
            ->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_guest_carts_are_isolated_between_guests(): void
    {
        // Guest A (browser) owns a cart with one line.
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();
        $this->assertSame(1, $this->guestItemCount());

        // Guest B (mobile) must not see or mutate A's cart.
        $tokenB = $this->mobileSessionToken();

        $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => '',
            GuestIdentityService::CSRF_COOKIE => '',
        ])->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
            ->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart', null);
    }
}
