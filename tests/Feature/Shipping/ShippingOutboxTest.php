<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ShippingOutboxOperationType;
use App\Enums\Shipping\ShippingOutboxStatus;
use App\Jobs\Shipping\ProcessShippingOutboxJob;
use App\Models\Shipment;
use App\Models\Shipping\ShippingOutbox;
use App\Services\Shipment\Outbox\DefaultShippingOutboxProcessor;
use App\Services\Shipment\Outbox\DefaultShippingOutboxReconciler;
use App\Services\Shipment\Outbox\ShippingOutboxOperation;
use App\Services\Shipment\Outbox\ShippingOutboxProcessor;
use App\Services\Shipment\Outbox\ShippingOutboxReconciler;
use App\Services\Shipment\Outbox\ShippingOutboxResult;
use App\Services\Shipment\Outbox\ShippingOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Tests\TestCase;

class ShippingOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function createShipment(): Shipment
    {
        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-' . Str::upper(Str::random(8)),
        ]);

        return Shipment::query()->create([
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    private function operation(Shipment $shipment, array $overrides = []): ShippingOutboxOperation
    {
        return new ShippingOutboxOperation(
            operation: $overrides['operation'] ?? ShippingOutboxOperationType::PROVIDER_CREATE_SHIPMENT,
            aggregateType: $overrides['aggregate_type'] ?? 'shipment',
            aggregateId: $overrides['aggregate_id'] ?? (string) $shipment->id,
            authorizedRequestId: $overrides['authorized_request_id'] ?? ('req-test-'.($overrides['version'] ?? 1)),
            version: $overrides['version'] ?? 1,
            providerCode: $overrides['provider_code'] ?? 'internal',
            providerMode: $overrides['provider_mode'] ?? 'internal',
            payload: $overrides['payload'] ?? ['shipment_id' => $shipment->id],
        );
    }

    /** @return object{processor:\Mockery\MockInterface,reconciler:\Mockery\MockInterface,service:ShippingOutboxService} */
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

    public function test_outbox_row_and_state_change_commit_together(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);

        try {
            DB::transaction(function () use ($service, $shipment) {
                $shipment->update(['status' => 'label_created']);
                $service->record($this->operation($shipment, ['version' => 1]));
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame(0, ShippingOutbox::query()->count());
    }

    public function test_record_is_idempotent_and_handles_unique_conflict(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $op = $this->operation($shipment, ['version' => 1]);

        $first = $service->record($op);

        $second = DB::transaction(function () use ($service, $op, $first) {
            // Simulate concurrent insert winning before this record() insert.
            DB::table('shipping_outbox')->insertOrIgnore([
                'operation' => $first->operation,
                'aggregate_type' => $first->aggregate_type,
                'aggregate_id' => $first->aggregate_id,
                'idempotency_key' => $first->idempotency_key,
                'authorized_request_id' => $op->authorizedRequestId,
                'provider_code' => $first->provider_code,
                'provider_mode' => $first->provider_mode,
                'payload' => json_encode(['shipment_id' => 999]),
                'status' => ShippingOutboxStatus::PENDING->value,
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $service->record($op);
        });

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShippingOutbox::query()->count());
    }

    public function test_lost_queue_message_is_recoverable_from_persisted_rows(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $row = $service->record($this->operation($shipment, ['version' => 1]));

        $ctx = $this->configuredService();
        $ctx->processor->shouldReceive('process')->once()->withArgs(fn (ShippingOutbox $outbox) => $outbox->id === $row->id)
            ->andReturn(ShippingOutboxResult::succeeded(['external_id' => 'ext-1']));

        $published = $ctx->service->processDue(100);

        $this->assertSame(1, $published);
        $this->assertSame(ShippingOutboxStatus::SUCCEEDED->value, $row->fresh()->status->value);
        $this->assertSame('ext-1', $row->fresh()->result['external_id'] ?? null);
    }

    public function test_process_due_skips_future_available_at_and_claims_stale_processing_lease(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $future = $service->record($this->operation($shipment, ['version' => 1]));
        $future->update(['available_at' => now()->addMinutes(10)]);

        $stale = $service->record($this->operation($shipment, ['version' => 2]));
        DB::table('shipping_outbox')->where('id', $stale->id)->update([
            'status' => ShippingOutboxStatus::PROCESSING->value,
            'updated_at' => now()->subSeconds(ShippingOutboxService::LEASE_SECONDS + 10),
            'available_at' => now()->subSeconds(ShippingOutboxService::LEASE_SECONDS + 10),
        ]);

        $ctx = $this->configuredService();
        $ctx->processor->shouldReceive('process')->once()
            ->withArgs(fn (ShippingOutbox $outbox) => $outbox->id === $stale->id)
            ->andReturn(ShippingOutboxResult::succeeded(['ok' => true]));

        $processed = $ctx->service->processDue(100);

        $this->assertSame(1, $processed);
        $this->assertSame(ShippingOutboxStatus::PENDING->value, $future->fresh()->status->value);
        $this->assertSame(ShippingOutboxStatus::SUCCEEDED->value, $stale->fresh()->status->value);
    }

    public function test_timeout_and_ambiguous_response_become_unknown_never_pending(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $row = $service->record($this->operation($shipment, ['version' => 1]));

        $ctx = $this->configuredService();
        $ctx->processor->shouldReceive('process')->once()->andReturn(ShippingOutboxResult::unknown('timeout'));

        $ctx->service->processOne($row->idempotency_key, $row->operation, $row->aggregate_type, (string) $row->aggregate_id);

        $fresh = $row->fresh();
        $this->assertSame(ShippingOutboxStatus::UNKNOWN->value, $fresh->status->value);
        $this->assertNotSame(ShippingOutboxStatus::PENDING->value, $fresh->status->value);
        $this->assertSame('timeout', $fresh->last_error);
    }

    public function test_connection_exception_timeout_is_ambiguous_but_local_connection_error_is_not(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $timeoutRow = $service->record($this->operation($shipment, ['version' => 1]));
        $localRow = $service->record($this->operation($shipment, ['version' => 2]));

        $ctx = $this->configuredService();
        $ctx->processor->shouldReceive('process')->twice()->andReturnUsing(function (ShippingOutbox $outbox) use ($timeoutRow) {
            if ($outbox->id === $timeoutRow->id) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            throw new ConnectionException('Connection refused');
        });

        $ctx->service->processById($timeoutRow->id);
        $ctx->service->processById($localRow->id);

        $this->assertSame(ShippingOutboxStatus::UNKNOWN->value, $timeoutRow->fresh()->status->value);
        $this->assertSame(ShippingOutboxStatus::PENDING->value, $localRow->fresh()->status->value);
        $this->assertStringContainsString('Connection refused', (string) $localRow->fresh()->last_error);
    }

    public function test_unknown_operation_is_reconciled_by_provider_lookup_before_retry(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $row = $service->record($this->operation($shipment, ['version' => 1]));
        $row->update(['status' => ShippingOutboxStatus::UNKNOWN, 'last_error' => 'timeout']);

        $ctx = $this->configuredService();
        $ctx->reconciler->shouldReceive('reconcile')->once()->withArgs(fn (ShippingOutbox $outbox) => $outbox->id === $row->id)
            ->andReturn(ShippingOutboxResult::succeeded(['external_id' => 'ext-reconciled']));
        $ctx->processor->shouldNotReceive('process');

        $ctx->service->processOne($row->idempotency_key, $row->operation, $row->aggregate_type, (string) $row->aggregate_id);

        $fresh = $row->fresh();
        $this->assertSame(ShippingOutboxStatus::SUCCEEDED->value, $fresh->status->value);
        $this->assertSame('ext-reconciled', $fresh->result['external_id'] ?? null);
    }

    public function test_reconciler_not_found_retry_signal_is_explicit_and_retries_once(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $row = $service->record($this->operation($shipment, ['version' => 1]));
        $row->update(['status' => ShippingOutboxStatus::UNKNOWN, 'last_error' => 'timeout', 'available_at' => now()->subMinute()]);

        $ctx = $this->configuredService();
        $ctx->reconciler->shouldReceive('reconcile')->once()->andReturn(ShippingOutboxResult::retry('not_found'));
        $ctx->processor->shouldReceive('process')->once()->andReturn(ShippingOutboxResult::succeeded(['external_id' => 'after-retry']));

        $ctx->service->processById($row->id);

        $this->assertSame(ShippingOutboxStatus::SUCCEEDED->value, $row->fresh()->status->value);
        $this->assertSame('after-retry', $row->fresh()->result['external_id'] ?? null);
    }

    public function test_reconciler_throw_and_unexpected_status_stay_unknown(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $throwRow = $service->record($this->operation($shipment, ['version' => 1]));
        $throwRow->update(['status' => ShippingOutboxStatus::UNKNOWN, 'attempts' => 2, 'available_at' => now()->subMinute()]);
        $unexpectedRow = $service->record($this->operation($shipment, ['version' => 2]));
        $unexpectedRow->update(['status' => ShippingOutboxStatus::UNKNOWN, 'attempts' => 2, 'available_at' => now()->subMinute()]);

        $ctx = $this->configuredService();
        $ctx->reconciler->shouldReceive('reconcile')->twice()->andReturnUsing(function (ShippingOutbox $outbox) use ($throwRow) {
            if ($outbox->id === $throwRow->id) {
                throw new \RuntimeException('lookup down');
            }

            return new ShippingOutboxResult(ShippingOutboxStatus::PROCESSING, null, 'bad');
        });

        $ctx->service->processById($throwRow->id);
        $ctx->service->processById($unexpectedRow->id);

        $this->assertSame(ShippingOutboxStatus::UNKNOWN->value, $throwRow->fresh()->status->value);
        $this->assertStringContainsString('lookup down', (string) $throwRow->fresh()->last_error);
        $this->assertSame(ShippingOutboxStatus::UNKNOWN->value, $unexpectedRow->fresh()->status->value);
        $this->assertStringContainsString('unexpected_result', (string) $unexpectedRow->fresh()->last_error);
    }

    public function test_unknown_concurrent_reconcile_claim_prevents_duplicate_external_reconcile(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $row = $service->record($this->operation($shipment, ['version' => 1]));
        $row->update(['status' => ShippingOutboxStatus::UNKNOWN, 'attempts' => 1, 'available_at' => now()->subMinute(), 'updated_at' => now()]);

        $ctx = $this->configuredService();
        $ctx->reconciler->shouldReceive('reconcile')->once()->andReturnUsing(function (ShippingOutbox $outbox) use ($ctx) {
            // Simulate another worker entering while lease is held.
            $ctx->service->processById($outbox->id);

            return ShippingOutboxResult::succeeded(['external_id' => 'once']);
        });

        $ctx->service->processById($row->id);

        $this->assertSame(ShippingOutboxStatus::SUCCEEDED->value, $row->fresh()->status->value);
        $this->assertSame(2, (int) $row->fresh()->attempts); // claim incremented once
    }

    public function test_failed_and_unknown_escalate_to_terminal_statuses(): void
    {
        config()->set('shipping.outbox_max_attempts', 2);
        config()->set('shipping.outbox_unknown_max_attempts', 2);

        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $failedRow = $service->record($this->operation($shipment, ['version' => 1]));
        $failedRow->update(['attempts' => 1]);
        $unknownRow = $service->record($this->operation($shipment, ['version' => 2]));
        $unknownRow->update(['status' => ShippingOutboxStatus::UNKNOWN, 'attempts' => 1, 'available_at' => now()->subMinute()]);

        $ctx = $this->configuredService();
        $ctx->processor->shouldReceive('process')->once()->andReturn(ShippingOutboxResult::failed('deterministic'));
        $ctx->reconciler->shouldNotReceive('reconcile');

        $ctx->service->processById($failedRow->id);
        $ctx->service->processById($unknownRow->id);

        $this->assertSame(ShippingOutboxStatus::FAILED->value, $failedRow->fresh()->status->value);
        $this->assertSame(ShippingOutboxStatus::FAILED_UNKNOWN->value, $unknownRow->fresh()->status->value);
    }

    public function test_default_stubs_are_fail_closed_and_not_dispatched(): void
    {
        Bus::fake();
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);

        $row = $service->recordAndDispatch($this->operation($shipment, ['version' => 1]));

        $this->assertSame(ShippingOutboxStatus::PENDING->value, $row->fresh()->status->value);
        Bus::assertNotDispatched(ProcessShippingOutboxJob::class);
        $this->assertInstanceOf(DefaultShippingOutboxProcessor::class, app(ShippingOutboxProcessor::class));
        $this->assertInstanceOf(DefaultShippingOutboxReconciler::class, app(ShippingOutboxReconciler::class));
    }

    public function test_no_external_call_occurs_inside_database_transaction(): void
    {
        Bus::fake();

        $ctx = $this->configuredService();
        $ctx->processor->shouldNotReceive('process');
        $ctx->reconciler->shouldNotReceive('reconcile');

        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $row = null;

        DB::transaction(function () use ($service, $shipment, &$row) {
            $row = $service->recordAndDispatch($this->operation($shipment, ['version' => 1]));
            $this->assertDatabaseHas('shipping_outbox', [
                'idempotency_key' => $row->idempotency_key,
                'status' => ShippingOutboxStatus::PENDING->value,
            ]);
        });

        Bus::assertDispatched(ProcessShippingOutboxJob::class, fn ($job) => $job->outboxId === $row->id);
    }
}
