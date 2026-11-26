<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ShippingOutboxStatus;
use App\Models\Shipment;
use App\Models\Shipping\ShippingOutbox;
use App\Services\Shipment\Outbox\ShippingOutboxOperation;
use App\Services\Shipment\Outbox\ShippingOutboxProcessor;
use App\Services\Shipment\Outbox\ShippingOutboxReconciler;
use App\Services\Shipment\Outbox\ShippingOutboxResult;
use App\Services\Shipment\Outbox\ShippingOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * #178: lease recovery must distinguish "never dispatched" from "possibly
 * dispatched", and manual requeue must be authorized, reasoned and audited.
 */
final class ShippingOutboxLeaseRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function createShipment(): Shipment
    {
        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-' . Str::upper(Str::random(8)),
        ]);

        return Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
    }

    private function operation(Shipment $shipment, string $key): ShippingOutboxOperation
    {
        return new ShippingOutboxOperation(
            operation: 'carrier.create_shipment',
            aggregateType: 'shipment',
            aggregateId: (string) $shipment->id,
            idempotencyKey: $key,
            providerCode: 'internal',
            providerMode: 'internal',
            payload: ['shipment_id' => $shipment->id],
        );
    }

    private function configuredService(): object
    {
        $processor = $this->mock(ShippingOutboxProcessor::class);
        $reconciler = $this->mock(ShippingOutboxReconciler::class);
        $this->app->instance(ShippingOutboxProcessor::class, $processor);
        $this->app->instance(ShippingOutboxReconciler::class, $reconciler);

        return (object) [
            'processor' => $processor,
            'reconciler' => $reconciler,
            'service' => app(ShippingOutboxService::class),
        ];
    }

    private function makeStale(ShippingOutbox $row, bool $dispatched): void
    {
        $staleAt = now()->subSeconds(ShippingOutboxService::LEASE_SECONDS + 60);
        DB::table('shipping_outbox')->where('id', $row->id)->update([
            'status' => ShippingOutboxStatus::PROCESSING->value,
            'processing_started_at' => $staleAt,
            'dispatch_started_at' => $dispatched ? $staleAt : null,
            'updated_at' => $staleAt,
        ]);
    }

    public function test_expired_lease_without_dispatch_marker_returns_to_pending_and_is_retried(): void
    {
        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'lease-safe'));
        $this->makeStale($row, dispatched: false);

        $ctx = $this->configuredService();
        $ctx->processor->shouldReceive('process')->once()->andReturn(ShippingOutboxResult::succeeded(['ok' => true]));
        $ctx->reconciler->shouldNotReceive('reconcile');

        $ctx->service->processById($row->id);

        $fresh = $row->fresh();
        $this->assertSame(ShippingOutboxStatus::SUCCEEDED->value, $fresh->status->value);
        $this->assertNotNull($fresh->dispatch_started_at);
    }

    public function test_expired_lease_after_dispatch_marker_becomes_unknown_and_is_not_retried(): void
    {
        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'lease-ambiguous'));
        $this->makeStale($row, dispatched: true);

        $ctx = $this->configuredService();
        $ctx->processor->shouldNotReceive('process');
        $ctx->reconciler->shouldReceive('reconcile')->once()->andReturn(ShippingOutboxResult::unknown('still ambiguous'));

        $ctx->service->processById($row->id);

        $fresh = $row->fresh();
        $this->assertSame(ShippingOutboxStatus::UNKNOWN->value, $fresh->status->value);
        $this->assertStringContainsString('lease_expired_after_dispatch_start', (string) $fresh->last_error);
        $this->assertTrue($fresh->available_at->greaterThan(now()));

        // Reconciliation is bounded by the visibility delay: the ambiguous
        // lease is never sent straight back to the provider.
        DB::table('shipping_outbox')->where('id', $row->id)->update(['available_at' => now()]);
        $ctx->service->processById($row->id);
    }

    public function test_dispatch_marker_is_written_before_the_provider_call(): void
    {
        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'marker-before-call'));

        $ctx = $this->configuredService();
        $ctx->processor->shouldReceive('process')->once()->andReturnUsing(function (ShippingOutbox $outbox) {
            $this->assertNotNull($outbox->fresh()->dispatch_started_at);

            return ShippingOutboxResult::succeeded(['ok' => true]);
        });

        $ctx->service->processById($row->id);

        $this->assertSame(ShippingOutboxStatus::SUCCEEDED->value, $row->fresh()->status->value);
    }

    public function test_late_worker_cannot_overwrite_a_newer_claim_after_the_provider_call(): void
    {
        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'claim-takeover'));

        $ctx = $this->configuredService();
        $ctx->reconciler->shouldNotReceive('reconcile');
        $ctx->processor->shouldReceive('process')->once()->andReturnUsing(function (ShippingOutbox $outbox) {
            // Simulate a second worker taking over the expired lease while the
            // first worker is still inside the provider call.
            DB::table('shipping_outbox')->where('id', $outbox->id)->update([
                'status' => ShippingOutboxStatus::UNKNOWN->value,
                'attempts' => (int) $outbox->attempts + 1,
                'processing_started_at' => now()->addMinute(),
                'last_error' => 'taken over by reconciler',
                'updated_at' => now(),
            ]);

            return ShippingOutboxResult::succeeded(['ok' => true]);
        });

        $this->assertFalse($ctx->service->processById($row->id));

        $fresh = $row->fresh();
        $this->assertSame(ShippingOutboxStatus::UNKNOWN->value, $fresh->status->value);
        $this->assertSame('taken over by reconciler', (string) $fresh->last_error);
        $this->assertNull($fresh->result);
    }

    public function test_manual_requeue_requires_a_reason(): void
    {
        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'requeue-reason'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ShippingOutboxService::class)->requeue($row, 1, '   ');
    }

    public function test_manual_requeue_resets_state_and_writes_an_audit_record(): void
    {
        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'requeue-audit'));
        $row->update([
            'status' => ShippingOutboxStatus::UNKNOWN,
            'attempts' => 25,
            'dispatch_started_at' => now(),
            'last_error' => 'timeout',
        ]);

        $updated = app(ShippingOutboxService::class)->requeue($row, 42, 'provider confirmed absent');

        $this->assertSame(ShippingOutboxStatus::PENDING->value, $updated->status->value);
        $this->assertNull($updated->dispatch_started_at);
        $this->assertSame(0, (int) $updated->attempts);
        $this->assertStringContainsString('provider confirmed absent', (string) $updated->last_error);

        $activity = Activity::query()->where('event', 'shipping.outbox.requeued')->firstOrFail();
        $this->assertSame(42, (int) $activity->causer_id);
        $this->assertSame('provider confirmed absent', $activity->properties['reason']);
        $this->assertSame('provider confirmed absent', $activity->properties['context']['reason']);
    }

    public function test_canonical_event_key_does_not_include_hmac_key_version(): void
    {
        $verifier = app(\App\Services\Shipment\CarrierWebhookVerifier::class);
        $method = new \ReflectionMethod($verifier, 'eventKey');
        $method->setAccessible(true);

        $identity = [
            'event_id' => null,
            'event_type' => 'shipment.status_changed',
            'status' => 'in_transit',
            'object_ref' => 'obj-123',
            'provider_shipment_ref' => 'ref-123',
            'shipment_uuid' => null,
            'occurred_at' => \Carbon\CarbonImmutable::now(),
        ];
        $base = [
            'header' => 'X-Shipping-Signature',
            'algorithm' => 'sha256',
            'secret' => 'test-secret',
            'mode' => 'sandbox',
            'account_code' => 'acct-1',
        ];

        $v1 = $method->invoke($verifier, 'null', $base + ['version' => 'v1'], $identity, []);
        $v2 = $method->invoke($verifier, 'null', $base + ['version' => 'v2'], $identity, []);

        $this->assertSame($v1, $v2);
    }

    public function test_requeue_endpoint_rejects_unauthenticated_callers(): void
    {
        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'requeue-unauth'));

        $this->postJson("/api/v1/admin/shipping-outbox/{$row->id}/requeue", ['reason' => 'test'])
            ->assertUnauthorized();
    }

    public function test_requeue_endpoint_rejects_non_admin_users(): void
    {
        $user = User::create([
            'name' => 'Customer',
            'email' => 'customer-outbox@example.test',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'requeue-forbidden'));

        $this->postJson("/api/v1/admin/shipping-outbox/{$row->id}/requeue", ['reason' => 'test'])
            ->assertForbidden();
    }

    public function test_requeue_endpoint_allows_admin_and_validates_reason(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-outbox@example.test',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $shipment = $this->createShipment();
        $row = app(ShippingOutboxService::class)->record($this->operation($shipment, 'requeue-allowed'));

        $this->postJson("/api/v1/admin/shipping-outbox/{$row->id}/requeue", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/admin/shipping-outbox/{$row->id}/requeue", ['reason' => 'operator decision'])
            ->assertOk()
            ->assertJsonPath('data.status', ShippingOutboxStatus::PENDING->value);
    }
}
