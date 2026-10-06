# Phase 5: Order Lifecycle
## Executive Summary
The Order lifecycle manages three state machines — order status, payment status, and fulfillment status — with synchronized transitions driven by the SOLE lifecycle writer `OrderService::changeOrderStatus()`, gated at runtime ONLY by `OrderFlowService::allowsFlowTransition()` (the legacy `$allowedOrderTransitions` map is deprecated, display-only, never consulted). `markCodAsPaid()` / `markCashierPaid()` are thin delegates, not separate writers. Inventory, promotion usage, and coupon consumption are finalized on payment success; cancellation runs an atomic cascade (restore-or-release, conditional promotion decrement, coupon release, fulfillment cascade). Self-transitions (`A → A`) are successful TRUE no-ops: acknowledged with success, zero events, zero history, zero side effects. `completed → cancelled` is FORBIDDEN — post-completion remediation belongs to the Refund/Compensation domain, never to the lifecycle writer.
---
## State Machines
### 1. Order Status
```
                    ┌─────────┐
                    │ PENDING │
                    └────┬────┘
                    ┌────┴────┐
               ┌────▼──┐  ┌──▼─────┐
               │PROCESS │  │CANCELLED│
               │ ING    │  │ (term)  │
               └────┬───┘  └────────┘
               ┌────▼───┐
               │COMPLETED│
               └────┬───┘
               ┌────▼───┐
               │DELIVERED│
               │ (term)  │
               └────────┘
```
**Runtime authority:** `OrderFlowService::allowsFlowTransition($order, $from, $to)` — fail-closed without flow; self (`$from === $to`) allowed; terminal (`delivered`/`cancelled` as source) locked; `cancelled ← anything but completed`; `completed ← anything` (financial gate separate); `delivered ← completed` (terminal absorption) or linear succession; `failed_delivery ← out_for_delivery`; `returned ← failed_delivery/out_for_delivery`. (The legacy `$allowedOrderTransitions` map below is retained for display/migration reference only and MUST NOT authorize transitions.)

Legacy reference (deprecated, NOT runtime authority):
| From \ To | pending | processing | completed | delivered | cancelled |
| **delivered** | | | | ✓ (term) | |
| **cancelled** | | | | | ✓ (term) |
**Constants on Order model:**
| Constant | Value |
| `ORDER_STATUS_PENDING` | `pending` |
| `ORDER_STATUS_PROCESSING` | `processing` |
| `ORDER_STATUS_COMPLETED` | `completed` |
| `ORDER_STATUS_CANCELLED` | `cancelled` |
| `ORDER_STATUS_DELIVERED` | `delivered` |

**Terminal states (verified, pinned by tests):**
```text
cancelled → anything   = REJECTED (cancelled → cancelled = successful no-op)
delivered → anything   = REJECTED (delivered → delivered = successful no-op)
completed → cancelled  = REJECTED (FORBIDDEN — see below)
```
The generic `completed ← anything` milestone rule cannot override terminal protection: the guard checks terminal SOURCES first.

**completed → cancelled is FORBIDDEN.** There is no transition, admin override, or flow path from a completed order to cancelled. Post-completion remediation (fraud, duplicate, void) belongs to the Refund/Compensation domain, which operates payment-marker-only and never mutates lifecycle state.
### 2. Payment Status
**Dual system:** Payment status is both a stored column and a computed accessor.
**Stored column** (conditionally set via `Schema::hasColumn`):
- Set to `payment-pending` on order creation (`OrderCreationService:69`)
- Set to `payment-success` on completion (`changeOrderStatus`, `markCodAsPaid`, `markCashierPaid`)
- Set to `payment-failed` on cancel ONLY via explicit opt-in `markPaymentFailed` (reaper `orders:cancel-unpaid` for never-paid expiry); ordinary cancellations intentionally leave the marker untouched.
**Accessor** (`Order::getPaymentStatusAttribute()`):
```php
public function getPaymentStatusAttribute(): ?string
{
    // 1. If column exists and is not null, return it
    if (array_key_exists('payment_status', $this->attributes) && $this->attributes['payment_status'] !== null) {
        return $this->attributes['payment_status'];
    }

    // 2. For COD/cashier: derive from latest transaction status
    if (in_array($this->payment_method, ['cod', 'pay_at_cashier'])) {
        $latestTransaction = $this->transactions()->latest()->first();
        if ($latestTransaction) {
            return match ($latestTransaction->status) {
                'paid' => PaymentStatus::SUCCESS,        // 'payment-success'
                'failed' => PaymentStatus::FAILED,       // 'payment-failed'
                default => PaymentStatus::PENDING,       // 'payment-pending'
            };
        }
        if (in_array($this->status, ['completed', 'delivered'])) {
            return PaymentStatus::SUCCESS;
        }
        return PaymentStatus::PENDING;
    }

    // 3. For online payment: derive from order status
    return match ($this->status) {
        'completed', 'delivered' => PaymentStatus::SUCCESS,
        'cancelled' => PaymentStatus::FAILED,
        default => PaymentStatus::PENDING,
    };
}
```
**Inconsistency:** The accessor returns values with the `payment-` prefix (e.g. `payment-pending`). The column, when set, also uses the same prefix. However, the Order model's constants like `PAYMENT_STATUS_PENDING = 'payment-pending'` use the same prefix. The raw order status `pending` (without prefix) is the order status, not the payment status. When the frontend checks `payment_status`, it receives `payment-pending`, `payment-success`, etc.
**Payment Status Enum** (separate from Order model constants):
```php
final class PaymentStatus extends Enum
{
    public const PENDING = 'payment-pending';
    public const SUCCESS = 'payment-success';
    public const FAILED  = 'payment-failed';
    public const REFUNDED = 'payment-refunded';
    // ... more values
}
```

### 3. Fulfillment Status

**States:**

```
                    ┌─────────┐
                    │ PENDING │
                    └────┬────┘
                    ┌────┴────┐
               ┌────▼──┐  ┌──▼─────┐
               │PROCESS │  │CANCELLED│
               │ ING    │  │ (term)  │
               └───┬─┬──┘  └────────┘
          ┌────────┘ └────────────┐
   ┌──────▼──────┐        ┌──────▼──────┐
   │ready_for_   │        │out_for_     │
   │pickup       │        │delivery     │
   └──────┬──────┘        └──────┬──────┘
          └──────────┬──────────┘
               ┌────▼───┐
               │DELIVERED│
               │ (term)  │
               └────────┘
```

**Allowed transitions** (from `OrderService::$allowedFulfillmentTransitions`):

| From \ To | pending | processing | ready_for_pickup | out_for_delivery | delivered | cancelled |
|---|---|---|---|---|---|---|
| **pending** | ✓ | ✓ | | | | ✓ |
| **processing** | | ✓ | ✓ | ✓ | | ✓ |
| **ready_for_pickup** | | | ✓ | | ✓ | ✓ |
| **out_for_delivery** | | | | ✓ | ✓ | ✓ |
| **delivered** | | | | | ✓ (term) | |
| **cancelled** | | | | | | ✓ (term) |

**Mapping from order status** (in `changeOrderStatus`):

| Order Status | Fulfillment Status |
|---|---|
| `processing` | `processing` |
| `completed` | `processing` (only if current is `pending`) |
| `cancelled` | `cancelled` |
| `delivered` | `delivered` |

---

## Order Status Change Flow (changeOrderStatus)

```
changeOrderStatus($invoiceId, $status, $orderId, ... 12-param contract)
  └─ DB::transaction
       ├─ Transaction::where('invoice_id', $invoiceId)->first()
       │    └─ transaction->order()->lockForUpdate()
       ├─ OR Order::whereKey($orderId)->lockForUpdate()
       ├─ OrderFlowService::allowsFlowTransition($previous, $status) → throws invalid_flow_transition if rejected
       │    (legacy map NEVER consulted)
       ├─ D2: IF $previousStatus === $status → return successful no-op
       │    (no markers, no history, no invoice, no cascade, no events)
       ├─ Flow Input gate (transition:<status> validated pre-mutation)
       ├─ F-1 payment-authority gate (unpaid → completed needs payments.mark_paid)
       ├─ D8-5 delivered invariant (paid + every fulfillment delivered, or audited force-deliver)
       ├─ Prepare $updateData = ['status' => $status]
       │    ├─ If completed: payment_status=payment-success, completed_at=now(), paid_at kept/set
       │    ├─ If cancelled (first time): cancelled_at=now()
       │    ├─ Reaper opt-in markPaymentFailed: pending/null payment → payment-failed (never clobbers paid)
       │    └─ Fulfillment status mapping (see above) + legacy order_status mirror sync + current_status_id mirror
       ├─ $order->update($updateData) + immutable order_status_history row (flow provenance + sanitized audit context)
       ├─ Invoice exactly once on FIRST transition away from pending (failure-swallowed)
       ├─ IF status === 'completed':
       │    ├─ recordCouponUsage($order)           ← coupon quota consumed (idempotent)
       │    ├─ finalizePromotionUsageAfterPayment  ← promotion consumed once (promotion_consumed guard)
       │    ├─ orderReservationService->commit()   ← idempotent active→committed claim
       │    └─ metrics rebuild deferred to afterCommit
       ├─ IF transaction exists && completed → transaction paid; && cancelled → transaction failed
       ├─ IF status === 'cancelled' && not already cancelled (atomic cascade):
       │    ├─ inventory_state COMMITTED → InventoryRestoreService::restore() (exactly-once state claim)
       │    │  else → orderReservationService->release() (active→released; no-op otherwise)
       │    ├─ promotion decrement ONLY if unpaid and not skipPromotionDecrement (Rule 17 / ORD-1)
       │    ├─ coupon reservation release (structurally idempotent; never returned)
       │    └─ cancelOpenFulfillmentsForOrder cascade (same transaction)
       ├─ event(new OrderStatusChanged($order))            ← real transitions ONLY (never on no-op)
       ├─ IF first-time cancelled → event(new OrderCancelled($order))
       ├─ IF first-time delivered → event(new OrderDelivered($order))
       └─ IF completed && $emitPaymentSuccess → event(new PaymentSucceeded($order))
```

**Self-transition = successful no-op (D2):** the writer short-circuits AFTER flow validation (flow-less orders still fail closed; route-level permissions still run in controllers first) and BEFORE any gate with side effects. Every producer — customer cancel, admin PATCH, Marvel adapter, reaper, payment callbacks, shipment completion, batch, mark-paid — gets identical no-op semantics. A batch mixing real/self/invalid transitions preserves per-order behavior.

**Customer cancel failure mapping (F-05):** a `false` return (order-resolution miss) from the writer maps to the cancel-specific `ERROR_CANCELLING_ORDER` 500 — never the unrelated `ERROR_ADDING_ITEMS_TO_ORDER`.

---

## Events & Listeners

### OrderStatusChanged (`App\Events\OrderStatusChanged`)

| Listener | Queue | Description |
|---|---|---|
| status notification fan-out (admin/user/sms/email/push) + timeline recorders | varies | Dispatched per transition |

Fired on **every REAL status change only**. Self-transitions (`A → A`) emit NOTHING — no event, no fan-out, no timeline entry (D2).

### OrderCancelled (`App\Events\OrderCancelled`)

| Listener | Queue | Description |
|---|---|---|
| `RestoreProductInventory` | `medium` | Delegates to the exactly-once `InventoryRestoreService::restore()` state claim (shared with the synchronous cancel path — parallel execution safe by construction) |
| `SendOrderCancelledNotification` | `medium` | Logs activity via `LogActivityJob` |

Fired on first-time cancellation only. The app provider registers NO `Marvel\Events` listeners (P5-C1 retired); the legacy dual registration is gone.

### OrderCreated (`App\Events\OrderCreated`)

| Listener | Queue | Description |
|---|---|---|
| `SendNewOrderNotification` | — | Sends new order notification |

Fired via `OrderCreationService::finalizeOrder()`.

### PaymentSucceeded (`App\Events\PaymentSucceeded`)

| Listener | Queue | Description |
|---|---|---|
| `SendPaymentSucceededNotification` | `medium` | Logs activity |
| `GenerateInvoiceListener` | `high` (afterCommit, 5 tries) | Generates invoice via `InvoiceService` |

Emission control via `$emitPaymentSuccess`: gateway callbacks own the dispatch and pass false; the writer emits otherwise — exactly once per REAL completion (self-completions emit nothing).

### PaymentFailed (`App\Events\PaymentFailed`)

| Listener | Queue | Description |
|---|---|---|
| `SendPaymentFailedNotification` | `medium` | Logs activity |

Fired by the reaper after canonical expiry-cancel (payment-domain finalization the writer doesn't own).

---

## Inventory Effect on Cancel

Cancellation restores-or-releases based on INVENTORY STATE (not payment):

- `inventory_state === COMMITTED` (paid, or COD/cashier committed at creation) → `InventoryRestoreService::restore()` claims committed→restored exactly once; `inventory_restored_at` is stamped as an observability marker only, never a guard. The queued `RestoreProductInventory` listener shares the same state claim, so listener + synchronous path cannot restore twice.
- otherwise → `orderReservationService->release()` claims active→released (no-op unless active — never double-release).

The two claims are mutually exclusive by state; duplicate cancels converge. All inside the same cancel transaction.

---

## Coupon & Promotion Effect on Cancel

| Discount Type | Reversed on Cancel? | Mechanism |
|---|---|---|
| **Coupon** | **NEVER** | Reservation released (structurally idempotent); quota never returned. Re-ordering with the same coupon stays consumed. |
| **Promotion** | **CONDITIONAL (Rule 17 / ORD-1)** | Unpaid cancel → `decrementUsage()` (floored at 0); paid cancel → usage kept (benefit delivered); never-paid expiry cancel (`orders:cancel-unpaid`, `skipPromotionDecrement`) → untouched. `promotion_consumed` flag never reset. |

**Policy:** Coupon quota is intentionally not returned. This prevents a user from using the same coupon repeatedly by cancelling and re-ordering.

---

## Problems

### P5-C1: Dual event system for OrderCancelled and RestoreProductInventory — RETIRED

The app provider registers no `Marvel\Events` at all. `RestoreProductInventory` listens only to `App\Events\OrderCancelled` and delegates to the exactly-once state claim.

### P5-C2: Payment status dual system inconsistency — FIXED

The accessor falls through on null column values (null-column fallthrough fixed); transaction-matched and status-matched derivations preserved.

### P5-C3: Self-transitions — RESOLVED as D2 successful no-ops

Self-transitions remain VALID flow transitions (the flow definition is unchanged) but the canonical writer short-circuits them: success, zero events, zero history, zero side effects (`OrderLifecycleNoopTest` pins pending→pending, completed→completed, cancelled→cancelled). R5-3's "prevent self-transitions" recommendation is explicitly DECLINED — the API contract keeps them idempotent-friendly.

### P5-C4: Missing payment_status update on cancel — ADDRESSED BY DESIGN

Ordinary cancels intentionally leave the payment marker untouched. The reaper opts in via `markPaymentFailed` (pending/null → `payment-failed`, never clobbers paid). This distinction is intentional: never-paid expiry vs ordinary cancel.

### P5-C5: Fulfillment status column changes are schema-guarded but not atomic — RETAINED POSTURE

The `Schema::hasColumn` guards remain as rolling-deploy safety. No partial-update incident; guards stay.

---

## Production Recommendations

### R5-1: Consolidate to single OrderCancelled event — DONE

No `Marvel\Events` registration app-side; single listener via the state claim.

### R5-2: Standardize payment_status — PARTIALLY DONE

Null-column fallthrough fixed. Conditional guards retained as rolling-deploy safety, not removed.

### R5-3: Prevent self-transitions — DECLINED (D2)

Self-transitions are successful no-ops by approved business decision, not errors. Flow gate still validates them; the writer skips all side effects.

### R5-4: Set payment_status on cancel — EVALUATED, OPT-IN ADOPTED

Blanket marking declined; reaper-only `markPaymentFailed` adopted (see P5-C4).

### R5-5: Add migration to make schema-guarded columns required — DEFERRED POSTURE

Backfill-then-guarantee posture for flow columns (NOT NULL after backfill migration `2026_10_04_000001`); rolling guards retained elsewhere.

### R5-6: Add regression test for promotion decrement on cancel — COVERED

Cancel-branch tests plus `ReaperAuthorityTest` (unpaid decrement + expiry skip) and paid-cancel retention tests pin the conditional behavior.
