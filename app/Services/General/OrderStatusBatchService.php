<?php

namespace App\Services\General;

use App\Exceptions\FlowInputValidationException;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Marvel\Database\Models\Order;
use Marvel\Exceptions\MarvelBadRequestException;

/**
 * Unified Order Status orchestrator: ONE pipeline for ONE or MANY orders.
 *
 * This is NOT a second status engine. Every order flows through the exact
 * same authority as the single-order PATCH:
 *
 *   granular target permission (OrderFlowService::assertUserCanTransitionTo)
 *     → OrderService::changeOrderStatus()  [permission, flow, inputs,
 *        payment guards, locking, mutation, mirror, history, side effects]
 *
 * Each order runs in ITS OWN transaction (owned by changeOrderStatus):
 * one failure never rolls back the batch. Results are collected per order
 * with machine-readable error codes; request shape failures (422) still
 * reject the whole call at the FormRequest layer.
 */
class OrderStatusBatchService
{
    /**
     * Synchronous-processing ceiling: each order holds a FOR UPDATE lock
     * plus guarded side effects (invoice, notifications, broadcasts), so
     * batches stay bounded for API timeouts. Queued bulk is a separate
     * documented decision, not this contract.
     */
    public const MAX_BATCH = 50;

    public function __construct(
        private OrderService $orderService,
        private OrderFlowService $flowService,
    ) {}

    /**
     * @param  mixed        $actor      Authenticated actor (null = system context).
     * @param  array<int>   $orderIds   One id or many — same pipeline either way.
     * @param  array<string,mixed> $flowValues Common transition inputs, applied per order.
     *
     * @return array{summary: array{total:int,succeeded:int,failed:int}, results: array<int, array<string,mixed>>}
     */
    public function updateStatuses(mixed $actor, array $orderIds, string $status, array $flowValues = []): array
    {
        $results = [];
        $succeeded = 0;

        foreach (array_values($orderIds) as $orderId) {
            $result = $this->updateSingle($actor, (int) $orderId, $status, $flowValues);
            $results[] = $result;

            if ($result['success']) {
                $succeeded++;
            }
        }

        $total = count($results);

        return [
            'summary' => [
                'total' => $total,
                'succeeded' => $succeeded,
                'failed' => $total - $succeeded,
            ],
            'results' => $results,
        ];
    }

    /**
     * @return array{order_id:int, success:bool, status?:string, current_status?:array{code:string,sort_order:int|null}, error?:array{code:string,message:string,details?:array<string,mixed>}}
     */
    public function updateSingle(mixed $actor, int $orderId, string $status, array $flowValues = []): array
    {
        try {
            $order = Order::query()->find($orderId);

            if (!$order) {
                return $this->failure($orderId, 'order_not_found', __('message.ERROR.NOT_FOUND'));
            }

            // SAME authorization as the single-order PATCH: the route
            // middleware already enforced the general update-order-status
            // gate; the granular target permission is enforced per order.
            // (Deliberately no ownership/view check: staff roles hold the
            // general permission without view perms — see PermissionSeeder —
            // so both endpoints must behave identically.)
            $this->flowService->assertUserCanTransitionTo($actor, $status);

            // SAME authority, SAME args as the single-order PATCH
            // (payment authority asserted, common flow_values applied).
            $mutated = $this->orderService->changeOrderStatus(
                null,
                $status,
                $order->id,
                true,
                null,
                null,
                true,
                $flowValues
            );

            if (!$mutated) {
                return $this->failure($orderId, 'internal_error', __('message.ERROR.SOMETHING_WENT_WRONG'));
            }

            $mutated->refresh();

            return [
                'order_id' => $orderId,
                'success' => true,
                'status' => $mutated->status,
                'current_status' => [
                    'code' => $mutated->currentStatus?->code ?? $mutated->status,
                    'sort_order' => $this->resolveSortOrder($mutated),
                ],
            ];
        } catch (AuthorizationException $e) {
            return $this->failure($orderId, 'missing_permission', $e->getMessage());
        } catch (FlowInputValidationException $e) {
            return $this->failure(
                $orderId,
                $this->classifyFlowInputErrors($e->errors(), $flowValues),
                $e->getMessage(),
                ['errors' => $e->errors()]
            );
        } catch (\RuntimeException $e) {
            // F-1 keeps its exact rule: completing an unpaid order without
            // payments.mark_paid is a distinct, machine-readable case.
            if ($e->getMessage() === __('message.ERROR.PERMISSION_MISSING_PERMISSIONS')) {
                return $this->failure($orderId, 'payment_permission_required', $e->getMessage());
            }

            return $this->failure($orderId, 'forbidden_transition', $e->getMessage());
        } catch (MarvelBadRequestException $e) {
            return $this->failure($orderId, 'forbidden_transition', $e->getMessage());
        } catch (ModelNotFoundException $e) {
            return $this->failure($orderId, 'order_not_found', __('message.ERROR.NOT_FOUND'));
        } catch (\Throwable $e) {
            report($e);

            return $this->failure($orderId, 'internal_error', __('message.ERROR.SOMETHING_WENT_WRONG'));
        }
    }

    /**
     * @return array{order_id:int, success:false, error:array{code:string,message:string,details?:array<string,mixed>}}
     */
    private function failure(int $orderId, string $code, string $message, array $details = []): array
    {
        $error = ['code' => $code, 'message' => $message];

        if (!empty($details)) {
            $error['details'] = $details;
        }

        return ['order_id' => $orderId, 'success' => false, 'error' => $error];
    }

    /**
     * Locale-safe classification: compare against the same rendered
     * strings the validator produced instead of matching English text.
     */
    private function classifyFlowInputErrors(array $errors, array $submitted): string
    {
        foreach ($errors as $key => $messages) {
            foreach ((array) $messages as $message) {
                if ($message === __('checkout.flow_input_unknown', ['key' => $key])) {
                    return 'unknown_flow_input';
                }
            }
        }

        foreach ($errors as $key => $messages) {
            foreach ((array) $messages as $message) {
                if ($message === __('checkout.flow_input_required', ['key' => $key])) {
                    return 'missing_flow_input';
                }
            }
        }

        return 'invalid_flow_input';
    }

    private function resolveSortOrder(Order $order): ?int
    {
        try {
            if (!OrderFlowService::tablesAvailable() || !$order->flow_id || !$order->current_status_id) {
                return null;
            }

            return (int) \Illuminate\Support\Facades\DB::table('order_flow_statuses')
                ->where('flow_id', $order->flow_id)
                ->where('status_id', $order->current_status_id)
                ->value('sort_order') ?: null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
