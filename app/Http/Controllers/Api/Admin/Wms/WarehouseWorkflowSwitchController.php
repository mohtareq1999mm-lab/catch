<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Audit\ActivityAuditService;
use App\Services\Warehouse\WarehouseWorkflowSwitchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Settings;

/**
 * WAREHOUSE-WORKFLOW-SWITCH: HTTP adapter for the runtime ON/OFF switch of
 * the Warehouse Workflow (Orders-only mode when OFF).
 *
 * Thin adapter only: request validation + audit trail. Tier precedence,
 * effective-state resolution, and fail-closed semantics live entirely in
 * WarehouseWorkflowSwitchService. The response envelope follows the
 * existing settings endpoints (message/200/success/data).
 *
 * Route-level permission gates reuse the existing settings permissions
 * (view-settings / update-settings): this switch is store-wide
 * configuration, not a warehouse-scoped operation. read and update
 * therefore require no new permission seeding.
 */
class WarehouseWorkflowSwitchController extends WmsAdminController
{
    public function __construct(private WarehouseWorkflowSwitchService $switches)
    {
    }

    /**
     * GET /api/v1/admin/warehouse-workflow/status
     *
     * Both decision tiers separately + the effective state, so the admin UI
     * can show WHY the workflow is off (config master vs. admin toggle vs.
     * temporarily unreadable).
     */
    public function status(): JsonResponse
    {
        return $this->apiResponse(
            __('wms.workflow.switch_fetched'),
            200,
            true,
            $this->switches->describe()
        );
    }

    /**
     * PUT /api/v1/admin/warehouse-workflow/status
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workflow_enabled' => ['required', 'boolean'],
        ]);

        $change = $this->switches->update($validated);
        $state = $this->switches->describe();

        ActivityAuditService::recordModel(
            Settings::query()->first(),
            'warehouse_workflow_switch_updated',
            'settings',
            __('activity.warehouse_workflow_switch_updated'),
            old: ['workflow_enabled' => $change['old_workflow_enabled']],
            new: ['workflow_enabled' => $change['workflow_enabled']],
            context: ['effective_enabled' => $state['effective_enabled']],
        );

        return $this->apiResponse(
            __('wms.workflow.switch_updated'),
            200,
            true,
            $state
        );
    }
}
