<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderFlowStatus;
use App\Models\OrderFlow\OrderStatus;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * P6 (§18/D10): the seeded international flow carries `export_processing`
 * between `packed` and `shipped`.
 *
 * - The seeder is idempotent: re-runs add no duplicate membership and
 *   preserve the §18 order.
 * - The new stage is a live linear successor: packed → export_processing
 *   → shipped walks through the canonical writer with the stage mirror.
 * - The alignment migration preserves customized flows (additive only).
 */
class ExportProcessingStageTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $customer;

    /** @var array<int, string> §18 international stage order. */
    private const EXPECTED = [
        'pending',
        'processing',
        'packed',
        'export_processing',
        'shipped',
        'in_transit',
        'arrived_at_destination_country',
        'customs_clearance',
        'customs_cleared',
        'local_carrier',
        'out_for_delivery',
        'delivered',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->customer = User::create([
            'name' => 'Export Customer',
            'email' => 'export-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function internationalStages(): array
    {
        return OrderFlowStatus::query()
            ->where('flow_id', $this->internationalFlowId())
            ->join('order_statuses', 'order_statuses.id', '=', 'order_flow_statuses.status_id')
            ->orderBy('order_flow_statuses.sort_order')
            ->pluck('order_statuses.code')
            ->all();
    }

    private function internationalFlowId(): int
    {
        return (int) OrderFlow::query()->where('shipping_type', 'international')->value('id');
    }

    private function statusId(string $code): int
    {
        return (int) OrderStatus::query()->where('code', $code)->value('id');
    }

    private function packedInternationalOrder(): Order
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'name' => 'Export Order',
            'user_phone' => '01000000000',
            'user_email' => $this->customer->email,
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
        ]);

        // Fixture arrangement (not a transition): international flow with
        // the packed starting stage + consistent mirror.
        $flowService = app(\App\Services\OrderFlow\OrderFlowService::class);
        $flowService->assignFlowToOrder($order->refresh(), 'international');
        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'packed',
            'current_status_id' => $flowService->statusIdForCode('packed'),
        ]);

        return $order->refresh();
    }

    /** @test */
    public function seeder_is_idempotent_and_keeps_section_order(): void
    {
        (new \Database\Seeders\OrderFlowSeeder)->run();
        (new \Database\Seeders\OrderFlowSeeder)->run();

        $this->assertSame(self::EXPECTED, $this->internationalStages());
        $this->assertSame(
            1,
            OrderFlowStatus::query()
                ->where('flow_id', $this->internationalFlowId())
                ->where('status_id', $this->statusId('export_processing'))
                ->count()
        );
    }

    /** @test */
    public function new_stage_walks_through_canonical_writer(): void
    {
        $order = $this->packedInternationalOrder();
        $service = app(OrderService::class);

        $service->changeOrderStatus(null, 'export_processing', $order->id);
        $fresh = $order->fresh();
        $this->assertSame('export_processing', $fresh->status);
        $this->assertSame($this->statusId('export_processing'), (int) $fresh->current_status_id);

        $service->changeOrderStatus(null, 'shipped', $order->id);
        $fresh = $order->fresh();
        $this->assertSame('shipped', $fresh->status);
        $this->assertSame($this->statusId('shipped'), (int) $fresh->current_status_id);
    }

    /** @test */
    public function alignment_preserves_customized_flows(): void
    {
        // Simulate an admin customization: an extra member appended.
        $flowId = $this->internationalFlowId();
        $customId = $this->statusId('customs_hold');
        $maxSort = (int) OrderFlowStatus::query()->where('flow_id', $flowId)->max('sort_order');
        OrderFlowStatus::query()->create([
            'flow_id' => $flowId,
            'status_id' => $customId,
            'sort_order' => $maxSort + 1,
        ]);

        /** @var object $migration */
        $migration = require database_path('migrations/2026_10_05_000001_align_international_flow_export_processing.php');
        $migration->up();

        $stages = $this->internationalStages();

        // Custom member preserved at the tail; seeded members keep §18 order.
        $this->assertSame('customs_hold', end($stages));
        $this->assertSame(self::EXPECTED, array_values(array_diff($stages, ['customs_hold'])));
    }
}
