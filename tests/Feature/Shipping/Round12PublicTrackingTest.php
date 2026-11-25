<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\OrderNotification;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShipmentNotification;
use App\Services\Guest\GuestOrderTokenService;
use App\Services\Tracking\TrackingAggregateService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Round 12 / #193: aggregate-only public tracking, authorised detail,
 * honest ETA, read-only deduped history, and Phase 0 evidence matrix.
 */
final class Round12PublicTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_TRACK = '/api/v1/general/track-order';
    private const GUEST_TRACK = '/api/v1/general/guest/orders/track';
    private const HISTORY = '/api/v1/user/notifications/history';

    private function createOrder(array $overrides = []): Order
    {
        $user = User::factory()->create([
            'email' => 'round12-' . uniqid() . '@example.test',
        ]);

        $defaults = [
            'user_id' => $user->id,
            'name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => '01000000000',
            'status' => Order::ORDER_STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'processing',
            'price' => 100,
            'total_price' => 100,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ];

        $order = Order::create(array_merge($defaults, $overrides));
        $order->refresh();

        return $order;
    }

    private function createShipment(Order $order, array $overrides = []): Shipment
    {
        return Shipment::create(array_merge([
            'order_id' => $order->id,
            'status' => 'in_transit',
            'estimated_delivery_at' => now()->addDays(3),
        ], $overrides));
    }

    private function publicPayload(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'user_email' => $order->user_email,
        ];
    }

    private function assertAggregate(
        array $aggregate,
        string $status,
        ?CarbonInterface $eta,
        ?CarbonInterface $deliveredAt,
        bool $hasShipments
    ): void {
        $this->assertSame($status, $aggregate['status']);
        $this->assertSame($eta?->toIso8601String(), $aggregate['eta']?->toIso8601String());
        $this->assertSame($deliveredAt?->toIso8601String(), $aggregate['delivered_at']?->toIso8601String());
        $this->assertSame($hasShipments, $aggregate['has_shipments']);
    }

    /** @test */
    public function public_lookup_is_aggregate_only_and_redacts_internal_data(): void
    {
        $order = $this->createOrder([
            'total_price' => 987.65,
            'tracking_number' => 'ORDER-AWB-SECRET',
        ]);
        $shipment = $this->createShipment($order, [
            'tracking_number' => 'AWB-SECRET-193',
            'provider_code' => 'SECRET_PROVIDER',
            'courier' => 'SECRET_COURIER',
            'metadata' => ['raw_payload' => 'RAW_SECRET_193'],
            'notes' => 'representative SECRET_REP +201234567890',
        ]);
        ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'unknown_status',
            'severity' => 'low',
            'opened_at' => now(),
            'notes' => 'EXCEPTION_SECRET_193',
        ]);

        $response = $this->postJson(self::PUBLIC_TRACK, $this->publicPayload($order));
        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertSame(
            ['order_number', 'status', 'fulfillment_status', 'created_at', 'aggregate'],
            array_keys($data['order'])
        );
        $this->assertSame(['status', 'eta', 'delivered_at', 'has_shipments'], array_keys($data['order']['aggregate']));
        $this->assertArrayNotHasKey('shipments', $data);
        $this->assertSame('in_transit', $data['order']['status']);

        $json = strtolower((string) json_encode($data));
        foreach ([
            'awb-secret-193', 'order-awb-secret', 'secret_provider', 'secret_courier',
            'raw_secret_193', 'raw_payload', 'secret_rep', 'exception_secret_193',
            'settlement', '987.65', '"metadata"', '"payload"', '"id"',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $json, 'Public aggregate leaked: ' . $needle);
        }
    }

    /** @test */
    public function public_lookup_never_fabricates_an_eta(): void
    {
        $order = $this->createOrder([
            'status' => Order::ORDER_STATUS_PROCESSING,
            'estimated_delivery_at' => null,
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);

        $first = $this->postJson(self::PUBLIC_TRACK, $this->publicPayload($order));
        $first->assertOk();
        $this->assertNull($first->json('data.estimated_delivery'));
        $this->assertNull($first->json('data.order.aggregate.eta'));

        $eta = now()->addDays(2)->startOfSecond();
        $shipment = $this->createShipment($order, ['estimated_delivery_at' => $eta]);

        $second = $this->postJson(self::PUBLIC_TRACK, $this->publicPayload($order));
        $second->assertOk();
        $this->assertSame($eta->toIso8601String(), $second->json('data.order.aggregate.eta'));
        $this->assertSame($eta->toIso8601String(), $second->json('data.estimated_delivery.estimated_date'));

        // One outstanding shipment without a real ETA makes the aggregate ETA
        // unavailable; no created_at-derived fallback is allowed.
        $this->createShipment($order, ['estimated_delivery_at' => null]);
        $third = $this->postJson(self::PUBLIC_TRACK, $this->publicPayload($order));
        $third->assertOk();
        $this->assertNull($third->json('data.estimated_delivery'));
        $this->assertNull($third->json('data.order.aggregate.eta'));
        $this->assertNotNull($shipment->fresh());
    }

    /** @test */
    public function per_shipment_detail_is_restricted_to_owners_and_guest_token_holders(): void
    {
        $order = $this->createOrder();
        $this->createShipment($order, ['tracking_number' => 'DETAIL-AWB-SECRET']);
        $token = app(GuestOrderTokenService::class)->issueTrackingToken($order);

        $public = $this->postJson(self::PUBLIC_TRACK, $this->publicPayload($order));
        $public->assertOk();
        $this->assertArrayNotHasKey('shipments', $public->json('data'));

        Sanctum::actingAs($order->user);
        $owner = $this->getJson('/api/v1/general/orders/' . $order->id . '/track');
        $owner->assertOk();
        $this->assertSame('in_transit', $owner->json('data.shipments.0.status'));
        $this->assertSame(
            ['status', 'shipped_at', 'estimated_delivery_at', 'delivered_at', 'cancelled_at'],
            array_keys($owner->json('data.shipments.0'))
        );

        $this->assertStringNotContainsString(
            'detail-awb-secret',
            strtolower((string) json_encode($owner->json('data.shipments')))
        );

        $outsider = $this->createOrder();
        Sanctum::actingAs($outsider->user);
        $this->getJson('/api/v1/general/orders/' . $order->id . '/track')->assertNotFound();

        $guest = $this->postJson(self::GUEST_TRACK, ['token' => $token]);
        $guest->assertOk();
        $this->assertSame('in_transit', $guest->json('data.shipments.0.status'));

        $this->postJson(self::GUEST_TRACK, ['token' => str_repeat('z', 43)])->assertNotFound();
    }

    /** @test */
    public function round12_aggregate_truth_table_is_read_only(): void
    {
        $service = app(TrackingAggregateService::class);
        $eta = now()->addDays(3)->startOfSecond();
        $deliveredAt = now()->subDay()->startOfSecond();

        // No shipments: stored evidence remains, no ETA is manufactured.
        $legacy = $this->createOrder([
            'status' => Order::ORDER_STATUS_PROCESSING,
            'estimated_delivery_at' => $eta,
        ]);
        $this->assertAggregate($service->forOrder($legacy), 'processing', $eta, null, false);

        // All cancelled: informational only, delivery mirror is ignored.
        $allCancelled = $this->createOrder([
            'status' => Order::ORDER_STATUS_DELIVERED,
            'actual_delivery_at' => $deliveredAt,
        ]);
        $this->createShipment($allCancelled, ['status' => 'cancelled']);
        $this->createShipment($allCancelled, ['status' => 'cancelled']);
        $this->assertAggregate($service->forOrder($allCancelled), 'cancelled', null, null, true);

        // All returned: returned is terminal only when nothing is outstanding.
        $allReturned = $this->createOrder();
        $this->createShipment($allReturned, ['status' => 'returned', 'delivered_at' => $deliveredAt]);
        $this->createShipment($allReturned, ['status' => 'returned', 'delivered_at' => $deliveredAt]);
        $this->assertAggregate($service->forOrder($allReturned), 'returned', null, null, true);

        // Returned + delivered: the return must not hide the true delivered set.
        $returnedAndDelivered = $this->createOrder([
            'status' => Order::ORDER_STATUS_DELIVERED,
            'actual_delivery_at' => $deliveredAt,
        ]);
        $this->createShipment($returnedAndDelivered, ['status' => 'returned', 'delivered_at' => $deliveredAt]);
        $this->createShipment($returnedAndDelivered, ['status' => 'delivered', 'delivered_at' => $deliveredAt]);
        $this->assertAggregate($service->forOrder($returnedAndDelivered), 'returned', null, null, true);

        // Returned + active: the outstanding shipment wins over the return.
        $returnedAndActive = $this->createOrder();
        $this->createShipment($returnedAndActive, ['status' => 'returned', 'delivered_at' => $deliveredAt]);
        $this->createShipment($returnedAndActive, ['status' => 'in_transit', 'estimated_delivery_at' => $eta]);
        $this->assertAggregate($service->forOrder($returnedAndActive), 'in_transit', $eta, null, true);

        // Delivered: the latest genuine delivered_at is used.
        $delivered = $this->createOrder(['status' => Order::ORDER_STATUS_PROCESSING]);
        $this->createShipment($delivered, [
            'status' => 'delivered',
            'delivered_at' => $deliveredAt->copy()->subDay(),
        ]);
        $this->createShipment($delivered, ['status' => 'delivered', 'delivered_at' => $deliveredAt]);
        $this->assertAggregate($service->forOrder($delivered), 'delivered', null, $deliveredAt, true);

        // Outstanding precedence: failed_delivery outranks every lower state.
        $precedence = $this->createOrder();
        foreach (['pending', 'label_created', 'in_transit', 'out_for_delivery', 'delayed', 'failed_delivery'] as $status) {
            $this->createShipment($precedence, ['status' => $status, 'estimated_delivery_at' => $eta]);
        }
        $this->assertAggregate($service->forOrder($precedence), 'failed_delivery', $eta, null, true);

        // failed_delivery ETA is unavailable when it predates the failure event.
        $staleFailure = $this->createOrder();
        $this->createShipment($staleFailure, [
            'status' => 'failed_delivery',
            'estimated_delivery_at' => $eta,
        ]);
        OrderTrackingEvent::create([
            'order_id' => $staleFailure->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now()->addDays(5),
            'actor_type' => 'system',
            'customer_visible' => true,
            'source' => 'shipment',
            'new_status' => 'failed_delivery',
        ]);
        $this->assertAggregate($service->forOrder($staleFailure), 'failed_delivery', null, null, true);

        // A failure event before the ETA leaves the ETA trustworthy.
        $freshFailure = $this->createOrder();
        $this->createShipment($freshFailure, [
            'status' => 'failed_delivery',
            'estimated_delivery_at' => $eta,
        ]);
        OrderTrackingEvent::create([
            'order_id' => $freshFailure->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now()->subDay(),
            'actor_type' => 'system',
            'customer_visible' => true,
            'source' => 'shipment',
            'new_status' => 'failed_delivery',
        ]);
        $this->assertAggregate($service->forOrder($freshFailure), 'failed_delivery', $eta, null, true);
    }

    /** @test */
    public function guest_expiry_uses_aggregate_truth_instead_of_stale_order_mirrors(): void
    {
        $stale = now()->subDays(60)->startOfSecond();

        foreach ([Order::ORDER_STATUS_DELIVERED, Order::ORDER_STATUS_COMPLETED] as $orderStatus) {
            foreach (['returned', 'in_transit'] as $shipmentStatus) {
                $order = $this->createOrder([
                    'status' => $orderStatus,
                    'actual_delivery_at' => $stale,
                    'completed_at' => $stale,
                ]);
                $this->createShipment($order, [
                    'status' => $shipmentStatus,
                    'delivered_at' => $shipmentStatus === 'returned' ? $stale : null,
                    'estimated_delivery_at' => now()->addDay(),
                ]);
                $token = app(GuestOrderTokenService::class)->issueTrackingToken($order);

                $response = $this->postJson(self::GUEST_TRACK, ['token' => $token]);
                $response->assertOk();

                $this->assertSame($shipmentStatus, $response->json('data.aggregate.status'));
                $this->assertNull($response->json('data.aggregate.delivered_at'));
                $this->assertNull($response->json('data.tracking_expires_at'), $shipmentStatus . ' must not inherit a stale delivery mirror');

                $fresh = $order->fresh();
                $this->assertSame($orderStatus, $fresh->status);
                $this->assertSame($stale->toIso8601String(), $fresh->actual_delivery_at->toIso8601String());
                $this->assertSame($stale->toIso8601String(), $fresh->completed_at->toIso8601String());
            }
        }
    }

    /** @test */
    public function guest_expiry_uses_delivered_aggregate_timestamp_and_keeps_legacy_fallback(): void
    {
        $deliveredAt = now()->subDays(2)->startOfSecond();
        $grace = max(1, (int) config('guest_checkout.tracking_grace_days', 30));

        $aggregated = $this->createOrder([
            'status' => Order::ORDER_STATUS_PROCESSING,
            'actual_delivery_at' => null,
            'completed_at' => null,
        ]);
        $this->createShipment($aggregated, ['status' => 'delivered', 'delivered_at' => $deliveredAt]);
        $aggregatedToken = app(GuestOrderTokenService::class)->issueTrackingToken($aggregated);
        $aggregatedResponse = $this->postJson(self::GUEST_TRACK, ['token' => $aggregatedToken]);
        $aggregatedResponse->assertOk();
        $this->assertSame('delivered', $aggregatedResponse->json('data.aggregate.status'));
        $this->assertSame($deliveredAt->toIso8601String(), $aggregatedResponse->json('data.aggregate.delivered_at'));
        $this->assertSame(
            $deliveredAt->copy()->addDays($grace)->toIso8601String(),
            $aggregatedResponse->json('data.tracking_expires_at')
        );

        $legacy = $this->createOrder([
            'status' => Order::ORDER_STATUS_DELIVERED,
            'actual_delivery_at' => $deliveredAt,
            'completed_at' => $deliveredAt->copy()->addDay(),
        ]);
        $legacyToken = app(GuestOrderTokenService::class)->issueTrackingToken($legacy);
        $legacyResponse = $this->postJson(self::GUEST_TRACK, ['token' => $legacyToken]);
        $legacyResponse->assertOk();
        $this->assertFalse($legacyResponse->json('data.aggregate.has_shipments'));
        $this->assertSame(
            $deliveredAt->copy()->addDays($grace)->toIso8601String(),
            $legacyResponse->json('data.tracking_expires_at')
        );
    }

    /** @test */
    public function notification_history_excludes_other_recipients_and_supports_pages(): void
    {
        $ownerOrder = $this->createOrder();
        $otherOrder = $this->createOrder();
        $user = $ownerOrder->user;
        $other = $otherOrder->user;
        $timeline = OrderTrackingEvent::create([
            'order_id' => $ownerOrder->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => 'system',
            'customer_visible' => true,
            'source' => 'shipment',
        ]);

        foreach ([
            ['owner' => $user, 'event_type' => 'owner_old', 'minutes_ago' => 30],
            ['owner' => $user, 'event_type' => 'owner_mid', 'minutes_ago' => 20],
            ['owner' => $user, 'event_type' => 'owner_new', 'minutes_ago' => 10],
            ['owner' => $other, 'event_type' => 'other_secret', 'minutes_ago' => 1],
        ] as $fixture) {
            $row = new OrderNotification();
            $row->forceFill([
                'order_id' => $fixture['owner']->id === $user->id ? $ownerOrder->id : $otherOrder->id,
                'user_id' => $fixture['owner']->id,
                'event_type' => $fixture['event_type'],
                'channel' => 'email',
                'status' => 'sent',
                'created_at' => now()->subMinutes($fixture['minutes_ago']),
            ])->save();
        }

        $shipment = new ShipmentNotification();
        $shipment->forceFill([
            'timeline_event_id' => $timeline->id,
            'channel' => 'push',
            'recipient_type' => 'user',
            'recipient_id' => (string) $user->id,
            'status' => 'sent',
            'created_at' => now()->subMinute(),
        ])->save();

        $otherShipment = new ShipmentNotification();
        $otherShipment->forceFill([
            'timeline_event_id' => $timeline->id,
            'channel' => 'sms',
            'recipient_type' => 'user',
            'recipient_id' => (string) $other->id,
            'status' => 'sent',
            'created_at' => now(),
        ])->save();

        Sanctum::actingAs($user);
        $first = $this->getJson(self::HISTORY . '?per_page=1&page=1');
        $first->assertOk();
        $this->assertSame(1, $first->json('data.current_page'));
        $this->assertSame(4, $first->json('data.total'));
        $this->assertSame('shipment', $first->json('data.data.0.source'));

        $second = $this->getJson(self::HISTORY . '?per_page=1&page=2');
        $second->assertOk();
        $this->assertSame(2, $second->json('data.current_page'));
        $this->assertCount(1, $second->json('data.data'));
        $this->assertSame('owner_new', $second->json('data.data.0.event_type'));

        foreach ([$first, $second] as $response) {
            $this->assertStringNotContainsString(
                'other_secret',
                strtolower((string) json_encode($response->json('data.data')))
            );
            $this->assertStringNotContainsString(
                (string) $other->id,
                strtolower((string) json_encode($response->json('data.data')))
            );
        }
    }

    /** @test */
    public function shipment_notification_dedupe_key_prevents_duplicate_history_rows(): void
    {
        $order = $this->createOrder();
        $timeline = OrderTrackingEvent::create([
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => 'system',
            'customer_visible' => true,
            'source' => 'shipment',
        ]);

        $key = [
            'timeline_event_id' => $timeline->id,
            'channel' => 'push',
            'recipient_type' => 'user',
            'recipient_id' => (string) $order->user_id,
        ];

        ShipmentNotification::create($key + ['status' => 'sent']);

        try {
            ShipmentNotification::create($key + ['status' => 'failed']);
            $this->fail('Duplicate shipment notification was persisted');
        } catch (QueryException) {
            // The storage dedupe key is the first line of defence; the service
            // dedupe keeps the unified projection stable for any legacy rows.
        }

        Sanctum::actingAs($order->user);
        $response = $this->getJson(self::HISTORY . '?per_page=10');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('shipment', $response->json('data.data.0.source'));
        $this->assertSame('sent', $response->json('data.data.0.status'));
    }

    /** @test */
    public function notification_history_is_read_only_deduped_and_source_discriminated(): void
    {
        $order = $this->createOrder();
        $user = $order->user;
        $timeline = OrderTrackingEvent::create([
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'event_timestamp' => now(),
            'actor_type' => 'system',
            'customer_visible' => true,
            'source' => 'shipment',
        ]);

        // Lower id but newer timestamp: the latest duplicate must win, not
        // the first row encountered by the query.
        foreach ([
            ['at' => now()->subMinutes(5), 'subject' => 'Shipped latest', 'status' => 'sent'],
            ['at' => now()->subMinutes(10), 'subject' => 'Shipped stale', 'status' => 'failed'],
        ] as $duplicateFixture) {
            $duplicate = new OrderNotification();
            $duplicate->forceFill([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'event_type' => 'order_shipped',
                'channel' => 'sms',
                'status' => $duplicateFixture['status'],
                'subject' => $duplicateFixture['subject'],
                'message' => 'On the way',
                'created_at' => $duplicateFixture['at'],
            ])->save();
        }

        $newer = now();
        $latestOrder = new OrderNotification();
        $latestOrder->forceFill([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'event_type' => 'order_delivered',
            'channel' => 'email',
            'status' => 'sent',
            'created_at' => $newer,
        ])->save();

        $latestShipment = new ShipmentNotification();
        $latestShipment->forceFill([
            'timeline_event_id' => $timeline->id,
            'channel' => 'push',
            'recipient_type' => 'user',
            'recipient_id' => (string) $user->id,
            'status' => 'sent',
            'created_at' => $newer->copy()->addSecond(),
        ])->save();

        $before = [
            'order' => OrderNotification::query()->count(),
            'shipment' => ShipmentNotification::query()->count(),
        ];

        Sanctum::actingAs($user);
        $response = $this->getJson(self::HISTORY . '?per_page=10');
        $response->assertOk();

        $items = $response->json('data.data');
        $this->assertCount(3, $items, 'Duplicate order rows collapse; three distinct rows remain');
        $this->assertSame(['shipment', 'order'], array_column(array_slice($items, 0, 2), 'source'));
        $this->assertContains('order', array_column($items, 'source'));

        $shipped = collect($items)->firstWhere('event_type', 'order_shipped');
        $this->assertNotNull($shipped);
        $this->assertSame('Shipped latest', $shipped['subject'], 'Latest duplicate must win');
        $this->assertSame('sent', $shipped['status'], 'Latest duplicate must win');

        foreach ($items as $item) {
            $this->assertSame([
                'source', 'event_type', 'channel', 'status', 'subject', 'message',
                'created_at', 'sent_at', 'delivered_at', 'failed_at',
            ], array_keys($item));
            $this->assertArrayNotHasKey('id', $item);
            $this->assertArrayNotHasKey('payload', $item);
            $this->assertArrayNotHasKey('provider_response', $item);
            $this->assertArrayNotHasKey('failure_reason', $item);
            $this->assertArrayNotHasKey('dedupe_key', $item);
        }

        $this->assertSame($before, [
            'order' => OrderNotification::query()->count(),
            'shipment' => ShipmentNotification::query()->count(),
        ], 'History reads must never mutate notification rows');

        $this->postJson(self::HISTORY)->assertStatus(405);
    }

    /** @test */
    public function closure_matrix_records_required_evidence_additions(): void
    {
        $path = dirname(__DIR__, 3) . '/docs/audits/ROUND_12_PHASE_0_CLOSURE_MATRIX.md';
        $this->assertFileExists($path);

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $table = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with(trim($line), '|')));
        $this->assertGreaterThanOrEqual(6, count($table), 'Matrix table must contain header, separator and risk rows');

        $parseRow = static function (string $line): array {
            return array_values(array_map(
                static fn (string $cell): string => trim(str_replace('**', '', $cell)),
                explode('|', trim($line, '|'))
            ));
        };

        $this->assertSame(
            ['Risk', 'Current repository evidence (verified)', 'Evidence gap', 'Required addition', 'Status'],
            $parseRow($table[0])
        );
        $this->assertSame(array_fill(0, 5, '---'), $parseRow($table[1]));

        $byRisk = [];
        foreach (array_slice($table, 2) as $line) {
            $cells = $parseRow($line);
            $this->assertCount(5, $cells, 'Every matrix row must have five columns');
            $this->assertSame(1, preg_match('/^(R\d+)\b/', $cells[0], $matches));
            $byRisk[$matches[1]] = $cells;
        }

        foreach (['R1', 'R2', 'R4', 'R7'] as $risk) {
            $this->assertArrayHasKey($risk, $byRisk);
            $this->assertNotSame('', $byRisk[$risk][2], $risk . ' must document an evidence gap');
            $this->assertNotSame('', $byRisk[$risk][3], $risk . ' must document a required addition');
            $this->assertSame('OPEN — evidence required', $byRisk[$risk][4]);
        }

        $this->assertStringContainsString('not** risk closure', (string) file_get_contents($path));
    }
}
