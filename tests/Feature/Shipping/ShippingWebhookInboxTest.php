<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\ShippingWebhookEvent;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * #143/#169: shipping webhook inbox boundary and HTTP contract.
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
            // Tests run with a direct REMOTE_ADDR; trust the loopback as the
            // configured proxy boundary so the limiter can derive source IP.
            'shipping.webhook_rate_limit.trusted_proxies' => ['127.0.0.1'],
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

        $response->assertStatus(202)
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

        $first->assertStatus(202)->assertJsonPath('data.status', 'accepted');
        $second->assertStatus(200)->assertJsonPath('data.status', 'duplicate');
        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame(
            'evt_duplicate_143',
            ShippingWebhookEvent::firstOrFail()->event_key,
        );
    }

    public function test_malformed_payload_returns_422_after_signature_verification(): void
    {
        $raw = '{"id":"evt_bad_payload","type":';
        $signature = 'v1='.hash_hmac('sha256', $raw, self::SECRET);

        $response = $this->call(
            'POST',
            self::URI,
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type' => 'application/json',
                'X-Shipping-Signature' => $signature,
            ]),
            $raw,
        );

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, ShippingWebhookEvent::count());
    }

    public function test_same_event_id_in_different_mode_and_account_is_not_a_duplicate(): void
    {
        $payload = $this->payload(['id' => 'evt_scoped_identity_143']);

        $sandbox = $this->postWebhook($payload);
        $sandbox->assertStatus(202)->assertJsonPath('data.status', 'accepted');

        config([
            // Only one mode may be active; the old sandbox block must be
            // disabled before the production identity can be used (#192).
            'shipping.providers.null.modes.sandbox.enabled' => false,
            'shipping.providers.null.mode' => 'production',
            'shipping.providers.null.modes.sandbox.enabled' => false,
            'shipping.providers.null.account_code' => 'null-production',
            'shipping.providers.null.modes.production.enabled' => true,
            'shipping.providers.null.modes.production.base_url' => 'local://webhook-test',
            'shipping.providers.null.modes.production.credentials' => [
                'api_key' => 'test-key',
                'api_secret' => 'test-secret',
            ],
            'shipping.providers.null.modes.production.webhook.secret' => self::SECRET,
            'shipping.providers.null.modes.production.webhook.version' => 'v1',
        ]);

        $production = $this->postWebhook($payload);
        $production->assertStatus(202)->assertJsonPath('data.status', 'accepted');

        $rows = ShippingWebhookEvent::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['evt_scoped_identity_143', 'evt_scoped_identity_143'], $rows->pluck('event_key')->all());
        $this->assertSame(['sandbox', 'production'], $rows->pluck('mode')->all());
        $this->assertSame(['null-local', 'null-production'], $rows->pluck('account_code')->all());
    }

    public function test_sandbox_webhook_is_not_linked_to_a_production_shipment(): void
    {
        $shipment = $this->createShipment('production');
        $payload = $this->payload([
            'id' => 'evt_mode_isolation_192',
            'metadata' => ['shipment_uuid' => $shipment->uuid],
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        $event = ShippingWebhookEvent::firstOrFail();
        $this->assertSame('sandbox', $event->mode);
        $this->assertNull($event->shipment_id);
        $this->assertSame('production', $shipment->fresh()->provider_mode);
        $this->assertSame('pending', $shipment->fresh()->status);
    }

    public function test_production_webhook_is_not_linked_to_a_sandbox_shipment(): void
    {
        $shipment = $this->createShipment('sandbox');
        $payload = $this->payload([
            'id' => 'evt_mode_isolation_reverse_192',
            'metadata' => ['shipment_uuid' => $shipment->uuid],
        ]);

        config([
            // Only one mode may be active; replace the default sandbox block
            // before the production identity is used (#192).
            'shipping.providers.null.modes.sandbox.enabled' => false,
            'shipping.providers.null.mode' => 'production',
            'shipping.providers.null.account_code' => 'null-production',
            'shipping.providers.null.modes.production.enabled' => true,
            'shipping.providers.null.modes.production.webhook.secret' => self::SECRET,
            'shipping.providers.null.modes.production.webhook.version' => 'v1',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        $event = ShippingWebhookEvent::firstOrFail();
        $this->assertSame('production', $event->mode);
        $this->assertNull($event->shipment_id);
        $this->assertSame('sandbox', $shipment->fresh()->provider_mode);
        $this->assertSame('pending', $shipment->fresh()->status);
    }

    public function test_mixed_mode_configuration_fails_closed_alerts_and_does_not_retry(): void
    {
        config([
            'shipping.providers.null.mode' => 'production',
            'shipping.providers.null.modes.production.enabled' => true,
            'shipping.providers.null.modes.production.webhook.secret' => self::SECRET,
            'shipping.providers.null.modes.production.webhook.version' => 'v1',
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $logger);
        Queue::fake();

        $response = $this->postWebhook($this->payload(['id' => 'evt_mixed_modes_192']));

        $response->assertStatus(503)->assertJson(['success' => false]);
        $response->assertHeaderMissing('Retry-After');
        $this->assertSame(0, ShippingWebhookEvent::count());
        Queue::assertNothingPushed();
        $logger->shouldHaveReceived('error', fn (mixed $message): bool => str_contains(
            (string) $message,
            'mixed sandbox/production configuration',
        ));
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

        $first->assertStatus(202)->assertJsonPath('data.status', 'accepted');
        $second->assertStatus(429)->assertJson(['success' => false]);
        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame('evt_limit_first', ShippingWebhookEvent::firstOrFail()->event_key);
    }

    public function test_unconfigured_trusted_proxies_fail_closed_before_verification_or_inbox(): void
    {
        config(['shipping.webhook_rate_limit.trusted_proxies' => []]);

        $response = $this->postWebhook($this->payload(), 'v1='.str_repeat('0', 64), [
            'X-Forwarded-For' => '203.0.113.10',
        ]);

        $response->assertStatus(503)->assertJson(['success' => false]);
        $this->assertSame(0, ShippingWebhookEvent::count());
    }

    public function test_source_ip_is_derived_only_through_configured_trusted_proxies(): void
    {
        config([
            'shipping.webhook_rate_limit.max_attempts' => 1,
            'shipping.webhook_rate_limit.trusted_proxies' => ['127.0.0.1'],
        ]);

        // Request arrives through a configured trusted proxy; the client IP is
        // derived from the forwarded chain, not from an untrusted peer.
        $first = $this->postWebhook($this->payload(['id' => 'evt_proxy_first']), false, [
            'X-Forwarded-For' => '198.51.100.10',
        ]);
        $second = $this->postWebhook($this->payload(['id' => 'evt_proxy_second']), false, [
            'X-Forwarded-For' => '198.51.100.10',
        ]);
        $differentClient = $this->postWebhook($this->payload(['id' => 'evt_proxy_third']), false, [
            'X-Forwarded-For' => '198.51.100.20',
        ]);

        $first->assertStatus(202)->assertJsonPath('data.status', 'accepted');
        $second->assertStatus(429)->assertJson(['success' => false]);
        $differentClient->assertStatus(202)->assertJsonPath('data.status', 'accepted');
        $this->assertSame(2, ShippingWebhookEvent::count());

        // Direct request from an untrusted remote peer must fail closed even if
        // it supplies forwarded headers.
        config(['shipping.webhook_rate_limit.trusted_proxies' => ['10.0.0.1']]);
        $untrusted = $this->postWebhook($this->payload(['id' => 'evt_proxy_untrusted']), false, [
            'X-Forwarded-For' => '198.51.100.30',
        ]);
        $untrusted->assertStatus(503)->assertJson(['success' => false]);
        $this->assertSame(2, ShippingWebhookEvent::count());
    }

    public function test_signature_verification_still_precedes_processing_when_limiter_allows(): void
    {
        $response = $this->postWebhook($this->payload(['id' => 'evt_sig_order']), 'v1='.str_repeat('0', 64));

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertSame(0, ShippingWebhookEvent::count());
    }

    private function createShipment(string $mode = 'sandbox'): Shipment
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
            'provider_code' => 'null',
            'provider_mode' => $mode,
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
