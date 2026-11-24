<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\DeliveryAttemptStatus;
use App\Models\Shipment;
use App\Models\Shipping\DeliveryRepresentative;
use App\Models\Shipping\ShipmentAssignment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Services\Shipping\DeliveryAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeliveryAttemptRepresentativeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shipping.delivery.max_attempts' => 3]);
    }

    public function test_enum_keeps_fixed_values_and_canonical_normalization(): void
    {
        $this->assertSame([
            'delivered',
            'customer_unavailable',
            'wrong_address',
            'refused',
            'cash_not_ready',
            'other',
        ], DeliveryAttemptStatus::values());

        $this->assertSame([
            'status' => 'other',
            'failure_reason' => 'Gate closed by security',
            'error' => null,
            'error_field' => null,
        ], DeliveryAttemptStatus::normalizeAttempt([
            'status' => '  other  ',
            'failure_reason' => '  Gate closed by security  ',
        ]));

        $this->assertSame([
            'status' => 'delivered',
            'failure_reason' => null,
            'error' => null,
            'error_field' => null,
        ], DeliveryAttemptStatus::normalizeAttempt([
            'status' => 'delivered',
            'failure_reason' => 'must be ignored',
        ]));
    }

    public function test_representative_cannot_cross_assignment_boundary_through_attempt_endpoint(): void
    {
        [$repA, $userA] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        [$repB] = $this->makeRepUser('Rep B', 'rep-b@example.test');
        $shipmentB = $this->createShipment();
        $assignmentB = $this->assign($shipmentB, $repB);

        Sanctum::actingAs($userA);

        // #164/#173: shipment_assignments is the authoritative scope; no
        // warehouse or staff role can widen it.
        $this->postJson('/api/v1/representative/assignments/' . $assignmentB->id . '/attempts', [
            'status' => 'customer_unavailable',
            'failure_reason' => 'No answer',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('shipment_delivery_attempts', ['shipment_assignment_id' => $assignmentB->id]);
    }

    public function test_ownership_is_rechecked_after_authorization_under_lock(): void
    {
        [$repA, $userA] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        [$repB] = $this->makeRepUser('Rep B', 'rep-b@example.test');
        $shipment = $this->createShipment();
        $assignment = $this->assign($shipment, $repA);

        Sanctum::actingAs($userA);

        $reassigned = false;
        DB::listen(function ($query) use (&$reassigned, $assignment, $repB): void {
            // Reassign after the pre-lock authorization read but before the
            // locked recheck reads the row. Triggering on the locking query
            // would be too late: the row is already selected by then.
            if ($reassigned
                || !str_contains($query->sql, 'shipment_assignments')
                || str_contains($query->sql, 'for update')
                || str_starts_with(strtolower(ltrim($query->sql)), 'update')
            ) {
                return;
            }

            $reassigned = true;
            DB::table('shipment_assignments')
                ->where('id', $assignment->id)
                ->update(['delivery_representative_id' => $repB->id]);
        });

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'customer_unavailable',
            'failure_reason' => 'No answer',
        ])->assertStatus(404);

        $this->assertTrue($reassigned, 'The assignment must be reassigned before the locked write.');
        $this->assertDatabaseMissing('shipment_delivery_attempts', ['shipment_assignment_id' => $assignment->id]);
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

    public function test_attempt_cannot_exceed_configured_maximum(): void
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
        ])->assertStatus(422)
            ->assertJsonValidationErrors('attempt_number')
            ->assertJsonFragment([
                'attempt_number' => [__('shipping.delivery_attempt.max_exceeded', ['max' => 2])],
            ]);

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
        ])->assertStatus(422)
            ->assertJsonValidationErrors('status')
            ->assertJsonFragment(['status' => [__('shipping.delivery_attempt.invalid_status')]]);

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'other',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('failure_reason')
            ->assertJsonFragment(['failure_reason' => [__('shipping.delivery_attempt.reason_required')]]);

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'other',
            'failure_reason' => 'Gate closed by security',
        ])->assertCreated();

        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'delivered',
            'failure_reason' => 'must be ignored',
        ])->assertCreated()->assertJsonPath('data.failure_reason', null);
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
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_inactive_assignment_is_readable_as_history_but_not_writable(): void
    {
        [$rep, $user] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        $assignment = $this->assign($this->createShipment(), $rep);
        $assignment->update(['status' => 'completed']);

        // #173 owns the read endpoint; #164 keeps the policy semantics aligned:
        // history is visible, but attempts require an active assignment.
        $this->assertTrue(Gate::forUser($user)->allows('view', $assignment));
        $this->assertFalse(Gate::forUser($user)->allows('recordAttempt', $assignment));

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/representative/assignments/' . $assignment->id . '/attempts', [
            'status' => 'refused',
            'failure_reason' => 'Refused',
        ])->assertStatus(404);
    }

    public function test_service_messages_use_translation_keys(): void
    {
        [$rep, $user] = $this->makeRepUser('Rep A', 'rep-a@example.test');
        $assignment = $this->assign($this->createShipment(), $rep);
        $service = app(DeliveryAttemptService::class);

        try {
            $service->recordAttempt($rep->id, $assignment, ['status' => 'other']);
            $this->fail('Missing reason must be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('shipping.delivery_attempt.reason_required'),
                $e->errors()['failure_reason'][0]
            );
        }

        $assignment->update(['status' => 'completed']);
        try {
            $service->recordAttempt($rep->id, $assignment, ['status' => 'refused']);
            $this->fail('Inactive assignment must be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('shipping.delivery_attempt.assignment_inactive'),
                $e->errors()['assignment'][0]
            );
        }

        $orphan = User::factory()->create([
            'name' => 'Orphan',
            'email' => 'orphan@example.test',
        ]);
        try {
            $service->resolveRepresentative($orphan);
            $this->fail('Missing representative profile must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(__('shipping.delivery_attempt.representative_required'), $e->getMessage());
        }
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
            'status' => ShipmentAssignment::STATUS_ACTIVE,
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
