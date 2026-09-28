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

        if ($request->filled('search')) {
            $search = $this->escapeLike((string) $request->get('search'));
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $flows = $query->orderBy('id')->paginate($this->perPage($request));

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
        $flow = OrderFlow::query()->with(['statuses', 'inputs' => fn ($q) => $q->ordered()])->findOrFail($id);

        $payload = (new OrderFlowResource($flow))->toArray(request());
        $payload['inputs'] = \App\Http\Resources\FlowInputResource::collection($flow->inputs)->toArray(request());

        return $this->apiResponse('Order flow retrieved successfully.', 200, true, $payload);
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

        try {
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
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

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
        // The explicit check stays even though validation would usually
        // reject first — it documents intent for future types (sea/air).
        if (isset($data['shipping_type']) && $data['shipping_type'] !== $flow->shipping_type) {
            return $this->apiResponse('shipping_type cannot be changed. Create a new flow instead.', 422, false);
        }

        try {
            $flow = DB::transaction(function () use ($flow, $data, $statuses) {
                $flow->update(array_intersect_key($data, array_flip(['code', 'name', 'is_default', 'is_active'])));
                if (isset($statuses)) {
                    $this->syncStatuses($flow, $statuses);
                }
                $this->enforceSingleDefault($flow);

                return $flow->fresh()->load('statuses');
            });
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse('Order flow updated successfully.', 200, true,
            (new OrderFlowResource($flow))->toArray(request()));
    }

    /**
     * Array order IS the transition map: position N -> N+1.
     * Removing a status re-links its neighbours automatically.
     *
     * @throws \InvalidArgumentException when in-flight orders sit at a
     *                                   removed, non-terminal status.
     */
    private function syncStatuses(OrderFlow $flow, iterable $statuses): void
    {
        $newIds = collect($statuses)->map(fn ($status) => (int) $status->id)->all();
        $currentIds = OrderFlowStatus::query()->where('flow_id', $flow->id)->pluck('status_id')
            ->map(fn ($id) => (int) $id)->all();
        $removed = array_diff($currentIds, $newIds);

        if (!empty($removed)
            && \Illuminate\Support\Facades\Schema::hasColumn('orders', 'current_status_id')
        ) {
            $inFlight = \Marvel\Database\Models\Order::query()
                ->where('flow_id', $flow->id)
                ->whereIn('current_status_id', $removed)
                ->whereNotIn('status', ['delivered', 'cancelled', 'completed'])
                ->count();

            if ($inFlight > 0) {
                throw new \InvalidArgumentException(
                    "Cannot remove statuses holding {$inFlight} in-flight order(s). Move them first."
                );
            }
        }

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

    private function perPage(Request $request): int
    {
        return max(1, min(200, (int) $request->get('per_page', 50)));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Exactly one active default per shipping_type. The most recently saved
     * default wins; the previous default is demoted (never deleted).
     *
     * Known limitation: concurrent writers can both read-then-write
     * is_default=true. Admin-only, low-frequency surface; the shipping_type
     * UNIQUE key still guarantees a single flow per type, so order routing
     * (which keys on shipping_type, not is_default) is unaffected.
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
