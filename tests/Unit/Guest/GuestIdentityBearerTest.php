<?php

namespace Tests\Unit\Guest;

use App\Models\GuestIdentity;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Mobile Credential core (T16 / S1).
 *
 * Service-level tests over the real migrated schema: dual transport
 * resolution, conflict rejection, rotation, revocation and hash-only
 * persistence. Raw tokens never need the HTTP cookie encryption layer here:
 * the service reads the request cookie bag directly.
 */
class GuestIdentityBearerTest extends TestCase
{
    use DatabaseTransactions;

    private GuestIdentityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GuestIdentityService::class);
    }

    private function cookieRequest(string $rawToken): Request
    {
        $request = Request::create('/api/v1/general/guest/cart', 'GET');
        $request->cookies->set(GuestIdentityService::cookieName(), $rawToken);

        return $request;
    }

    private function bearerRequest(string $token): Request
    {
        $request = Request::create('/api/v1/general/guest/cart', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    private function newCookieIdentity(): array
    {
        return $this->service->create(Request::create('/api/v1/general/guest/session', 'POST'));
    }

    private function newMobileIdentity(): array
    {
        return $this->service->createForMobile(Request::create('/api/v1/general/guest/mobile/session', 'POST'));
    }

    public function test_cookie_only_resolution_succeeds(): void
    {
        [$identity, $raw] = $this->newCookieIdentity();

        $resolved = $this->service->current($this->cookieRequest($raw));

        $this->assertNotNull($resolved);
        $this->assertSame($identity->id, $resolved->id);
        $this->assertSame(GuestIdentityService::MODE_COOKIE, $this->service->authMode($this->cookieRequest($raw)));
    }

    public function test_bearer_only_resolution_succeeds(): void
    {
        [$identity, $token] = $this->newMobileIdentity();

        $request = $this->bearerRequest($token);
        $resolved = $this->service->current($request);

        $this->assertNotNull($resolved);
        $this->assertSame($identity->id, $resolved->id);
        $this->assertSame(GuestIdentityService::MODE_BEARER, $this->service->authMode($request));
    }

    public function test_both_credentials_same_identity_resolve(): void
    {
        [$identity, $raw] = $this->newCookieIdentity();

        // Attach a Mobile Credential to the SAME identity.
        $token = $this->service->rotateMobileToken(
            Request::create('/api/v1/general/guest/mobile/session/rotate', 'POST'),
            $identity
        );

        $request = $this->cookieRequest($raw);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $resolved = $this->service->current($request);

        $this->assertNotNull($resolved);
        $this->assertSame($identity->id, $resolved->id);
    }

    public function test_both_credentials_conflicting_identities_are_rejected(): void
    {
        [, $raw] = $this->newCookieIdentity();
        [, $token] = $this->newMobileIdentity();

        $request = $this->cookieRequest($raw);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        try {
            $this->service->current($request);
            $this->fail('Expected a credential conflict rejection.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('guest_checkout.credential_conflict', $e->getMessage());
        }
    }

    public function test_missing_credentials_resolve_to_null(): void
    {
        $this->assertNull($this->service->current(Request::create('/api/v1/general/guest/cart', 'GET')));
    }

    public function test_malformed_tokens_resolve_to_null(): void
    {
        foreach (['short', 'id|sanctum-shaped-token-hash', str_repeat('a', 200)] as $token) {
            $this->assertNull(
                $this->service->current($this->bearerRequest($token)),
                'Token shape should be rejected: ' . $token
            );
        }
    }

    public function test_expired_token_resolves_to_null(): void
    {
        [$identity, $token] = $this->newMobileIdentity();

        $identity->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertNull($this->service->current($this->bearerRequest($token)));
    }

    public function test_revoked_token_resolves_to_null(): void
    {
        [$identity, $token] = $this->newMobileIdentity();

        $this->service->revokeMobile($identity);

        $this->assertNull($this->service->current($this->bearerRequest($token)));
    }

    public function test_rotation_invalidates_the_previous_token(): void
    {
        [$identity, $oldToken] = $this->newMobileIdentity();

        $newToken = $this->service->rotateMobileToken(
            Request::create('/api/v1/general/guest/mobile/session/rotate', 'POST'),
            $identity
        );

        $this->assertNotSame($oldToken, $newToken);
        $this->assertNull($this->service->current($this->bearerRequest($oldToken)));

        $resolved = $this->service->current($this->bearerRequest($newToken));
        $this->assertNotNull($resolved);
        $this->assertSame($identity->id, $resolved->id);
    }

    public function test_only_the_hash_is_persisted(): void
    {
        [$identity, $token] = $this->newMobileIdentity();

        $row = GuestIdentity::query()->findOrFail($identity->id);
        $expected = $this->service->hashMobileToken($token);

        $this->assertSame($expected, $row->mobile_token_hash);
        $this->assertNotSame($token, $row->mobile_token_hash);
        $this->assertNotSame($token, $row->token_hash);

        // The raw token must not appear in any stored string column value.
        foreach ([$row->token_hash, $row->mobile_token_hash] as $stored) {
            $this->assertStringNotContainsString($token, (string) $stored);
        }
    }
}
