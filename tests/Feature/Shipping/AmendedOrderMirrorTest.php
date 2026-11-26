<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\Shipment;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * #189: the amended aggregate order mirror. ShipmentService is the only
 * writer; the aggregate is informational and never drives order lifecycle.
 */
class AmendedOrderMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_returned_never_overrides_an_active_outstanding_shipment(): void
    {
        $order = $this->order(['status' => Order::ORDER_STATUS_COMPLETED]);
        $completedAt = $order->completed_at;
        $returnedEta = Carbon::parse('2030-01-01 10:00:00');
        $activeEta = Carbon::parse('2030-01-02 11:00:00');
        $returned = $this->shipment($order, 'returned', $returnedEta);
        $active = $this->shipment($order, 'in_transit', $activeEta);

        // Both trigger directions must produce the same active aggregate.
        app(ShipmentService::class)->syncOrderMirror($returned);
        $this->assertSame('in_transit', $order->fresh()->shipment_status);
        $this->assertSame($activeEta->toDateTimeString(), $this->eta($order));

        app(ShipmentService::class)->syncOrderMirror($active);
        $this->assertSame('in_transit', $order->fresh()->shipment_status);
        $this->assertSame($activeEta->toDateTimeString(), $this->eta($order));
        $this->assertNull($order->fresh()->actual_delivery_at);
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame($completedAt?->toDateTimeString(), $order->fresh()->completed_at?->toDateTimeString());
    }

    public function test_all_cancelled_produces_cancelled_without_lifecycle_transition(): void
    {
        $order = $this->order(['status' => 'processing']);
        $completedAt = $order->completed_at;
        $first = $this->shipment($order, 'cancelled', Carbon::parse('2030-01-01 10:00:00'), Carbon::parse('2029-12-01 10:00:00'));
        $second = $this->shipment($order, 'cancelled');

        app(ShipmentService::class)->syncOrderMirror($second);

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->shipment_status);
        $this->assertNull($fresh->estimated_delivery_at);
        $this->assertNull($fresh->actual_delivery_at);
        $this->assertSame('processing', $fresh->status);
        $this->assertSame($completedAt?->toDateTimeString(), $fresh->completed_at?->toDateTimeString());
        $this->assertSame($first->status, 'cancelled');
    }

    public function test_all_returned_produces_returned_without_lifecycle_transition(): void
    {
        $order = $this->order(['status' => Order::ORDER_STATUS_COMPLETED]);
        $completedAt = $order->completed_at;
        $order->update([
            'actual_delivery_at' => Carbon::parse('2029-12-01 10:00:00'),
            'estimated_delivery_at' => Carbon::parse('2030-01-01 10:00:00'),
        ]);
        $first = $this->shipment($order, 'returned', Carbon::parse('2030-01-03 10:00:00'));
        $second = $this->shipment($order, 'returned', Carbon::parse('2030-01-04 10:00:00'));

        app(ShipmentService::class)->syncOrderMirror($first);

        $fresh = $order->fresh();
        $this->assertSame('returned', $fresh->shipment_status);
        $this->assertNull($fresh->estimated_delivery_at);
        $this->assertNull($fresh->actual_delivery_at);
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $fresh->status);
        $this->assertSame($completedAt?->toDateTimeString(), $fresh->completed_at?->toDateTimeString());
        $this->assertSame($second->status, 'returned');
    }

    /**
     * @dataProvider outstandingPrecedenceProvider
     */
    public function test_outstanding_precedence_uses_named_states_from_outstanding_set_only(string $status, string $expected): void
    {
        $order = $this->order();
        $eta = Carbon::parse('2030-01-02 11:00:00');
        $returned = $this->shipment($order, 'returned', Carbon::parse('2030-01-01 10:00:00'));
        $outstanding = $this->shipment($order, $status, $status === 'failed_delivery' ? null : $eta);

        app(ShipmentService::class)->syncOrderMirror($returned);

        $this->assertSame($expected, $order->fresh()->shipment_status);
        $this->assertSame($status === 'failed_delivery' ? null : $eta->toDateTimeString(), $this->eta($order));
        $this->assertNull($order->fresh()->actual_delivery_at);
        $this->assertNotNull($outstanding->fresh());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function outstandingPrecedenceProvider(): array
    {
        return [
            'failed delivery outranks delayed' => ['failed_delivery', 'failed_delivery'],
            'delayed outranks out for delivery' => ['delayed', 'delayed'],
            'out for delivery outranks in transit' => ['out_for_delivery', 'out_for_delivery'],
            'in transit outranks picked up' => ['in_transit', 'in_transit'],
            'picked up outranks label created' => ['picked_up', 'picked_up'],
            'label created outranks pending' => ['label_created', 'label_created'],
            'pending is last' => ['pending', 'pending'],
        ];
    }

    public function test_precedence_is_chosen_from_outstanding_set_not_returned_set(): void
    {
        $order = $this->order();
        $this->shipment($order, 'returned', Carbon::parse('2030-01-01 10:00:00'));
        $this->shipment($order, 'in_transit', Carbon::parse('2030-01-02 10:00:00'));
        $trigger = $this->shipment($order, 'delayed', Carbon::parse('2030-01-03 10:00:00'));

        app(ShipmentService::class)->syncOrderMirror($trigger);

        $this->assertSame('delayed', $order->fresh()->shipment_status);
        $this->assertSame('2030-01-03 10:00:00', $this->eta($order));
    }

    public function test_aggregate_eta_is_unavailable_when_any_outstanding_shipment_lacks_eta(): void
    {
        $order = $this->order();
        $this->shipment($order, 'in_transit', Carbon::parse('2030-01-02 10:00:00'));
        $trigger = $this->shipment($order, 'in_transit');

        app(ShipmentService::class)->syncOrderMirror($trigger);

        $this->assertSame('in_transit', $order->fresh()->shipment_status);
        $this->assertNull($order->fresh()->estimated_delivery_at);
    }

    public function test_failed_delivery_eta_is_unavailable_without_freshness_evidence(): void
    {
        $order = $this->order();
        $trigger = $this->shipment($order, 'failed_delivery', Carbon::parse('2030-01-02 10:00:00'));
        $this->shipment($order, 'in_transit', Carbon::parse('2030-01-03 10:00:00'));

        app(ShipmentService::class)->syncOrderMirror($trigger);

        $this->assertSame('failed_delivery', $order->fresh()->shipment_status);
        $this->assertNull($order->fresh()->estimated_delivery_at);
    }

    public function test_aggregate_eta_is_max_of_reliable_outstanding_etas(): void
    {
        $order = $this->order();
        $this->shipment($order, 'in_transit', Carbon::parse('2030-01-02 10:00:00'));
        $this->shipment($order, 'delayed', Carbon::parse('2030-01-05 10:00:00'));
        $trigger = $this->shipment($order, 'picked_up', Carbon::parse('2030-01-04 10:00:00'));

        app(ShipmentService::class)->syncOrderMirror($trigger);

        $this->assertSame('delayed', $order->fresh()->shipment_status);
        $this->assertSame('2030-01-05 10:00:00', $this->eta($order));
    }

    public function test_delivery_timestamp_is_max_delivered_at_only_for_delivered_aggregate(): void
    {
        $order = $this->order(['status' => Order::ORDER_STATUS_COMPLETED]);
        $completedAt = $order->completed_at;
        $firstDelivered = $this->shipment($order, 'delivered', null, Carbon::parse('2029-12-01 10:00:00'));
        $this->shipment($order, 'delivered', null, Carbon::parse('2029-12-03 12:00:00'));

        app(ShipmentService::class)->syncOrderMirror($firstDelivered);

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->shipment_status);
        $this->assertSame('2029-12-03 12:00:00', $fresh->actual_delivery_at?->toDateTimeString());
        $this->assertNull($fresh->estimated_delivery_at);
        $this->assertSame($completedAt?->toDateTimeString(), $fresh->completed_at?->toDateTimeString());

        $pending = $this->shipment($order, 'pending');
        app(ShipmentService::class)->syncOrderMirror($pending);

        $reopened = $order->fresh();
        $this->assertSame('pending', $reopened->shipment_status);
        $this->assertNull($reopened->actual_delivery_at);
        $this->assertNull($reopened->estimated_delivery_at);
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $reopened->status);
        $this->assertSame($completedAt?->toDateTimeString(), $reopened->completed_at?->toDateTimeString());
    }

    public function test_returned_plus_delivered_is_returned_and_clears_delivery_timestamp(): void
    {
        $order = $this->order(['status' => Order::ORDER_STATUS_COMPLETED]);
        $completedAt = $order->completed_at;
        $order->update(['actual_delivery_at' => Carbon::parse('2029-12-01 10:00:00')]);
        $delivered = $this->shipment($order, 'delivered', null, Carbon::parse('2029-12-01 10:00:00'));
        $returned = $this->shipment($order, 'returned', Carbon::parse('2030-01-01 10:00:00'));

        app(ShipmentService::class)->syncOrderMirror($returned);

        $fresh = $order->fresh();
        $this->assertSame('returned', $fresh->shipment_status);
        $this->assertNull($fresh->estimated_delivery_at);
        $this->assertNull($fresh->actual_delivery_at);
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $fresh->status);
        $this->assertSame($completedAt?->toDateTimeString(), $fresh->completed_at?->toDateTimeString());
        $this->assertSame('delivered', $delivered->fresh()->status);
    }

    public function test_no_shipments_leaves_mirror_unchanged(): void
    {
        $order = $this->order();
        $order->update([
            'shipment_status' => 'delivered',
            'estimated_delivery_at' => Carbon::parse('2030-01-02 10:00:00'),
            'actual_delivery_at' => Carbon::parse('2029-12-01 10:00:00'),
        ]);
        $shipment = $this->shipment($order, 'in_transit', Carbon::parse('2030-01-03 10:00:00'));
        $shipment->delete();

        app(ShipmentService::class)->syncOrderMirror($shipment);

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->shipment_status);
        $this->assertSame('2030-01-02 10:00:00', $fresh->estimated_delivery_at?->toDateTimeString());
        $this->assertSame('2029-12-01 10:00:00', $fresh->actual_delivery_at?->toDateTimeString());
    }

    public function test_mirror_sync_dispatches_no_lifecycle_event(): void
    {
        $order = $this->order();
        $trigger = $this->shipment($order, 'returned');

        \Illuminate\Support\Facades\Event::fake([ShipmentStatusChanged::class]);
        app(ShipmentService::class)->syncOrderMirror($trigger);

        \Illuminate\Support\Facades\Event::assertNotDispatched(ShipmentStatusChanged::class);
    }

    public function test_only_shipment_service_writes_the_order_mirror(): void
    {
        $canonical = realpath(app_path('Services/Shipment/ShipmentService.php'));
        $source = (string) file_get_contents((string) $canonical);
        $this->assertStringContainsString('function syncOrderMirror(', $source);
        $this->assertStringContainsString('function applyOrderMirror(', $source);

        foreach ($this->phpFiles(app_path()) as $file) {
            if (realpath($file) === $canonical) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('syncOrderMirror(', $contents, "Mirror writer call in {$file}");
            $this->assertStringNotContainsString('applyOrderMirror(', $contents, "Mirror writer call in {$file}");
            if (str_contains($file, DIRECTORY_SEPARATOR.'Listeners'.DIRECTORY_SEPARATOR)) {
                foreach (['shipment_status', 'actual_delivery_at', 'estimated_delivery_at'] as $column) {
                    $this->assertStringNotContainsString("'{$column}' =>", $contents, "Listener mirror write in {$file}");
                }
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function order(array $overrides = []): Order
    {
        $user = User::create([
            'name' => 'Mirror User',
            'email' => 'mirror-'.Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt('password'),
        ]);

        return Order::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Mirror Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'shipment_status' => 'label_created',
            'completed_at' => Carbon::parse('2029-11-01 10:00:00'),
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
    }

    private function shipment(
        Order $order,
        string $status,
        ?Carbon $estimatedDeliveryAt = null,
        ?Carbon $deliveredAt = null,
    ): Shipment {
        return Shipment::create([
            'order_id' => $order->id,
            'status' => $status,
            'tracking_number' => 'TRK-'.Str::upper(Str::random(12)),
            'courier' => 'TestCourier',
            'estimated_delivery_at' => $estimatedDeliveryAt,
            'delivered_at' => $deliveredAt,
        ]);
    }

    private function eta(Order $order): ?string
    {
        return $order->fresh()->estimated_delivery_at?->toDateTimeString();
    }

    /** @return string[] */
    private function phpFiles(string $directory): array
    {
        $files = [];
        if (!is_dir($directory)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
