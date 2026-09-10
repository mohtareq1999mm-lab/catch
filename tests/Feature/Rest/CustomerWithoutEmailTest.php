<?php

namespace Tests\Feature\Rest;

use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\User;
use Tests\TestCase;

class CustomerWithoutEmailTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_register_without_email()
    {
        $response = $this->postJson('/api/v1/register', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone_number' => '01234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'policy' => '1',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'phone_number' => '01234567890',
            'email' => null,
        ]);
    }

    /** @test */
    public function user_without_email_can_login_by_phone()
    {
        $user = User::factory()->withoutEmail()->create([
            'phone_number' => '01234567890',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/token', [
            'phone_number' => '01234567890',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['token']]);
    }

    /** @test */
    public function user_without_email_can_access_me_endpoint()
    {
        $user = User::factory()->withoutEmail()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertJsonFragment(['email' => null]);
    }

    /** @test */
    public function email_verification_middleware_does_not_block_null_email_users()
    {
        $user = User::factory()->withoutEmail()->create();

        // This middleware is currently not attached to any route
        // but we test the logic is correct
        $middleware = new \App\Http\Middleware\VirifiyEmailMiddleware();
        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $response = $middleware->handle($request, fn($req) => response('passed'));

        $this->assertEquals('passed', $response->getContent());
    }

    /** @test */
    public function checkout_validation_accepts_null_email()
    {
        $user = User::factory()->withoutEmail()->create();

        \Marvel\Database\Models\PickupLocation::create([
            'store_name' => 'Test Store',
            'address' => 'Test Address',
            'phone' => '01234567890',
        ]);

        // We test validation only - actual checkout requires cart setup
        $request = new \Marvel\Http\Requests\OrderCreateRequest();
        $request->setUserResolver(fn() => $user);
        $request->merge([
            'name' => 'Test User',
            'user_phone' => '01234567890',
            'user_email' => null,
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => 1,
        ]);

        $validator = \Illuminate\Support\Facades\Validator::make(
            $request->all(),
            $request->rules()
        );

        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function fast_checkout_validation_accepts_null_email()
    {
        $user = User::factory()->withoutEmail()->create();

        $country = \Marvel\Database\Models\Country::create(['name' => 'Egypt']);
        \Marvel\Database\Models\Governorate::create([
            'country_id' => $country->id,
            'name' => 'Cairo',
        ]);

        $request = new \Marvel\Http\Requests\FastCheckoutRequest();
        $request->setUserResolver(fn() => $user);
        $request->merge([
            'name' => 'Test User',
            'user_phone' => '01234567890',
            'user_email' => null,
            'address' => ['street' => 'Test St'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);

        $validator = \Illuminate\Support\Facades\Validator::make(
            $request->all(),
            $request->rules()
        );

        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function invoice_structure_validator_accepts_null_email()
    {
        $snapshot = [
            'snapshot_version' => '2.1.0',
            'snapshot_schema' => 3,
            'customer' => [
                'id' => 1,
                'name' => 'Test User',
                'email' => null,
                'phone' => '01234567890',
            ],
            'billing_address' => [],
            'shipping_address' => [],
            'fulfillment' => [
                'type' => 'delivery',
                'shipping_method' => 'standard',
                'shipping_price' => 10.0,
            ],
            'items' => [],
            'pricing_breakdown' => [
                'subtotal' => 100,
                'promotion_discount' => 0,
                'coupon_discount' => 0,
                'shipping_price' => 10,
                'total' => 110,
                'currency' => 'EGP',
            ],
            'payment' => [
                'method' => 'cod',
                'transaction_id' => null,
                'paid_at' => null,
            ],
            'taxes' => [],
            'metadata' => [
                'system_version' => '1.0.0',
                'locale' => 'en',
                'generated_at' => now()->toIso8601String(),
            ],
            'audit' => [
                'generated_by' => 'system',
                'generation_attempts' => 1,
            ],
        ];

        $validator = new \App\Services\Invoice\Validators\StructureValidator();

        // Should not throw exception
        $validator->validate($snapshot);
        $this->assertTrue(true);
    }

    /** @test */
    public function customer_contact_resolver_generates_fallback_for_null_email()
    {
        $order = new \Marvel\Database\Models\Order();
        $order->id = 123;
        $order->user_email = null;

        $resolver = new \App\Services\Payment\CustomerContactResolver();
        $email = $resolver->emailForGateway($order);

        $this->assertEquals('order-123@no-email.meem.local', $email);
    }

    /** @test */
    public function customer_contact_resolver_uses_real_email_when_available()
    {
        $order = new \Marvel\Database\Models\Order();
        $order->id = 123;
        $order->user_email = 'real@example.com';

        $resolver = new \App\Services\Payment\CustomerContactResolver();
        $email = $resolver->emailForGateway($order);

        $this->assertEquals('real@example.com', $email);
    }

    /** @test */
    public function database_allows_multiple_null_emails()
    {
        $user1 = User::factory()->withoutEmail()->create(['phone_number' => '01111111111']);
        $user2 = User::factory()->withoutEmail()->create(['phone_number' => '02222222222']);

        $this->assertNull($user1->email);
        $this->assertNull($user2->email);
        $this->assertNotEquals($user1->id, $user2->id);
    }

    /** @test */
    public function database_enforces_unique_constraint_on_non_null_emails()
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create(['email' => 'test@example.com']);
    }

    /** @test */
    public function user_registration_validation_requires_phone_number()
    {
        $response = $this->postJson('/api/v1/register', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'policy' => '1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['phone_number']);
    }

    /** @test */
    public function existing_email_users_remain_unaffected()
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertJsonFragment(['email' => 'existing@example.com']);
    }
}
