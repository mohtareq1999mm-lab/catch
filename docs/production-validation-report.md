# Production Validation Report

**Date:** 2026-07-25  
**Auditor:** Principal Architect / QA Automation Engineer  
**Scope:** Full checkout, payment, inventory, coupon, promotion lifecycle

---

## Executive Summary

| Category | Score |
|----------|-------|
| **Production Readiness** | **4/10 — NOT READY** |
| **Financial Integrity** | 5/10 |
| **Concurrency Safety** | 3/10 |
| **Reservation Safety** | 7/10 |
| **Inventory Safety** | 4/10 |
| **Checkout Safety** | 4/10 |
| **Payment Safety** | 3/10 |
| **Coupon Safety** | 6/10 |
| **Promotion Safety** | 7/10 |
| **Test Suite Health** | 7/10 (1 pre-existing failure) |

---

## Database Test Data (Real Records)

### Products (First 20)
| ID | Name | Price | Type | Stock | Reserved |
|----|------|-------|------|-------|----------|
| 1 | Maybelline Fit Me Foundation | 29.99 | variable | 56 | 0 |
| 2 | L'Oréal True Match Foundation | 34.99 | variable | 145 | 0 |
| 3 | NYX Can't Stop Won't Stop Foundation | 24.99 | variable | 83 | 0 |
| 4 | MAC Studio Fix Foundation | 45.00 | variable | 0 | 0 |
| 5 | Huda Beauty Faux Filter Foundation | 49.99 | variable | 87 | 0 |
| 6 | Maybelline Instant Age Rewind Concealer | 19.99 | variable | 25 | 0 |
| ... | *(100+ products total, all cosmetics/makeup)* | | | | |

### Coupons (20 active)
| ID | Code | Type | Discount | Max Discount | Valid Until | Used |
|----|------|------|----------|-------------|-------------|------|
| 1 | SUMMER20 | percentage | 20% | 100.00 | 2026-09-05 | 1 |
| 2 | WELCOME10 | percentage | 10% | 50.00 | 2026-09-15 | 1 |
| 3 | FREESHIP | fixed_rate | 50.00 | — | 2026-09-09 | 1 |
| 4 | FLASH25 | percentage | 25% | 150.00 | 2026-08-11 | 1 |
| 5 | WEEKEND15 | percentage | 15% | 75.00 | 2026-08-24 | 1 |
| 6 | NEW10 | percentage | 10% | 50.00 | 2026-08-25 | 1 |
| 7 | LOYAL5 | percentage | 5% | 25.00 | 2026-08-30 | 1 |
| 8 | BULK30 | percentage | 30% | 200.00 | 2026-08-13 | 1 |
| 9 | REFER20 | percentage | 20% | 100.00 | 2026-08-13 | 1 |
| 10 | HOLIDAY | fixed_rate | 100.00 | — | 2026-08-12 | 1 |

### Promotions (20 active)
| ID | Name | Type | Value | Max Discount | Min Order |
|----|------|------|-------|-------------|-----------|
| 1 | Summer Special 20% Off | percentage | 20% | 100.00 | 500.00 |
| 2 | 50 EGP Off Electronics | fixed_rate | 50.00 | — | 300.00 |
| 5 | 100 EGP Off First Grocery Order | fixed_rate | 100.00 | — | 300.00 |
| 7 | Beauty Products 25% Off | percentage | 25% | 120.00 | 0.00 |
| 10 | 200 EGP Off TVs & Audio | fixed_rate | 200.00 | — | 0.00 |
| 13 | Free Shipping Orders Over 500 | fixed_rate | 40.00 | — | 0.00 |
| 19 | Furniture & Home 35% Off | percentage | 35% | 300.00 | 0.00 |

### Coupon Assignments: **0 records**
### Coupon Assignment Usages: **0 records**  
### Coupon Usages: **20 records** (all with null order_id — unused/dead)

---

## Test Suite Results

| Test Suite | Tests | Status |
|-----------|-------|--------|
| CouponAssignmentApiTest | 30/30 | ✅ PASS |
| CouponAssignmentValidationTest | 13/13 | ✅ PASS |
| CouponsProductionHardenTest | 44/44 | ✅ PASS |
| CouponSystemTest | 21/21 | ✅ PASS |
| AssignedCouponSystemTest | 47/47 | ✅ PASS |
| OrdersProductionHardenTest | 47/47 | ✅ PASS (previously verified) |
| PaymentProductionHardenTest | 30/30 | ✅ PASS (previously verified) |
| CheckoutRegressionTest | 20/20 | ✅ PASS (previously verified) |
| Full Suite (1913 tests) | — | ⚠️ 1 FAILURE (pre-existing, unrelated) |

### Pre-existing Failure
- `SnapshotIntegrityServiceTest::test_hash_is_independent_of_key_order` — Hash mismatch. Unrelated to coupon/checkout.

---

## 🚨 CRITICAL FINDINGS

### C-1: Inventory Finalized Before Payment Confirmed (OrderService.php:228-229)

**Evidence:**
- `addItemsInOrder()` (line 228-229) calls `finalizeOrder()` and `finalizeItemsByShippingMethod()` 
- These decrement `stock_quantity` and zero `reserved_quantity` on the product
- This happens BEFORE the user is redirected to the payment gateway
- If the user abandons payment, `stock_quantity` is permanently lost

**Code Path:**
```
POST /orders/payment → OrderController::checkout()
  → addItemsInOrder() [transaction]
      → finalizeOrder() → creates order + order items
      → finalizeItemsByShippingMethod() → stock_quantity--, reserved_quantity = 0
  → [transaction commits]
  → handleOnlinePayment() → redirect to gateway
  → USER ABANDONS → no callback → stock is gone
```

**Impact:** For every abandoned online payment, inventory is permanently lost. No restoration mechanism exists. Over time, this causes systematic inventory drift.

**Test Evidence:** No test covers this scenario. All existing tests mock or skip the inventory finalization step.

---

### C-2: No Idempotency on Payment Callback (OrderController.php:169-326)

**Evidence:**
- `checkoutCallback()` has no idempotency key or locking
- `Transaction` lookup uses plain `first()` not `lockForUpdate()`
- Two concurrent callbacks can both pass the lookup and both execute
- `changeOrderStatus('completed')` allows `completed → completed` transition (OrderService.php:459)

**Code Path (duplicate callback):**
```
Callback #1 → verifyPayment() → returns "paid"
Callback #2 → verifyPayment() → returns "paid" (same paymentId)
Both find Transaction (no lock)
Both call changeOrderStatus('completed') — allowed transition
Both call recordCouponUsage() — partially protected
Both fire PaymentSucceeded event
```

**Impact:** Duplicate PaymentSucceeded events, redundant processing. Coupon usage increment is partially protected but `coupon.increment('used')` at OrderService.php:650 has no `lockForUpdate` on the `coupons` row, creating a race.

---

### C-3: Missing Transaction Lock on Callback Lookup (OrderController.php:176-178)

**Evidence:**
```php
$transaction = Transaction::where('gateway_transaction_id', $paymentId)
    ->orWhere('invoice_id', $paymentId)
    ->first();  // ← NO lockForUpdate!
```

If two callbacks arrive simultaneously (gateway retry), both read the same Transaction state, and both proceed with `update()`.

---

### C-4: `calcInvoicePrice()` Runs Transaction Without Locks (OrderService.php:104-146)

**Evidence:**
- `DB::beginTransaction()` at line 107
- `getCartUser()` at line 108 — reads cart WITHOUT `lockForUpdate()`
- Coupon validation WITHOUT lock
- `update()` at line 120 (removes coupon) and line 135 (updates total_price)

Two concurrent `calcInvoicePrice()` requests can interleave:
1. Request A reads cart (sees coupon)
2. Request B reads cart (sees coupon)
3. Request A validates coupon (valid)
4. Request B validates coupon (valid)
5. Both calculate, then merge updates

**Impact:** Cart `total_price` can become inconsistent. Race condition on concurrent price calculations.

---

### C-5: `$coupon->increment('used')` Without lockForUpdate (OrderService.php:650)

**Evidence:**
```php
$assignment->increment('used');   // line 652 — assignment IS locked
$coupon->increment('used');        // line 650 — coupon NOT locked
```

The `coupon_assignments` row is locked, but the `coupons` row is not. Two concurrent checkouts can both call `$coupon->increment('used')` without mutual exclusion.

**Impact:** `coupons.used` can exceed `coupons.limiter`. Coupon `limiter` check is done without lock at validation time.

---

### C-6: No Global Transaction Wrapping Payment Callback (OrderController.php:169-326)

**Evidence:**
`checkoutCallback()` has multiple state-changing operations WITHOUT a wrapping `DB::transaction()`:
1. `Transaction::update()` (line 199)
2. `finalizeItemsByShippingMethod()` (line 272)
3. `changeOrderStatus('completed')` (line 278) — has its own inner transaction
4. `PaymentSucceeded` event (line 283)

If the process crashes between steps 3 and 4 (between `changeOrderStatus` and `PaymentSucceeded`), the order is completed but:
- Inventory is finalized
- Coupon usage is recorded
- No notification fires

**Impact:** Silent partial failures. Inconsistent state.

---

## 🟠 HIGH FINDINGS

### H-1: No Inventory Restoration on Cancellation (OrderService.php:534)

**Evidence:** `changeOrderStatus()` fires `OrderCancelled` at line 534, but there is NO listener that restores `stock_quantity`. The cancelled order's inventory stays permanently deducted.

**Code Path:**
```
OrderService::changeOrderStatus('cancelled')
  → OrderCancelled event dispatch
  → NO listener → stock_quantity remains decremented
```

---

### H-2: Legacy Webhook Handler Has No Protection (PaymentTrait.php:348-369)

**Evidence:**
- `webhookSuccessResponse()` in `PaymentTrait.php` uses NO `lockForUpdate()`, NO transaction
- Writes directly to `order.order_status` and `order.payment_status` (legacy columns)
- `checkOrderStatusIsFinal()` check is non-locking read
- Two concurrent webhooks can both pass the check and both update

---

### H-3: Order/Transaction Created in Separate Transactions (PaymentCheckoutHandler.php:58-68)

**Evidence:**
1. `addItemsInOrder()` commits its transaction (order + items + inventory finalization)
2. THEN `handleOnlinePayment()` creates the Transaction record

If server crashes between step 1 and 2, the order exists in the database with inventory deducted but **no payment transaction**.

**Impact:** Orphan orders with no transaction. User sees 500 error, retries, potentially gets a duplicate order.

---

### H-4: CalcInvoicePrice Calls promoteService Without Cart Lock (OrderService.php:123-128)

**Evidence:** `calculateCheckoutTotals()` calls `promotionService->applySelectedPromotion()` which locks the promotion row, but the cart itself is NOT locked. The promotion's usage counter could be incremented while the cart data is stale.

---

## 🟡 MEDIUM FINDINGS

### M-1: Public Coupon `firstOrCreate` Race → 500 Error (OrderService.php:672-685)

**Evidence:** `CouponUsage::firstOrCreate()` at line 672 can throw `QueryException` on duplicate key if two requests race. The exception bubbles up, rolling back the `changeOrderStatus()` transaction.

**Impact:** User gets 500 error instead of success. No data corruption, but poor UX.

### M-2: `checkout()` Access Control on Transaction QR (OrderController.php:150-166)

**Evidence:** `getTransactionQr()` checks `$order->user_id !== $request->user()->id` but uses `find()` not `findOrFail()`. If the order doesn't exist, it returns 404 (from the relationship), but the error message "TRANSACTION_NOT_FOUND" is misleading — it could be the order that's missing.

---

## 🔵 LOW FINDINGS

### L-1: CouponAssignments Table Empty — Feature Not In Production Use

**Evidence:** `coupon_assignments` has 0 rows. `coupon_assignment_usages` has 0 rows. The feature is deployed but unused.

### L-2: All 20 CouponUsage Records Have Null order_id

**Evidence:** Every `coupon_usages.order_id` is null. These are orphaned records — the coupon was "used" but no order is associated.

### L-3: Redundant `finalizeInventory()` Call (PaymentCheckoutHandler.php:92,116)

**Evidence:** COD and cashier handlers call `finalizeItemsByShippingMethod()` again after `addItemsInOrder()` already called it. This is wasteful but safe (items already finalized).

---

## Locking Verification

| Operation | File | Line | lockForUpdate? | Status |
|-----------|------|------|----------------|--------|
| Cart lock (addItemsInOrder) | OrderService.php | 156 | ✅ Yes | Good |
| Cart lock (ensureCartReservation) | CartInventoryService.php | 293-304 | ✅ Yes | Good |
| Coupon lock (addItemsInOrder) | OrderService.php | 169 | ✅ Yes | Good |
| Coupon lock (recordCouponUsage - assignment) | OrderService.php | 632 | ✅ Yes | Good |
| Coupon lock (recordCouponUsage - usage) | OrderService.php | 645 | ✅ Yes | Good |
| Coupon lock (recordCouponUsage - coupons table) | OrderService.php | 650 | ❌ No | **C-5** |
| Promotion lock (applySelectedPromotion) | PromotionService.php | 77 | ✅ Yes | Good |
| Promotion lock (incrementUsage) | PromotionService.php | 163-178 | ✅ Yes | Good |
| Order lock (changeOrderStatus) | OrderService.php | 478, 483 | ✅ Yes | Good |
| Transaction lock (markCodAsPaid) | OrderService.php | 548 | ✅ Yes | Good |
| Transaction lock (checkoutCallback) | OrderController.php | 176-178 | ❌ No | **C-3** |
| Cart lock (calcInvoicePrice) | OrderService.php | 108 | ❌ No | **C-4** |
| Payment webhook (PaymentTrait) | PaymentTrait.php | 348-369 | ❌ No | **H-2** |
| Reserve item | CartInventoryService.php | 23-76 | ✅ Yes | Good |
| Release item | CartInventoryService.php | 167-187 | ✅ Yes | Good |
| Finalize items | CartInventoryService.php | 237-270 | ✅ Yes | Good |

---

## Financial Integrity Assessment

### Price Calculation Flow
```
Cart Items
  → refreshCartItemPrices (product/variant prices)
  → calculateCheckoutTotals
      → subtotal = Σ(unit_price × quantity)
      → applyPromotion (if selected)
          → percentage: subtotal × (value / 100), capped at max_discount_amount
          → fixed_rate: min(value, subtotal), capped at max_discount_amount
      → applyCoupon (if applied)
          → percentage: total × (coupon.discount / 100), capped at max_discount_amount
          → fixed_rate: min(coupon.discount, total)
          → free_shipping: sets shipping to 0
  → resolveShippingPrice (governorate-based)
  → resolveFreeShippingByThreshold (subtotal > free_shipping_over)
  → finalTotal = subtotal - promotion_discount - coupon_discount + shipping
```

**Issue Found:** `resolveFreeShippingByCoupon()` at line 132 in `calcInvoicePrice()` checks `couponDiscountType === FREE_SHIPPING` AFTER the coupon has already been applied in `calculateCheckoutTotals()`. But this check uses `checkoutTotals->couponDiscountType` which should be set correctly. No calculation error found, but the shipping price is applied AFTER the coupon discount was calculated, which is correct.

### Order Snapshot
The `orders` table stores:
- `price` — subtotal before discounts
- `total_price` — final amount charged
- `shipping_price` — shipping cost
- `coupon_discount` — coupon reduction
- `coupon_discount_type` — how the discount was applied
- `promotion_discount` — promotion reduction
- `promotion_type` — how the promotion was applied

**Verification:** Snapshots are complete. All critical pricing fields are captured at order creation time. Cart changes after order creation do NOT affect existing orders. ✅

---

## Concurrency Race Analysis

### Scenario: Two Concurrent `addItemsInOrder()` Calls

| Step | Request A | Request B |
|------|-----------|-----------|
| 1 | `lockForUpdate` on Cart | `lockForUpdate` on Cart → **BLOCKED** |
| 2 | Reads cart, validates coupon, calculates totals | Waiting |
| 3 | Creates order and items | Waiting |
| 4 | Finalizes inventory | Waiting |
| 5 | Commits transaction → cart released | Waiting |
| 6 | | Acquires lock → cart is now `checked_out` or empty → **no items** → returns null |

**Result:** Only one order is created. The second gets `null` and returns 500 error. ✅ No duplicate order.

### Scenario: Two Concurrent `checkoutCallback()` Calls

| Step | Callback A | Callback B |
|------|-----------|-----------|
| 1 | `first()` reads Transaction (no lock) | `first()` reads Transaction (no lock) |
| 2 | Both see same "pending" transaction | Both see same "pending" |
| 3 | `verifyPayment()` — returns "paid" | `verifyPayment()` — returns "paid" |
| 4 | `Transaction::update()` → "paid" | `Transaction::update()` → "paid" (overwrites) |
| 5 | `changeOrderStatus('completed')` | `changeOrderStatus('completed')` |
| 6 | Inner transaction locks order → commits | Inner transaction locks order → commits (allowed) |
| 7 | `recordCouponUsage()` — guarded | `recordCouponUsage()` — guarded by `lockForUpdate` |

**Result:** Both succeed. Duplicate processing but no data corruption on coupons due to locking. However, `PaymentSucceeded` fires twice and all side effects execute twice. ❌

### Scenario: Two Concurrent Coupon Assignments (same coupon + user)

See previous report (C-1 from Coupon Assignment audit) — TOCTOU race condition. ❌

---

## Test Coverage Gaps

| Scenario | Covered? | Notes |
|----------|----------|-------|
| Valid checkout with simple product | ❌ | No end-to-end checkout test |
| Valid checkout with variable product | ❌ | No variant selection test |
| Coupon percentage discount | ❌ | Only unit tests mock coupon |
| Coupon fixed discount | ❌ | Not tested end-to-end |
| Promotion only | ❌ | No end-to-end promotion test |
| Promotion + Coupon stacking | ❌ | Not tested |
| Flash sale + promotion | ❌ | Not tested |
| Expired coupon rejection | ✅ | Via CouponValidator test |
| Disabled coupon rejection | ✅ | Via CouponValidator test |
| Usage limit reached | ✅ | Via CouponValidator test |
| Concurrent checkout | ❌ | No concurrency test |
| Concurrent callback | ❌ | No concurrency test |
| Payment failure + retry | ❌ | Not tested |
| Abandoned payment inventory leak | ❌ | **Critical gap** |
| Duplicate callback | ❌ | **Critical gap** |
| Order cancellation inventory restore | ❌ | **Critical gap** |
| Amount mismatch detection | ✅ | Via OrderController code |
| Currency mismatch detection | ✅ | Via OrderController code |

---

## Code Issues Summary

| ID | Severity | File | Line | Description |
|----|----------|------|------|-------------|
| C-1 | **CRITICAL** | OrderService.php | 228-229 | Inventory finalized before payment confirmed; abandoned payments permanently lose stock |
| C-2 | **CRITICAL** | OrderController.php | 169-326 | No idempotency — duplicate callbacks process twice |
| C-3 | **CRITICAL** | OrderController.php | 176-178 | Transaction lookup in callback without lockForUpdate |
| C-4 | **CRITICAL** | OrderService.php | 104-146 | calcInvoicePrice has DB transaction but NO lockForUpdate |
| C-5 | **CRITICAL** | OrderService.php | 650 | `$coupon->increment('used')` without lockForUpdate on coupons row |
| C-6 | **CRITICAL** | OrderController.php | 169-326 | No wrapping DB::transaction in checkoutCallback |
| H-1 | **HIGH** | OrderService.php | 534 | No inventory restoration on order cancellation |
| H-2 | **HIGH** | PaymentTrait.php | 348-369 | Legacy webhook handler has no locking or transaction |
| H-3 | **HIGH** | PaymentCheckoutHandler.php | 58-68 | Transaction created outside order creation transaction |
| H-4 | **HIGH** | OrderService.php | 123-128 | calcInvoicePrice calls promotion service without cart lock |
| M-1 | **MEDIUM** | OrderService.php | 672-685 | public coupon firstOrCreate race → 500 error |
| M-2 | **MEDIUM** | OrderController.php | 150-166 | Transaction QR access control uses find() not findOrFail() |
| L-1 | **LOW** | — | — | CouponAssignments table empty |
| L-2 | **LOW** | — | — | All 20 coupon_usages have null order_id |
| L-3 | **LOW** | PaymentCheckoutHandler.php | 92,116 | Redundant finalizeInventory() for COD/cashier |

---

## Final Verdict

**WOULD YOU APPROVE THIS IMPLEMENTATION FOR PRODUCTION?**

**NO.** ❌

### Why Not:

1. **Inventory Will Leak:** C-1 means every abandoned online payment permanently loses inventory. For a cosmetics store with 100+ products, even 5 abandoned checkouts per day creates significant inventory drift within weeks.

2. **Payment Callback Has No Safety Net:** C-2, C-3, C-6 mean duplicate callbacks, partial failures, and inconsistent states are possible. A gateway retry (which happens routinely) will process the payment twice.

3. **Race Conditions in Core Financial Logic:** C-4 (calcInvoicePrice without locks) and C-5 (coupon increment without lock) mean concurrent users can cause pricing inconsistencies and coupon over-usage.

4. **No Inventory Restoration on Cancellation:** H-1 means cancelled orders' inventory is never returned to stock. Over time, this creates a growing gap between actual stock and database stock.

### What Must Be Fixed Before Production:

| Priority | Issue | Fix |
|----------|-------|-----|
| P0 | C-1 | Move inventory finalization from `addItemsInOrder()` to `checkoutCallback()` after payment verification |
| P0 | C-2/C-3 | Add `lockForUpdate()` on Transaction lookup in callback + idempotency check |
| P0 | C-4 | Add `lockForUpdate()` on Cart in `calcInvoicePrice()` |
| P0 | C-5 | Add `lockForUpdate()` on Coupon row before `increment('used')` |
| P0 | C-6 | Wrap entire callback in `DB::transaction()` |
| P1 | H-1 | Add listener for `OrderCancelled` event to restore `stock_quantity` |
| P1 | H-3 | Create Transaction record inside the same transaction as order creation |

### Risk Assessment:

| Risk | Without Fixes | With Fixes |
|------|---------------|------------|
| Inventory leak | 100% certain over time | Close to 0% |
| Duplicate payment processing | Likely (gateway retries are common) | Very unlikely |
| Pricing race condition | Possible under load | Prevented by locking |
| Orphan orders | Possible on server crash | Prevented by atomic transaction |

**Estimated fix time: 2-3 days** (requires careful refactoring of the checkout flow, adding inventory rollback listeners, and comprehensive concurrency tests)

**Recommendation:** Do not deploy to production until all Critical and High issues are resolved. After fixes, run a concurrency test suite with 10+ simultaneous requests to verify locking works end-to-end.
