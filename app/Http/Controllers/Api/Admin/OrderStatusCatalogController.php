<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderStatusCatalogUpdateRequest;
use App\Http\Resources\OrderStatusResource;
use App\Models\OrderFlow\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class OrderStatusCatalogController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = OrderStatus::query();

        if ($request->filled('search')) {
            $search = addcslashes((string) $request->get('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $statuses = $query->orderBy('id')->paginate(max(1, min(200, (int) $request->get('per_page', 50))));

        return $this->apiResponse('Order statuses retrieved successfully.', 200, true, [
            'data' => OrderStatusResource::collection($statuses->items()),
            'meta' => [
                'current_page' => $statuses->currentPage(),
                'per_page' => $statuses->perPage(),
                'total' => $statuses->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $status = OrderStatus::query()->findOrFail($id);

        return $this->apiResponse('Order status retrieved successfully.', 200, true,
            (new OrderStatusResource($status))->toArray(request()));
    }

    /**
     * Display name / description / activation only. The stable code is
     * immutable once the status is referenced by a flow or an order.
     */
    public function update(OrderStatusCatalogUpdateRequest $request, int $id): JsonResponse
    {
        $status = OrderStatus::query()->findOrFail($id);
        $data = $request->validated();

        // Status deactivation guardrail: a status currently held by
        // in-flight (non-terminal) orders cannot be deactivated — those
        // orders would lose their enterable forward-path presentation
        // (options would report inactive_status) with no automatic
        // migration. Supervised exits (completed/cancelled) stay available,
        // so nothing is hard-stranded; still, move the orders first, then
        // deactivate. Covers legacy rows whose mirror was never backfilled
        // (current_status_id NULL + legacy status string).
        if (array_key_exists('is_active', $data) && !$data['is_active'] && $status->is_active
            && \Illuminate\Support\Facades\Schema::hasColumn('orders', 'current_status_id')
        ) {
            $holders = \Marvel\Database\Models\Order::query()
                ->whereNotIn('status', \App\Services\OrderFlow\OrderFlowService::TERMINAL_STATUSES)
                ->where(function ($q) use ($status) {
                    $q->where('current_status_id', $status->id)
                        ->orWhere(function ($q) use ($status) {
                            $q->whereNull('current_status_id')->where('status', $status->code);
                        });
                })
                ->count();

            if ($holders > 0) {
                return $this->apiResponse(
                    __('checkout.flow_status_inflight_block', ['code' => $status->code]),
                    422,
                    false
                );
            }
        }

        $status->update($data);

        return $this->apiResponse('Order status updated successfully.', 200, true,
            (new OrderStatusResource($status->fresh()))->toArray(request()));
    }
}
