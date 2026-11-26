<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ShippingOutboxOperationType;
use App\Enums\Shipping\ShippingOutboxStatus;
use App\Models\Shipment;
use App\Models\Shipping\ShippingOutbox;
use App\Services\Shipment\Outbox\ShippingOutboxOperation;
use App\Services\Shipment\Outbox\ShippingOutboxService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/** #184 focused contract coverage against the durable #152 outbox table. */
class ShippingOutboxContractTest extends TestCase
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

    /**
     * Stand in for a concurrent writer that commits a winner row after
     * record()'s prechecks but before its insertOrIgnore(). The injected insert
     * makes MySQL suppress the duplicate and return 0, exercising the race
     * deterministically instead of relying on thread scheduling.
     */
    private function injectWinnerBeforeInsert(
        ShippingOutboxOperation $operation,
        string $winnerRequestId,
        ?string $winnerKey = null,
    ): void {
        $injected = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$injected, $operation, $winnerRequestId, $winnerKey): void {
            $normalized = strtolower($query);
            if ($injected || ! str_contains($normalized, 'insert ignore') || ! str_contains($normalized, 'shipping_outbox')) {
                return;
            }

            $injected = true;
            DB::table('shipping_outbox')->insert([
                'operation' => $operation->operation->value,
                'aggregate_type' => $operation->aggregateType,
                'aggregate_id' => $operation->aggregateId,
                'idempotency_key' => $winnerKey ?? $operation->idempotencyKey,
                'authorized_request_id' => $winnerRequestId,
                'status' => ShippingOutboxStatus::PENDING->value,
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_operation_key_is_stored_deterministically_and_versions_are_idempotent(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $aggregateId = (string) $shipment->id;
        $key = ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING->value.':shipment:'.$aggregateId.':1';

        $request = new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            $aggregateId,
            'req-1',
            1,
            'internal',
            'internal',
            [
                'shipment_id' => $shipment->id,
                'user_email' => 'customer@example.test',
                'amount' => 99,
            ],
        );

        $first = $service->record($request);

        $this->assertSame($key, $first->idempotency_key);
        $this->assertSame(['shipment_id' => $shipment->id], $first->payload);

        $retry = $service->record($request);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, ShippingOutbox::query()->count());

        $newVersion = $service->record(new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            $aggregateId,
            'req-2',
            2,
        ));

        $this->assertNotSame($first->id, $newVersion->id);
        $this->assertSame(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING->value.':shipment:'.$aggregateId.':2',
            $newVersion->idempotency_key,
        );
        $this->assertSame(2, ShippingOutbox::query()->count());
    }

    public function test_same_authorized_request_cannot_create_a_new_version(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $aggregateId = (string) $shipment->id;

        $service->record(new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            $aggregateId,
            'req-1',
        ));

        try {
            $service->record(new ShippingOutboxOperation(
                ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
                'shipment',
                $aggregateId,
                'req-1',
                2,
            ));
            $this->fail('A reused authorized request must not mint a new version.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }

        $this->assertSame(1, ShippingOutbox::query()->count());
    }

    public function test_unauthorized_version_bump_is_rejected(): void
    {
        $shipment = $this->createShipment();

        $this->expectException(ValidationException::class);

        app(ShippingOutboxService::class)->record(new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            (string) $shipment->id,
            'req-x',
            2,
        ));
    }

    public function test_version_cannot_be_reused_by_a_different_authorized_request(): void
    {
        $shipment = $this->createShipment();
        $service = app(ShippingOutboxService::class);
        $aggregateId = (string) $shipment->id;

        $service->record(new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            $aggregateId,
            'req-1',
        ));

        $this->expectException(ValidationException::class);

        $service->record(new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            $aggregateId,
            'req-2',
        ));
    }

    public function test_authorized_request_identity_is_unique_at_the_database_boundary(): void
    {
        $shipment = $this->createShipment();
        $values = [
            'operation' => ShippingOutboxOperationType::POLL_RUN->value,
            'aggregate_type' => 'shipment',
            'aggregate_id' => (string) $shipment->id,
            'authorized_request_id' => 'req-db',
            'idempotency_key' => ShippingOutboxOperationType::POLL_RUN->value.':shipment:'.$shipment->id.':1',
            'status' => ShippingOutboxStatus::PENDING->value,
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('shipping_outbox')->insert($values);
        $values['idempotency_key'] = ShippingOutboxOperationType::POLL_RUN->value.':shipment:'.$shipment->id.':2';

        $this->expectException(QueryException::class);

        DB::table('shipping_outbox')->insert($values);
    }

    public function test_operation_identity_is_unique_at_the_database_boundary(): void
    {
        $shipment = $this->createShipment();
        $values = [
            'operation' => ShippingOutboxOperationType::POLL_RUN->value,
            'aggregate_type' => 'shipment',
            'aggregate_id' => (string) $shipment->id,
            'idempotency_key' => ShippingOutboxOperationType::POLL_RUN->value.':shipment:'.$shipment->id.':1',
            'status' => ShippingOutboxStatus::PENDING->value,
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('shipping_outbox')->insert($values);

        $this->expectException(QueryException::class);

        DB::table('shipping_outbox')->insert($values);
    }

    public function test_insert_or_ignore_winner_from_another_request_is_rejected(): void
    {
        $shipment = $this->createShipment();
        $operation = new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            (string) $shipment->id,
            'req-loser',
            1,
            null,
            null,
            ['shipment_id' => $shipment->id],
        );

        $this->injectWinnerBeforeInsert($operation, 'req-winner');

        try {
            app(ShippingOutboxService::class)->record($operation);
            $this->fail('A conflicting winner row must not be returned as this request\'s own.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('authorized_request_id', $e->errors());
        }

        $this->assertSame(1, ShippingOutbox::query()->count());
        $this->assertSame('req-winner', ShippingOutbox::query()->value('authorized_request_id'));
        $this->assertSame($operation->idempotencyKey, ShippingOutbox::query()->value('idempotency_key'));
    }

    public function test_insert_or_ignore_same_request_other_version_is_validation_error(): void
    {
        $shipment = $this->createShipment();
        $operation = new ShippingOutboxOperation(
            ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING,
            'shipment',
            (string) $shipment->id,
            'req-1',
            1,
            null,
            null,
            ['shipment_id' => $shipment->id],
        );
        $winnerKey = ShippingOutboxOperationType::PROVIDER_SYNC_TRACKING->value.':shipment:'.$shipment->id.':2';

        $this->injectWinnerBeforeInsert($operation, 'req-1', $winnerKey);

        try {
            app(ShippingOutboxService::class)->record($operation);
            $this->fail('A lost authorized-request race must be a validation error, not a missing-model error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }

        $this->assertSame(1, ShippingOutbox::query()->count());
        $this->assertSame('req-1', ShippingOutbox::query()->value('authorized_request_id'));
        $this->assertSame($winnerKey, ShippingOutbox::query()->value('idempotency_key'));
    }
}
