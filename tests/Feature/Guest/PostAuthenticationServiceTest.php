<?php

namespace Tests\Feature\Guest;

use App\Services\Customer\PostAuthenticationService;
use App\Services\Guest\GuestIdentityService;
use App\Models\Currency;
use App\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\SocialLoginCode;
use Marvel\Database\Models\User;

/**
 * One post-authentication module (round-2 ticket #53).
 *
 * A single module owns what must happen after a customer credential is
 * issued: mobile-customer eligibility (one decision, no per-controller
 * helpers), guest cart Login Merge (exactly once, non-fatal), and currency
 * adoption. Every authentication entry point calls the interface once.
 */
class PostAuthenticationServiceTest extends GuestCheckoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        parent::startGuestSession();

        // Currency adoption validates against active Currency rows only.
        Currency::create([
            'code' => 'KWD',
            'name' => ['en' => 'Kuwaiti Dinar', 'ar' => 'دينار كويتي'],
            'symbol' => ['en' => 'د.ك', 'ar' => 'د.ك'],
            'country_name' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
            'numeric_code' => '414',
            'decimal_places' => 3,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function service(): PostAuthenticationService
    {
        return app(PostAuthenticationService::class);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Post Auth User',
            'email' => 'post-auth-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'phone_number' => '+20' . substr(str_replace('.', '', uniqid('', true)), -10),
            'type' => 'user',
            'is_active' => true,
        ], $overrides));
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/social/exchange', 'POST');
        $request->cookies->set(
            GuestIdentityService::COOKIE,
            $this->guestCookies[GuestIdentityService::COOKIE]
        );
        $request->headers->set('X-Currency', 'KWD');

        return $request;
    }

    private function guestCart(): ?Cart
    {
        return Cart::query()->where('guest_id', $this->currentGuestId())->first();
    }

    private function accountCartFor(User $user): ?Cart
    {
        return Cart::query()->where('user_id', $user->id)->first();
    }

    public function test_eligibility_is_decided_in_one_place(): void
    {
        $customer = $this->makeUser();
        $inactive = $this->makeUser(['is_active' => false]);
        $admin = $this->makeUser(['type' => 'admin']);

        $this->assertTrue($this->service()->isEligibleCustomer($customer));
        $this->assertFalse($this->service()->isEligibleCustomer($inactive));
        $this->assertFalse($this->service()->isEligibleCustomer($admin));
        $this->assertFalse($this->service()->isEligibleCustomer(null));
    }

    public function test_after_authentication_merges_the_guest_cart_and_adopts_currency(): void
    {
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();

        $merge = $this->service()->afterAuthentication($user, $this->request());

        $this->assertSame('merged', $merge['status']);
        $this->assertNotNull($this->accountCartFor($user), 'The account cart must exist after the merge.');
        $this->assertNull($this->guestCart(), 'The guest cart is consumed by the merge.');
        $this->assertSame(
            'KWD',
            UserPreference::query()->where('user_id', $user->id)->value('currency_code'),
            'Currency adoption must run as part of post-authentication.'
        );
    }

    public function test_a_second_call_after_a_completed_merge_merges_nothing(): void
    {
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();

        $first = $this->service()->afterAuthentication($user, $this->request());
        $second = $this->service()->afterAuthentication($user, $this->request());

        $this->assertSame('merged', $first['status']);
        $this->assertNull($second, 'Login Merge must not run twice for the same guest cart.');
    }

    public function test_an_ineligible_customer_never_merges_and_never_adopts(): void
    {
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $admin = $this->makeUser(['type' => 'admin']);

        $merge = $this->service()->afterAuthentication($admin, $this->request());

        $this->assertNull($merge);
        $this->assertNotNull($this->guestCart(), 'The guest cart must stay intact.');
        $this->assertNull(
            UserPreference::query()->where('user_id', $admin->id)->value('currency_code'),
            'Currency adoption must not run for an ineligible customer.'
        );
    }

    public function test_social_exchange_merges_the_guest_cart_and_adopts_currency(): void
    {
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();
        $code = SocialLoginCode::create([
            'user_id' => $user->id,
            'code' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addMinutes(10),
            'used' => false,
        ]);

        $response = $this->call('POST', '/api/v1/social/exchange', ['code' => $code->code], [
            GuestIdentityService::COOKIE => $this->guestCookies[GuestIdentityService::COOKIE],
        ], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $data = $response->getData(true);
        $this->assertArrayHasKey('guest_cart_merge', $data, 'A successful exchange must report the Login Merge.');
        $this->assertSame('merged', $data['guest_cart_merge']['status']);
        $this->assertArrayHasKey('token', $data);

        $this->assertNull($this->guestCart(), 'The guest cart is consumed at exchange time.');
        $this->assertSame(
            'KWD',
            UserPreference::query()->where('user_id', $user->id)->value('currency_code'),
            'Currency adoption must run at the exchange authentication moment.'
        );
    }

    public function test_a_failed_exchange_never_merges(): void
    {
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $response = $this->call('POST', '/api/v1/social/exchange', ['code' => 'not-a-real-code'], [
            GuestIdentityService::COOKIE => $this->guestCookies[GuestIdentityService::COOKIE],
        ]);
        $response->assertStatus(400);

        $this->assertNotNull($this->guestCart(), 'Failed authentication must never merge.');
        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->count()
        );
    }
}
