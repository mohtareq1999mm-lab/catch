<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Exceptions\InvalidLabelContentException;
use App\Models\Shipment;
use App\Models\ShipmentLabel;
use App\Services\Shipment\LabelStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class ShippingLabelStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        config(['shipping.labels.max_bytes' => 5 * 1024 * 1024]);
        config(['shipping.labels.allowed_formats' => ['pdf', 'png']]);
        config(['shipping.labels.disk' => 'private']);
    }

    public function test_valid_pdf_label_is_stored_privately(): void
    {
        $shipment = $this->createShipment();
        $service = app(LabelStorageService::class);

        $content = $this->pdfBytes();
        $label = $service->store($shipment, $content, 'label.pdf');

        $this->assertInstanceOf(ShipmentLabel::class, $label);
        $this->assertSame($shipment->id, $label->shipment_id);
        $this->assertSame('pdf', $label->format);
        $this->assertSame('application/pdf', $label->mime_type);
        $this->assertSame(strlen($content), $label->size_bytes);
        Storage::disk('private')->assertExists($label->path);
        $this->assertStringNotContainsString('/storage/', $label->path);
        $this->assertFalse(str_starts_with($label->path, 'public/'));
    }

    public function test_valid_png_label_is_stored_privately(): void
    {
        $shipment = $this->createShipment();
        $service = app(LabelStorageService::class);

        $content = $this->pngBytes();
        $label = $service->store($shipment, $content, 'label.png');

        $this->assertSame('png', $label->format);
        $this->assertSame('image/png', $label->mime_type);
        Storage::disk('private')->assertExists($label->path);
    }

    public function test_label_rejects_invalid_magic_bytes(): void
    {
        $shipment = $this->createShipment();
        $service = app(LabelStorageService::class);

        $this->expectException(InvalidLabelContentException::class);
        $service->store($shipment, 'not-a-real-pdf', 'label.pdf');
    }

    public function test_label_rejects_oversized_content(): void
    {
        config(['shipping.labels.max_bytes' => 16]);
        $shipment = $this->createShipment();
        $service = app(LabelStorageService::class);

        $this->expectException(InvalidLabelContentException::class);
        $service->store($shipment, $this->pdfBytes(), 'label.pdf');
    }

    public function test_label_rejects_disallowed_format(): void
    {
        $shipment = $this->createShipment();
        $service = app(LabelStorageService::class);

        $this->expectException(InvalidLabelContentException::class);
        $service->store($shipment, $this->gifBytes(), 'label.gif');
    }

    public function test_label_rejects_mime_extension_spoofing(): void
    {
        $shipment = $this->createShipment();
        $service = app(LabelStorageService::class);

        $this->expectException(InvalidLabelContentException::class);
        $service->store($shipment, $this->pngBytes(), 'label.pdf');
    }

    public function test_label_storage_fails_closed_for_public_disk(): void
    {
        config(['shipping.labels.disk' => 'public']);
        $shipment = $this->createShipment();
        $service = app(LabelStorageService::class);

        $this->expectException(InvalidLabelContentException::class);
        $service->store($shipment, $this->pdfBytes(), 'label.pdf');
    }

    public function test_label_is_accessible_only_via_authorized_short_lived_signed_url(): void
    {
        $shipment = $this->createShipment();
        $label = app(LabelStorageService::class)->store($shipment, $this->pdfBytes(), 'label.pdf');

        $url = URL::temporarySignedRoute('general.shipping.labels.show', now()->addMinutes(10), ['uuid' => $label->uuid]);
        $this->get($url)->assertOk();

        $unsigned = route('general.shipping.labels.show', ['uuid' => $label->uuid]);
        $this->get($unsigned)->assertStatus(403);
    }

    private function createShipment(): Shipment
    {
        $user = User::create([
            'name' => 'Label Test User',
            'email' => 'label-' . Str::lower(Str::random(8)) . '@example.test',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Label Test Customer',
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
            'status' => 'pending',
        ]);
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function pngBytes(): string
    {
        $png = "\x89PNG\r\n\x1a\n";
        $ihdr = "IHDR" . pack('N', 1) . pack('N', 1) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
        $png .= pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
        $idatData = "\x78\x9c\x63\x00\x01\x00\x00\x05\x00\x01";
        $idat = "IDAT" . $idatData;
        $png .= pack('N', strlen($idatData)) . $idat . pack('N', crc32($idat));
        $iend = "IEND";
        $png .= pack('N', 0) . $iend . pack('N', crc32($iend));
        return $png;
    }

    private function gifBytes(): string
    {
        return "GIF89a\x01\x00\x01\x00\x00\x00\x00\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";
    }
}
