<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Services\Shipment\ShipmentExceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class ShipmentExceptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_exception_moves_through_allowed_lifecycle_states_without_mutating_shipment(): void
    {
        $shipment = $this->createShipment('pending');
        $order = $shipment->order;
        $orderBefore = $order->fresh()->only(['status', 'payment_status', 'fulfillment_status']);
        $exception = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'UNKNOWN_STATUS',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'opened',
            'opened_at' => now(),
        ]);

        $service = app(ShipmentExceptionService::class);
        $investigator = $this->createUser('investigator');

        $investigating = $service->markInvestigating($exception, $investigator->id, 'checking carrier');
        $this->assertSame(ShipmentExceptionStatus::INVESTIGATING->value, $investigating->status);
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame($orderBefore, $order->fresh()->only(array_keys($orderBefore)));

        $resolved = $service->resolve($investigating, $investigator->id, 'carrier confirmed');
        $this->assertSame(ShipmentExceptionStatus::RESOLVED->value, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame($investigator->id, $resolved->resolved_by);
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame($orderBefore, $order->fresh()->only(array_keys($orderBefore)));

        $dismissedException = ShipmentException::create([
            'shipment_id' => $shipment->id,
            'type' => 'MODE_MISMATCH',
            'severity' => 'high',
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => 'opened',
            'opened_at' => now(),
        ]);

        $dismissed = $service->dismiss($dismissedException, $investigator->id, 'false positive');
        $this->assertSame(ShipmentExceptionStatus::DISMISSED->value, $dismissed->status);
        $this->assertNotNull($dismissed->resolved_at);
        $this->assertSame($investigator->id, $dismissed->resolved_by);
        $this->assertSame('pending', $shipment->fresh()->status);
        $this->assertSame($orderBefore, $order->fresh()->only(array_keys($orderBefore)));
    }

    private function createUser(string $prefix): User
    {
        return User::create([
            'name' => ucfirst($prefix),
            'email' => $prefix.'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);
    }

    private function createShipment(string $status): Shipment
    {
        $user = $this->createUser('customer');
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Exception Customer',
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
            'status' => $status,
        ]);
    }
}
