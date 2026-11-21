<?php

namespace App\Services\Order;

use App\Events\AssignedCouponConsumed;
use App\Events\OrderCancelled;
use App\Events\OrderStatusChanged;
use App\Services\Coupon\CouponReservationService;
use App\Services\Customer\CustomerMetricsService;
use App\Services\General\PromotionService;
use App\Services\Inventory\InventoryRestoreService;
use App\Services\Inventory\OrderReservationService;
use App\Services\Invoice\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponAssignmentUsage;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Promotion;
use Marvel\Enums\OrderStatus as MarvelOrderStatus;

/**
 * The single executor for every Order lifecycle transition.
 *
 * The public surface is intent-named: callers say why the order is moving,
 * never which internal flags to flip. Every intent funnels into execute(),
 * which owns the flow gate, payment authority, delivery invariant, locks,
 * inventory/coupon/promotion consequences, history, and lifecycle events.
 *
 * This is the only writer of order lifecycle status (`orders.status`,
 * `current_status_id`, `order_status`, `payment_status` derived from
 * completion, `fulfillment_status` derived from order status). Controllers,
 * jobs, commands, and listeners must call an intent here; none may mutate
 * those columns directly. `finalizePromotionUsageAfterPayment()` is the one
 * companion writer on the same row and only stamps `promotion_consumed`.
 */
class OrderTransitionService
{
    private static array $allowedFulfillmentTransitions = [
        Order::FULFILLMENT_STATUS_PENDING => [Order::FULFILLMENT_STATUS_PENDING, Order::FULFILLMENT_STATUS_PROCESSING, Order::FULFILLMENT_STATUS_CANCELLED],
        Order::FULFILLMENT_STATUS_PROCESSING => [Order::FULFILLMENT_STATUS_PROCESSING, Order::FULFILLMENT_STATUS_READY_FOR_PICKUP, Order::FULFILLMENT_STATUS_OUT_FOR_DELIVERY, Order::FULFILLMENT_STATUS_CANCELLED],
        Order::FULFILLMENT_STATUS_READY_FOR_PICKUP => [Order::FULFILLMENT_STATUS_READY_FOR_PICKUP, Order::FULFILLMENT_STATUS_DELIVERED, Order::FULFILLMENT_STATUS_CANCELLED],
        Order::FULFILLMENT_STATUS_OUT_FOR_DELIVERY => [Order::FULFILLMENT_STATUS_OUT_FOR_DELIVERY, Order::FULFILLMENT_STATUS_DELIVERED, Order::FULFILLMENT_STATUS_CANCELLED],
        Order::FULFILLMENT_STATUS_DELIVERED => [Order::FULFILLMENT_STATUS_DELIVERED],
        Order::FULFILLMENT_STATUS_CANCELLED => [Order::FULFILLMENT_STATUS_CANCELLED],
    ];

    public function __construct(
        private PromotionService $promotionService,
        private OrderReservationService $orderReservationService,
        private InventoryRestoreService $inventoryRestoreService,
        private CouponReservationService $couponReservationService,
        private InvoiceService $invoiceService,
        private DeliveryCompletionInvariant $deliveryCompletion,
        private ?CustomerMetricsService $customerMetricsService = null,
    ) {}

    /**
     * Gateway-verified payment completed the order. The provider established
     * payment authority and the callback owns the PaymentSucceeded event.
     *
     * $assertPaymentAuthority defaults to false because the canonical callback
     * derived the order from a claimed transaction. The legacy Marvel webhook
     * passes true: its pre-intent call relied on the default, so an
     * authenticated actor without payments.mark_paid is still refused there.
     */
    public function completeFromGateway(Order $order, bool $assertPaymentAuthority = false): Order|false
    {
        return $this->execute(
            $order,
            Order::ORDER_STATUS_COMPLETED,
            emitPaymentSuccess: false,
            assertPaymentAuthority: $assertPaymentAuthority,
        );
    }

    /**
     * A zero-payable order completed without provider verification. There is
     * no payment to assert, so the payment-authority gate is exempt.
     */
    public function completeZeroValue(Order $order): Order|false
    {
        return $this->execute(
            $order,
            Order::ORDER_STATUS_COMPLETED,
            assertPaymentAuthority: false,
        );
    }

    /**
     * Staff confirmed a cash/COD payment. The actor must hold
     * payments.mark_paid unless the order is already paid.
     */
    public function completeManualPayment(Order $order, string $method, int $transactionId, ?string $reason = null): Order|false
    {
        return $this->execute(
            $order,
            Order::ORDER_STATUS_COMPLETED,
            auditReason: $reason,
            auditContext: [
                'action' => 'manual_mark_paid',
                'payment_method' => $method,
                'transaction_id' => $transactionId,
            ],
        );
    }

    /**
     * The unpaid-order reaper expired a never-paid order. Promotion usage was
     * never incremented, so it must not be decremented; the pending payment
     * marker becomes failed.
     */
    public function expireUnpaid(Order $order): Order|false
    {
        return $this->execute(
            $order,
            Order::ORDER_STATUS_CANCELLED,
            auditReason: 'Order cancelled due to reservation expiry',
            auditContext: [
                'reservation_expires_at' => $order->reservation_expires_at?->toIso8601String(),
                'trigger' => 'orders:cancel-unpaid',
            ],
            skipPromotionDecrement: true,
            markPaymentFailed: true,
        );
    }

    /**
     * The guest used the single-use cancellation link. The OTP proof rides
     * along in the audit trail.
     */
    public function cancelByGuest(Order $order, string $proof): Order|false
    {
        return $this->execute(
            $order,
            Order::ORDER_STATUS_CANCELLED,
            auditReason: 'Guest single-use cancellation link',
            auditContext: [
                'trigger' => 'guest_cancellation_link',
                'possession_proof' => $proof,
            ],
            // ORD-1: never decrement promotion usage for an order whose usage
            // was never incremented (unpaid cancellation).
            skipPromotionDecrement: $order->payment_status !== Order::PAYMENT_STATUS_SUCCESS,
        );
    }

    /**
     * The customer cancelled an unpaid, still-cancellable order.
     */
    public function cancelByCustomer(Order $order): Order|false
    {
        return $this->execute($order, Order::ORDER_STATUS_CANCELLED);
    }

    /**
     * Staff cancelled an order with a required operational reason.
     */
    public function cancelByStaff(Order $order, string $reason): Order|false
    {
        return $this->execute($order, Order::ORDER_STATUS_CANCELLED, auditReason: $reason);
    }

    /**
     * A checkout retry replaced a still-pending order with a fresh one.
     */
    public function cancelAsSuperseded(Order $order, string $reason): Order|false
    {
        return $this->execute(
            $order,
            Order::ORDER_STATUS_CANCELLED,
            auditReason: $reason,
            auditContext: [
                'trigger' => 'checkout-retry-supersede',
                'superseded_order_id' => $order->id,
            ],
            skipPromotionDecrement: true,
        );
    }

    /**
     * Aggregate fulfillment progress legally advanced the order to a
     * logistics milestone.
     */
    public function advanceFulfillment(Order $order, string $target, string $reason): Order|false
    {
        return $this->execute(
            $order,
            $target,
            auditReason: 'Fulfillment progress: ' . $reason,
            auditContext: [
                'trigger' => 'fulfillment-sync',
                'milestone' => $reason,
            ],
        );
    }

    /**
     * Every fulfillment is delivered; the shipment path marks the order
     * delivered. The delivery invariant is enforced by execute().
     */
    public function markDelivered(Order $order): Order|false
    {
        return $this->execute($order, Order::ORDER_STATUS_DELIVERED);
    }

    /**
     * Audited escape hatch: bypass the delivery invariant with a permission,
     * a reason, and an authenticated actor.
     */
    public function forceDeliver(Order $order, string $reason): Order|false
    {
        return $this->execute(
            $order,
            Order::ORDER_STATUS_DELIVERED,
            auditReason: $reason,
            forceDelivered: true,
        );
    }

    /**
     * Staff-directed transition to an explicit target (single PATCH and batch
     * update). Authorization is asserted by the caller before this intent is
     * reached.
     */
    public function transitionTo(Order $order, string $status, array $flowValues = []): Order|false
    {
        return $this->execute($order, $status, flowValues: $flowValues);
    }

    public function finalizePromotionUsageAfterPayment(Order $order): void
    {
        if ($order->promotion_consumed) {
            return;
        }

        $promotionId = $order->promotion_id ? (int) $order->promotion_id : null;
        if ($promotionId) {
            $counted = $this->promotionService->incrementUsage($promotionId);

            if (!$counted) {
                // F-06: the limiter filled between apply and completion, so
                // the guarded increment matched no row. The approved
                // customer-favoring contract holds — the order keeps its
                // discount, promotion_consumed still sets below — but the
                // uncounted grant must be observable, not silent.
                $snapshot = Promotion::query()->whereKey($promotionId)->first(['id', 'usage', 'limiter']);
                \Illuminate\Support\Facades\Log::warning('promotion.usage.limiter_blocked', [
                    'promotion_id' => $promotionId,
                    'order_id' => $order->getKey(),
                    'usage' => $snapshot?->usage,
                    'limiter' => $snapshot?->limiter,
                ]);
            }
        }

        if (Schema::hasColumn('orders', 'promotion_consumed')) {
            $order->update(['promotion_consumed' => true]);
        }
    }

    private function execute(
        Order $order,
        string $status,
        bool $emitPaymentSuccess = true,
        ?string $auditReason = null,
        ?array $auditContext = null,
        bool $assertPaymentAuthority = true,
        array $flowValues = [],
        bool $skipPromotionDecrement = false,
        bool $markPaymentFailed = false,
        bool $forceDelivered = false,
    ): Order|false {
        return DB::transaction(function () use ($order, $status, $emitPaymentSuccess, $auditReason, $auditContext, $assertPaymentAuthority, $flowValues, $skipPromotionDecrement, $markPaymentFailed, $forceDelivered) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();
            if (!$locked) {
                return false;
            }
            $order = $locked;
            $transaction = $order->transactions()->latest()->first();

            $previousStatus = $order->status;

            // Order Flow is the SOLE lifecycle authority. The assigned flow
            // decides every transition: linear successor plus supervised
            // exits (completed/cancelled/failed_delivery/returned/
            // delivered-from-completed). The legacy map is deprecated and is
            // NEVER consulted here. allowsFlowTransition() returns false when
            // the flow tables/columns are absent or the order has no flow —
            // fail-closed: such transitions are rejected, never legacy-allowed.
            $flowService = app(\App\Services\OrderFlow\OrderFlowService::class);

            if (!$flowService->allowsFlowTransition($order, $previousStatus, $status)) {
                throw new \RuntimeException(
                    __('checkout.invalid_flow_transition', [
                        'from' => $previousStatus,
                        'to' => $status,
                    ])
                );
            }

            // D2: self-transitions are successful no-ops. The flow gate above
            // stays the authority (flow-less orders still fail closed there;
            // route-level permissions still run in controllers before this
            // writer is ever reached), and every producer calling this writer
            // gets identical semantics. From here: no markers, no history,
            // no invoice, no cascade, no events — "already in the requested
            // state; acknowledge success without changing anything."
            if ($previousStatus === $status) {
                return $order->fresh() ?? $order;
            }

            // Flow Input gate (transition context): an ADDITIONAL gate AFTER
            // transition validation, BEFORE business guards and mutation.
            // Missing/invalid required inputs throw BEFORE any state changes,
            // so the order status MUST NOT change on validation failure.
            // Empty values pass unless the flow demands transition inputs.
            $validatedTransitionValues = [];
            if (\App\Services\OrderFlow\OrderFlowService::orderFlowColumnsAvailable() && $order->flow_id) {
                $transitionFlow = \App\Models\OrderFlow\OrderFlow::query()->find($order->flow_id);
                if ($transitionFlow) {
                    $validatedTransitionValues = $flowService->validateFlowValues(
                        $transitionFlow,
                        $flowValues,
                        'transition:'.$status
                    );
                }
            }

            // F-1: completing an UNPAID order is a financial act. An
            // authenticated actor needs payments.mark_paid; system/gateway
            // paths (callbacks, webhooks, zero-value, reconciliation) run
            // unauthenticated or pass $assertPaymentAuthority=false because
            // payment authority was established elsewhere (provider
            // verification or zero amount). Paid orders stay on the normal
            // fulfillment path regardless of actor.
            if ($status === Order::ORDER_STATUS_COMPLETED && $assertPaymentAuthority && auth()->check()
                && !$this->deliveryCompletion->isPaid($order) && !$this->actorCanMarkPaid()
            ) {
                \Illuminate\Support\Facades\Log::warning('Order completion blocked: missing payments.mark_paid', [
                    'order_id' => $order->id,
                    'actor_id' => auth()->id(),
                    'previous_status' => $previousStatus,
                ]);

                throw new \RuntimeException(
                    __('message.ERROR.PERMISSION_MISSING_PERMISSIONS')
                );
            }

            // Phase 8 (D8-5): the normal `delivered` transition requires the
            // shipment completion invariant (paid + every fulfillment
            // delivered + at least one fulfillment) so it cannot bypass the
            // rule maybeCompleteOrder enforces. Same-state re-sets stay
            // idempotent. The explicit force path (audited escape hatch for
            // digital/service-recovery cases) needs its own intent flag,
            // the update-order-status permission, a reason and an actor —
            // and is recorded in history metadata below via $auditContext.
            // Order Flow validation above stays the lifecycle authority; this
            // is an additional business guard, never a second writer.
            if ($status === Order::ORDER_STATUS_DELIVERED && $previousStatus !== Order::ORDER_STATUS_DELIVERED) {
                if ($forceDelivered) {
                    if (!auth()->check() || !$this->actorCanForceDeliver()) {
                        throw new \RuntimeException(
                            __('message.ERROR.PERMISSION_MISSING_PERMISSIONS')
                        );
                    }
                    if ($auditReason === null || trim($auditReason) === '') {
                        throw new \RuntimeException(
                            __('message.ERROR.FORCE_DELIVER_REASON_REQUIRED')
                        );
                    }
                    $auditContext = array_merge($auditContext ?? [], [
                        'force_delivered' => true,
                        'force_delivered_by' => auth()->id(),
                    ]);
                    \Illuminate\Support\Facades\Log::warning('Order force-delivered (completion invariant bypassed)', [
                        'order_id' => $order->id,
                        'actor_id' => auth()->id(),
                        'previous_status' => $previousStatus,
                        'reason' => trim($auditReason),
                    ]);
                } elseif (!$this->deliveryCompletion->mayComplete($order)) {
                    throw new \RuntimeException(
                        __('message.ERROR.DELIVER_TRANSITION_BLOCKED')
                    );
                }
            }

            $updateData = ['status' => $status];

            // Persist validated transition input values (business copies +
            // audit rows) BEFORE the status mutation, same transaction.
            if (!empty($validatedTransitionValues)) {
                $flowService->persistValidatedValues($order, $validatedTransitionValues, 'transition:'.$status);
                $order->refresh();
            }

            // Canonical flow sync: current_status_id mirrors orders.status.
            // Written ONLY here (and at creation) so the two cannot drift.
            if (Schema::hasColumn('orders', 'current_status_id')
                && \App\Services\OrderFlow\OrderFlowService::tablesAvailable()
            ) {
                $flowStatusId = $flowService->statusIdForCode($status);
                if ($flowStatusId) {
                    $updateData['current_status_id'] = $flowStatusId;
                }
            }

            if ($status === Order::ORDER_STATUS_COMPLETED) {
                // Business contract: completed => payment succeeded.
                if (Schema::hasColumn('orders', 'payment_status')) {
                    $updateData['payment_status'] = Order::PAYMENT_STATUS_SUCCESS;
                }
                if (Schema::hasColumn('orders', 'paid_at')) {
                    $updateData['paid_at'] = $order->getRawOriginal('paid_at') ?? now();
                }
            }
            if ($status === Order::ORDER_STATUS_COMPLETED && Schema::hasColumn('orders', 'completed_at')) {
                $updateData['completed_at'] = now();
            }

            if ($status === Order::ORDER_STATUS_CANCELLED && $previousStatus !== Order::ORDER_STATUS_CANCELLED && Schema::hasColumn('orders', 'cancelled_at')) {
                $updateData['cancelled_at'] = now();
            }

            // Reaper outcome parity: the system expiry command (orders:cancel-unpaid)
            // marks never-paid orders payment-failed. Explicit opt-in only —
            // ordinary cancellations never touch the payment marker. A paid
            // marker is never clobbered: only pending/null rows move.
            if ($status === Order::ORDER_STATUS_CANCELLED && $markPaymentFailed && Schema::hasColumn('orders', 'payment_status')) {
                $currentPayment = $order->getRawOriginal('payment_status');
                if ($currentPayment === null || $currentPayment === Order::PAYMENT_STATUS_PENDING) {
                    $updateData['payment_status'] = Order::PAYMENT_STATUS_FAILED;
                }
            }

            $fulfillmentStatusMap = [
                Order::ORDER_STATUS_PROCESSING => Order::FULFILLMENT_STATUS_PROCESSING,
                Order::ORDER_STATUS_COMPLETED => null,
                Order::ORDER_STATUS_CANCELLED => Order::FULFILLMENT_STATUS_CANCELLED,
                Order::ORDER_STATUS_DELIVERED => Order::FULFILLMENT_STATUS_DELIVERED,
            ];
            if (array_key_exists($status, $fulfillmentStatusMap) && Schema::hasColumn('orders', 'fulfillment_status')) {
                $newFulfillment = $fulfillmentStatusMap[$status];
                $currentFulfillment = $order->getRawOriginal('fulfillment_status') ?? Order::FULFILLMENT_STATUS_PENDING;
                if ($newFulfillment === null) {
                    if ($currentFulfillment === Order::FULFILLMENT_STATUS_PENDING) {
                        $updateData['fulfillment_status'] = Order::FULFILLMENT_STATUS_PROCESSING;
                    }
                } elseif ($this->canTransitionFulfillmentStatus($currentFulfillment, $newFulfillment)) {
                    $updateData['fulfillment_status'] = $newFulfillment;
                }
            }

            if (!$order->update($updateData)) {
                return false;
            }

            // D4: legacy Marvel readers (tracking timeline, admin views)
            // consume the prefixed `order_status` column — keep it synced on
            // every canonical transition so the two can never drift.
            // Modern-only stages (packed/shipped/delivered/...) have no
            // legacy counterpart and leave the column untouched.
            $legacyOrderStatus = self::mapToLegacyOrderStatus($status);
            if ($legacyOrderStatus !== null && Schema::hasColumn('orders', 'order_status')) {
                Order::withoutGlobalScopes()->whereKey($order->id)->update(['order_status' => $legacyOrderStatus]);
                $order->setAttribute('order_status', $legacyOrderStatus);
            }

            $actorId = null;
            $actorType = 'system';
            // Record immutable status history (guarded for tests without migration)
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('order_status_history')) {
                    $oldPaymentStatus = $order->getOriginal('payment_status');
                    $oldFulfillmentStatus = $order->getOriginal('fulfillment_status');
                    // Determine actor type
                    $actorId = null;
                    $actorType = 'system';
                    if (auth()->check()) {
                        $actorId = auth()->id();
                        try {
                            $user = auth()->user();
                            // F-1: manual payment confirmation now carries
                            // payments.mark_paid; the legacy order permission
                            // is still recognized during the transition.
                            $isAdmin = method_exists($user, 'hasAnyPermission')
                                ? $user->hasAnyPermission(['payments.mark_paid', 'update-order-status'])
                                : (method_exists($user, 'hasPermissionTo')
                                    ? ($user->hasPermissionTo('payments.mark_paid') || $user->hasPermissionTo('update-order-status'))
                                    : (method_exists($user, 'can') ? ($user->can('payments.mark_paid') || $user->can('update-order-status')) : false));
                            $actorType = $isAdmin ? 'admin' : 'user';
                        } catch (\Throwable $e) {
                            $actorType = 'user';
                        }
                    }

                    // Only record if status actually changed or payment/fulfillment changed
                    $shouldRecord = $previousStatus !== $order->status
                        || ($oldPaymentStatus !== ($updateData['payment_status'] ?? $order->payment_status))
                        || isset($updateData['fulfillment_status']);

                    if ($shouldRecord) {
                        $historyMetadata = [
                            'old_payment_status' => $oldPaymentStatus,
                            'new_payment_status' => $updateData['payment_status'] ?? $order->payment_status,
                            'old_fulfillment_status' => $oldFulfillmentStatus,
                            'new_fulfillment_status' => $updateData['fulfillment_status'] ?? $order->fulfillment_status,
                        ];
                        // Flow provenance (no-op when the order has no flow).
                        $historyMetadata = array_merge(
                            $historyMetadata,
                            $flowService->flowMetadata($order, $previousStatus, $order->status)
                        );
                        $historyNotes = "Status changed from {$previousStatus} to {$order->status}";
                        // Manual-audit context (mark-paid reason, transaction,
                        // action): appended without altering the canonical text.
                        $sanitizedReason = $auditReason !== null && trim($auditReason) !== ''
                            ? \Illuminate\Support\Str::limit(strip_tags($auditReason), 500, '')
                            : null;
                        if ($sanitizedReason !== null) {
                            $historyNotes .= " — Reason: {$sanitizedReason}";
                            $historyMetadata['reason'] = $sanitizedReason;
                        }
                        if (is_array($auditContext)) {
                            foreach ($auditContext as $contextKey => $contextValue) {
                                if (!array_key_exists($contextKey, $historyMetadata)) {
                                    $historyMetadata[$contextKey] = $contextValue;
                                }
                            }
                        }
                        $order->recordStatusChange(
                            oldStatus: $previousStatus,
                            newStatus: $order->status,
                            changedBy: $actorId,
                            changedByType: $actorType,
                            notes: $historyNotes,
                            metadata: $historyMetadata,
                            oldPaymentStatus: $oldPaymentStatus,
                            newPaymentStatus: $updateData['payment_status'] ?? $order->payment_status,
                            oldFulfillmentStatus: $oldFulfillmentStatus,
                            newFulfillmentStatus: $updateData['fulfillment_status'] ?? $order->fulfillment_status
                        );
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }

            // P2-5: Structured logging + metrics for observability
            try {
                \App\Services\Logging\OrderTrackingLogger::logStatusChange($order, $previousStatus, $order->status, $actorId ?? null, $actorType ?? 'system');
                \App\Services\Metrics\OrderTrackingMetrics::incrementStatusChange($previousStatus, $order->status);
            } catch (\Throwable $e) {
                // logging must never break status transition
            }

            // Business contract: an Invoice is generated exactly once, when the
            // Order performs its FIRST VALID transition AWAY from `pending` —
            // regardless of the target status (processing / completed /
            // cancelled). Same-status re-sets are NOT "leaving pending".
            // Reuses the idempotent InvoiceService (existing-invoice lock),
            // so completion paths that also fire PaymentSucceeded still end
            // with ONE invoice. Failures never block the operational status.
            if ($previousStatus === Order::ORDER_STATUS_PENDING
                && $status !== Order::ORDER_STATUS_PENDING
                && Schema::hasTable('invoices')) {
                try {
                    $this->invoiceService->generateFromOrder($order);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            if ($status === Order::ORDER_STATUS_COMPLETED) {
                $this->recordCouponUsage($order);

                // Canonical route is self-sufficient for every payment method:
                // committing inventory and finalizing promotion usage are both
                // idempotent no-ops if the online-payment callback already did them.
                $this->finalizePromotionUsageAfterPayment($order);
                $this->orderReservationService->commit($order);

                // P1-3: refresh customer metrics on qualifying completion only
                // (status=completed AND payment_status=payment-success).
                // Deferred to afterCommit so the metrics scan/upsert never
                // extends the Order FOR UPDATE lock hold. Idempotent; never
                // blocks completion on failure.
                $orderIdForMetrics = $order->getKey();
                DB::afterCommit(function () use ($orderIdForMetrics) {
                    try {
                        $fresh = Order::query()->find($orderIdForMetrics);
                        if ($fresh && $fresh->status === Order::ORDER_STATUS_COMPLETED
                            && $fresh->payment_status === Order::PAYMENT_STATUS_SUCCESS
                            && $fresh->user) {
                            $metricsService = $this->customerMetricsService
                                ?? app(\App\Services\Customer\CustomerMetricsService::class);
                            $metricsService->rebuildForUser($fresh->user);

                            // Coupon distribution: metrics-family trees may
                            // now evaluate differently for this user.
                            event(new \App\Events\Coupons\CustomerMetricsUpdated($fresh->user, $fresh->getKey()));
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }

            if ($transaction) {
                if ($status === Order::ORDER_STATUS_COMPLETED) {
                    $transaction->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                    ]);
                }

                if ($status === Order::ORDER_STATUS_CANCELLED) {
                    $transaction->update([
                        'status' => 'failed',
                    ]);
                }
            }

            if ($status === Order::ORDER_STATUS_CANCELLED && $previousStatus !== Order::ORDER_STATUS_CANCELLED) {
                // Phase 3 addendum: the restore-vs-release decision is keyed
                // on INVENTORY STATE, not payment. COD/cashier commit at
                // creation while still unpaid — a committed-but-unpaid cancel
                // must restore (committed→restored claim) or its stock leaks:
                // release() only claims active→released and would no-op.
                // release() and restore() claims are mutually exclusive by
                // state, so exactly one fires and duplicate cancels converge.
                if ($order->inventory_state === Order::INVENTORY_STATE_COMMITTED) {
                    // Committed order cancellation: restore inventory to stock.
                    // P7-2: the state claim inside restore() is the single
                    // exactly-once authority; the legacy column below is
                    // stamped as an observability marker only, never a guard.
                    $restored = $this->inventoryRestoreService->restore($order);
                    if ($restored) {
                        Order::whereKey($order->getKey())
                            ->whereNull('inventory_restored_at')
                            ->update(['inventory_restored_at' => now()]);
                    }
                } else {
                    // Uncommitted cancellation: release the active reservation
                    // (no-op unless currently active — never double-release).
                    $this->orderReservationService->release($order);
                }

                // Only decrement promotion usage for unpaid cancellations (Rule 17).
                // Paid orders that are cancelled must NOT decrement promotion usage
                // as the promotion benefit was already delivered and consumed.
                // D3: the system expiry command (orders:cancel-unpaid) passes
                // $skipPromotionDecrement for never-paid expiry cancels (ORD-1) —
                // promotion usage must not move for orders that were never paid.
                if (!$skipPromotionDecrement && $order->payment_status !== Order::PAYMENT_STATUS_SUCCESS) {
                    $this->promotionService->decrementUsage($order->promotion_id ? (int) $order->promotion_id : null);
                }

                // CP-08 (INV-04): every pre-payment cancellation path releases
                // the coupon reservation exactly once. Delete-by-order is
                // structurally idempotent (post-payment cancels find no row).
                $this->couponReservationService->release($order);

                // P7-1: order cancellation cascades to cancellable
                // fulfillments through the fulfillment authority (same
                // transaction — atomic with the order cancel above).
                $this->cancelOpenFulfillmentsForOrder($order, $auditReason, $actorType ?? 'system');
            }

            event(new OrderStatusChanged($order, $previousStatus, $order->status, $actorId ?? null, $actorType ?? 'system'));

            if ($status === Order::ORDER_STATUS_CANCELLED && $previousStatus !== Order::ORDER_STATUS_CANCELLED) {
                event(new OrderCancelled($order));
            }

            if ($status === Order::ORDER_STATUS_DELIVERED && $previousStatus !== Order::ORDER_STATUS_DELIVERED) {
                event(new \App\Events\OrderDelivered($order));
            }

            // completed => payment succeeded: emit the payment-success lifecycle
            // exactly once per completion. Callers that already own the payment
            // event (gateway callback) pass $emitPaymentSuccess = false.
            // D1: listener failures (e.g. invoice generation) must never roll
            // back a valid completion — same guard the callbacks already use.
            if ($status === Order::ORDER_STATUS_COMPLETED && $emitPaymentSuccess) {
                try {
                    event(new \App\Events\PaymentSucceeded($order));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $order;
        });
    }

    /**
     * P7-1 (D7-1): cascade an order cancellation to every cancellable
     * fulfillment (pending/picking/picked/packing/ready_to_ship) through
     * FulfillmentService::cancelFulfillment() — the logic is never
     * duplicated here. Runs inside the caller's order-cancel transaction,
     * so order + fulfillment cancellation are atomic.
     *
     * Lazy service resolution: constructor injection would close a cycle
     * (OrderService → FulfillmentService → PackingService →
     * ShipmentService → OrderService).
     *
     * Lock order is preserved (Order already locked by the caller →
     * Fulfillment → Batch → tasks → packages); cancelFulfillment never
     * locks back toward orders.
     *
     * Shipped/delivered/terminal fulfillments are never force-cancelled —
     * they are surfaced explicitly (log) instead of silently claimed.
     * Guard refusals from the fulfillment authority (sealed custody,
     * packed work, live shipment) propagate so the whole cancellation
     * rolls back atomically instead of orphaning warehouse work.
     */
    private function cancelOpenFulfillmentsForOrder(Order $order, ?string $auditReason, string $actorType): void
    {
        $cancellable = ['pending', 'picking', 'picked', 'packing', 'ready_to_ship'];

        $ids = \App\Models\Fulfillment\Fulfillment::where('order_id', $order->getKey())
            ->whereIn('status', $cancellable)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            $fulfillments = app(\App\Services\Fulfillment\FulfillmentService::class);
            $reason = trim((string) $auditReason) !== ''
                ? trim((string) $auditReason)
                : "Order #{$order->getKey()} cancelled";
            $cancelledBy = auth()->check() ? auth()->id() : null;

            foreach ($ids as $fulfillmentId) {
                $fulfillment = \App\Models\Fulfillment\Fulfillment::whereKey($fulfillmentId)->first();
                if ($fulfillment === null) {
                    continue;
                }
                $fulfillments->cancelFulfillment($fulfillment, 'Order cancelled: ' . $reason, [
                    'cancel_source' => 'order_cancel',
                    'cancelled_by' => $cancelledBy,
                    'actor_type' => $actorType,
                ]);
            }
        }

        $skipped = \App\Models\Fulfillment\Fulfillment::where('order_id', $order->getKey())
            ->whereNotIn('status', array_merge($cancellable, ['cancelled']))
            ->pluck('status', 'id');

        if ($skipped->isNotEmpty()) {
            // Phase 8 (F8-5): a shipped fulfillment is never auto-cancelled
            // and its live shipments are never silently orphaned — surface
            // them explicitly (ids + states) so operations can void the
            // labels through ShipmentService::cancelShipment. Read-only
            // surface: no shipment mutation, no lock-order change, the order
            // cancel above still commits (Phase-7 rule preserved).
            $liveShipments = \App\Models\Shipment::whereIn('fulfillment_id', $skipped->keys()->all())
                ->whereNotIn('status', \App\Models\Shipment::TERMINAL_STATUSES)
                ->pluck('status', 'id')
                ->toArray();
            \Illuminate\Support\Facades\Log::warning('Order cancelled with non-cancellable fulfillments left open', [
                'order_id' => $order->getKey(),
                'skipped_fulfillments' => $skipped->toArray(),
                'live_shipments_requiring_manual_follow_up' => $liveShipments,
            ]);
        }
    }

    /**
     * F-1 helper: does the current authenticated actor hold the dedicated
     * manual-payment permission? The legacy update-order-status permission
     * is intentionally NOT sufficient here.
     */
    private function actorCanMarkPaid(): bool
    {
        try {
            $user = auth()->user();

            if (!$user || !method_exists($user, 'hasAnyPermission')) {
                return false;
            }

            return (bool) $user->hasAnyPermission(['payments.mark_paid']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Phase 8 (D8-5): explicit permission for the force-delivered escape
     * hatch. Reuses the existing update-order-status permission — Phase 8
     * seeds no new permissions (Phase 9 owns RBAC).
     */
    private function actorCanForceDeliver(): bool
    {
        try {
            $user = auth()->user();

            if (!$user || !method_exists($user, 'hasAnyPermission')) {
                return false;
            }

            return (bool) $user->hasAnyPermission(['update-order-status']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function canTransitionFulfillmentStatus(string $from, string $to): bool
    {
        return in_array($to, self::$allowedFulfillmentTransitions[$from] ?? [], true);
    }

    /**
     * D4: modern catalog code -> legacy prefixed `order_status` counterpart.
     * Only codes the legacy Marvel vocabulary understands are mirrored;
     * modern-only stages return null and leave the legacy column untouched.
     */
    private static function mapToLegacyOrderStatus(string $code): ?string
    {
        return [
            'pending' => MarvelOrderStatus::PENDING,
            'processing' => MarvelOrderStatus::PROCESSING,
            'completed' => MarvelOrderStatus::COMPLETED,
            'cancelled' => MarvelOrderStatus::CANCELLED,
            'out_for_delivery' => MarvelOrderStatus::OUT_FOR_DELIVERY,
            'ready_for_pickup' => MarvelOrderStatus::READY_FOR_PICKUP,
        ][$code] ?? null;
    }

    /**
     * Record coupon usage after successful payment.
     *
     * Fail-closed contract (INV-03, CP-04): an order carrying a coupon
     * discount MUST NOT complete unless usage is committed here. Any policy
     * failure throws CouponConsumptionException so the surrounding
     * transaction rolls back and the order stays non-completed.
     *
     * Idempotency (INV-02): same-order repeats are no-ops via the
     * `coupon_consumed` flag + unique-keyed usage rows.
     *
     * Policy: quota is consumed at payment success and NEVER automatically
     * returned on cancellation/refund (POLICY 5, anti-abuse).
     *
     * Assigned coupons record: coupon_assignment_usages + assignments.used +
     * coupons.used. Public coupons record coupon_usages (firstOrCreate) +
     * coupons.used.
     *
     * Concurrency: coupon / assignment rows are locked (lockForUpdate)
     * before incrementing, so concurrent completions cannot over-consume.
     *
     * @throws \App\Exceptions\CouponConsumptionException
     */
    private function recordCouponUsage($order): void
    {
        if (!$order->coupon || $order->coupon_consumed) {
            return;
        }

        // CP-09: canonical lookup (case-insensitive, trimmed).
        $coupon = Coupon::byCode($order->coupon)->lockForUpdate()->first();
        if (!$coupon) {
            throw new \App\Exceptions\CouponConsumptionException(
                \App\Exceptions\CouponConsumptionException::REASON_COUPON_MISSING,
                __('coupon.not_found'),
                ['order_id' => $order->id, 'code' => $order->coupon],
            );
        }

        // POLICY 4: a missing or expired reservation is revalidated and
        // reacquired before consumption. Never complete on a stale hold,
        // never silently skip consumption when the coupon is unavailable.
        $this->revalidateAndReacquireReservation($order, $coupon);

        // Consumption path follows the ORDER's user grant, not the coupon's
        // global assignment population: assignment-family modes gate
        // unassigned users earlier (NOT_ASSIGNED preserved below), while
        // dynamic / assignment_or_dynamic coupons complete unassigned users
        // via the public single-use path (CouponUsage unique).
        $targetingMode = 'assignment';
        if (Schema::hasTable('coupon_targetings') && method_exists($coupon, 'targeting')) {
            $targetingMode = $coupon->targeting?->mode ?? 'assignment';
        }

        $assignment = null;
        if (Schema::hasTable('coupon_assignments')) {
            $assignment = CouponAssignment::where('coupon_id', $coupon->id)
                ->where('user_id', $order->user_id)
                ->lockForUpdate()
                ->first();
        }

        if ($assignment) {
            if ($assignment->used >= $assignment->max_uses) {
                throw new \App\Exceptions\CouponConsumptionException(
                    \App\Exceptions\CouponConsumptionException::REASON_QUOTA_EXHAUSTED,
                    __('coupon.usage_quota_exceeded'),
                    ['order_id' => $order->id, 'assignment_id' => $assignment->id],
                );
            }

            // POLICY 1: prior public consumption blocks assigned-path reuse.
            $publiclyUsed = CouponUsage::where('coupon_id', $coupon->id)
                ->where('user_id', $order->user_id)
                ->whereNotNull('used_at')
                ->exists();
            if ($publiclyUsed) {
                throw new \App\Exceptions\CouponConsumptionException(
                    \App\Exceptions\CouponConsumptionException::REASON_ALREADY_USED,
                    __('coupon.already_used'),
                    ['order_id' => $order->id, 'coupon_id' => $coupon->id],
                );
            }

            if (CouponAssignmentUsage::where('coupon_assignment_id', $assignment->id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->exists()) {
                // S2: idempotent repeat — usage already committed, but make
                // sure the flag reflects it for future fast-path exits.
                if (Schema::hasColumn('orders', 'coupon_consumed') && !$order->coupon_consumed) {
                    $order->update(['coupon_consumed' => true]);
                }

                return;
            }

            $coupon->increment('used');

            $assignment->increment('used');

            CouponAssignmentUsage::create([
                'coupon_assignment_id' => $assignment->id,
                'order_id' => $order->id,
                'used_at' => now(),
            ]);

            // Consume reservation AFTER successful redemption
            $this->couponReservationService->consume($order);

            DB::afterCommit(function () use ($coupon, $assignment, $order) {
                $remainingUses = max(0, $assignment->max_uses - $assignment->fresh()->used);
                event(new AssignedCouponConsumed(
                    coupon: $coupon,
                    couponAssignment: $assignment,
                    user: $order->user,
                    order: $order,
                    remainingUses: $remainingUses,
                    consumedAt: now(),
                ));
            });
        } else {
            // Assignment-family modes fail closed here (defense in depth —
            // apply/checkout already reject unassigned users). Dynamic and
            // assignment_or_dynamic fall through to the public single-use path.
            if ($targetingMode !== 'dynamic' && $targetingMode !== 'assignment_or_dynamic'
                && Schema::hasTable('coupon_assignments') && $coupon->assignments()->exists()) {
                throw new \App\Exceptions\CouponConsumptionException(
                    \App\Exceptions\CouponConsumptionException::REASON_NOT_ASSIGNED,
                    __('coupon.not_assigned'),
                    ['order_id' => $order->id, 'coupon_id' => $coupon->id],
                );
            }

            $couponUsage = CouponUsage::firstOrCreate(
                [
                    'coupon_id' => $coupon->id,
                    'user_id' => $order->user_id,
                ],
                [
                    'order_id' => $order->id,
                    'used_at' => now(),
                ]
            );

            if (!$couponUsage->wasRecentlyCreated) {
                // Single-use public coupon already consumed by another order:
                // completing this order would grant a free second discount.
                throw new \App\Exceptions\CouponConsumptionException(
                    \App\Exceptions\CouponConsumptionException::REASON_ALREADY_USED,
                    __('coupon.already_used'),
                    ['order_id' => $order->id, 'coupon_id' => $coupon->id],
                );
            }

            $coupon->increment('used');

            // Consume reservation AFTER successful redemption
            $this->couponReservationService->consume($order);
        }

        if (Schema::hasColumn('orders', 'coupon_consumed')) {
            $order->update(['coupon_consumed' => true]);
        }

        // Consumption moves quotas/validity: retire discovery caches so the
        // catalog stops advertising exhausted coupons.
        \App\Services\Coupon\Discovery\CouponDiscoveryCache::invalidate();
    }

    /**
     * POLICY 4: ensure a live reservation exists at completion.
     *
     * A stale (expired) or missing reservation is dropped, eligibility +
     * capacity are revalidated, and a fresh reservation is acquired. Any
     * failure throws so completion cannot proceed fail-open.
     *
     * @throws \App\Exceptions\CouponConsumptionException
     */
    private function revalidateAndReacquireReservation($order, $coupon): void
    {
        $reservation = \App\Models\CouponReservation::where('order_id', $order->id)->first();

        if ($reservation && $reservation->expires_at && $reservation->expires_at->isFuture()) {
            // Live reservation = commitment made at checkout with full
            // validation (≤30min old). Completion proceeds on that hold;
            // state drift inside the TTL window is bounded by design
            // (pre-existing POLICY 4 semantics, identical exposure for all
            // legacy rules). Only a stale/missing reservation triggers full
            // revalidation below.
            return;
        }

        if (!$order->user) {
            // Fail closed: no identity means no eligibility (dynamic rules,
            // assignment checks, and per-user limits cannot be evaluated).
            // Occurs when the account was deleted after checkout.
            throw new \App\Exceptions\CouponConsumptionException(
                \App\Exceptions\CouponConsumptionException::REASON_NOT_ELIGIBLE,
                __('coupon.not_eligible'),
                ['order_id' => $order->id, 'coupon_id' => $coupon->id, 'reason' => 'missing_user'],
            );
        }

        if ($reservation) {
            $reservation->delete();
        }

        // Revalidate against the ORDER's own items (M7: the product gate
        // must hold at completion too — pending-order reuse can edit items
        // between checkout and payment). Orders without items skip the gate
        // exactly as the checkout-time validator does for empty item sets.
        $orderItems = $order->orderItems()->get()->map(
            fn ($item) => ['product_id' => $item->product_id]
        );
        // AREA_IN saved-address remediation: payment-time revalidation
        // evaluates the order user's CURRENT saved addresses (same rule as
        // claim/apply/checkout). The ORDER's persisted delivery area
        // (snapshot) drives shipping only, never coupon eligibility.
        // Applies when no live reservation exists; a live reservation is
        // the checkout-time commitment (see revalidateAndReacquireReservation).
        $validation = \App\Services\Coupon\CouponOrchestrator::validate($coupon, $order->user, $orderItems);
        if (!$validation['valid']) {
            throw new \App\Exceptions\CouponConsumptionException(
                \App\Exceptions\CouponConsumptionException::REASON_NOT_ELIGIBLE,
                $validation['message'] ?? __('coupon.not_eligible'),
                ['order_id' => $order->id, 'coupon_id' => $coupon->id, 'reason' => $validation['reason']],
            );
        }

        try {
            $this->couponReservationService->reserve($order, $coupon);
        } catch (\RuntimeException $e) {
            throw new \App\Exceptions\CouponConsumptionException(
                \App\Exceptions\CouponConsumptionException::REASON_NO_CAPACITY,
                $e->getMessage(),
                ['order_id' => $order->id, 'coupon_id' => $coupon->id],
                $e,
            );
        }
    }
}
