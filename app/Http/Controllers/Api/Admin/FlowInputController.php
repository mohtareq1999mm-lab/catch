<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FlowInputBulkStoreRequest;
use App\Http\Requests\Admin\FlowInputUpsertRequest;
use App\Http\Resources\FlowInputResource;
use App\Models\OrderFlow\FlowInput;
use App\Models\OrderFlow\OrderFlow;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Traits\ApiResponse;

/**
 * Admin Flow Input management (definitions only).
 *
 * Array position / sort_order drives frontend rendering. Removing or
 * deactivating a REQUIRED input is fail-closed while in-flight
 * (non-terminal) orders exist on the flow — same guard contract as
 * OrderFlowController::syncStatuses().
 */
class FlowInputController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderFlowService $flows,
    ) {
        $this->middleware(['auth:sanctum']);
    }

    public function index(Request $request, int $flowId): JsonResponse
    {
        $flow = OrderFlow::query()->findOrFail($flowId);

        $inputs = $flow->inputs()->ordered()
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->get();

        return $this->apiResponse('Flow inputs retrieved successfully.', 200, true,
            FlowInputResource::collection($inputs)->toArray($request));
    }

    /**
     * Bulk creation: ONE request, ONE flow, MANY input definitions, ONE
     * database transaction. Contract is always {inputs: [...]}.
     *
     * All-or-nothing: every item is fully validated (shape, definition
     * rules, intra-request duplicates, existing-key/sort collisions,
     * in-flight guard) BEFORE anything is persisted; the inserts then run
     * inside a single transaction, so a late failure rolls everything back.
     */
    public function store(FlowInputBulkStoreRequest $request, int $flowId): JsonResponse
    {
        $flow = OrderFlow::query()->findOrFail($flowId);
        $items = array_values($request->validated()['inputs']);

        foreach ($items as $i => &$item) {
            $item['required_at'] = $item['required_at'] ?? FlowInput::REQUIRED_AT_CHECKOUT;

            try {
                $this->flows->validateFlowInputDefinition($item);
            } catch (\InvalidArgumentException $e) {
                return $this->apiResponse("inputs.{$i}: {$e->getMessage()}", 422, false);
            }
        }
        unset($item);

        // Intra-request duplicates fail before touching the database.
        foreach (['key' => 'checkout.flow_input_key_duplicate_request', 'sort_order' => 'checkout.flow_input_sort_duplicate_request'] as $field => $messageKey) {
            $seen = [];
            foreach ($items as $i => $item) {
                if (!array_key_exists($field, $item) || $item[$field] === null) {
                    continue;
                }
                if (isset($seen[$item[$field]])) {
                    return $this->apiResponse(__($messageKey, [$field => $item[$field]]), 422, false);
                }
                $seen[$item[$field]] = true;
            }
        }

        // Collisions with existing definitions fail before persisting.
        $existingKeys = FlowInput::query()->where('flow_id', $flow->id)->pluck('key')->all();
        foreach ($items as $i => $item) {
            if (in_array($item['key'], $existingKeys, true)) {
                return $this->apiResponse(
                    "inputs.{$i}: " . __('checkout.flow_input_key_taken'),
                    422,
                    false
                );
            }
        }
        $existingSorts = FlowInput::query()->where('flow_id', $flow->id)->pluck('sort_order')->all();
        foreach ($items as $i => $item) {
            if (isset($item['sort_order']) && in_array($item['sort_order'], $existingSorts, true)) {
                return $this->apiResponse(
                    "inputs.{$i}: " . __('checkout.flow_input_sort_taken', ['sort_order' => $item['sort_order']]),
                    422,
                    false
                );
            }
        }

        // In-flight guard (same contract as deactivation): introducing a
        // REQUIRED input changes what in-flight orders must satisfy.
        $addsRequired = collect($items)->contains(
            fn ($item) => !empty($item['required']) && ($item['is_active'] ?? true)
        );
        if ($addsRequired && $this->flowHasInFlightOrders($flow->id)) {
            return $this->apiResponse(__('checkout.flow_input_inflight_block', ['key' => '*']), 422, false);
        }

        // Deterministic ordering: explicit sort_order respected, omitted
        // slots allocated sequentially after the current maximum, in
        // request order.
        $nextSort = (int) (FlowInput::query()->where('flow_id', $flow->id)->max('sort_order') ?? 0);
        foreach ($items as &$item) {
            $item['sort_order'] = $item['sort_order'] ?? ++$nextSort;
            $nextSort = max($nextSort, (int) $item['sort_order']);
        }
        unset($item);

        $created = DB::transaction(function () use ($flow, $items) {
            return collect($items)->map(fn (array $item) => FlowInput::create([
                'flow_id' => $flow->id,
                'key' => $item['key'],
                'label' => $item['label'],
                'placeholder' => $item['placeholder'] ?? null,
                'help_text' => $item['help_text'] ?? null,
                'type' => $item['type'],
                'source' => $item['source'] ?? null,
                'required' => $item['required'] ?? false,
                'required_at' => $item['required_at'],
                'sort_order' => $item['sort_order'],
                'validation' => $item['validation'] ?? null,
                'is_active' => $item['is_active'] ?? true,
            ]))->values();
        });

        return $this->apiResponse('Flow inputs created successfully.', 201, true,
            FlowInputResource::collection($created)->toArray(request()));
    }

    public function update(FlowInputUpsertRequest $request, int $id): JsonResponse
    {
        $input = FlowInput::query()->findOrFail($id);
        $data = $request->validated();

        // The key is the stable frontend contract: immutable once created.
        if (isset($data['key']) && $data['key'] !== $input->key) {
            return $this->apiResponse(__('checkout.flow_input_key_immutable'), 422, false);
        }

        $candidate = array_merge($input->toArray(), $data);

        try {
            $this->flows->validateFlowInputDefinition($candidate);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        $turningOptionalOff = array_key_exists('required', $data) && !$data['required'] && $input->required
            || array_key_exists('is_active', $data) && !$data['is_active'] && $input->is_active;

        if ($turningOptionalOff && $this->hasInFlightOrders($input)) {
            return $this->apiResponse(
                __('checkout.flow_input_inflight_block', ['key' => $input->key]),
                422,
                false
            );
        }

        $input->update(array_intersect_key($data, array_flip([
            'label', 'placeholder', 'help_text', 'type', 'source',
            'required', 'required_at', 'sort_order', 'validation', 'is_active',
        ])));

        return $this->apiResponse('Flow input updated successfully.', 200, true,
            (new FlowInputResource($input->fresh()))->toArray(request()));
    }

    public function destroy(int $id): JsonResponse
    {
        $input = FlowInput::query()->findOrFail($id);

        if ($input->required && $this->hasInFlightOrders($input)) {
            return $this->apiResponse(
                __('checkout.flow_input_inflight_block', ['key' => $input->key]),
                422,
                false
            );
        }

        $input->delete();

        return $this->apiResponse('Flow input deleted successfully.', 200, true);
    }

    private function nextSortOrder(int $flowId): int
    {
        return (int) (FlowInput::query()->where('flow_id', $flowId)->max('sort_order') ?? 0) + 1;
    }

    /**
     * In-flight guard: non-terminal orders on this flow.
     * Mirrors OrderFlowController::syncStatuses() terminal exclusion.
     */
    private function hasInFlightOrders(FlowInput $input): bool
    {
        return $this->flowHasInFlightOrders($input->flow_id);
    }

    private function flowHasInFlightOrders(int $flowId): bool
    {
        if (!Schema::hasColumn('orders', 'flow_id')) {
            return false;
        }

        return \Marvel\Database\Models\Order::query()
            ->where('flow_id', $flowId)
            ->whereNotIn('status', ['delivered', 'cancelled', 'completed'])
            ->exists();
    }
}
