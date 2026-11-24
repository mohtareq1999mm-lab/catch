<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\DeliveryRepresentative;
use App\Models\Shipping\ShipmentAssignment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use Marvel\Database\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Tests\TestCase;

class DeliveryAttemptRepresentativeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shipping.delivery.max_attempts' => 3]);
    }

    public function test_representative_can_only_read_own_assignments(): void
    {
        [$repA, $userA] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        [$repB, $userB] = $this->makeRepUser('Rep B', 'rep-b@example.test');

        $shipmentA = $this->createShipment();
        $shipmentB = $this->createShipment();
        $assignmentA = $this->assign($shipmentA, $repA);
        $assignmentB = $this->assign($shipmentB, $repB);

        Sanctum::actingAs($userA);

        $this->getJson('/api/v1/representative/assignments')
            ->assertOk()
            ->assertJsonFragment(['id' => $assignmentA->id])
            ->assertJsonMissing(['id' => $assignmentB->id]);

        $this->getJson('/api/v1/representative/assignments/' . $assignmentA->id)->assertOk();
        $this->getJson('/api/v1/representative/assignments/' . $assignmentB->id)->assertStatus(404);
    }

    public function test_representative_cannot_act_on_other_representatives_assignment(): void
    {
        [$repA, $userA] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        [$repB] = $this->makeRepUser('Rep B', 'rep-b@example.test');
        $shipmentB = $this->createShipment();
        $assignmentB = $this->assign($shipmentB, $repB);

        Sanctum::actingAs($userA);

        $this->postJson('/api/v1/representative/assignments/' . $assignmentB->id . '/attempts', [
            'status' => 'customer_unavailable',
            'failure_reason' => 'No answer',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('shipment_delivery_attempts', ['shipment_assignment_id' => $assignmentB->id]);
    }

    public function test_attempt_number_is_unique_per_shipment_and_increments(): void
    {
        [$rep, $user] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        $shipment = $this->createShipment();
        $assignment = $this->assign($shipment, $rep);

        Sanctum::actingAs($user);

        $r1 = $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'customer_unavailable',
            'failure_reason' => 'No answer',
        ])->assertCreated();
        $r2 = $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'refused',
            'failure_reason' => 'Refused package',
        ])->assertCreated();

        $this->assertSame(1, $r1->json('data.attempt_number'));
        $this->assertSame(2, $r2->json('data.attempt_number'));
        $this->assertSame(2, ShipmentDeliveryAttempt::where('shipment_id', $shipment->id)->count());
        $this->assertDatabaseCount('shipment_delivery_attempts', 2);
    }

    public function test_attempt_cannot_exceed_configured_maximum_under_concurrent_requests(): void
    {
        config(['shipping.delivery.max_attempts' => 2]);
        [$rep, $user] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        $shipment = $this->createShipment();
        $assignment = $this->assign($shipment, $rep);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'customer_unavailable',
            'failure_reason' => 'No answer',
        ])->assertCreated();
        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'wrong_address',
            'failure_reason' => 'Address incorrect',
        ])->assertCreated();

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'refused',
            'failure_reason' => 'Refused',
        ])->assertStatus(422);

        $this->assertSame(2, ShipmentDeliveryAttempt::where('shipment_id', $shipment->id)->count());
    }

    public function test_attempt_taxonomy_is_fixed_and_other_requires_reason(): void
    {
        [$rep, $user] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        $shipment = $this->createShipment();
        $assignment = $this->assign($shipment, $rep);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'not_a_real_status',
            'failure_reason' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'other',
        ])->assertStatus(422)->assertJsonValidationErrors(['failure_reason']);

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'other',
            'failure_reason' => 'Gate closed by security',
        ])->assertCreated();
    }

    public function test_no_attempt_automatically_creates_a_return_shipment(): void
    {
        [$rep, $user] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        $shipment = $this->createShipment();
        $assignment = $this->assign($shipment, $rep);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'refused',
            'failure_reason' => 'Refused',
        ])->assertCreated();

        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseMissing('shipments', ['status' => 'returned', 'order_id' => $shipment->order_id]);
        // No return shipment / no extra shipment created
        $this->assertSame(1, Shipment::query()->count());
    }

    private function makeRepUser(string $name, string $email): array
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        $rep = DeliveryRepresentative::create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        return [$rep, $user];
    }

    private function assign(Shipment $shipment, DeliveryRepresentative $rep): ShipmentAssignment
    {
        return ShipmentAssignment::create([
            'shipment_id' => $shipment->id,
            'delivery_representative_id' => $rep->id,
            'status' => 'active',
            'assigned_at' => now(),
        ]);
    }

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
}

