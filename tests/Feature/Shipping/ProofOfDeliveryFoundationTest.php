<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipment;
use App\Models\Shipping\ShipmentPod;
use App\Services\Shipping\ProofOfDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;
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

    public function test_proof_of_delivery_file_is_private_and_served_only_through_authorized_short_lived_links(): void
    {
        Storage::fake('private');
        $shipment = $this->createShipment();
        $uploader = User::factory()->create();

        $pod = app(ProofOfDeliveryService::class)->store($shipment, [
            'photo' => UploadedFile::fake()->image('pod.jpg'),
            'recipient_name' => 'Recipient',
            'captured_at' => now()->toIso8601String(),
        ], $uploader);

        Storage::disk('private')->assertExists($pod->file_reference);
        $this->assertStringStartsWith('shipment-pods/', $pod->file_reference);

        $url = app(ProofOfDeliveryService::class)->temporaryUrl($pod);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('/api/v1/general/shipments/pods/', $url);

        $this->get($url)->assertOk();

        $tampered = $url . 'tampered';
        $this->get($tampered)->assertForbidden();

        $unsigned = route('api.v1.shipments.pods.file', ['pod' => $pod->id]);
        $this->get($unsigned)->assertForbidden();
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

        $this->expectException(ValidationException::class);

        try {
            app(ProofOfDeliveryService::class)->store($shipment, [
                'photo' => UploadedFile::fake()->image('pod-2.jpg'),
                'recipient_name' => 'Recipient Two',
                'captured_at' => now()->toIso8601String(),
            ], $user);
        } finally {
            $this->assertSame(1, ShipmentPod::query()->where('shipment_id', $shipment->id)->where('status', 'successful')->count());
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
}
