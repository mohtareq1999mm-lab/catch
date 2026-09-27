<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderFlowUpsertRequest;
use App\Http\Resources\OrderFlowResource;
use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderFlowStatus;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Traits\ApiResponse;

class OrderFlowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderFlowService $flows,
    ) {
        $this->middleware(['auth:sanctum']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = OrderFlow::query()->with('statuses');

        if ($request->filled('shipping_type')) {
            $query->where('shipping_type', $request->get('shipping_type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $flows = $query->orderBy('id')->paginate((int) $request->get('per_page', 50));

        return $this->apiResponse('Order flows retrieved successfully.', 200, true, [
            'data' => OrderFlowResource::collection($flows->items()),
            'meta' => [
                'current_page' => $flows->currentPage(),
                'per_page' => $flows->perPage(),
                'total' => $flows->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $flow = OrderFlow::query()->with('statuses')->findOrFail($id);

        return $this->apiResponse('Order flow retrieved successfully.', 200, true,
            (new OrderFlowResource($flow))->toArray(request()));
    }

    public function store(OrderFlowUpsertRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (!isset($data['code'], $data['name'], $data['shipping_type'], $data['status_ids'])) {
            return $this->apiResponse('code, name, shipping_type and status_ids are required.', 422, false);
        }

        try {
            $statuses = $this->flows->validateFlowStatuses($data['status_ids']);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        $flow = DB::transaction(function () use ($data, $statuses) {
            $flow = OrderFlow::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'shipping_type' => $data['shipping_type'],
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->syncStatuses($flow, $statuses);
            $this->enforceSingleDefault($flow);

            return $flow->fresh()->load('statuses');
        });

        return $this->apiResponse('Order flow created successfully.', 201, true,
            (new OrderFlowResource($flow))->toArray(request()));
    }

    public function update(OrderFlowUpsertRequest $request, int $id): JsonResponse
    {
        $flow = OrderFlow::query()->findOrFail($id);
        $data = $request->validated();
        $statuses = null;

        if (isset($data['status_ids'])) {
            try {
                $statuses = $this->flows->validateFlowStatuses($data['status_ids']);
            } catch (\InvalidArgumentException $e) {
                return $this->apiResponse($e->getMessage(), 422, false);
            }
        }

        // shipping_type is the flow identity and is unique per flow (see
        // OrderFlowUpsertRequest): it can never be changed to another value.
        $flow = DB::transaction(function () use ($flow, $data, $statuses) {
            $flow->update(array_intersect_key($data, array_flip(['code', 'name', 'is_default', 'is_active'])));
            if (isset($statuses)) {
                $this->syncStatuses($flow, $statuses);
            }
            $this->enforceSingleDefault($flow);

            return $flow->fresh()->load('statuses');
        });

        return $this->apiResponse('Order flow updated successfully.', 200, true,
            (new OrderFlowResource($flow))->toArray(request()));
    }

    /**
     * Array order IS the transition map: position N -> N+1.
     * Removing a status re-links its neighbours automatically.
     */
    private function syncStatuses(OrderFlow $flow, iterable $statuses): void
    {
        OrderFlowStatus::query()->where('flow_id', $flow->id)->delete();

        $sort = 1;
        foreach ($statuses as $status) {
            OrderFlowStatus::create([
                'flow_id' => $flow->id,
                'status_id' => $status->id,
                'sort_order' => $sort++,
            ]);
        }
    }

    /**
     * Exactly one active default per shipping_type. The most recently saved
     * default wins; the previous default is demoted (never deleted).
     */
    private function enforceSingleDefault(OrderFlow $flow): void
    {
        if (!$flow->is_default || !$flow->is_active) {
            return;
        }

        OrderFlow::query()
            ->where('shipping_type', $flow->shipping_type)
            ->where('id', '!=', $flow->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
