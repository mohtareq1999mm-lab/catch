<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\ShippingWebhookEvent;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Mockery;
use Tests\TestCase;

/**
 * #143: shipping webhook inbox boundary.
 *
 * The HTTP route is the seam. Every case here proves that an unauthenticated
 * delivery cannot change durable state, and that an authenticated delivery is
 * committed before the provider is acknowledged. No shipment/order/payment
 * lifecycle service is called by this endpoint.
 */
class ShippingWebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/general/shipping/webhooks/null';

    private const SECRET = 'whsec_test_143';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.providers.null.mode' => 'sandbox',
            'shipping.providers.null.modes.sandbox.enabled' => true,
            'shipping.providers.null.modes.sandbox.webhook.secret' => self::SECRET,
            'shipping.providers.null.modes.sandbox.webhook.version' => 'v1',
            'shipping.webhook_rate_limit.max_attempts' => 60,
            'shipping.webhook_rate_limit.decay_seconds' => 60,
        ]);
    }

    public function test_invalid_signature_is_rejected_before_any_state_change(): void
    {
        $response = $this->postWebhook($this->payload(), 'v1='.str_repeat('0', 64));

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertSame(0, ShippingWebhookEvent::count());
        $this->assertSame(0, Shipment::count());
    }

    public function test_missing_signature_is_rejected_before_any_state_change(): void
    {
        $response = $this->postWebhook($this->payload(), null);

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertSame(0, ShippingWebhookEvent::count());
        $this->assertSame(0, Shipment::count());
    }

    public function test_verified_event_is_durably_persisted_before_acknowledgement(): void
    {
        $shipment = $this->createShipment();
        $payload = $this->payload([
            'id' => 'evt_acceptance_143',
            'metadata' => ['shipment_uuid' => $shipment->uuid],
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['status' => 'accepted']]);

        $event = ShippingWebhookEvent::firstOrFail();
        $this->assertSame('null', $event->provider_code);
        $this->assertSame('sandbox', $event->mode);
        $this->assertSame('null-local', $event->account_code);
        $this->assertSame('evt_acceptance_143', $event->event_key);
        $this->assertSame('shipment.status_changed', $event->event_type);
        $this->assertSame($shipment->id, $event->shipment_id);
        $this->assertSame('received', $event->status);
        $this->assertNotNull($event->received_at);
        $this->assertNotNull($event->occurred_at);

        // Acceptance is inbox-only: no lifecycle state is advanced.
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame('pending', Order::findOrFail($shipment->order_id)->status);

        $redacted = json_encode($event->redacted_payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::SECRET, $redacted);
        $this->assertArrayNotHasKey('signature', $event->redacted_payload);
        $this->assertArrayNotHasKey('raw_payload', $event->redacted_payload);
    }

    public function test_duplicate_event_is_acknowledged_once_and_not_processed_twice(): void
    {
        $payload = $this->payload(['id' => 'evt_duplicate_143']);

        $first = $this->postWebhook($payload);
        $second = $this->postWebhook($payload);

        $first->assertStatus(200)->assertJsonPath('data.status', 'accepted');
        $second->assertStatus(200)->assertJsonPath('data.status', 'duplicate');
        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame(
            'evt_duplicate_143',
            ShippingWebhookEvent::firstOrFail()->event_key,
        );
    }

    public function test_same_event_id_in_different_mode_and_account_is_not_a_duplicate(): void
    {
        $payload = $this->payload(['id' => 'evt_scoped_identity_143']);

        $sandbox = $this->postWebhook($payload);
        $sandbox->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        config([
            'shipping.providers.null.mode' => 'production',
            'shipping.providers.null.account_code' => 'null-production',
            'shipping.providers.null.modes.production.enabled' => true,
            'shipping.providers.null.modes.production.webhook.secret' => self::SECRET,
            'shipping.providers.null.modes.production.webhook.version' => 'v1',
        ]);

        $production = $this->postWebhook($payload);
        $production->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        $rows = ShippingWebhookEvent::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['evt_scoped_identity_143', 'evt_scoped_identity_143'], $rows->pluck('event_key')->all());
        $this->assertSame(['sandbox', 'production'], $rows->pluck('mode')->all());
        $this->assertSame(['null-local', 'null-production'], $rows->pluck('account_code')->all());
    }

    public function test_limiter_backend_failure_fails_closed_with_503_before_verification(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('tooManyAttempts')->andThrow(new \RuntimeException('limiter backend unavailable'));
        $this->app->instance(RateLimiter::class, $limiter);

        $response = $this->postWebhook($this->payload(), 'v1='.str_repeat('0', 64));

        $response->assertStatus(503)->assertJson(['success' => false]);
        $this->assertSame(0, ShippingWebhookEvent::count());
    }

    public function test_actual_rate_limit_returns_429_and_does_not_accept_overflow_event(): void
    {
        config(['shipping.webhook_rate_limit.max_attempts' => 1]);

        $first = $this->postWebhook($this->payload(['id' => 'evt_limit_first']));
        $second = $this->postWebhook($this->payload(['id' => 'evt_limit_second']));

        $first->assertStatus(200)->assertJsonPath('data.status', 'accepted');
        $second->assertStatus(429)->assertJson(['success' => false]);
        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame('evt_limit_first', ShippingWebhookEvent::firstOrFail()->event_key);
    }

    private function createShipment(): Shipment
    {
        $user = User::create([
            'name' => 'Webhook Test User',
            'email' => 'webhook-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Webhook Test Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);

        return Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'id' => 'evt_'.Str::lower(Str::random(12)),
            'type' => 'shipment.status_changed',
            'status' => 'in_transit',
            'occurred_at' => '2026-11-22T10:00:00Z',
            'data' => [
                'shipment' => ['id' => 'carrier_'.Str::lower(Str::random(8))],
            ],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $payload
     * @param string|false|null $signature FALSE = compute a valid signature,
     *        NULL = omit the header, string = use that exact value.
     */
    private function postWebhook(array $payload, string|false|null $signature = false, array $headers = [])
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $signature === false ? 'v1='.hash_hmac('sha256', $raw, self::SECRET) : $signature;

        $requestHeaders = array_merge([
            'Content-Type' => 'application/json',
        ], $headers);

        if ($signature !== null) {
            $requestHeaders['X-Shipping-Signature'] = $signature;
        }

        return $this->call(
            'POST',
            self::URI,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($requestHeaders),
            $raw,
        );
    }
}
