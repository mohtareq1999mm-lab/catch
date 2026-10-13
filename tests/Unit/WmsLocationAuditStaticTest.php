<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WMS Location audit — static source regression (no DB).
 *
 * Locks the five confirmed audit fixes so a future edit cannot silently
 * regress them. Each assertion inspects the actual source file; behavior
 * proofs at the HTTP boundary live in
 * tests/Feature/Wms/WmsLocationAuditTest.php (requires an isolated DB —
 * never run against the shared MySQL `catch` database).
 *
 * - F1: LocationController::destroy must gate on the REAL open set
 *   (pending/assigned/picking — never the non-existent 'claimed') and must
 *   refuse cleanly when picking HISTORY references the location (FK
 *   RESTRICT on picking_tasks.product_location_id would otherwise 500).
 * - F2: barcode uniqueness must be validated in Create/Update requests
 *   (locations.barcode is DB-unique) so duplicates 422 instead of 500.
 * - F3: PickingTaskDetailResource must expose shelf identity
 *   (code/name/barcode/type/status) for the operator, additively.
 * - F4: recordPick must carry op_seq replay protection (confirm() parity)
 *   so a retried POST cannot double-count picked quantity.
 * - F5: transferStock must refuse draining a placement pinned by an open
 *   picking task (pre-lock fast fail + post-lock authoritative recheck).
 */
class WmsLocationAuditStaticTest extends TestCase
{
    private function src(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../../' . $relative);
    }

    public function test_location_destroy_gates_real_open_statuses(): void
    {
        $src = $this->src('app/Http/Controllers/Api/Admin/Wms/LocationController.php');

        $this->assertStringContainsString("'pending', 'assigned', 'picking'", $src);
        $this->assertStringNotContainsString("'pending', 'claimed'", $src);
    }

    public function test_location_destroy_refuses_picking_history_cleanly(): void
    {
        $src = $this->src('app/Http/Controllers/Api/Admin/Wms/LocationController.php');

        $this->assertStringContainsString('delete_history', $src);
        // Historical check spans every status (no status filter).
        $this->assertStringContainsString("\$hasHistory = \$placementIds->isNotEmpty() && PickingTask::whereIn('product_location_id', \$placementIds)", $src);
        $this->assertStringContainsString('->exists()', $src);
    }

    public function test_location_history_translation_exists_in_both_locales(): void
    {
        $this->assertStringContainsString('delete_history', $this->src('resources/lang/en/wms.php'));
        $this->assertStringContainsString('delete_history', $this->src('resources/lang/ar/wms.php'));
    }

    public function test_barcode_uniqueness_validated_on_create_and_update(): void
    {
        $create = $this->src('app/Http/Requests/Admin/Wms/CreateLocationRequest.php');
        $update = $this->src('app/Http/Requests/Admin/Wms/UpdateLocationRequest.php');

        $this->assertStringContainsString("Rule::unique('locations', 'barcode')", $create);
        $this->assertStringContainsString("Rule::unique('locations', 'barcode')->ignore(\$locationId)", $update);
    }

    public function test_picking_detail_exposes_shelf_identity_additively(): void
    {
        $src = $this->src('app/Http/Resources/Wms/PickingTaskDetailResource.php');

        foreach (["'code' => \$loc?->code", "'name' => \$loc?->name", "'barcode' => \$loc?->barcode", "'type' => \$loc?->type", "'status' => \$loc?->status"] as $field) {
            $this->assertStringContainsString($field, $src);
        }
        // Legacy id keys preserved for existing callers.
        $this->assertStringContainsString("'location_id' => \$this->productLocation->location_id", $src);
    }

    public function test_picking_commands_eager_load_placement_location(): void
    {
        $src = $this->src('app/Http/Controllers/Api/Admin/Wms/PickingController.php');

        $this->assertGreaterThanOrEqual(7, substr_count($src, 'productLocation.location'));
        // No bare productLocation load remains: neither the array-single
        // form ['...', 'productLocation'] nor loadMissing('productLocation').
        $this->assertStringNotContainsString("'productLocation']", $src);
        $this->assertStringNotContainsString("loadMissing('productLocation')", $src);
    }

    public function test_record_pick_carries_op_seq_replay_protection(): void
    {
        $request = $this->src('app/Http/Requests/Admin/Wms/RecordPickRequest.php');
        $service = $this->src('app/Services/Fulfillment/BatchPickingService.php');
        $controller = $this->src('app/Http/Controllers/Api/Admin/Wms/PickingController.php');

        $this->assertStringContainsString("'op_seq'", $request);
        $this->assertStringContainsString('?int $opSeq = null', $service);
        $this->assertStringContainsString('Record pick replay ignored', $service);
        $this->assertStringContainsString("'op_seq' => max(\$opSeq, (int) \$locked->op_seq)", $service);
        $this->assertStringContainsString("\$request->validated('op_seq')", $controller);
    }

    public function test_transfer_refuses_open_task_pinned_placement(): void
    {
        $src = $this->src('app/Services/Inventory/StockOperationService.php');

        $this->assertStringContainsString('transfer_open_task', $src);
        // Pre-lock fast fail + post-lock authoritative recheck.
        $this->assertGreaterThanOrEqual(2, substr_count($src, "whereIn('status', ['pending', 'assigned', 'picking'])"));
        $this->assertStringContainsString('transfer_open_task', $this->src('resources/lang/en/wms.php'));
        $this->assertStringContainsString('transfer_open_task', $this->src('resources/lang/ar/wms.php'));
    }

    public function test_central_authority_invariants_untouched(): void
    {
        // The audit fixes must not move stock ownership: assignPlacement
        // still writes only product_location_id, syncWithStock stays
        // MONITOR-only, and the transfer authority before/after proof stays.
        $assign = $this->src('app/Services/Fulfillment/FulfillmentService.php');
        $sync = $this->src('app/Services/Fulfillment/ProductLocationService.php');
        $transfer = $this->src('app/Services/Inventory/StockOperationService.php');

        $this->assertStringContainsString("\$locked->update(['product_location_id' => \$target->id])", $assign);
        $this->assertStringContainsString('MONITOR, never a throwing gate', $sync);
        $this->assertStringContainsString('transfer_altered', $transfer);
    }
}
