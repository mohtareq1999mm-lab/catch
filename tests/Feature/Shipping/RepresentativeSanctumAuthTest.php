<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipping\DeliveryRepresentative;
use App\Models\Shipping\ShipmentAssignment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Models\Shipping\ShipmentPod;
use App\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class RepresentativeSanctumAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_representative_profile_requires_a_linked_user(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeliveryRepresentative::query()->create([
            'name' => 'Rep No User',
            'is_active' => true,
        ]);
    }

    public function test_database_rejects_representative_without_user_when_model_guard_is_bypassed(): void
    {
        $this->expectException(QueryException::class);

        DB::table('delivery_representatives')->insert([
            'name' => 'Raw Rep No User',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_representative_with_unknown_user_when_model_guard_is_bypassed(): void
    {
        $this->expectException(QueryException::class);

        DB::table('delivery_representatives')->insert([
            'user_id' => 999999,
            'name' => 'Raw Rep Unknown User',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_representative_profile_user_link_is_unique(): void
    {
        $user = User::factory()->create();

        DeliveryRepresentative::query()->create([
            'name' => 'Rep One',
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DeliveryRepresentative::query()->create([
            'name' => 'Rep Two',
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    public function test_representative_authentication_reuses_existing_sanctum_guard(): void
    {
        [$rep, $user] = $this->makeRepresentative();

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/representative/me');

        $response->assertOk();
        $response->assertJsonPath('data.id', $rep->id);
        $response->assertJsonPath('data.user_id', $user->id);

        $this->assertSame('sanctum', config('auth.defaults.guard'));
    }

    public function test_representative_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/representative/me')->assertUnauthorized();
        $this->getJson('/api/v1/representative/assignments')->assertUnauthorized();
    }

    public function test_authenticated_non_representative_gets_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/representative/me')->assertForbidden();
        $this->getJson('/api/v1/representative/assignments')->assertForbidden();
    }

    public function test_inactive_representative_gets_forbidden(): void
    {
        [$rep, $user] = $this->makeRepresentative('inactive@example.test', false);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/representative/me');
        $response->assertForbidden();
        $this->assertSame($rep->id, $rep->fresh()->id);
    }

    public function test_representative_can_view_own_assignment_attempt_and_pod(): void
    {
        [$rep, $user] = $this->makeRepresentative();
        $assignment = $this->makeAssignment($rep);

        $attempt = ShipmentDeliveryAttempt::query()->create([
            'shipment_id' => $assignment->shipment_id,
            'shipment_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'failure_reason' => 'customer_unavailable',
        ]);

        $pod = ShipmentPod::query()->create([
            'shipment_id' => $assignment->shipment_id,
            'shipment_assignment_id' => $assignment->id,
            'shipment_delivery_attempt_id' => $attempt->id,
            'status' => 'captured',
            'recipient_name' => 'Recipient',
            'captured_at' => now(),
            'file_reference' => 'pods/test.jpg',
            'uploaded_by' => $user->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/representative/assignments/' . $assignment->id)
            ->assertOk()
            ->assertJsonPath('data.id', $assignment->id);

        $attemptResponse = $this->getJson('/api/v1/representative/attempts/' . $attempt->id)
            ->assertOk()
            ->assertJsonPath('data.id', $attempt->id);
        $this->assertArrayNotHasKey('failure_reason', $attemptResponse->json('data'));
        $this->assertArrayNotHasKey('metadata', $attemptResponse->json('data'));

        $podResponse = $this->getJson('/api/v1/representative/pods/' . $pod->id)
            ->assertOk()
            ->assertJsonPath('data.id', $pod->id);
        $this->assertArrayNotHasKey('file_reference', $podResponse->json('data'));
    }

    public function test_representative_assignments_are_scoped_to_own_records(): void
    {
        [$repA, $userA] = $this->makeRepresentative('a@example.test');
        [$repB] = $this->makeRepresentative('b@example.test');
        $assignmentA = $this->makeAssignment($repA);
        $this->makeAssignment($repB);

        Sanctum::actingAs($userA, ['*']);

        $response = $this->getJson('/api/v1/representative/assignments');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$assignmentA->id], $ids);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_representative_cannot_access_assignment_not_owned_by_them(): void
    {
        [$repA, $userA] = $this->makeRepresentative('a@example.test');
        [$repB] = $this->makeRepresentative('b@example.test');
        $assignmentB = $this->makeAssignment($repB);

        Sanctum::actingAs($userA, ['*']);

        $this->getJson('/api/v1/representative/assignments/' . $assignmentB->id)
            ->assertNotFound();
    }

    public function test_representative_cannot_act_on_other_representatives_attempt_or_pod(): void
    {
        [, $userA] = $this->makeRepresentative('a@example.test');
        [$repB] = $this->makeRepresentative('b@example.test');
        $assignmentB = $this->makeAssignment($repB);

        $attempt = ShipmentDeliveryAttempt::query()->create([
            'shipment_id' => $assignmentB->shipment_id,
            'shipment_assignment_id' => $assignmentB->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'failure_reason' => 'customer_unavailable',
        ]);

        $pod = ShipmentPod::query()->create([
            'shipment_id' => $assignmentB->shipment_id,
            'shipment_assignment_id' => $assignmentB->id,
            'shipment_delivery_attempt_id' => $attempt->id,
            'status' => 'captured',
            'recipient_name' => 'Recipient',
            'captured_at' => now(),
            'file_reference' => 'pods/test.jpg',
            'uploaded_by' => $userA->id,
        ]);

        Sanctum::actingAs($userA, ['*']);

        $this->getJson('/api/v1/representative/attempts/' . $attempt->id)->assertNotFound();
        $this->getJson('/api/v1/representative/pods/' . $pod->id)->assertNotFound();
    }

    public function test_representative_link_grants_no_staff_warehouse_or_admin_permissions(): void
    {
        [$rep, $user] = $this->makeRepresentative();

        $user->refresh();
        $rep->refresh();

        $this->assertSame($user->id, $rep->user_id);
        $this->assertFalse($user->hasRole('super_admin'));
        $this->assertFalse($user->hasRole('store_owner'));
        $this->assertFalse($user->hasRole('staff'));
        $this->assertEmpty($user->getAllPermissions()->pluck('name')->all());
    }

    public function test_representative_routes_are_wired_with_auth_and_representative_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.representative.me');
        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();
        $this->assertContains('api', $middleware);
        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains('representative', $middleware);

        $expected = [
            'api.representative.assignments.index',
            'api.representative.assignments.show',
            'api.representative.attempts.show',
            'api.representative.pods.show',
        ];
        foreach ($expected as $name) {
            $r = Route::getRoutes()->getByName($name);
            $this->assertNotNull($r, "Missing route {$name}");
            $this->assertContains('auth:sanctum', $r->gatherMiddleware());
            $this->assertContains('representative', $r->gatherMiddleware());
        }
    }

    /**
     * @return array{0: DeliveryRepresentative, 1: User}
     */
    private function makeRepresentative(string $email = 'rep@example.test', bool $active = true): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);

        $rep = DeliveryRepresentative::query()->create([
            'name' => 'Rep ' . $email,
            'email' => $email,
            'is_active' => $active,
            'user_id' => $user->id,
        ]);

        return [$rep, $user];
    }

    private function makeAssignment(DeliveryRepresentative $rep): ShipmentAssignment
    {
        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-' . random_int(10000, 99999),
        ]);

        $shipment = Shipment::query()->create([
            'order_id' => $order->id,
            'status' => 'pending',
        ]);

        return ShipmentAssignment::query()->create([
            'shipment_id' => $shipment->id,
            'delivery_representative_id' => $rep->id,
            'status' => 'active',
            'assigned_at' => now(),
        ]);
    }
}
