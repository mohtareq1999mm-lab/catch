<?php

namespace Tests\Feature\Guest;

use App\Models\GuestIdentity;
use App\Services\Guest\GuestIdentityService;

/**
 * Mobile Guest Session HTTP surface (T18 / S5 / A3 / A4).
 */
class GuestMobileSessionTest extends GuestCheckoutTestCase
{
    /** Start from a clean client: no browser cookies, no stale Authorization. */
    private function clearCredentials(): static
    {
        return $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => '',
            GuestIdentityService::CSRF_COOKIE => '',
        ])->withHeaders(['Authorization' => '']);
    }

    private function asBearer(string $token): static
    {
        return $this->clearCredentials()->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function createMobileSession(): string
    {
        $response = $this->clearCredentials()->postJson(self::PREFIX . '/mobile/session');

        $response->assertCreated();
        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        return $token;
    }

    public function test_creation_returns_token_once_without_cookies(): void
    {
        $response = $this->clearCredentials()->postJson(self::PREFIX . '/mobile/session');

        $response->assertCreated()
            ->assertHeader('cache-control', 'no-store')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.cart', null);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.guest.expires_at'));
        $this->assertCount(0, $response->headers->getCookies(), 'No cookies may be issued to native clients.');
    }

    public function test_bearer_credential_authenticates_cart_endpoints(): void
    {
        $token = $this->createMobileSession();

        $this->asBearer($token)->postJson(self::PREFIX . '/cart/items', [
            'item' => [
                'product_id' => $this->product->id,
                'quantity' => 1,
                'shipping_method' => 'scheduled',
            ],
        ])->assertCreated();

        $this->assertSame(1, $this->guestItemCount());

        $this->asBearer($token)->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart.items.0.product_id', $this->product->id);
    }

    public function test_store_with_usable_bearer_credential_returns_409(): void
    {
        $token = $this->createMobileSession();

        $this->asBearer($token)->postJson(self::PREFIX . '/mobile/session')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'mobile_session_exists');

        // Never a second identity.
        $this->assertSame(1, GuestIdentity::query()->count());
    }

    public function test_store_with_usable_cookie_credential_returns_409(): void
    {
        $this->startGuestSession();

        $this->asGuest()->postJson(self::PREFIX . '/mobile/session')
            ->assertStatus(409)
            ->assertJsonPath('code', 'mobile_session_exists');
    }

    public function test_rotate_and_destroy_require_a_bearer_credential(): void
    {
        $this->clearCredentials()->postJson(self::PREFIX . '/mobile/session/rotate')
            ->assertStatus(401);

        $this->clearCredentials()->deleteJson(self::PREFIX . '/mobile/session')
            ->assertStatus(401);
    }

    public function test_rotation_invalidates_the_old_token_immediately(): void
    {
        $old = $this->createMobileSession();

        $this->asBearer($old)->postJson(self::PREFIX . '/cart/items', [
            'item' => [
                'product_id' => $this->product->id,
                'quantity' => 1,
                'shipping_method' => 'scheduled',
            ],
        ])->assertCreated();

        $rotate = $this->asBearer($old)->postJson(self::PREFIX . '/mobile/session/rotate');

        $rotate->assertOk()->assertHeader('cache-control', 'no-store');
        $new = $rotate->json('data.token');

        $this->assertIsString($new);
        $this->assertNotSame($old, $new);

        // Old token: no longer resolves to the identity (cart invisible).
        $this->asBearer($old)->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart', null);

        // New token: same identity, same cart.
        $this->asBearer($new)->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart.items.0.product_id', $this->product->id);
    }

    public function test_revocation_kills_the_credential_and_clears_the_hash(): void
    {
        $token = $this->createMobileSession();
        $guestId = $this->currentGuestId();

        $this->asBearer($token)->deleteJson(self::PREFIX . '/mobile/session')
            ->assertOk()
            ->assertHeader('cache-control', 'no-store');

        $row = GuestIdentity::query()->findOrFail($guestId);
        $this->assertNull($row->mobile_token_hash);
        $this->assertNotNull($row->revoked_at);

        $this->asBearer($token)->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart', null);
    }

    public function test_sanctum_shaped_token_is_not_accepted_as_a_guest_credential(): void
    {
        $response = $this->clearCredentials()
            ->withHeaders(['Authorization' => 'Bearer 1|' . str_repeat('a', 60)])
            ->postJson(self::PREFIX . '/mobile/session');

        // Ignored as a guest credential -> a brand-new identity is created.
        $response->assertCreated();

        $issued = (string) $response->json('data.token');
        $this->assertNotSame('', $issued);
        $this->assertStringNotContainsString('1|', $issued);
    }
}
