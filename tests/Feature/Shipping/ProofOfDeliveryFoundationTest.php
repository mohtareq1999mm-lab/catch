<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\ShipmentAssignment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Models\Shipping\ShipmentPod;
use App\Services\Shipping\ProofOfDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProofOfDeliveryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_proof_of_delivery_rejects_missing_photo(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(ProofOfDeliveryService::class)->store($shipment, [
            'recipient_name' => 'Recipient',
            'captured_at' => now()->toIso8601String(),
        ], $user);
    }

    public function test_proof_of_delivery_rejects_missing_recipient_name(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $user = User::factory()->create();

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->image('pod.jpg'),
                'captured_at' => now()->toIso8601String(),
            ], $user);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recipient_name', $e->errors());
        }
    }

    public function test_proof_of_delivery_rejects_missing_capture_timestamp(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $user = User::factory()->create();

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->image('pod.jpg'),
                'recipient_name' => 'Recipient',
            ], $user);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('captured_at', $e->errors());
        }
    }

    public function test_proof_of_delivery_rejects_invalid_captured_at_format(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $user = User::factory()->create();

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->image('pod.jpg'),
                'recipient_name' => 'Recipient',
                'captured_at' => 'not-a-date',
            ], $user);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('captured_at', $e->errors());
        }
    }

    public function test_proof_of_delivery_rejects_non_image_and_oversized_photo(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $user = User::factory()->create();

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->create('pod.pdf', 10, 'application/pdf'),
                'recipient_name' => 'Recipient',
                'captured_at' => now()->toIso8601String(),
            ], $user);
            $this->fail('Expected validation exception for non-image.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photo', $e->errors());
        }

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->create('pod.jpg', 10241, 'image/jpeg'),
                'recipient_name' => 'Recipient',
                'captured_at' => now()->toIso8601String(),
            ], $user);
            $this->fail('Expected validation exception for oversized.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photo', $e->errors());
        }
    }

    public function test_proof_of_delivery_file_is_private_and_served_only_through_authorized_short_lived_links(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $uploader = $this->createShipmentViewer();

        $pod = app(ProofOfDeliveryService::class)->store($shipment, [
            'photo' => UploadedFile::fake()->image('pod.jpg'),
            'recipient_name' => 'Recipient',
            'captured_at' => now()->toIso8601String(),
        ], $uploader);

        Storage::disk('private')->assertExists($pod->file_reference);
        $this->assertStringStartsWith('shipment-pods/', $pod->file_reference);

        $url = app(ProofOfDeliveryService::class)->temporaryUrl($pod, $uploader);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('uid=', $url);
        $this->assertStringContainsString('/api/v1/general/shipments/pods/', $url);

        $this->actingAs($uploader, 'sanctum')->get($url)->assertOk();

        $tampered = $url . 'tampered';
        $this->actingAs($uploader, 'sanctum')->get($tampered)->assertForbidden();

        $unsigned = route('api.v1.shipments.pods.file', ['pod' => $pod->id, 'uid' => $uploader->id]);
        $this->actingAs($uploader, 'sanctum')->get($unsigned)->assertForbidden();

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->get($url)->assertForbidden();

        $this->get($url)->assertForbidden();
    }

    public function test_expired_signed_url_is_rejected(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $uploader = $this->createShipmentViewer();
        $pod = app(ProofOfDeliveryService::class)->store($shipment, [
            'photo' => UploadedFile::fake()->image('pod.jpg'),
            'recipient_name' => 'Recipient',
            'captured_at' => now()->toIso8601String(),
        ], $uploader);

        $expired = URL::temporarySignedRoute('api.v1.shipments.pods.file', now()->subMinute(), [
            'pod' => $pod->id,
            'uid' => $uploader->id,
        ]);

        $this->actingAs($uploader, 'sanctum')->get($expired)->assertForbidden();
    }

    public function test_at_most_one_successful_proof_of_delivery_per_shipment(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $user = User::factory()->create();

        app(ProofOfDeliveryService::class)->store($shipment, [
            'photo' => UploadedFile::fake()->image('pod-1.jpg'),
            'recipient_name' => 'Recipient One',
            'captured_at' => now()->toIso8601String(),
        ], $user);

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->image('pod-2.jpg'),
                'recipient_name' => 'Recipient Two',
                'captured_at' => now()->toIso8601String(),
            ], $user);
            $this->fail('Expected duplicate POD validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shipment_id', $e->errors());
        }

        $this->assertSame(1, ShipmentPod::query()->where('shipment_id', $shipment->id)->where('status', 'successful')->count());
    }

    public function test_duplicate_successful_pod_unique_violation_is_mapped_and_uploaded_file_cleaned(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $user = User::factory()->create();

        $uploadedPath = null;
        $listener = function (ShipmentPod $model) use (&$uploadedPath, $shipment): void {
            $uploadedPath = $model->file_reference;
            DB::table('shipment_pods')->insert([
                'shipment_id' => $shipment->id,
                'status' => 'successful',
                'recipient_name' => 'Race winner',
                'captured_at' => now(),
                'file_reference' => 'shipment-pods/' . $shipment->id . '/race.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
        ShipmentPod::creating($listener);

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->image('pod.jpg'),
                'recipient_name' => 'Recipient',
                'captured_at' => now()->toIso8601String(),
            ], $user);
            $this->fail('Expected duplicate POD validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shipment_id', $e->errors());
        } finally {
            ShipmentPod::getEventDispatcher()->forget('eloquent.creating: ' . ShipmentPod::class, $listener);
        }

        $this->assertNotNull($uploadedPath);
        Storage::disk('private')->assertMissing($uploadedPath);
        // The simulated racing insert is rolled back with the failed store transaction.
        $this->assertSame(0, ShipmentPod::query()->where('shipment_id', $shipment->id)->where('status', 'successful')->count());
    }

    public function test_proof_of_delivery_rejects_cross_shipment_assignment_or_attempt_references(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $otherShipment = $this->createShipment();
        $user = User::factory()->create();

        $rep = \App\Models\Shipping\DeliveryRepresentative::query()->create(['name' => 'Rep', 'is_active' => true]);
        $otherAssignment = ShipmentAssignment::query()->create([
            'shipment_id' => $otherShipment->id,
            'delivery_representative_id' => $rep->id,
            'status' => 'active',
            'assigned_at' => now(),
        ]);
        $otherAttempt = ShipmentDeliveryAttempt::query()->create([
            'shipment_id' => $otherShipment->id,
            'shipment_assignment_id' => $otherAssignment->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'attempted_at' => now(),
        ]);

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->image('pod.jpg'),
                'recipient_name' => 'Recipient',
                'captured_at' => now()->toIso8601String(),
                'shipment_assignment_id' => $otherAssignment->id,
                'shipment_delivery_attempt_id' => $otherAttempt->id,
            ], $user);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $e) {
            $this->assertTrue(array_key_exists('shipment_assignment_id', $e->errors()) || array_key_exists('shipment_delivery_attempt_id', $e->errors()));
        }

        $assignment = ShipmentAssignment::query()->create([
            'shipment_id' => $shipment->id,
            'delivery_representative_id' => $rep->id,
            'status' => 'active',
            'assigned_at' => now(),
        ]);
        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->image('pod.jpg'),
                'recipient_name' => 'Recipient',
                'captured_at' => now()->toIso8601String(),
                'shipment_assignment_id' => $assignment->id,
                'shipment_delivery_attempt_id' => $otherAttempt->id,
            ], $user);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shipment_delivery_attempt_id', $e->errors());
        }
    }

    private function createShipment(): Shipment
    {
        $order = \Marvel\Database\Models\Order::query()->forceCreate([
            'tracking_number' => 'TRK-' . strtoupper(bin2hex(random_bytes(4))),
        ]);

        return Shipment::query()->create([
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    private function createShipmentViewer(): User
    {
        $user = User::factory()->create();
        $permission = SpatiePermission::findOrCreate(Permission::VIEW_SHIPMENTS, 'api');
        $role = Role::findOrCreate('shipment_viewer_test', 'api');
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        return $user;
    }
}
