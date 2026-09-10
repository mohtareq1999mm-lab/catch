# Implementation Design: Production Hardening & Concurrency Safety

> **Status:** Design Review — No code written yet
> **Date:** 2026-07-25
> **Author:** AI Architecture Review

---

## Table of Contents

1. [C-2: Duplicate Payment Callback (Missing Idempotency)](#c-2-duplicate-payment-callback-missing-idempotency)
2. [C-3: Transaction Lookup Without `lockForUpdate()`](#c-3-transaction-lookup-without-lockforupdate)
3. [C-4: `calcInvoicePrice()` Concurrency Race](#c-4-caltinvoiceprice-concurrency-race)
4. [C-5: Coupon Usage Race (Global Counter Without Lock)](#c-5-coupon-usage-race-global-counter-without-lock)
5. [C-6: `checkoutCallback()` Not Wrapped in Atomic DB Transaction](#c-6-checkoutcallback-not-wrapped-in-atomic-db-transaction)
6. [Flash Sale: Variable-Product Percentage Bug](#flash-sale-variable-product-percentage-bug)
7. [Global Review & Implementation Roadmap](#global-review--implementation-roadmap)

---

# C-2: Duplicate Payment Callback (Missing Idempotency)

---

## 1. Root Cause

**File:** `app/Http/Controllers/Api/General/OrderController.php`
**Method:** `checkoutCallback()` (lines 169–305)

The method has **no idempotency guard at its entry point**. Every incoming callback for the same paymentId is treated as a fresh request. Here is the complete execution flow that repeats on every callback:

1. **Line 171:** Extract `paymentId` from query string or request body.
2. **Lines 176-178:** Look up Transaction by `gateway_transaction_id` or `invoice_id` — no `lockForUpdate()` (this is C-3).
3. **Line 188:** Call gateway's `verifyPayment()` — external HTTP call, repeated on each callback.
4. **Lines 199-204:** Update the Transaction record (sets `status`, `gateway_response`, `paid_at`).
5. **Lines 268-274:** Re-load the user's active cart, re-finalize cart items via `finalizeItemsByShippingMethod()`.
6. **Line 276:** Re-finalize promotion usage via `finalizePromotionUsageAfterPayment()`.
7. **Line 278:** Re-transition order status to 'completed' via `changeOrderStatus()`.
8. **Lines 282-284:** Re-dispatch `PaymentSucceeded` event.

Although `changeOrderStatus()` (OrderService:495) allows the `completed→completed` self-transition (line 485), and `recordCouponUsage()` (OrderService:667) has some idempotency via `CouponAssignmentUsage::exists()` (line 694) and `firstOrCreate` (line 723), the **external side effects are not idempotent**:

- **`finalizeItemsByShippingMethod()`** (CartInventoryService:235) calls `finalizeStock()` (CartInventoryService:419) which **decrements `stock_quantity` and increments `sold_quantity`**. On a duplicate callback, if the cart items still exist (race window before deletion), stock is deducted a second time.
- **`finalizePromotionUsageAfterPayment()`** (OrderService:261) calls `PromotionService::incrementUsage()` (PromotionService:163). When `limiter` is null, this increments `promotions.usage` unconditionally.
- **`PaymentSucceeded` event** is dispatched again, firing listeners (notifications, activity logs) a second time.
- **Gateway `verifyPayment()`** is called again, costing an external API call and potentially marking the invoice as verified again at the gateway.

---

## 2. Failure Scenario

### Thread A (First legitimate callback)
### Thread B (Gateway retry / network duplicate)

```
Timeline:

T1: Thread A enters checkoutCallback(paymentId=X)
T2: Thread A reads Transaction — status=pending, paid_at=null
T3: Thread B enters checkoutCallback(paymentId=X)  [gateway retry]
T4: Thread B reads Transaction — status=pending, paid_at=null
T5: Thread A calls verifyPayment(X) → success
T6: Thread A updates Transaction: status=paid, paid_at=now()
T7: Thread A finalizes cart items → stock deducted correctly
T8: Thread A increments promotion usage (count=1)
T9: Thread A changes order status → completed
T10: Thread A dispatches PaymentSucceeded
T11: Thread B calls verifyPayment(X) → success (or cached)
T12: Thread B updates Transaction: status=paid, paid_at=now()  [no-op, same values]
T13: Thread B finalizes cart items → stock deducted AGAIN (over-deduction)
T14: Thread B increments promotion usage AGAIN (count=2)
T15: Thread B changes order status → completed  [self-transition, harmless]
T16: Thread B dispatches PaymentSucceeded   [duplicate notification]
```

**Database State After:**
- `stock_quantity`: reduced by 2× the intended amount
- `sold_quantity`: inflated by 2×
- `promotions.usage`: incremented twice instead of once
- `orders.status`: 'completed' (correct, but events fired twice)
- `transactions.status`: 'paid' (correct)

---

## 3. Correct Design

### Design: Check-and-Bail Idempotency Guard + `lockForUpdate()`

The entire `checkoutCallback()` method must be wrapped in a single `DB::transaction()` (see C-6). The first operation inside this transaction **must** acquire a `lockForUpdate()` on the Transaction row and check whether the order has already been completed.

**Idempotency check sequence (all inside one transaction):**

1. Acquire `lockForUpdate()` on the Transaction row.
2. If the Transaction's `status` is already 'paid' (or the order's status is already 'completed'), **bail immediately** — return the success response without any side effects.
3. If the Transaction is still pending, proceed with the normal flow.

This is the **same pattern already used** in `RestoreProductInventory.php` (lines 22-25), where a `whereNull('inventory_restored_at')` check combined with `lockForUpdate()` provides idempotency.

### Why This Is Correct

- **Atomic:** The lock and the check happen inside the same DB transaction, so no concurrent request can slip between them.
- **Efficient:** The second (duplicate) callback does nothing except read the already-committed state and return a success response.
- **Consistent with existing patterns:** The `RestoreProductInventory` listener uses exactly this pattern. The `recordCouponUsage` method already uses a similar pattern for the assignment-level check.
- **No external side effects on bail:** The gateway `verifyPayment()` call is made **before** the transaction lock (to get the verified invoice ID), which is acceptable because `verifyPayment` is inherently safe to call multiple times.

### Why `lockForUpdate()` Must Be Used (Not Just a `where('status', 'pending')`)

Without `lockForUpdate()`, two concurrent requests can both read `status = 'pending'` and both proceed. `lockForUpdate()` creates a row-level lock so the second request waits until the first commits, then re-reads the updated state (now `status = 'paid'`) and bails.

---

## 4. Exact Place To Fix

| File | Class | Method | Why |
|------|-------|--------|-----|
| `app/Http/Controllers/Api/General/OrderController.php` | `OrderController` | `checkoutCallback()` | Entry point for payment callbacks. This is where the idempotency check must be added. |
| `app/Services/General/OrderService.php` | `OrderService` | `changeOrderStatus()` | Already handles self-transition correctly at line 485. No change needed for status. |
| `app/Services/General/CartInventoryService.php` | `CartInventoryService` | `finalizeItemsByShippingMethod()` | Should ideally be idempotent-safe, but with the guard at `checkoutCallback`, it won't be called twice. No change needed. |

**No other files require changes for this issue alone.** The fix is entirely within `checkoutCallback()`.

---

## 5. Required Changes

### 5.1 Add idempotency guard at the start of the success path

Inside `checkoutCallback()`, after verifying the payment and checking success, but before any mutation:

1. Wrap the entire mutation block in a `DB::transaction()` (see C-6).
2. Inside the transaction, re-fetch the Transaction with `lockForUpdate()`.
3. Check if the Transaction is already `paid` or the Order is already `completed`.
4. If already processed, return the success redirect immediately — do not re-apply inventory, promotion, or events.

### 5.2 Move the `verifyPayment()` call outside the transaction

The gateway HTTP call should remain outside the DB transaction to avoid holding a DB connection open during an external HTTP request.

**Execution order after fix:**
1. Extract `paymentId` (unchanged).
2. `verifyPayment(paymentId)` — external call (outside transaction).
3. If not successful, handle failure (unchanged).
4. **Begin DB transaction**
5. **Re-fetch Transaction with `lockForUpdate()`**
6. **If already paid → bail (return success redirect)**
7. Re-fetch Order with `lockForUpdate()` (via `changeOrderStatus`)
8. Finalize inventory, promotion, coupon, change order status
9. **Commit DB transaction**
10. Dispatch events after commit (using `DB::afterCommit` or after the transaction closes)
11. Return success redirect

### 5.3 Deduplicate event dispatching

The `PaymentSucceeded` event should only be dispatched if the order was NOT already completed. This is naturally handled by the idempotency guard (step 6 above).

---

## 6. Side Effects

| Flow | Impact |
|------|--------|
| **Checkout** | None. The guard only activates on duplicate callbacks. |
| **COD** | Not affected. COD uses `markCodAsPaid()` which has its own transaction and locking. |
| **Cashier** | Not affected. Same as COD. |
| **Online Payment** | Directly affected (this is the flow being fixed). Duplicate callbacks now do nothing. |
| **Retry Payment** | If a payment is genuinely retried (different paymentId), the guard won't trigger because it matches on Transaction+Order state. |
| **Pending Orders** | No impact. The guard only applies to completed orders. |
| **Inventory** | No longer double-deducted on duplicate callbacks. |
| **Coupons** | No longer double-consumed. |
| **Promotions** | No longer double-incremented. |
| **Order Cancellation** | Not affected. |
| **Refund** | Not affected. |
| **Callbacks / Webhooks** | All callback flows benefit from the guard. |

---

## 7. Compatibility

| Question | Answer |
|----------|--------|
| **Break APIs?** | No. The response format is unchanged. Duplicate callbacks return the same success response. |
| **Require migration?** | No. |
| **Require new columns?** | No. The check uses existing `transactions.status` and `orders.status`. |
| **Require new indexes?** | No. `gateway_transaction_id` and `invoice_id` are already indexed (or retrieved by primary key). |
| **Require config changes?** | No. |
| **Require queue changes?** | No. |
| **Require cache invalidation?** | No. |

---

## 8. Required Tests

| Test | Type | Purpose | Expected |
|------|------|---------|----------|
| Duplicate callback returns same success | Feature | Call `checkoutCallback` twice with same paymentId | Second call returns same success response, no inventory double-deducted |
| Duplicate callback does not double-deduct stock | Feature | Verify `stock_quantity` after two callbacks | Stock deducted exactly once |
| Duplicate callback does not double-increment promotion | Feature | Verify `promotions.usage` after two callbacks | Incremented exactly once |
| Duplicate callback does not double-dispatch events | Feature | Use event fakes | `PaymentSucceeded` dispatched exactly once |
| Concurrent duplicate callbacks are safe | Concurrency | Dispatch two callbacks simultaneously using `Http::pool` or parallel requests | Exactly one processes, the other bails |
| Idempotency guard does not block first callback | Feature | Normal single callback | Processes correctly, inventory/promotion updated |
| Failed payment still reports failure | Feature | Callback with failed payment | Returns failure redirect, no idempotency check triggered |

---

## 9. Risk Assessment

| Risk | Score | Explanation |
|------|-------|-------------|
| **Implementation Risk** | 2/10 | The pattern is well-understood and already used elsewhere (`RestoreProductInventory`). Low complexity. |
| **Regression Risk** | 3/10 | If the transaction wrapping introduces an unexpected rollback on failure, a legitimate callback could fail. Mitigated by proper try/catch. |
| **Performance Impact** | 1/10 | An additional `lockForUpdate()` query adds negligible latency. |
| **Deadlock Risk** | 4/10 | `lockForUpdate()` on Transaction could deadlock if other code locks the same row in a different order. However, the only other place locking Transaction is `changeOrderStatus()` (via `transactions() order()->lockForUpdate()`), and those are called within `checkoutCallback`'s own transaction. The lock is acquired at the start, so ordering is consistent. |
| **Maintenance Impact** | 1/10 | The guard is a simple `if` check. Very low ongoing cost. |

---

## 10. Rollback Plan

**Revert:** Undo the changes in `checkoutCallback()`. Remove the `DB::transaction()` wrapper, remove the `lockForUpdate()` check, and restore the original method body.

**Procedure:**
1. `git revert <commit-hash>`
2. Deploy to staging, verify basic callback flow.
3. Deploy to production.

**Risk of rollback:** Returns to the current broken state (no idempotency). This is acceptable since it's the current state.

---

# C-3: Transaction Lookup Without `lockForUpdate()`

---

## 1. Root Cause

**File:** `app/Http/Controllers/Api/General/OrderController.php`
**Method:** `checkoutCallback()` (lines 176-178)

```php
$transaction = Transaction::where('gateway_transaction_id', $paymentId)
    ->orWhere('invoice_id', $paymentId)
    ->first();  // NO lockForUpdate!
```

This plain `->first()` read creates a **TOCTOU (Time of Check, Time of Use)** vulnerability. Because no exclusive lock is held on the Transaction row, two concurrent callback invocations can both read the same Transaction with `status = 'pending'` simultaneously. Both will proceed to process the payment.

**Contrast with correct patterns elsewhere:**
- `markCodAsPaid()` (OrderService:574): Uses `->lockForUpdate()->first()` on the Transaction.
- `markCashierPaid()` (OrderService:605): Uses `->lockForUpdate()->first()` on the Transaction.
- `addItemsInOrder()` (OrderService:156): Uses `->lockForUpdate()` on the Cart.

The callback is the **only** payment-processing path that omits `lockForUpdate()`.

---

## 2. Failure Scenario

(Identical to C-2 scenario, as C-3 is the mechanism by which C-2 is exploitable.)

```
Database state: Transaction(gateway_transaction_id=X, status=pending)

Thread A: SELECT * FROM transactions WHERE gateway_transaction_id = X
          → status=pending
Thread B: SELECT * FROM transactions WHERE gateway_transaction_id = X
          → status=pending  [SAME stale state!]
Thread A: UPDATE transactions SET status='paid', paid_at=NOW() WHERE id=...
Thread B: UPDATE transactions SET status='paid', paid_at=NOW() WHERE id=...
          [Both succeed, same final state on Transaction]
Thread A: finalizeItemsByShippingMethod() → stock deducted
Thread B: finalizeItemsByShippingMethod() → stock deducted AGAIN
```

The Transaction UPDATE is itself idempotent (setting the same values), but the downstream side effects (inventory, promotions, events) are not.

---

## 3. Correct Design

**Change the Transaction lookup from `->first()` to `->lockForUpdate()->first()`** inside a DB transaction.

The `lockForUpdate()` creates a row-level exclusive lock that blocks concurrent readers. Thread B's SELECT...FOR UPDATE will block until Thread A's transaction completes (commits or rolls back). At that point, Thread B re-reads the row and sees `status = 'paid'`, allowing the idempotency guard (C-2) to bail out.

### Why This Is Correct

- **Pessimistic locking** is the right tool here because the conflict window is real (payment gateway retries) and the cost of conflicts is high (inventory corruption).
- **Consistent with the rest of the codebase** — every other payment mutation path uses `lockForUpdate()`.
- **MySQL/InnoDB handles this efficiently** — row-level locks are released on commit/rollback. No table locks.

---

## 4. Exact Place To Fix

| File | Class | Method | Lines | Why |
|------|-------|--------|-------|-----|
| `app/Http/Controllers/Api/General/OrderController.php` | `OrderController` | `checkoutCallback()` | 176-178, 192-196 | Both Transaction lookups must use `lockForUpdate()` |
| `app/Http/Controllers/Api/General/OrderController.php` | `OrderController` | `checkoutErrorCallback()` | 314-316, 332-336 | Same pattern, though error path has fewer side effects |

**Note:** The fix for C-3 is technically bundled into C-2 and C-6. Adding a `DB::transaction()` and relocating the lookup inside it with `lockForUpdate()` achieves all three goals simultaneously.

---

## 5. Required Changes

1. Move the Transaction lookup **inside** the `DB::transaction()` block.
2. Change `->first()` to `->lockForUpdate()->first()`.
3. The `verifyPayment()` call remains outside the transaction (before it).
4. The second Transaction lookup (line 192-196, for when the first lookup found nothing) must also use `lockForUpdate()`.
5. Apply the same fix to `checkoutErrorCallback()` — same pattern, same lines.

---

## 6. Side Effects

| Flow | Impact |
|------|--------|
| All flows | No negative impact. The lock is only held for the duration of the callback transaction (milliseconds). Other operations on the Transaction row (e.g., admin queries) may briefly wait, but this is normal DB behavior. |

---

## 7. Compatibility

| Question | Answer |
|----------|--------|
| **Break APIs?** | No. |
| **Require migration?** | No. |
| **Require new columns?** | No. |
| **Require new indexes?** | No. |
| **Require config changes?** | No. |
| **Require queue changes?** | No. |
| **Require cache invalidation?** | No. |

---

## 8. Required Tests

(Same as C-2 — covering the concurrency scenario.)

| Test | Type | Purpose |
|------|------|---------|
| `concurrent_duplicate_callbacks_are_serialized_by_lock` | Concurrency | Fire two callbacks in parallel; verify only one processes inventory |

---

## 9. Risk Assessment

| Risk | Score | Explanation |
|------|-------|-------------|
| **Implementation Risk** | 1/10 | Changing `first()` to `lockForUpdate()->first()` is a single-word change. |
| **Regression Risk** | 2/10 | If the query builder's `->lockForUpdate()` is not supported by the database driver (SQLite), it fails silently or throws. SQLite supports it in recent versions, but the test suite runs on SQLite. A `sharedLock` fallback may be needed for tests. |
| **Performance Impact** | 1/10 | Row lock is held for a few milliseconds during the callback transaction. |
| **Deadlock Risk** | 3/10 | Potential deadlock if Transaction rows are locked in different orders. Mitigation: always lock Transaction first in the callback. In `changeOrderStatus()`, the lock is on Order (via `order()->lockForUpdate()`), not on Transaction directly. |
| **Maintenance Impact** | 1/10 | Zero maintenance. |

---

## 10. Rollback Plan

Same as C-2 (the changes are in the same method).

---

# C-4: `calcInvoicePrice()` Concurrency Race

---

## 1. Root Cause

**File:** `app/Services/General/OrderService.php`
**Method:** `calcInvoicePrice()` (lines 104-146)

```php
public function calcInvoicePrice($request)
{
    try {
        DB::beginTransaction();
        $cart = $this->getCartUser();  // NO lockForUpdate!
        if (!$cart) {
            DB::rollBack();
            throw new \InvalidArgumentException(__('checkout.cart_not_found'));
        }
        // ... calculates prices, applies coupon, etc ...
        $cart->update(['total_price' => $finalTotal]);
        DB::commit();
        return $cart->total_price;
    } catch (...) { ... }
}
```

The `getCartUser()` method (line 328-335) does NOT use `lockForUpdate()`:

```php
private function getCartUser()
{
    return Cart::query()
        ->where('user_id', auth()->id())
        ->where('status', 'active')
        ->with(['items' => ..., 'items.product.flash_sales' => ..., 'items.productVariant'])
        ->first();
}
```

**Contrast with `addItemsInOrder()`** (line 148), which does:
```php
$cart = Cart::query()
    ->where('user_id', auth()->id())
    ->where('status', 'active')
    ->lockForUpdate()  // CORRECT
    ->with([...])
    ->first();
```

**Why this is a race:**

The method does the following inside a transaction but without locking the cart:
1. Read cart (line 108) — no lock
2. Read cart items (via eager loading, accessed at line 113 via `$cart->items->isEmpty()`)
3. Calculate checkout totals from the cart items (line 123)
4. Update cart's `total_price` (line 135)

If the user adds items to their cart between steps 1 and 4, or if a concurrent request from the same user modifies the cart, the calculation becomes stale. Steps 1-4 should be protected by `lockForUpdate()` on the cart row.

---

## 2. Failure Scenario

```
Thread A (calcInvoicePrice for User X)
Thread B (addItemsToCart for User X — via ajax/cart API)

Timeline:
T1: Thread A begins calcInvoicePrice()
T2: Thread A reads Cart (items: A=qty2, B=qty1)  → subtotal=$100
T3: Thread B adds Item C (qty1, price=$50) to cart
T4: Thread A calculates price: subtotal=$100, coupon applied on $100
T5: Thread A updates cart.total_price = $100
T6: Thread A commits transaction
T7: Thread B commits its cart update

Result: checkout shows $100, but actual cart has items worth $150.
Customer pays $100 for $150 worth of items.
```

Conversely, if Thread A starts after Thread B but before Thread B's DB write:

```
T1: Thread A reads Cart (items: A=qty2, B=qty1, C=qty1) → subtotal=$150
T2: Thread B removes Item C from cart
T3: Thread A calculates price: subtotal=$150 (stale data)
T4: Thread A updates cart.total_price = $150 (but cart only has $100 worth)

Result: Customer is quoted $150 for $100 worth of items.
```

---

## 3. Correct Design

**Add `lockForUpdate()` to the cart query in `getCartUser()`.**

This is a one-line change that makes `calcInvoicePrice()` consistent with `addItemsInOrder()`.

When `lockForUpdate()` is applied, Thread B's `addItemsToCart` will block if it tries to modify the same cart row while Thread A's `calcInvoicePrice` transaction is active. Thread B waits until Thread A commits, then proceeds with the updated state.

### Why This Is Correct

- **Consistency with `addItemsInOrder()`:** The same pattern is already used at line 156.
- **Pessimistic locking for financial calculations:** Invoice price is a financial computation. Even a rare race can cause financial loss. Pessimistic locking is appropriate.
- **Minimal lock duration:** The lock is held only for the duration of `calcInvoicePrice()` (a few DB queries — < 100ms in normal conditions).

### Why It Is Safe

- The cart row is only modified by the owning user, so cross-user contention is impossible.
- The lock prevents the same user from having two concurrent invoice calculations with different cart states.
- The lock is released on `DB::commit()` or `DB::rollBack()`.

---

## 4. Exact Place To Fix

| File | Class | Method | Lines | Why |
|------|-------|--------|-------|-----|
| `app/Services/General/OrderService.php` | `OrderService` | `getCartUser()` | 330 | Private method called only by `calcInvoicePrice()`. Adding `lockForUpdate()` here protects the only caller. |

---

## 5. Required Changes

1. Add `->lockForUpdate()` after `->where('status', 'active')` on line 332.

**Before:**
```php
return Cart::query()
    ->where('user_id', auth()->id())
    ->where('status', 'active')
    ->with([...])
    ->first();
```

**After:**
```php
return Cart::query()
    ->where('user_id', auth()->id())
    ->where('status', 'active')
    ->lockForUpdate()
    ->with([...])
    ->first();
```

**No other changes needed.**

---

## 6. Side Effects

| Flow | Impact |
|------|--------|
| **Checkout** | Cart is locked during price calculation. If the user tries to modify their cart via AJAX while `calcInvoicePrice` is running, the AJAX request waits a few ms. Negligible UX impact. |
| **COD / Cashier / Online** | All call `calcInvoicePrice` indirectly (via the checkout flow). All benefit from the fix. |
| **Cart modification (add/remove items)** | May briefly block behind the lock. Normal database behavior. |
| **All other flows** | No impact. `getCartUser()` is private and only called from `calcInvoicePrice()`. |

---

## 7. Compatibility

| Question | Answer |
|----------|--------|
| **Break APIs?** | No. Response format unchanged. |
| **Require migration?** | No. |
| **Require new columns?** | No. |
| **Require new indexes?** | No. |
| **Require config changes?** | No. |
| **Require queue changes?** | No. |
| **Require cache invalidation?** | No. |

---

## 8. Required Tests

| Test | Type | Purpose | Expected |
|------|------|---------|----------|
| Concurrent calcInvoicePrice and cart modification | Concurrency | Thread A calls calcInvoicePrice, Thread B adds item. | Cart is locked; Thread B waits; total_price calculated on correct state. |
| Normal calcInvoicePrice still works | Feature | Standard checkout price calculation | Returns correct total. |

---

## 9. Risk Assessment

| Risk | Score | Explanation |
|------|-------|-------------|
| **Implementation Risk** | 1/10 | Single-line addition of `->lockForUpdate()`. |
| **Regression Risk** | 2/10 | If SQLite doesn't support `lockForUpdate` on the version used, tests may fail. Mitigation: use `DB::connection()->getDriverName()` check or ensure SQLite PDO supports it. |
| **Performance Impact** | 1/10 | Negligible. Lock held for sub-second duration. |
| **Deadlock Risk** | 2/10 | `calcInvoicePrice` only locks the cart row. `addItemsInOrder` also locks the cart row (same order). No cross-row lock ordering issue. |
| **Maintenance Impact** | 1/10 | None. |

---

## 10. Rollback Plan

**Revert:** Remove `->lockForUpdate()` from `getCartUser()`.

`git revert <commit-hash>`

---

# C-5: Coupon Usage Race (Global Counter Without Lock)

---

## 1. Root Cause

**File:** `app/Services/General/OrderService.php`
**Method:** `recordCouponUsage()` (lines 667-738)

**Two paths, two problems:**

### Path A: Assigned Coupons (lines 680-721)

1. **Line 673:** `$coupon = Coupon::where('code', $order->coupon)->first();` — **No `lockForUpdate()`** on the global `coupons` row.
2. **Line 683:** `CouponAssignment::where(...)->lockForUpdate()->first();` — Assignment row IS locked. **Correct.**
3. **Line 701:** `$coupon->increment('used');` — Increments the global `coupons.used` counter on an **unlocked model instance**. The `$coupon` was loaded at line 673 (without lock), so by line 701 the value may be stale.

### Path B: Public Coupons (lines 722-737)

1. **Line 723:** `CouponUsage::firstOrCreate(...)` — Uses unique constraint `(coupon_id, user_id)` for idempotency. **Correct for per-user dedup.**
2. **Line 735:** `$coupon->increment('used');` — Same issue: operates on the unlocked model loaded at line 673.

**Why the global `coupons.used` matters:**

The `Coupon` model has a `limiter` field (max total uses). This is checked at validation time (CouponValidator:28 — `used >= limiter`), but NOT re-checked at consumption time inside `recordCouponUsage()`. Two concurrent checkouts that both pass validation (because both see `used < limiter`) can both call `increment('used')`, potentially exceeding the limiter.

While `Coupon::scopeValid()` (Coupon model:126) filters `used < limiter`, and validation is done via this scope, the **consumption path** does not re-lock or re-validate the limiter.

---

## 2. Failure Scenario

```
Coupon: code=SUMMER20, limiter=1, used=0

Thread A (User 1 checkout)
Thread B (User 2 checkout)

Timeline:
T1: User 1's checkout validates coupon: scopeValid() sees used=0 < limiter=1 → valid
T2: User 2's checkout validates coupon: scopeValid() sees used=0 < limiter=1 → valid
T3: Thread A enters recordCouponUsage() — $coupon = Coupon::where('code', ...)->first() (used=0, no lock)
T4: Thread B enters recordCouponUsage() — $coupon = Coupon::where('code', ...)->first() (used=0, no lock)
T5: Thread A: $coupon->increment('used') → used becomes 1
T6: Thread B: $coupon->increment('used') → used becomes 2  [EXCEEDS limiter=1!]

Result: coupons.used = 2, but limiter = 1. The coupon's max usage is exceeded.
```

For **assigned coupons**, the assignment-level check (line 690: `$assignment->used >= $assignment->max_uses`) is correctly protected by `lockForUpdate()`. But the **global** counter is not.

For **public coupons with `firstOrCreate`**, the per-user unique constraint prevents the same user from using the coupon twice. But **different users** in concurrent requests can both pass the unique constraint (because `coupon_id, user_id` pairs are different) and both increment the global counter.

---

## 3. Correct Design

**Lock the `coupons` row before incrementing `used`.**

The fix is to load the `Coupon` with `lockForUpdate()` at the beginning of `recordCouponUsage()`, inside the same transaction that the caller (`changeOrderStatus()`, `markCodAsPaid()`, `markCashierPaid()`) already establishes.

### Design: Lock the Coupon Row

1. Load `$coupon` with `lockForUpdate()` instead of plain `first()`.
2. After locking, re-check `used >= limiter` (if limiter is set).
3. If the limiter would be exceeded, **bail** without recording usage or incrementing.
4. Otherwise, increment and proceed.

### Why This Is Correct

- **Consistency:** The coupon validation (at checkout time) checks `used < limiter`, but between validation and consumption (which can be minutes or hours later for COD), other orders may have consumed the quota. The lock re-validates at consumption time.
- **Minimal change:** Only the Coupon loading line (673) needs to change. The `increment('used')` at lines 701 and 735 then operates on the freshly locked value.
- **Same pattern as assignment lock:** The assignment row is already locked (line 683). Adding the lock on the parent coupon row completes the protection.

### Why the Assignment Counter Does Not Need This Fix

The assignment row is already locked (line 683) and the `used >= max_uses` check (line 690) happens after the lock. This is correct. The gap is only the global `coupons.used` counter.

---

## 4. Exact Place To Fix

| File | Class | Method | Lines | Why |
|------|-------|--------|-------|-----|
| `app/Services/General/OrderService.php` | `OrderService` | `recordCouponUsage()` | 673 | Add `->lockForUpdate()` to the Coupon lookup |
| `app/Services/General/OrderService.php` | `OrderService` | `recordCouponUsage()` | 671-676 | Optionally add a limiter re-check after locking |

---

## 5. Required Changes

### 5.1 Lock the coupon row

Change line 673:
```php
// Before:
$coupon = Coupon::where('code', $order->coupon)->first();

// After:
$coupon = Coupon::where('code', $order->coupon)->lockForUpdate()->first();
```

### 5.2 Add limiter re-check (optional but recommended)

After line 675 (`if (!$coupon) { return; }`), add:
```
If the coupon has a limiter set AND `$coupon->used >= $coupon->limiter`, return early (do not record usage).
```

This prevents the global counter from exceeding the limiter even if validation passed earlier.

**Note:** The `changeOrderStatus()` method is always called within a `DB::transaction()` (line 497), and `markCodAsPaid()` and `markCashierPaid()` also wrap calls in `DB::transaction()`. So the `lockForUpdate()` will function correctly.

---

## 6. Side Effects

| Flow | Impact |
|------|--------|
| **Checkout** | Coupon consumption is now fully locked. The coupon row is briefly locked during `changeOrderStatus()` / COD marking. No other process can modify the same coupon concurrently. |
| **COD** | `markCodAsPaid()` calls `recordCouponUsage()` inside its transaction (line 588). The coupon is locked here too. |
| **Cashier** | Same as COD. |
| **Online Payment** | `changeOrderStatus('completed')` calls `recordCouponUsage()` (line 535). Protected. |
| **Coupon Assignment** | The assignment lock (line 683) now operates alongside the coupon lock. Acquiring locks in the same order (coupon first, then assignment) is important for deadlock prevention. |
| **Coupon Validation** | No impact. Validation reads without lock, which is acceptable (it's a non-binding pre-check). The binding check happens at consumption. |
| **Order Cancellation** | Coupon is NEVER decremented on cancellation (by design, per policy comment at line 649). No impact. |

---

## 7. Compatibility

| Question | Answer |
|----------|--------|
| **Break APIs?** | No. |
| **Require migration?** | No. |
| **Require new columns?** | No. |
| **Require new indexes?** | No. |
| **Require config changes?** | No. |
| **Require queue changes?** | No. |
| **Require cache invalidation?** | No. |

---

## 8. Required Tests

| Test | Type | Purpose | Expected |
|------|------|---------|----------|
| Concurrent coupon consumption — assigned coupon | Concurrency | Two users with assigned coupon quotas checkout simultaneously | Both get their usage counted; `coupons.used` incremented correctly by 2. |
| Concurrent coupon consumption — same public coupon, different users | Concurrency | Two different users with the same public coupon checkout simultaneously | Both get their usage; `coupons.used` incremented by 2 (if limiter allows). |
| Coupon limiter not exceeded under concurrent load | Concurrency | Multiple concurrent checkouts with limiter=1 for a public coupon | Only one succeeds; the other(s) see `used >= limiter` and skip recording. |
| Coupon usage not recorded when limiter already reached | Feature | Coupon with limiter already reached | `recordCouponUsage` returns early without incrementing. |

---

## 9. Risk Assessment

| Risk | Score | Explanation |
|------|-------|-------------|
| **Implementation Risk** | 1/10 | Adding `->lockForUpdate()` to one query line. |
| **Regression Risk** | 2/10 | The lock may cause `recordCouponUsage()` to block briefly if another transaction holds the same coupon row lock. The caller must handle this gracefully (it does — all callers have try/catch). |
| **Performance Impact** | 1/10 | Single extra row lock. Sub-millisecond in normal load. |
| **Deadlock Risk** | 5/10 | **This is the highest risk item.** `recordCouponUsage()` acquires locks in this order: (1) Coupon row (new), (2) CouponAssignment row (existing). If any other code path acquires locks in the opposite order (Assignment first, then Coupon), a deadlock is possible. **Required:** Audit all code that locks `coupon_assignments` to ensure lock order is consistent. The `CouponAssignmentRepository::removeAssignment()` (line 114) locks Assignment only. This must be reviewed. |
| **Maintenance Impact** | 1/10 | None. |

---

## 10. Rollback Plan

**Revert:** Remove `->lockForUpdate()` from line 673 and remove the limiter re-check block.

`git revert <commit-hash>`

---

# C-6: `checkoutCallback()` Not Wrapped in Atomic DB Transaction

---

## 1. Root Cause

**File:** `app/Http/Controllers/Api/General/OrderController.php`
**Method:** `checkoutCallback()` (lines 169-305)

The method performs multiple dependent mutations **without a wrapping database transaction**:

1. Lines 199-204: Update Transaction (DB write)
2. Lines 268-274: Finalize cart items via `CartInventoryService::finalizeItemsByShippingMethod()` — this method HAS its own internal transaction (CartInventoryService:235)
3. Line 276: Finalize promotion usage via `finalizePromotionUsageAfterPayment()` — calls `PromotionService::incrementUsage()` which is NOT transactional on its own (it's a single `increment()` call)
4. Line 278: Change order status via `changeOrderStatus()` — this method HAS its own internal transaction (OrderService:497)

**The problem is that each step is individually transactional, but the steps are not atomic together.**

If the process crashes after step 2 but before step 4:
- Transaction is updated to 'paid'
- Cart items are finalized (stock deducted)
- Promotion usage is NOT incremented
- Order status is NOT changed to 'completed'
- `PaymentSucceeded` is NOT dispatched

The system is in an inconsistent state: the payment is recorded as paid, inventory is deducted, but the order remains 'pending'.

**Contrast with `markCodAsPaid()`** (OrderService:567-596) and **`markCashierPaid()`** (OrderService:598-627):
Both correctly wrap ALL mutations (transaction update, order update, coupon recording, promotion finalization, inventory finalization, event dispatching) inside a **single** `DB::transaction()`.

---

## 2. Failure Scenario

```
Timeline:

T1: Gateway sends callback for payment X
T2: verifyPayment() → success
T3: Transaction updated to 'paid', paid_at=now()       ← committed (separate UPDATE)
T4: CRASH! Server process dies (OOM, deploy, network loss)
T5: -- System is now inconsistent --
    Transaction: status=paid, paid_at=2026-07-25 12:00:00
    Order: status='pending'  (NOT updated!)
    Inventory: stock deducted (via finalizeItemsByShippingMethod which completed before crash)
    Promotion usage: NOT incremented
    PaymentSucceeded: NOT dispatched
    Customer: payment taken, order shows as pending
```

When the callback retries:
- Transaction already shows 'paid' (but without the guard from C-2, the retry would try again)
- With C-2 guard: retry sees `status=paid`, bails. The order stays 'pending' forever.

---

## 3. Correct Design

**Wrap the entire mutation block of `checkoutCallback()` inside a single `DB::transaction()`.**

Execution flow after fix:

```
DB::transaction(function () use (...) {
    // 1. Lock Transaction row (lockForUpdate) — C-3
    // 2. Idempotency check — C-2: if already paid, bail
    // 3. Update Transaction
    // 4. Finalize cart items (uses CartInventoryService, already transactional)
    // 5. Finalize promotion usage
    // 6. Change order status (uses changeOrderStatus, already transactional)
    // 7. (Transaction commits here — ALL changes are atomic)
});

// 8. After commit: dispatch events
event(new PaymentSucceeded($order));
```

### Why This Is Correct

- **Atomicity:** Either ALL mutations succeed or NONE do.
- **Consistency with COD/Cashier paths:** Both `markCodAsPaid()` and `markCashierPaid()` already use this pattern.
- **Nested transactions work correctly:** Laravel's `DB::transaction()` uses a savepoint-based approach for nested transactions. The inner transactions in `finalizeItemsByShippingMethod` and `changeOrderStatus` become savepoints, not independent transactions. If the outer transaction rolls back, everything rolls back.
- **Event dispatching after commit:** Events are dispatched after the transaction commits, so listeners see a consistent state.

### Why Nested Transactions Work

Laravel's `DB::transaction()` uses a counter-based approach:
- The outermost `transaction()` call creates a real database transaction.
- Nested `transaction()` calls create savepoints.
- If a nested "transaction" rolls back, only the savepoint is rolled back (the inner changes are undone).
- If the outermost transaction rolls back, EVERYTHING is undone.
- If the outermost transaction commits, EVERYTHING is committed.

This means `finalizeItemsByShippingMethod()` (which calls `DB::transaction()` internally) and `changeOrderStatus()` (which also calls `DB::transaction()` internally) will both work correctly inside the outer transaction.

---

## 4. Exact Place To Fix

| File | Class | Method | Lines | Why |
|------|-------|--------|-------|-----|
| `app/Http/Controllers/Api/General/OrderController.php` | `OrderController` | `checkoutCallback()` | 267-278 | Wrap the mutation block in `DB::transaction()` |

---

## 5. Required Changes

### 5.1 Wrap mutations in DB::transaction()

Move lines 267-278 (the success mutation block after the mismatch check) into a `DB::transaction()` closure.

**Before (pseudo):**
```php
if ($order) {
    // ... mismatch check ...
    if ($hasMismatch) { ... return failure; }

    // Mutations (NOT atomic):
    if ($user = User::find($order->user_id)) {
        $cart = $this->cartInventoryService->getActiveCartForUser($user);
        if ($cart) { ... finalizeItemsByShippingMethod(...); }
    }
    $this->orderService->finalizePromotionUsageAfterPayment($order);
    $order = $this->orderService->changeOrderStatus($transaction->invoice_id, 'completed');
}
```

**After (pseudo):**
```php
if ($order) {
    // ... mismatch check (remains outside transaction) ...

    DB::transaction(function () use ($order, $transaction, $paymentId) {
        // Re-lock Transaction with lockForUpdate
        $transaction = Transaction::where('gateway_transaction_id', $paymentId)
            ->orWhere('invoice_id', $paymentId)
            ->lockForUpdate()
            ->first();

        // Idempotency check
        if ($transaction->status === 'paid') {
            return; // Bail — already processed
        }

        // Update Transaction
        $transaction->update([...]);

        // Finalize cart
        if ($user = User::find($order->user_id)) {
            $cart = $this->cartInventoryService->getActiveCartForUser($user);
            if ($cart) { ... finalizeItemsByShippingMethod(...); }
        }

        $this->orderService->finalizePromotionUsageAfterPayment($order);
        $order = $this->orderService->changeOrderStatus($transaction->invoice_id, 'completed');
    });

    // Event dispatching AFTER transaction commit
    event(new PaymentSucceeded($order));
}
```

### 5.2 Move event dispatching after the transaction

Events should be dispatched after the transaction commits so that event listeners see committed data. Use either:
- Explicit placement after the `DB::transaction()` block, OR
- `DB::afterCommit()` inside the block

### 5.3 Remove inner `lockForUpdate` duplication if applicable

Since the outer transaction already locks the Transaction row, the `changeOrderStatus()` method's transaction will create a savepoint. The `lockForUpdate()` inside it will re-lock the Order row (via `order()->lockForUpdate()`), which is a different row. No conflict.

---

## 6. Side Effects

| Flow | Impact |
|------|--------|
| **All flows** | Transactions are now nested. Laravel handles this correctly with savepoints. |
| **Callback** | The mutation section is now atomic. If any step fails, all preceding steps roll back. |
| **Inventory finalization** | Runs inside the outer transaction now. If promotion usage fails, inventory deduction rolls back too. |
| **ChangeOrderStatus** | Runs inside the outer transaction. Its internal transaction becomes a savepoint. |

---

## 7. Compatibility

| Question | Answer |
|----------|--------|
| **Break APIs?** | No. Response format unchanged. |
| **Require migration?** | No. |
| **Require new columns?** | No. |
| **Require new indexes?** | No. |
| **Require config changes?** | No. |
| **Require queue changes?** | No. |
| **Require cache invalidation?** | No. |

---

## 8. Required Tests

| Test | Type | Purpose | Expected |
|------|------|---------|----------|
| Callback rollback on failure | Feature | Simulate exception during `changeOrderStatus` | Transaction remains 'pending', order unchanged, inventory NOT deducted |
| Nested transaction rollback | Feature | Force failure inside inner `finalizeItemsByShippingMethod` | Outer transaction rolls back everything |
| Successful callback commits atomically | Feature | Complete happy path | All changes visible after commit |

---

## 9. Risk Assessment

| Risk | Score | Explanation |
|------|-------|-------------|
| **Implementation Risk** | 3/10 | Wrapping in `DB::transaction()` is straightforward, but nesting transactions with savepoints means errors in inner transactions must be handled carefully (they should be allowed to propagate to the outer transaction). |
| **Regression Risk** | 4/10 | If an inner transaction throws an exception that is caught prematurely, the outer transaction might not roll back. All exceptions should propagate. The current `finalizeItemsByShippingMethod` catches exceptions internally (line 252-256 in CartInventoryService — no, it doesn't; it uses `DB::transaction()` which re-throws). This should be verified. |
| **Performance Impact** | 1/10 | Transaction duration increases by a few milliseconds (the time to run all mutations). Acceptable. |
| **Deadlock Risk** | 3/10 | Holding the Transaction lock for the entire callback processing increases the window for deadlocks. But since the callback is the only place that processes this specific Transaction row, contention is low. |
| **Maintenance Impact** | 2/10 | Future developers must remember to keep all mutations inside the transaction. A code comment should clearly mark the transactional boundary. |

---

## 10. Rollback Plan

**Revert:** Remove `DB::transaction()` wrapper from `checkoutCallback()`, move event dispatching back inside the method body.

`git revert <commit-hash>`

---

# Flash Sale: Variable-Product Percentage Bug

---

## 1. Root Cause

**File:** `packages/marvel/src/Listeners/FlashSaleProductProcess.php`
**Method:** `processNewlyAddedProductInFlashSale()` (lines 44-95)

**This listener runs when products are attached to a flash sale.** It updates product/variant `sale_price` columns and the `price_after_flash_sale` flag on the product.

There are **four distinct bugs** in this method:

### Bug 1: Duplicate variable-product loop in `percentage` case (lines 57-73)

```php
case 'percentage':
    // FIRST loop (lines 57-67):
    if ($product->product_type === ProductType::VARIABLE) {
        foreach ($product->variations as $key => $variation) {
            $sale_price = $pricingService->calculateVariantCurrentPrice($product, $variation, $flash_sale);
            Variation::where('id', $variation->id)->update(['sale_price' => $sale_price]);
        }
    }

    // SECOND identical loop (lines 69-73):
    if ($product->product_type === ProductType::VARIABLE) {
        foreach ($product->variations as $key => $variation) {
            $sale_price = $pricingService->calculateVariantCurrentPrice($product, $variation, $flash_sale);
            $variation->sale_price = $sale_price;
            $variation->save();
        }
    }
    break;
```

Two loops iterate over the same variations. The first uses `Variation::where(...)->update()` (raw query), the second uses `$variation->save()` (Eloquent). The first loop may update a different table/model than the second. Both produce the same result but the work is duplicated. This could have been caused by a copy-paste error during development where one approach was tried, then another was added without removing the first.

### Bug 2: Missing `final_price` case for variable products (lines 74-91)

The `switch` statement handles `percentage`, `fixed_rate`, and defaults. The `final_price` case has no variable-product handling:

```php
case 'fixed_rate':  // Fixed rate has variable handling
    if ($product->product_type === ProductType::VARIABLE) { ... }
    break;
// No case 'final_price':
// Fall through to default, which only handles simple products
default:
    // Only handles simple products
    $sale_price = $pricingService->calculateFlashSalePrice(...);
    break;
```

When a flash sale of type `final_price` is applied to a variable product, **no variant's `sale_price` is updated**. The default case only sets `$product->price_after_flash_sale`.

### Bug 3: `price_after_flash_sale` always uses product price (line 90)

```php
$product->price_after_flash_sale = $pricingService->calculateFlashSalePrice($flash_sale, $product->price);
```

For variable products, `$product->price` is often 0 or a default value, NOT the actual variant prices. The `price_after_flash_sale` column (which is a cached value shown in product listings) will be calculated against the wrong base price for variable products.

### Bug 4: Order-time re-resolution may diverge from cart-time price

**File:** `app/Services/Checkout/OrderCreationService.php` (lines 131-146)

At order creation time, `resolveActiveFlashSale()` re-queries the database and recalculates prices. If the flash sale status changed between when the user added items to cart and when they checked out, the order price could differ from what the customer expected.

This is less of a "bug" and more of an **architectural concern** — the cart stores prices at add-to-cart time (via `CartInventoryService::reserveItem()` which calls `calculateVariantCurrentPrice`), but order creation recalculates prices from scratch. If the flash sale has ended or its discount changed, the order price will be different from the cart price.

**However**, the `refreshCartItemPrices()` method (OrderService:405) is called at checkout before order creation (line 165), and it re-syncs cart item prices with the current flash sale state. So the customer sees the recalculated price at checkout time. This is the correct behavior — the price is locked at checkout time, not at add-to-cart time.

**The actual `ProductPricingService` calculation logic is correct** for both percentage and fixed-rate cases. The runtime code does use `$variant->price` as the base, not `$product->price`.

---

## 2. Failure Scenario

### Bug 1: Duplicate Loop
Duplicate SQL queries executed on every flash sale product attachment. Performance issue, not a correctness issue (both loops produce the same `sale_price`).

### Bug 2: Missing `final_price` case
```
Flash Sale: type=final_price, discount=99.99
Product: variable product with 3 variants (prices: 150, 200, 250)

Expected: All 3 variants have sale_price=99.99 (final price)
Actual: No variants have sale_price set; only product.price_after_flash_sale=99.99

Result: If any code path reads $variant->sale_price as the display price,
variants show full price (150, 200, 250) instead of the flash sale price (99.99).
```

### Bug 3: Wrong base price for `price_after_flash_sale`
```
Flash Sale: type=percentage, discount=20%
Product: variable product with variants (prices: 150, 200, 250)
Product.price = 0 (default for variable products — price is in variants)

$product->price_after_flash_sale = calculateFlashSalePrice(flashSale, 0)
= 0 - (0 * 0.20) = 0

Result: Product shows price_after_flash_sale=0, which may be displayed as
"FREE" or "KWD 0.000" on product listing pages.
```

---

## 3. Correct Design

### Fix 1: Remove duplicate loop

Delete the first loop (lines 57-67) in the `percentage` case. Keep the second loop (lines 69-73) which uses Eloquent `$variation->save()`.

### Fix 2: Add `final_price` case for variable products

Add a `case 'final_price':` that iterates over variable products' variants and sets their `sale_price` using `calculateVariantCurrentPrice()`.

### Fix 3: Set `price_after_flash_sale` correctly for variable products

For variable products, either:
- Option A: Calculate `price_after_flash_sale` based on the lowest variant price, OR
- Option B: Leave `price_after_flash_sale` as null for variable products (the accessor `getPriceAfterFlashSaleAttribute` at Product.php:211 recalculates dynamically), OR
- Option C: Calculate `price_after_flash_sale` based on `$product->from_price` (if that field exists as the minimum variant price)

Option B is the safest — the accessor already provides the correct dynamic calculation. Setting a stale cached value is worse than having no cached value.

---

## 4. Exact Place To Fix

| File | Class | Method | Lines | Why |
|------|-------|--------|-------|-----|
| `packages/marvel/src/Listeners/FlashSaleProductProcess.php` | `FlashSaleProductProcess` | `processNewlyAddedProductInFlashSale()` | 44-95 | All 3 bugs are in this single method. |

**No other files require changes.** The runtime pricing in `ProductPricingService` and `OrderCreationService` is correct. The bug is only in the cache-population listener.

---

## 5. Required Changes

### 5.1 Remove the first (duplicate) variable-product loop in `percentage` case

Delete lines 57-67 (the `Variation::where(...)->update(...)` loop).

### 5.2 Add `final_price` case for variable products

Add a `case 'final_price':` block that mirrors the `fixed_rate` variable handling:
```
case 'final_price':
    if ($product->product_type === ProductType::VARIABLE) {
        foreach ($product->variations as $variation) {
            $sale_price = $pricingService->calculateVariantCurrentPrice($product, $variation, $flash_sale);
            $variation->sale_price = $sale_price;
            $variation->save();
        }
    }
    break;
```

### 5.3 Fix `price_after_flash_sale` for variable products

Change line 90 so that for variable products, `price_after_flash_sale` is NOT set (or is explicitly set to null):
```
if ($product->product_type !== ProductType::VARIABLE) {
    $product->price_after_flash_sale = $pricingService->calculateFlashSalePrice($flash_sale, $product->price);
}
```

### 5.4 Also fix the `fixed_rate` case to use the correct pattern (optional)

The `fixed_rate` case has the `percentage` pattern (first loop only, correct pattern) but should also skip setting `price_after_flash_sale` for variable products. This is a minor consistency fix.

---

## 6. Side Effects

| Flow | Impact |
|------|--------|
| **Flash Sale processing** | Duplicate queries eliminated. `final_price` type now correctly processes variable products. |
| **Product listing** | `price_after_flash_sale` column no longer incorrectly set to 0 for variable products. The dynamic accessor handles display correctly. |
| **Checkout** | No impact. Order-time pricing re-resolution is correct and unchanged. |
| **Cart** | No impact. Cart-time pricing via `calculateVariantCurrentPrice` is correct and unchanged. |
| **Search/Indexing** | If search indexes rely on `price_after_flash_sale`, variable products may show different values. Verify that the index uses the accessor or recalculates dynamically. |

---

## 7. Compatibility

| Question | Answer |
|----------|--------|
| **Break APIs?** | No. Response format unchanged. `price_after_flash_sale` may change from 0 to null for variable products (more correct). |
| **Require migration?** | No. |
| **Require new columns?** | No. |
| **Require new indexes?** | No. |
| **Require config changes?** | No. |
| **Require queue changes?** | No. |
| **Require cache invalidation?** | If product listing cache includes `price_after_flash_sale`, it should be invalidated for affected products. |

---

## 8. Required Tests

| Test | Type | Purpose | Expected |
|------|------|---------|----------|
| Flash sale percentage applies to variable product variants | Feature | Create flash sale (percentage=20%), attach variable product with variants | All variants get correct `sale_price` (variant_price * 0.8) |
| Flash sale fixed_rate applies to variable product variants | Feature | Create flash sale (fixed_rate=25), attach variable product | All variants get `sale_price = variant_price - 25` |
| Flash sale final_price applies to variable product variants | Feature | Create flash sale (final_price=99.99), attach variable product | All variants get `sale_price = 99.99` |
| Product price_after_flash_sale is null for variable products | Feature | Variable product with flash sale | `price_after_flash_sale` is null (accessor still returns correct value) |
| Flash sale percentage applies to simple products | Feature | Percentage flash sale on simple product | Simple product's `price_after_flash_sale` is correctly set |
| No duplicate queries on flash sale product attach | Performance | Attach variable product to percentage flash sale | Each variant updated exactly once (not twice) |
| No duplicate queries on flash sale product attach — fixed_rate | Performance | Attach variable product to fixed_rate flash sale | Each variant updated exactly once |

---

## 9. Risk Assessment

| Risk | Score | Explanation |
|------|-------|-------------|
| **Implementation Risk** | 2/10 | Simple deletion and addition of case blocks. The logic already exists for `percentage` and `fixed_rate` — `final_price` is a straightforward addition. |
| **Regression Risk** | 3/10 | If any code relied on `price_after_flash_sale` being 0 for variable products (unlikely, but possible), it will break. Mitigation: search for `price_after_flash_sale` usages before changing. |
| **Performance Impact** | 1/10 (positive) | Removing duplicate loop improves performance. |
| **Deadlock Risk** | 1/10 | No locking involved. |
| **Maintenance Impact** | 1/10 | Cleaner code after fix. |

---

## 10. Rollback Plan

**Revert:** Undo changes to `FlashSaleProductProcess.php`.

`git revert <commit-hash>`

---

# Global Review & Implementation Roadmap

---

## 1. Issue Relationships

| Issues | Relationship | Must Fix Together? |
|--------|-------------|-------------------|
| C-2, C-3, C-6 | **Strongly related.** All three are in `checkoutCallback()`. C-3 (missing `lockForUpdate`) and C-6 (missing transaction) are the mechanism that enables C-2 (duplicate processing). | **YES — must be fixed together in the same PR.** |
| C-4 | **Independent.** Affects `calcInvoicePrice()` in a different method. No code overlap with C-2/C-3/C-6. | Can be fixed independently, but should be grouped for efficiency. |
| C-5 | **Related to C-2/C-6** only via the call chain: `changeOrderStatus()` calls `recordCouponUsage()`. If the outer transaction (C-6) is added, C-5's fix (adding `lockForUpdate` to coupon lookup) naturally benefits from being inside that transaction. | Can be fixed independently, but is more effective when C-6 is also implemented. |
| Flash Sale | **Completely independent.** Different file (`FlashSaleProductProcess.php`), different concern (product/variant sale price cache). | Can be fixed independently in any order. |

---

## 2. Implementation Phases

### Phase 1: Payment Callback Safety (C-2, C-3, C-6)

| Aspect | Detail |
|--------|--------|
| **Files touched** | 1 file: `app/Http/Controllers/Api/General/OrderController.php` (+ tests) |
| **Changes** | Wrap mutation block in `DB::transaction()`; add `lockForUpdate()` to Transaction lookup; add idempotency guard; move event dispatching after commit |
| **Complexity** | Medium |
| **Risk** | Medium (nested transactions, deadlock potential) |
| **Tests required** | 7 tests (C-2: 6, C-3: 1, C-6: 3 — some overlap) |
| **Estimated time** | 2-3 hours implementation + testing |

### Phase 2: Invoice Price Concurrency (C-4)

| Aspect | Detail |
|--------|--------|
| **Files touched** | 1 file: `app/Services/General/OrderService.php` (+ tests) |
| **Changes** | Add `->lockForUpdate()` to `getCartUser()` |
| **Complexity** | Very Low |
| **Risk** | Very Low |
| **Tests required** | 2 tests (concurrent + normal) |
| **Estimated time** | 30 minutes implementation + testing |

### Phase 3: Coupon Consumption Locking (C-5)

| Aspect | Detail |
|--------|--------|
| **Files touched** | 1 file: `app/Services/General/OrderService.php` (+ tests) |
| **Changes** | Add `->lockForUpdate()` to Coupon lookup in `recordCouponUsage()`; add limiter re-check |
| **Complexity** | Low |
| **Risk** | Low-Medium (deadlock with assignment lock — see risk section) |
| **Tests required** | 4 tests (concurrency scenarios) |
| **Estimated time** | 1-2 hours implementation + testing |

### Phase 4: Flash Sale Bug Fix (Variable-Product Percentage)

| Aspect | Detail |
|--------|--------|
| **Files touched** | 1 file: `packages/marvel/src/Listeners/FlashSaleProductProcess.php` (+ tests) |
| **Changes** | Remove duplicate loop; add `final_price` case; fix `price_after_flash_sale` for variable products |
| **Complexity** | Low |
| **Risk** | Low |
| **Tests required** | 5 tests (various flash sale types × product types) |
| **Estimated time** | 1-2 hours implementation + testing |

---

## 3. Recommended Implementation Order

```
Phase 1 → Phase 2 → Phase 3 → Phase 4
```

**Why this order:**

1. **Phase 1 (Payment Callback Safety)** is the highest priority. It fixes the most critical issue (inventory over-deduction, financial inconsistency). It also establishes the transaction pattern that Phase 3 benefits from.

2. **Phase 2 (Invoice Price Concurrency)** is the simplest change (one line) with the lowest risk. Quick win.

3. **Phase 3 (Coupon Consumption Locking)** depends on understanding the transaction boundaries established in Phase 1. The deadlock risk requires careful lock ordering analysis.

4. **Phase 4 (Flash Sale Bug)** is completely independent and can be done at any time. Lowest priority.

---

## 4. Merge Strategy

| Scenario | Recommendation |
|----------|---------------|
| **Single PR** | **Recommended.** All 6 issues can go in one PR. Touches only 3 files total: `OrderController.php`, `OrderService.php`, `FlashSaleProductProcess.php`. The changes are well-understood and independently verifiable. |
| **Split PR #1** | Phase 1 + Phase 2 + Phase 3 (payments + coupons + invoice) — 2 files, all concurrency hardening |
| **Split PR #2** | Phase 4 (Flash Sale) — 1 file, independent concern |
| **Phase 1 only** | If risk appetite is low, deploy just Phase 1 first, then Phase 2+3, then Phase 4. |

---

## 5. PR that Minimizes Merge Conflicts

**Single PR touching all 3 files.** Since:
- `OrderController.php`: Only `checkoutCallback()` and `checkoutErrorCallback()` are modified
- `OrderService.php`: Only `getCartUser()` line 330 and `recordCouponUsage()` line 673 are modified
- `FlashSaleProductProcess.php`: Only `processNewlyAddedProductInFlashSale()` lines 44-95 are modified

These are all isolated methods that are unlikely to conflict with other work. A single branch with all changes is the safest approach.

---

## 6. Final Decision

### Q1: Are any issues related and should be fixed together?
**Yes.** C-2, C-3, and C-6 are in the same method (`checkoutCallback()`) and fixing any one without the others leaves the system partially protected:
- C-6 without C-3: Transaction is atomic but still vulnerable to concurrent race (lockForUpdate missing)
- C-3 without C-6: lockForUpdate without a wrapping transaction does nothing (locks are released at statement end outside a transaction)
- C-2 without C-3 and C-6: The idempotency check has no lock protection, so concurrent callbacks can both pass it

### Q2: Which issues MUST be implemented in the same pull request?
**C-2, C-3, C-6** — These are interdependent and must go together.

### Q3: Which issues can safely be implemented independently?
- **C-4** (calcInvoicePrice race) — Independent
- **C-5** (coupon usage race) — Mostly independent; better with C-6 but can be standalone
- **Flash Sale** (variable-product percentage) — Completely independent

### Q4: What is the safest implementation order?
```
Phase 1 (C-2+C-3+C-6) → Phase 3 (C-5) → Phase 2 (C-4) → Phase 4 (Flash Sale)
```

Phase 1 first (critical bug), then Phase 3 (coupon integrity), then Phase 2 (quick win), then Phase 4 (independent).

### Q5: Which implementation minimizes merge conflicts and regression risk?
**A single PR with all changes.** The three files are independent (no shared methods/code), and each change is confined to one method. Tests ensure no regressions. This minimizes CI/CD cycles and deployment risk.
