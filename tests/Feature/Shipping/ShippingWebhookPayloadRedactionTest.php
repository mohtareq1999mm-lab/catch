<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipping\ShippingWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippingWebhookPayloadRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/general/shipping/webhooks/null';
    private const SECRET = 'whsec_test_159';

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
            'shipping.webhook_fingerprints.current_version' => 'v1',
            'shipping.webhook_fingerprints.keys' => ['v1' => 'fingerprint-secret-v1'],
        ]);
    }

    public function test_redacted_payload_masks_or_omits_pii_fields(): void
    {
        $payload = [
            'id' => 'evt_pii_159',
            'type' => 'shipment.status_changed',
            'status' => 'in_transit',
            'occurred_at' => '2026-11-22T10:00:00Z',
            'data' => [
                'shipment' => ['id' => 'carrier_pii_159'],
                'customer' => [
                    'email' => 'person@example.test',
                    'phone' => '+201001234567',
                    'address' => '12 Secret Street, Cairo',
                    'national_id' => '29801010101010',
                ],
            ],
            'customer_email' => 'person@example.test',
            'phone_number' => '+201001234567',
            'address_line1' => '12 Secret Street',
            'nationalId' => '29801010101010',
        ];

        $response = $this->postWebhook($payload);
        $response->assertStatus(200);

        $event = ShippingWebhookEvent::firstOrFail();
        $encoded = json_encode($event->redacted_payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('person@example.test', $encoded);
        $this->assertStringNotContainsString('+201001234567', $encoded);
        $this->assertStringNotContainsString('12 Secret Street', $encoded);
        $this->assertStringNotContainsString('29801010101010', $encoded);
        $this->assertArrayNotHasKey('customer_email', $event->redacted_payload);
        $this->assertArrayNotHasKey('phone_number', $event->redacted_payload);
        $this->assertArrayNotHasKey('address_line1', $event->redacted_payload);
        $this->assertArrayNotHasKey('nationalId', $event->redacted_payload);
    }

    public function test_raw_request_bytes_and_unredacted_payload_are_not_stored(): void
    {
        $payload = [
            'id' => 'evt_raw_159',
            'type' => 'shipment.status_changed',
            'status' => 'in_transit',
            'occurred_at' => '2026-11-22T10:00:00Z',
            'customer' => ['email' => 'secret@example.test', 'phone' => '555-RAW'],
            'internal_debug_blob' => str_repeat('A', 300),
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postWebhook($payload)->assertStatus(200);

        $event = ShippingWebhookEvent::firstOrFail();
        $stored = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret@example.test', $stored);
        $this->assertStringNotContainsString('555-RAW', $stored);
        $this->assertStringNotContainsString('internal_debug_blob', $stored);
        $this->assertStringNotContainsString(str_repeat('A', 50), $stored);
        $this->assertStringNotContainsString($raw, $stored);
    }

    public function test_stable_provider_event_id_is_primary_identity(): void
    {
        $payload = $this->payload(['id' => 'evt_provider_primary_159', 'status' => 'delivered']);
        $this->postWebhook($payload)->assertStatus(200);

        $event = ShippingWebhookEvent::firstOrFail();
        $this->assertSame('evt_provider_primary_159', $event->event_key);
        $this->assertStringNotContainsString('fp:', $event->event_key);
    }

    public function test_fallback_fingerprint_is_versioned_hmac_over_normalized_fields(): void
    {
        $payload = $this->payload();
        unset($payload['id']);

        $this->postWebhook($payload)->assertStatus(200);

        $event = ShippingWebhookEvent::firstOrFail();
        $this->assertStringStartsWith('fp:v1:', $event->event_key);
        $this->assertMatchesRegularExpression('/^fp:v1:[a-f0-9]{64}$/', $event->event_key);
    }

    public function test_fallback_fingerprint_is_independent_of_receipt_time_and_raw_bytes(): void
    {
        $base = $this->payload();
        unset($base['id']);

        $rawA = json_encode($base, JSON_THROW_ON_ERROR);
        $rawB = json_encode($base + ['ignored_debug' => 'x'], JSON_THROW_ON_ERROR);
        $this->assertNotSame($rawA, $rawB);

        $this->postWebhook($base)->assertStatus(200);
        $firstKey = ShippingWebhookEvent::firstOrFail()->event_key;

        $this->travel(5)->minutes();
        $payloadB = $base;
        $payloadB['ignored_debug'] = 'x';
        $payloadB['occurred_at'] = '2026-11-23T11:00:00Z';
        $this->postWebhook($payloadB)->assertStatus(200)->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame($firstKey, ShippingWebhookEvent::firstOrFail()->event_key);
    }

    public function test_fingerprint_is_stable_across_key_rotation(): void
    {
        $payload = $this->payload();
        unset($payload['id']);

        config(['shipping.webhook_fingerprints.current_version' => 'v1', 'shipping.webhook_fingerprints.keys' => ['v1' => 'secret-v1']]);
        $this->postWebhook($payload)->assertStatus(200)->assertJsonPath('data.status', 'accepted');
        $firstKey = ShippingWebhookEvent::firstOrFail()->event_key;
        $this->assertStringStartsWith('fp:v1:', $firstKey);

        config([
            'shipping.webhook_fingerprints.current_version' => 'v2',
            'shipping.webhook_fingerprints.keys' => ['v1' => 'secret-v1', 'v2' => 'secret-v2'],
        ]);
        $this->postWebhook($payload)->assertStatus(200)->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame($firstKey, ShippingWebhookEvent::firstOrFail()->event_key);
    }

    public function test_legacy_sha256_fallback_fingerprint_is_deduped_after_versioned_hmac_upgrade(): void
    {
        $payload = $this->payload([
            'type' => 'shipment.status_changed',
            'status' => 'in_transit',
            'data' => ['shipment' => ['id' => 'carrier_legacy_159']],
        ]);
        unset($payload['id']);

        $legacyKey = 'fp:'.hash('sha256', implode('|', [
            'null',
            'sandbox',
            'null-local',
            'carrier_legacy_159',
            'shipment.status_changed',
            'in_transit',
        ]));

        ShippingWebhookEvent::query()->create([
            'provider_code' => 'null',
            'mode' => 'sandbox',
            'account_code' => 'null-local',
            'webhook_version' => 'v1',
            'event_key' => $legacyKey,
            'event_type' => 'shipment.status_changed',
            'provider_shipment_ref' => 'carrier_legacy_159',
            'shipment_id' => null,
            'redacted_payload' => ['event_key' => $legacyKey],
            'raw_status' => 'received',
            'status' => 'received',
            'received_at' => now()->subDay(),
            'occurred_at' => now()->subDay(),
        ]);

        $this->postWebhook($payload)->assertStatus(200)->assertJsonPath('data.status', 'duplicate');

        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame($legacyKey, ShippingWebhookEvent::firstOrFail()->event_key);
    }

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'evt_' . Str::lower(Str::random(12)),
            'type' => 'shipment.status_changed',
            'status' => 'in_transit',
            'occurred_at' => '2026-11-22T10:00:00Z',
            'data' => ['shipment' => ['id' => 'carrier_' . Str::lower(Str::random(8))]],
        ], $overrides);
    }

    /** @param array<string,mixed> $payload */
    private function postWebhook(array $payload, string|false|null $signature = false, array $headers = [])
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $signature === false ? 'v1=' . hash_hmac('sha256', $raw, self::SECRET) : $signature;

        $requestHeaders = array_merge(['Content-Type' => 'application/json'], $headers);
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
