<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P6 (§18/D10): the seeded international flow gains `export_processing`
     * between `packed` and `shipped`:
     *   pending → processing → packed → export_processing → shipped → …
     *
     * Convergence semantics (honors the customization promise):
     *  - the export_processing catalog row is ensured (never duplicated);
     *  - a missing membership is appended;
     *  - the seeded flow is re-sequenced to the seed order ONLY when its
     *    membership set exactly matches the seed set (pristine or
     *    seed-complete flows). Flows carrying custom additions/removals
     *    keep their order; the addition lands additively with a log line.
     *
     * Fresh databases already converge via the base migration (which reads
     * the live flowsSeed()); this migration converges upgraded databases.
     * Reversible: down() removes the seeded membership (in-flight orders
     * keep their stage row; see note below).
     */
    public function up(): void
    {
        if (!Schema::hasTable('order_flows')
            || !Schema::hasTable('order_statuses')
            || !Schema::hasTable('order_flow_statuses')
        ) {
            return;
        }

        $flowId = DB::table('order_flows')->where('code', 'international')->value('id');

        if (!$flowId) {
            Log::warning('International flow alignment skipped: no seeded international flow. OrderFlowSeeder will create it.');

            return;
        }

        $this->ensureCatalogRow();

        $seed = $this->seedOrder();
        $statusIds = DB::table('order_statuses')->pluck('id', 'code');

        // Append the new member when absent (additive, never duplicated).
        $exportId = $statusIds['export_processing'] ?? null;

        if ($exportId === null) {
            throw new \RuntimeException('International flow alignment aborted: export_processing catalog row is missing.');
        }

        $hasMember = DB::table('order_flow_statuses')
            ->where('flow_id', $flowId)
            ->where('status_id', $exportId)
            ->exists();

        if (!$hasMember) {
            $maxSort = (int) (DB::table('order_flow_statuses')->where('flow_id', $flowId)->max('sort_order') ?? 0);
            DB::table('order_flow_statuses')->insert([
                'flow_id' => $flowId,
                'status_id' => $exportId,
                'sort_order' => $maxSort + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Exact convergence only for seed-complete flows; customized flows
        // keep their order (addition stays additive).
        $current = DB::table('order_flow_statuses')
            ->join('order_statuses', 'order_statuses.id', '=', 'order_flow_statuses.status_id')
            ->where('order_flow_statuses.flow_id', $flowId)
            ->orderBy('order_flow_statuses.sort_order')
            ->pluck('order_statuses.code')
            ->all();

        if (count($current) === count($seed) && empty(array_diff($current, $seed))) {
            // Two-phase re-sequencing: the (flow_id, sort_order) unique key
            // forbids transient collisions, so rows park in a temp range
            // first, then land on their final positions.
            $sort = 1;
            foreach ($seed as $code) {
                DB::table('order_flow_statuses')
                    ->where('flow_id', $flowId)
                    ->where('status_id', $statusIds[$code])
                    ->update(['sort_order' => 1000 + $sort++, 'updated_at' => now()]);
            }
            $sort = 1;
            foreach ($seed as $code) {
                DB::table('order_flow_statuses')
                    ->where('flow_id', $flowId)
                    ->where('status_id', $statusIds[$code])
                    ->update(['sort_order' => $sort++, 'updated_at' => now()]);
            }

            Log::info('International flow aligned with export_processing.', ['flow_id' => $flowId]);
        } else {
            Log::info('International flow customized; export_processing added additively, order preserved.', [
                'flow_id' => $flowId,
                'current' => $current,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_flows')
            || !Schema::hasTable('order_statuses')
            || !Schema::hasTable('order_flow_statuses')
        ) {
            return;
        }

        $flowId = DB::table('order_flows')->where('code', 'international')->value('id');
        $exportId = DB::table('order_statuses')->where('code', 'export_processing')->value('id');

        if (!$flowId || !$exportId) {
            return;
        }

        // In-flight orders keep their stage row (FK-safe: nothing references
        // the membership row); their export_processing stage simply stops
        // being a linear successor, supervised exits still apply.
        $inFlight = (int) DB::table('orders')
            ->where('flow_id', $flowId)
            ->where('current_status_id', $exportId)
            ->count();

        if ($inFlight > 0) {
            Log::warning('International flow rollback: in-flight orders reference export_processing.', [
                'count' => $inFlight,
            ]);
        }

        DB::table('order_flow_statuses')
            ->where('flow_id', $flowId)
            ->where('status_id', $exportId)
            ->delete();
    }

    /**
     * @return array<int, string> §18 international stage order.
     */
    private function seedOrder(): array
    {
        return [
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
    }

    /**
     * The catalog row travels with the seed definition; ensure it (and its
     * aligned description) without touching anything else.
     */
    private function ensureCatalogRow(): void
    {
        $row = DB::table('order_statuses')->where('code', 'export_processing')->first();

        if (!$row) {
            DB::table('order_statuses')->insert([
                'code' => 'export_processing',
                'name' => json_encode(
                    \App\Services\OrderFlow\OrderFlowService::bilingualStatusName('export_processing', 'Export Processing'),
                    JSON_UNESCAPED_UNICODE
                ),
                'description' => 'Shipment prepared for export',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('order_statuses')->where('id', $row->id)->update([
            'description' => 'Shipment prepared for export',
            'updated_at' => now(),
        ]);
    }
};
