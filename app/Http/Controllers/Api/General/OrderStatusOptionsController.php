<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderStatusOptionsResource;
use App\Models\OrderFlow\OrderFlow;
use App\Services\General\OrderService;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Order;
use Marvel\Enums\Permission;
use Marvel\Traits\ApiResponse;

/**
 * Order-specific available status transitions.
 *
 * Answers: "given THIS order and THIS actor, what can move where?"
 * Definitions (flow catalog) live under the admin + public definition
 * endpoints; THIS endpoint intersects them with Flow validity, the
 * actor's granular target permissions, and active flags.
 *
 * Read-only and advisory: PATCH revalidates permission + transition +
 * inputs server-side. Never trust these flags client-side.
 */
class OrderStatusOptionsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderFlowService $flows,
    ) {
        $this->middleware(['auth:sanctum']);
    }

    public function index(Request $request, int $id): JsonResponse
    {
        $order = Order::query()->find($id);

        if (!$order) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        $user = $request->user();

        if (!$this->canView($user, $order)) {
            return $this->apiResponse(NOT_AUTHORIZED, 403, false);
        }

        $flow = $order->flow_id
            ? OrderFlow::query()->with(['statuses', 'inputs'])->find($order->flow_id)
            : null;

        // Preloaded once: full catalog map (names/active) + required-input
        // keys grouped by required_at. No per-candidate queries below.
        $catalog = $this->catalogMap();
        $requiredByContext = $this->requiredInputsByContext($flow);

        $candidates = $this->candidates($order, $flow, $user, $catalog, $requiredByContext);

        $payload = OrderStatusOptionsResource::payload(
            currentCode: (string) $order->status,
            currentName: $this->currentStatusName($order, $flow, $catalog),
            flow: $flow ? ['code' => $flow->code, 'shipping_type' => $flow->shipping_type] : null,
            statuses: $candidates,
        );

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            (new OrderStatusOptionsResource($payload))->toArray($request)
        );
    }

    private function canView(mixed $user, Order $order): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            if (method_exists($user, 'hasAnyPermission')
                && $user->hasAnyPermission([Permission::VIEW_ORDERS, Permission::VIEW_ORDER])
            ) {
                return true;
            }
        } catch (\Throwable) {
        }

        return (int) $order->user_id === (int) $user->getKey();
    }

    /**
     * @param  array<string, \App\Models\OrderFlow\OrderStatus>  $catalog
     * @param  array<string, array<int, string>>  $requiredByContext
     * @return array<int, array<string, mixed>>
     */
    private function candidates(Order $order, ?OrderFlow $flow, mixed $user, array $catalog, array $requiredByContext): array
    {
        $from = (string) $order->status;
        $rows = [];

        if ($flow) {
            // Relation is eager-loaded with sort_order; no extra query.
            $ordered = $flow->statuses->sortBy(fn ($s) => (int) $s->pivot->sort_order)->values();

            foreach ($ordered as $status) {
                if ($status->code === $from) {
                    continue;
                }

                $rows[$status->code] = [
                    'model' => $status,
                    'sort_order' => (int) $status->pivot->sort_order,
                ];
            }

            // Supervised exits outside the linear path (same union the
            // guard enforces: completed/cancelled/failed_delivery/returned).
            foreach (['completed', 'cancelled', 'failed_delivery', 'returned'] as $exit) {
                if ($exit !== $from && !isset($rows[$exit])) {
                    $rows[$exit] = ['model' => null, 'sort_order' => null];
                }
            }
        } else {
            // Flow-less legacy order: legacy map targets only.
            foreach (OrderService::getAllowedOrderStatusTargets($from) as $code) {
                if ($code !== $from) {
                    $rows[$code] = ['model' => null, 'sort_order' => null];
                }
            }
        }

        $result = [];
        foreach ($rows as $code => $row) {
            $model = $row['model'];
            $catalogRow = $catalog[$code] ?? null;
            $isActive = $model ? (bool) $model->is_active : ($catalogRow ? (bool) $catalogRow->is_active : false);

            $transitionAllowed = false;
            $reason = null;

            if (!$isActive) {
                $reason = 'inactive_status';
            } else {
                $flowAllows = $flow
                    ? $this->flows->allowsFlowTransition($order, $from, $code)
                    : false;
                $legacyAllows = in_array($code, OrderService::getAllowedOrderStatusTargets($from), true);
                $transitionAllowed = $flowAllows || $legacyAllows;

                if (!$transitionAllowed) {
                    $reason = 'forbidden_transition';
                }
            }

            $permitted = $this->flows->userCanTransitionTo($user, $code);

            if ($transitionAllowed && !$permitted) {
                $reason = 'missing_permission';
            }

            $requiresInputs = $requiredByContext['transition:'.$code] ?? [];

            $result[] = OrderStatusOptionsResource::candidate(
                code: $code,
                name: $model ?? $catalogRow ?? $code,
                sortOrder: $row['sort_order'],
                transitionAllowed: $transitionAllowed,
                permitted: $permitted,
                permission: OrderFlowService::targetStatusPermission($code),
                reason: $reason,
                requiresInputs: $requiresInputs,
            );
        }

        return $result;
    }

    private function currentStatusName(Order $order, ?OrderFlow $flow, array $catalog): ?array
    {
        try {
            $current = $order->currentStatus;
            if ($current) {
                return \App\Support\LocalizedName::for($current, 'name');
            }
        } catch (\Throwable) {
        }

        if ($flow) {
            $match = $flow->statuses->firstWhere('code', (string) $order->status);
            if ($match) {
                return \App\Support\LocalizedName::for($match, 'name');
            }
        }

        $row = $catalog[(string) $order->status] ?? null;

        return $row
            ? \App\Support\LocalizedName::for($row, 'name')
            : ['en' => (string) $order->status, 'ar' => null];
    }

    /**
     * Full catalog keyed by code (single query).
     *
     * @return array<string, \App\Models\OrderFlow\OrderStatus>
     */
    private function catalogMap(): array
    {
        try {
            return \App\Models\OrderFlow\OrderStatus::query()->get()->keyBy('code')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Required input keys grouped by required_at (in-memory; flow inputs
     * are already eager-loaded, no extra queries).
     *
     * @return array<string, array<int, string>>
     */
    private function requiredInputsByContext(?OrderFlow $flow): array
    {
        $grouped = [];

        if (!$flow) {
            return $grouped;
        }

        foreach ($flow->inputs as $input) {
            if ($input->required && $input->is_active) {
                $grouped[$input->required_at][] = $input->key;
            }
        }

        return $grouped;
    }
}
