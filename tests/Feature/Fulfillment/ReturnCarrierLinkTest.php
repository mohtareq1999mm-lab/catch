<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\ReturnRequest;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * #172: a carrier return may reference an existing shipment and keep minimal
 * provider return metadata. ReturnService remains the lifecycle authority.
 */
class ReturnCarrierLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_request_can_link_a_shipment_and_keep_provider_return_metadata(): void
    {
        $shipment = $this->createShipment();
        $user = \App\Models\User::create([
            'name' => 'Return user',
            'email' => 'return-172-'.uniqid().'@example.test',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);

        $return = ReturnRequest::create([
            'return_number' => 'RET-172-'.uniqid(),
            'order_id' => $shipment->order_id,
            'warehouse_id' => $this->createWarehouse()->id,
            'customer_id' => $user->id,
            'status' => 'pending',
            'reason' => 'carrier return',
            'requested_by' => $user->id,
            'requested_at' => now(),
            'shipment_id' => $shipment->id,
            'provider_return_metadata' => [
                'provider_code' => 'internal',
                'carrier_reference' => 'RET-CARRIER-172',
            ],
        ]);

        $fresh = $return->fresh();
        $this->assertSame($shipment->id, (int) $fresh->shipment_id);
        $this->assertTrue($fresh->shipment()->first()->is($shipment));
        $this->assertSame([
            'provider_code' => 'internal',
            'carrier_reference' => 'RET-CARRIER-172',
        ], $fresh->provider_return_metadata);

        $approved = app(ReturnService::class)->approveReturnRequest($fresh, [], $user->id);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($shipment->id, (int) $approved->fresh()->shipment_id);
        $this->assertSame('RET-CARRIER-172', $approved->fresh()->provider_return_metadata['carrier_reference']);
    }

    public function test_legacy_return_rows_keep_null_link_and_metadata_after_lifecycle_transitions(): void
    {
        $user = \App\Models\User::create([
            'name' => 'Legacy return user',
            'email' => 'legacy-return-172-'.uniqid().'@example.test',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);
        $order = $this->createOrder($user->id);

        $legacyId = DB::table('return_requests')->insertGetId([
            'return_number' => 'RET-172-LEGACY-'.uniqid(),
            'order_id' => $order->id,
            'warehouse_id' => $this->createWarehouse()->id,
            'customer_id' => $user->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacy = ReturnRequest::findOrFail($legacyId);
        $this->assertNull($legacy->shipment_id);
        $this->assertNull($legacy->provider_return_metadata);

        $approved = app(ReturnService::class)->approveReturnRequest($legacy, [], $user->id);

        $this->assertSame('approved', $approved->status);
        $this->assertNull($approved->fresh()->shipment_id);
        $this->assertNull($approved->fresh()->provider_return_metadata);
    }

    public function test_link_carrier_return_preserves_existing_metadata_keys_on_collision(): void
    {
        $shipment = $this->createShipment();
        $user = \App\Models\User::factory()->create(['type' => 'customer']);

        $return = ReturnRequest::create([
            'return_number' => 'RET-174-COLLISION-' . uniqid(),
            'order_id' => $shipment->order_id,
            'warehouse_id' => $this->createWarehouse()->id,
            'customer_id' => $user->id,
            'status' => 'pending',
            'reason' => 'carrier return',
            'requested_by' => $user->id,
            'requested_at' => now(),
            'provider_return_metadata' => [
                'carrier_reference' => 'ORIGINAL',
                'provider_code' => 'kept',
            ],
        ]);

        $linked = app(ReturnService::class)->linkCarrierReturn($shipment->fresh(), [
            'carrier_reference' => 'REPLACEMENT',
            'new_key' => 'filled',
        ]);

        $this->assertSame($return->id, $linked?->id);
        $metadata = $linked->fresh()->provider_return_metadata;
        $this->assertSame('ORIGINAL', $metadata['carrier_reference'] ?? null);
        $this->assertSame('kept', $metadata['provider_code'] ?? null);
        $this->assertSame('filled', $metadata['new_key'] ?? null);
    }

    public function test_link_carrier_return_skips_request_already_linked_to_another_shipment(): void
    {
        $user = \App\Models\User::factory()->create(['type' => 'customer']);
        $order = $this->createOrder($user->id);
        $linkedShipment = $this->createShipmentForOrder($order);
        $otherShipment = $this->createShipmentForOrder($order);

        $return = ReturnRequest::create([
            'return_number' => 'RET-174-LOCKED-' . uniqid(),
            'order_id' => $order->id,
            'warehouse_id' => $this->createWarehouse()->id,
            'customer_id' => $user->id,
            'status' => 'pending',
            'reason' => 'carrier return',
            'requested_by' => $user->id,
            'requested_at' => now(),
            'shipment_id' => $linkedShipment->id,
            'provider_return_metadata' => ['carrier_reference' => 'LOCKED'],
        ]);

        Log::spy();
        $result = app(ReturnService::class)->linkCarrierReturn($otherShipment->fresh(), [
            'carrier_reference' => 'MUST-NOT-OVERWRITE',
            'new_key' => 'must-not-write',
        ]);

        $this->assertNull($result);
        $fresh = $return->fresh();
        $this->assertSame((int) $linkedShipment->id, (int) $fresh->shipment_id);
        $this->assertSame(['carrier_reference' => 'LOCKED'], $fresh->provider_return_metadata);
        Log::shouldNotHaveReceived('info', [
            'Carrier return linked to existing return request',
            \Mockery::any(),
        ]);
    }

    public function test_link_carrier_return_returns_null_when_no_suitable_request_exists(): void
    {
        $shipment = $this->createShipment();

        Log::spy();
        $result = app(ReturnService::class)->linkCarrierReturn($shipment->fresh(), [
            'carrier_reference' => 'NO-RETURN',
        ]);

        $this->assertNull($result);
        $this->assertSame(0, ReturnRequest::count());
        Log::shouldNotHaveReceived('info', [
            'Carrier return linked to existing return request',
            \Mockery::any(),
        ]);
    }

    private function createShipment(): Shipment
    {
        $order = $this->createOrder(\App\Models\User::factory()->create(['type' => 'customer'])->id);

        return Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    private function createShipmentForOrder(Order $order): Shipment
    {
        return Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    private function createWarehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'WH-RET-172-'.uniqid(),
            'name' => 'Return link warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);
    }

    private function createOrder(int $userId): Order
    {
        return Order::create([
            'user_id' => $userId,
            'name' => 'Return link order',
            'user_email' => 'return-172-'.uniqid().'@example.test',
            'user_phone' => '01000000000',
            'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
    }
}
