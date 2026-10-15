<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Controllers\Controller;
use App\Http\Resources\Wms\WarehouseWorkflowVersionResource;
use App\Services\Warehouse\WarehouseWorkflowService;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Warehouse Workflow admin surface (Q-08 manage-warehouse-workflow).
 * Global config, no warehouse scoping (unlike WmsAdminController).
 * Thin: Request → Service → Resource. Never owns transactions.
 */
class WarehouseWorkflowController extends Controller
{
    use ApiResponse;

    public function __construct(private WarehouseWorkflowService $workflows) {}

    public function show()
    {
        $current = $this->workflows->current();

        if (! $current) {
            return $this->apiResponse(__('wms.workflow.no_published_version'), 200, true, [
                'current' => null,
                'defaults' => $this->workflows->defaults(),
                'legacy' => true,
            ]);
        }

        return $this->apiResponse(__('wms.workflow.fetched'), 200, true, new WarehouseWorkflowVersionResource($current));
    }

    public function history(Request $request)
    {
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $versions = $this->workflows->history((int) ($validated['per_page'] ?? 15));

        return $this->apiResponse(__('wms.workflow.history_fetched'), 200, true, WarehouseWorkflowVersionResource::collection($versions));
    }

    public function publish(Request $request)
    {
        $validated = $request->validate(
            [
                'picking_enabled' => ['sometimes', 'boolean'],
                'packing_enabled' => ['sometimes', 'boolean'],
                'shipping_enabled' => ['sometimes', 'boolean'],
                'order_received_enabled' => ['sometimes', 'boolean'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ],
            [
                'picking_enabled.boolean' => __('wms.workflow.invalid_flag', ['field' => 'picking_enabled']),
                'packing_enabled.boolean' => __('wms.workflow.invalid_flag', ['field' => 'packing_enabled']),
                'shipping_enabled.boolean' => __('wms.workflow.invalid_flag', ['field' => 'shipping_enabled']),
                'order_received_enabled.boolean' => __('wms.workflow.invalid_flag', ['field' => 'order_received_enabled']),
            ]
        );

        // Q-01: QC must never be activatable — reject direct manipulation.
        if ($request->has('quality_check_enabled')) {
            throw new UnprocessableEntityHttpException(__('wms.workflow.quality_check_not_supported'));
        }

        try {
            $version = $this->workflows->publish($validated, auth()->id());
        } catch (\RuntimeException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->apiResponse(__('wms.workflow.published'), 201, true, new WarehouseWorkflowVersionResource($version));
    }
}
