<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * #137: ShipmentService owns status, canonical timeline and order mirror.
 */
class ShipmentStatusAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OrderTrackingEvent::flushEventListeners();
        parent::tearDown();
    }

    public function test_timeline_failure_rolls_back_status_and_mirror(): void
    {
        [$order, $shipment] = $this->fixture();

        OrderTrackingEvent::creating(function (): never {
            throw new \RuntimeException('timeline unavailable');
        });

        try {
            app(ShipmentService::class)->updateStatus($shipment->id, 'picked_up');
            $this->fail('Timeline failure must abort the transition.');
        } catch (\Throwable $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e;
            }
            $this->assertSame('timeline unavailable', $e->getMessage());
        }

        $this->assertSame('label_created', $shipment->fresh()->status);
        $this->assertSame('label_created', $order->fresh()->shipment_status);
        $this->assertSame(0, OrderTrackingEvent::count());
    }

    public function test_status_change_event_is_after_commit_and_carries_persisted_timeline_identity(): void
    {
        [$order, $shipment] = $this->fixture();

        $seen = [];
        Event::listen(ShipmentStatusChanged::class, function (ShipmentStatusChanged $event) use (&$seen): void {
            $seen[] = $event;
        });

        // A rolled-back outer transaction must suppress the deferred event and
        // every write made by the canonical service inside its savepoint.
        try {
            DB::beginTransaction();
            app(ShipmentService::class)->updateStatus($shipment->id, 'picked_up');
            $this->assertCount(0, $seen, 'Event must wait for the outer commit.');
            throw new \RuntimeException('rollback outer transaction');
        } catch (\RuntimeException $e) {
            DB::rollBack();
        }

        $this->assertCount(0, $seen);
        $this->assertSame('label_created', $shipment->fresh()->status);
        $this->assertSame('label_created', $order->fresh()->shipment_status);
        $this->assertSame(0, OrderTrackingEvent::count());

        app(ShipmentService::class)->updateStatus($shipment->id, 'picked_up');

        $this->assertCount(1, $seen);
        $event = $seen[0];
        $timeline = OrderTrackingEvent::firstOrFail();

        $this->assertSame($timeline->id, $event->timelineEventId);
        $this->assertSame($shipment->id, $event->shipmentId);
        $this->assertSame($order->id, $event->orderId);
        $this->assertSame('label_created', $event->oldStatus);
        $this->assertSame('picked_up', $event->newStatus);
        $this->assertNotSame('', $event->source);
        $this->assertSame('shipment.status_changed', $timeline->event_type);
        $this->assertSame('picked_up', $shipment->fresh()->status);
        $this->assertSame('picked_up', $order->fresh()->shipment_status);
    }

    public function test_controllers_listeners_and_resources_do_not_write_shipment_status_or_timeline(): void
    {
        $roots = [
            app_path('Http/Controllers'),
            app_path('Listeners'),
            app_path('Http/Resources'),
        ];

        foreach ($roots as $root) {
            foreach ($this->phpFiles($root) as $file) {
                $source = file_get_contents($file);
                $this->assertDoesNotMatchRegularExpression(
                    '/(?:->update\s*\(|->fill\s*\()[^\n;]*[\'"]shipment_status[\'"]\s*=>/s',
                    $source,
                    "Mirror write in {$file}",
                );
                $this->assertDoesNotMatchRegularExpression(
                    '/->shipment_status\s*=/',
                    $source,
                    "Mirror write in {$file}",
                );

                if (str_contains(basename($file), 'Shipment')) {
                    $this->assertDoesNotMatchRegularExpression(
                        '/->update\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
                        $source,
                        "Direct shipment status write in {$file}",
                    );
                }
            }
        }

        foreach ($this->phpFiles(app_path('Listeners/Shipment')) as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('OrderTrackingEvent::', $source, "Timeline write in {$file}");
            $this->assertStringNotContainsString("'shipment_status' =>", $source, "Mirror write in {$file}");
        }
    }

    /** @return array{0: Order, 1: Shipment} */
    private function fixture(): array
    {
        $user = User::create([
            'name' => 'Shipment Authority User',
            'email' => 'shipment-authority-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Shipment Authority Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'shipment_status' => 'label_created',
            'tracking_number' => 'TRK-0',
            'courier_name' => 'DHL',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'status' => 'label_created',
            'tracking_number' => 'TRK-0',
            'courier' => 'DHL',
        ]);

        return [$order, $shipment];
    }

    /** @return string[] */
    private function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
