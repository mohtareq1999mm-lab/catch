<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\ShippingWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class ShippingInternalEventInboxExclusionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_160';

    public function test_internal_provider_webhook_is_rejected_and_creates_no_inbox_row(): void
    {
        config([
            'shipping.providers.internal.mode' => 'internal',
            'shipping.providers.internal.modes.internal.enabled' => true,
        ]);

        $payload = [
            'id' => 'evt_internal_160',
            'type' => 'shipment.status_changed',
            'status' => 'delivered',
            'occurred_at' => '2026-11-23T10:00:00Z',
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/api/v1/general/shipping/webhooks/internal',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Content-Type' => 'application/json',
                'X-Shipping-Signature' => 'v1=' . hash_hmac('sha256', $raw, self::SECRET),
            ]),
            $raw,
        );

        $response->assertStatus(503);
        $this->assertSame(0, ShippingWebhookEvent::count());
    }

    public function test_internal_delivery_actions_do_not_create_external_inbox_rows(): void
    {
        $user = User::create([
            'name' => 'Internal Test',
            'email' => 'internal-' . Str::lower(Str::random(8)) . '@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Internal Customer',
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
        Shipment::create(['order_id' => $order->id, 'status' => 'pending', 'provider_code' => 'internal', 'provider_mode' => 'internal']);

        $this->assertSame(0, ShippingWebhookEvent::count());
    }
}
