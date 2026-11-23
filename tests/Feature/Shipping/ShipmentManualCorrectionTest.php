<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Permission as MarvelPermission;
use Marvel\Database\Models\User;
use Tests\TestCase;

class ShipmentManualCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_correction_requires_authorization_and_audits_change(): void
    {
        $actor = $this->authorizedUser();
        $shipment = $this->createShipment();

        $response = $this->actingAs($actor, 'sanctum')->postJson("/api/v1/admin/shipments/{$shipment->id}/manual-correction", [
            'status' => 'picked_up',
            'reason' => 'Carrier support confirmed pickup manually.',
        ]);

        $response->assertStatus(200);
        $shipment->refresh();
        $this->assertSame('picked_up', $shipment->status);

        $this->assertDatabaseHas('shipment_manual_corrections', [
            'shipment_id' => $shipment->id,
            'actor_id' => $actor->id,
            'old_status' => 'label_created',
            'new_status' => 'picked_up',
            'reason' => 'Carrier support confirmed pickup manually.',
        ]);

        $audit = \DB::table('shipment_manual_corrections')->where('shipment_id', $shipment->id)->first();
        $this->assertNotNull($audit->timeline_event_id);
        $this->assertDatabaseHas('order_tracking_events', [
            'id' => $audit->timeline_event_id,
            'order_id' => $shipment->order_id,
            'new_status' => 'picked_up',
            'source' => 'manual_correction',
        ]);
    }

    public function test_manual_correction_is_forbidden_without_permission(): void
    {
        $actor = $this->userWithoutPermission();
        $shipment = $this->createShipment();

        $this->actingAs($actor, 'sanctum')->postJson("/api/v1/admin/shipments/{$shipment->id}/manual-correction", [
            'status' => 'picked_up',
            'reason' => 'Should not work.',
        ])->assertStatus(403);

        $this->assertDatabaseCount('shipment_manual_corrections', 0);
    }

    private function authorizedUser(): User
    {
        $user = $this->userWithoutPermission();
        $permission = MarvelPermission::firstOrCreate(['name' => 'wms-shipments.manual-correct', 'guard_name' => 'api']);
        $user->givePermissionTo($permission);
        return $user;
    }

    private function userWithoutPermission(): User
    {
        return User::create([
            'name' => 'Manual Correction Actor',
            'email' => 'manual-correction-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);
    }

    private function createShipment(): Shipment
    {
        $customer = User::create([
            'name' => 'Manual Correction Customer',
            'email' => 'manual-customer-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = Order::create([
            'user_id' => $customer->id,
            'name' => 'Manual Correction Order',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
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
            'status' => 'label_created',
            'provider_code' => 'test_carrier',
            'provider_mode' => 'sandbox',
        ]);
    }
}
