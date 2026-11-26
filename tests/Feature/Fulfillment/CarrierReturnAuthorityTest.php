<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\DTOs\Shipping\CarrierReturnLink;
use App\Models\Fulfillment\ReturnItem;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Fulfillment\Warehouse;
use App\Models\User;
use App\Services\Fulfillment\CarrierReturnProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * #179: a carrier return callback is link evidence only. ReturnService stays
 * the only authority allowed to inspect, approve disposition, restock, or
 * complete a return.
 */
class CarrierReturnAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_carrier_return_link_never_inspects_approves_disposition_or_restocks(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Carrier return order',
            'user_email' => $user->email,
            'user_phone' => '01000000000',
            'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $warehouse = Warehouse::create([
            'code' => 'WH-179-'.uniqid(),
            'name' => 'Carrier return warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);

        $return = ReturnRequest::create([
            'return_number' => 'RET-179-'.uniqid(),
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $user->id,
            'status' => 'pending',
            'reason' => 'carrier return',
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        $linked = app(CarrierReturnProcessor::class)->link($return, new CarrierReturnLink(
            'internal',
            'internal',
            ['carrier_return_id' => 'RET-CARRIER-179'],
            null,
            'redacted://shipping/return/RET-CARRIER-179',
        ));

        $fresh = $linked->fresh();

        $this->assertSame('RET-CARRIER-179', $fresh->provider_return_metadata['carrier_reference']);
        $this->assertSame('internal', $fresh->provider_return_metadata['provider_code']);

        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->approved_by);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->inspected_by);
        $this->assertNull($fresh->inspected_at);
        $this->assertNull($fresh->restocked_at);
        $this->assertNull($fresh->completed_at);
        $this->assertSame(0, ReturnItem::query()->where('return_request_id', $return->id)->count());
        $this->assertArrayNotHasKey('disposition', $fresh->provider_return_metadata ?? []);
    }
}
