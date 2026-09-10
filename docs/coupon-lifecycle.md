# Coupon Lifecycle Audit

## Table of Contents

1. [Coupon Domain Model](#1-coupon-domain-model)
2. [Coupon Types](#2-coupon-types)
3. [Public vs Assigned Coupons](#3-public-vs-assigned-coupons)
4. [Coupon Creation](#4-coupon-creation)
5. [Coupon Validation](#5-coupon-validation)
6. [Assignment to Cart](#6-assignment-to-cart)
7. [Stored Inside Cart](#7-stored-inside-cart)
8. [Checkout Validation](#8-checkout-validation)
9. [Payment & Order Creation](#9-payment--order-creation)
10. [Usage Record](#10-usage-record)
11. [Removal](#11-removal)
12. [Expiration](#12-expiration)
13. [Failure Handling](#13-failure-handling)
14. [Retry Scenarios](#14-retry-scenarios)
15. [Critical Questions Answered](#15-critical-questions-answered)
16. [Bugs Found](#16-bugs-found)

---

## 1. Coupon Domain Model

### Coupon (packages/marvel/src/Database/Models/Coupon.php)

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint PK | |
| `code` | string (unique) | Auto-generated: `coupon_RANDOM7` |
| `slug` | string | |
| `name` | json (translatable) | Human-readable name |
| `discount_type` | enum: percentage, fixed_rate, free_shipping | Type of discount |
| `discount` | decimal | Amount or percentage value |
| `max_discount_amount` | decimal, nullable | Cap for percentage discounts |
| `start_date` | date, nullable | When coupon becomes active |
| `end_date` | date, nullable | When coupon expires |
| `limiter` | integer, nullable | Max total uses (across all users) |
| `used` | integer | Current usage count |
| `status` | boolean | Enabled/disabled |
| `border_color` | string, nullable | UI hint |
| `borderless` | boolean | UI hint |

### CouponAssignment (packages/marvel/src/Database/Models/CouponAssignment.php)

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint PK | |
| `coupon_id` | bigint FK→coupons | |
| `user_id` | bigint FK→users | Who gets the assignment |
| `max_uses` | integer | Per-user quota |
| `used` | integer | Current per-user usage |
| `assigned_at` | datetime | When assigned |
| `expires_at` | datetime, nullable | Optional assignment-level expiry |

### CouponUsage (packages/marvel/src/Database/Models/CouponUsage.php)

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint PK | |
| `coupon_id` | bigint FK→coupons | |
| `user_id` | bigint FK→users | |
| `order_id` | bigint FK→orders | |
| `used_at` | datetime | When used |

**Unique constraint**: `(coupon_id, user_id)` — one usage per user per public coupon.

### CouponAssignmentUsage (packages/marvel/src/Database/Models/CouponAssignmentUsage.php)

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint PK | |
| `coupon_assignment_id` | bigint FK→coupon_assignments | |
| `order_id` | bigint FK→orders | |
| `used_at` | datetime | When used |

**Append-only**: rows are never updated or deleted.

---

## 2. Coupon Types

| Discount Type | Enum Value | Behavior |
|--------------|------------|----------|
| **Percentage** | `percentage` | `discount`% off, capped at `max_discount_amount` |
| **Fixed Rate** | `fixed_rate` | `discount` amount off (cannot exceed subtotal) |
| **Free Shipping** | `free_shipping` | Sets shipping cost to 0 |

**Discount calculation** (`CouponCalculator::calculate()`):

```php
if (percentage) {
    discountAmount = price * (discount / 100);
    if (max_discount_amount !== null) {
        discountAmount = min(discountAmount, max_discount_amount);
    }
} elseif (fixed_rate) {
    discountAmount = min(discount, price);
}
// free_shipping sets discountAmount = 0 but marks 'freeShipping' = true
finalPrice = max(0, price - discountAmount);
```

### Key Finding

1. **Free shipping is not a discount** — `FREE_SHIPPING` type produces `discountAmount = 0`, `finalPrice = price` unchanged. The shipping price is zeroed separately via `resolveFreeShippingByCoupon()` in `OrderService`.

---

## 3. Public vs Assigned Coupons

### Public Coupons

- No rows in `coupon_assignments` table
- ANY authenticated user can apply them (once per user)
- Usage tracked in `coupon_usages` table: one row per `(coupon_id, user_id)`
- Global `limiter` on the coupon is shared across all users

### Assigned Coupons

- One or more rows in `coupon_assignments` table
- Only assigned users can apply them
- Usage tracked in `coupon_assignment_usages` table (immutable audit log)
- Per-user `max_uses` quota on the assignment
- Assignment can have its own `expires_at`

### Detection Logic

`CouponAssignmentValidator::validate()`:

```php
$hasAssignments = $coupon->assignments()->exists();
// If no assignments → public coupon flow
// If assignments → must find matching CouponAssignment for this user
```

### Key Finding

2. **A coupon cannot be both public AND assigned** — The presence of ANY assignment row makes the coupon restricted. The logic is all-or-nothing per coupon.
3. **Assigned coupon validation skips per-user usage check** — For assigned coupons, `CouponValidator::validate()` is called with `$user = null`, so the `already_used` check (which queries `coupon_usages`) is bypassed. Usage is managed entirely at the assignment level.
4. **Inconsistent: assigned coupons still use `coupon_usages`** — The `recordCouponUsage()` in `OrderService` increments `coupons.used` for both public AND assigned coupons via `$coupon->increment('used')`.

---

## 4. Coupon Creation

### Admin Endpoint

Coupons are created via admin endpoints (Marvel's `CouponController` or GraphQL `CouponMutator`).

### Auto-generated Code

```php
static::creating(function ($coupon) {
    $code = strtoupper(Str::random(7));
    $coupon->code = strtolower(preg_replace('/\s+/', '_', 'coupon' . "_" . $code));
});
```

### Observer

`CouponObserver` logs activity on create, update, status change, and delete.

---

## 5. Coupon Validation

### Validation Chain

```
CouponOrchestrator::validate(coupon, user, items)
  → CouponAssignmentValidator::validate(coupon, user)
    → Check if assignments exist
    → If yes: find user's assignment, check expiry, check quota
  → CouponValidator::validate(coupon, user?, items?)
    → Check status = true
    → Check start_date <= today
    → Check end_date >= today
    → Check limiter is null or used < limiter
    → If user provided: check coupon_usages for already_used
    → If items provided: check product restriction intersection
```

### Validation Rules Summary

| Rule | Source | When Checked |
|------|--------|-------------|
| Coupon exists | `CouponOrchestrator::validateByCode()` | Always |
| Coupon enabled | `CouponValidator` | Always |
| Date range active | `CouponValidator` | Always |
| Global usage limit | `CouponValidator` | Always |
| Already used by user (public) | `CouponValidator` | When user provided |
| Product restriction | `CouponValidator` | When items provided |
| User is assigned (if restricted) | `CouponAssignmentValidator` | When user provided |
| Assignment not expired | `CouponAssignmentValidator` | If assignment exists |
| Assignment quota not exceeded | `CouponAssignmentValidator` | If assignment exists |

---

## 6. Assignment to Cart

### Endpoint

```
POST /api/v1/coupons/apply  { code: "coupon_XXXXXXX" }
  → auth:sanctum middleware
  → CouponController::applyCoupon()
    → CouponService::addCouponToCart($code)
```

### Code Path

```
CouponService::addCouponToCart($code):
  DB::transaction:
    1. Get authenticated user
    2. Check user has a cart (if no cart → return null → 400 error)
    3. Check if coupon already applied (cart.coupon === $code → return ['already_applied' => true])
    4. Validate via CouponOrchestrator::validateByCode($code, $user, $cart->items)
    5. If invalid → return null → 400 error
    6. Calculate cart total with coupon
    7. Store coupon code on cart: $cart->forceFill(['coupon' => $coupon->code])->save()
    8. Return ['total_price', 'coupon_discount', 'free_shipping']
```

### Key Finding

5. **Coupon is NOT validated with `lockForUpdate` on the coupon row** — The `CouponAssignmentValidator::validate()` uses a regular `->first()` without locking. Between validation and storage, the coupon could theoretically be modified. However, since the coupon is stored as a string (not a FK), the actual locking is deferred to checkout time.
6. **Cart `total_price` calculation in `updateCartTotalPrice()` uses stale price** — It calculates based on the current `total_price` of the cart, which may not reflect the latest promotion discounts. But since promotions are re-calculated at checkout, this is informational only.
7. **The response returns coupon discount but doesn't update cart's `total_price`** — Wait, it actually does not update `cart.total_price`. Looking at the code: `$cart->forceFill(['coupon' => $coupon->code])->save()`. It stores the coupon on the cart but the `total_price` field is NOT updated. However, `CartResource::toArray()` at read time recalculates coupon discount from the `$this->coupon` string. So the `total_price` is only used for subtotal, not final total.

Actually wait, let me re-check. The return value `$totalPriceForCart = $couponTotal['finalPrice']` is computed but *only returned in the response* — it's NOT stored back to `$cart->total_price`. So the cart's `total_price` remains the raw subtotal. This is confirmed by `CartResource` which calculates `subtotal = items.sum('total_price')` and `coupon_discount = CouponCalculator::calculate()` separately.

So the pattern is:
- `cart.total_price` = raw subtotal (sum of item line totals)
- Coupon discount is calculated at read time by `CartResource`
- Promotion discount is stored on cart items (`item.discount_amount`) and cleared on every cart mutation
- Final total is only calculated at checkout time in `OrderService::calculateCheckoutTotals()`

---

## 7. Stored Inside Cart

Coupon is stored as a **string** on the cart:

```php
$cart->coupon = 'coupon_XXXXXXX'  // plain string, no FK
```

### Database

```sql
carts.coupon VARCHAR(255) NULL
```

### No Referential Integrity

- If the coupon is deleted from `coupons` table, the cart still holds the code string.
- No CASCADE, no foreign key constraint.
- The code string can reference a non-existent coupon.

### When the String is Read

`CartResource::toArray()`:
```php
$couponModel = $this->coupon ? Coupon::where('code', $this->coupon)->first() : null;
```

This reads the coupon from DB each time the cart is serialized. If the coupon was deleted, `$couponModel` is null and no coupon data appears in the response.

---

## 8. Checkout Validation

### Validation During Checkout

`OrderService::addItemsInOrder()` (lines 168-179):

```php
if ($cart->coupon) {
    $lockedCoupon = Coupon::where('code', $cart->coupon)->lockForUpdate()->first();
    if ($lockedCoupon) {
        $validation = CouponOrchestrator::validate($lockedCoupon, $request->user(), $cart->items);
        if (!$validation['valid']) {
            $cart->update(['coupon' => null]);  // Clear invalid coupon
        } elseif ($lockedCoupon->discount_type === DiscountType::FREE_SHIPPING) {
            $freeShippingCoupon = true;
        }
    } else {
        $cart->update(['coupon' => null]);  // Clear if coupon not found
    }
}
```

### Key Finding

8. **BUG — Stale `$cart->coupon` in memory** — After `$cart->update(['coupon' => null])`, the in-memory `$cart->coupon` still holds the old value. When `calculatePriceByCoupon()` is called later (line 440), it reads `$cart->coupon` which is still the old code — re-finds the coupon by that code, and re-applies it. This was already documented in the previous audit. The fix is `$cart->refresh()`.

9. **Coupon is validated with `lockForUpdate` at checkout** — This is the first time the coupon row is locked. This prevents concurrent checkouts from both seeing a valid coupon and over-consuming its limit.

---

## 9. Payment & Order Creation

### Snapshot on Order

When the order is created, coupon data is snapshotted:

```php
Order::create([
    'coupon' => $checkoutTotals->coupon ?? $cart->coupon ?? null,
    'coupon_discount' => $checkoutTotals->couponDiscount ?: null,
    'coupon_discount_type' => $checkoutTotals->couponDiscountType,
    'coupon_discount_max_amount' => $checkoutTotals->couponDiscountMaxAmount,
]);
```

### Payment Success → Usage Recorded

`OrderService::recordCouponUsage()` (line 667):

This is called from `changeOrderStatus()` when status transitions to `'completed'` (line 535):

```php
if ($status === 'completed') {
    $this->recordCouponUsage($order);
}
```

**For public coupons:**
```php
$couponUsage = CouponUsage::firstOrCreate(
    ['coupon_id' => $coupon->id, 'user_id' => $order->user_id],
    ['order_id' => $order->id, 'used_at' => now()]
);
if ($couponUsage->wasRecentlyCreated) {
    $coupon->increment('used');
}
```

**For assigned coupons:**
```php
$assignment = CouponAssignment::where('coupon_id', $coupon->id)
    ->where('user_id', $order->user_id)
    ->lockForUpdate()
    ->first();
// ... validates quota, checks existing usage for this order
$coupon->increment('used');
$assignment->increment('used');
CouponAssignmentUsage::create(['coupon_assignment_id' => $assignment->id, 'order_id' => $order->id, 'used_at' => now()]);
// Dispatches AssignedCouponConsumed event after commit
```

### When is `recordCouponUsage()` Called?

| Path | Where Called | Timing |
|------|-------------|--------|
| Online payment success | `changeOrderStatus('completed')` | After gateway verification + idempotency check |
| COD marked as paid | `markCodAsPaid()` → `recordCouponUsage()` | When admin marks paid |
| Cashier marked as paid | `markCashierPaid()` → `recordCouponUsage()` | When admin marks paid |
| Order cancelled | `changeOrderStatus('cancelled')` | Does NOT call `recordCouponUsage()` (only for 'completed') |

### Key Finding

10. **Coupon usage is recorded only when order becomes 'completed'** — For online payments, this happens in the callback. For COD/cashier, this happens when admin marks as paid. The coupon quota is NOT consumed at order creation time.
11. **Policy: Coupon is NEVER returned on cancellation/refund** — The comment in `recordCouponUsage()` explicitly states this is intentional to prevent abuse.
12. **`$coupon->increment('used')` happens ALWAYS for assigned coupons** — Even though the assignment already tracks per-user usage, the global `coupons.used` counter is also incremented. This means `coupons.used` acts as a global ceiling for both public and assigned coupons.

---

## 10. Usage Record

### Record Tables

| Table | Records | Key |
|-------|---------|-----|
| `coupon_usages` | Public coupon usage | `(coupon_id, user_id)` unique |
| `coupon_assignment_usages` | Assigned coupon usage | `(coupon_assignment_id, order_id)` |

### Counter Columns

| Column | Incremented When | Decremented When |
|--------|-----------------|------------------|
| `coupons.used` | Payment succeeds (order → completed) | **NEVER** |
| `coupon_assignments.used` | Payment succeeds (order → completed) | **NEVER** |

### Key Finding

13. **Usage counters are NEVER decremented** — Once a coupon is consumed, it's permanent. This is an explicit policy decision documented in the code.

---

## 11. Removal

### From Cart

| Trigger | Mechanism | Code Location |
|---------|-----------|---------------|
| Coupon invalid at checkout | `$cart->update(['coupon' => null])` | OrderService:173 |
| Coupon not found at checkout | `$cart->update(['coupon' => null])` | OrderService:178 |
| Last item removed from cart | ReleaseItem → remaining=0 → clear coupon | CartInventoryService:180 |
| Cart checked_out | Not explicitly cleared (but releaseItem handles at item level) | finalizeCart |
| Cart expired | Not explicitly cleared (but releaseItem handles at item level) | expireCart |
| calcInvoicePrice validation fails | `$cart->update(['coupon' => null])` | OrderService:120 |

### From Coupon Tables

Coupon deletion via admin: observer logs the deletion, but does NOT clear it from carts (no FK, no cascade). The coupon code string remains on carts but becomes un-resolvable.

---

## 12. Expiration

### Coupon Expiration

Defined by `end_date` field. Checked during validation:
```php
if ($coupon->end_date && $coupon->end_date->lt(today())) {
    return self::invalid('expired', __('coupon.expired'));
}
```

### Assignment Expiration

Defined by `coupon_assignments.expires_at` field:
```php
if ($assignment->expires_at && $assignment->expires_at->isPast()) {
    return self::invalid('assignment_expired', __('coupon.assignment_expired'));
}
```

### When Expiration is Detected

- At coupon application time (`POST /coupons/apply`)
- At checkout validation (`OrderService::addItemsInOrder()`)
- At `calcInvoicePrice` validation

### Key Finding

14. **No scheduled job to clean expired coupons from carts** — Expired coupons are only detected at validation time. If a coupon expires while it's already on a cart, it stays there until the user attempts checkout or the cart is modified.

---

## 13. Failure Handling

### Scenario: Checkout Fails

If `addItemsInOrder()` throws an exception (e.g., minimum order not met), the DB transaction is rolled back. The cart is unchanged — coupon stays.

### Scenario: Payment Fails

`checkoutCallback()` when `!$result->success`:
- Transaction → 'failed'
- `PaymentFailed` event dispatched
- **Cart NOT modified** — coupon stays, items reserved

`checkoutErrorCallback()` (cancellation):
- Transaction → 'failed'
- `PaymentFailed` event dispatched
- **Cart NOT modified** — coupon stays

### Scenario: Payment Expires

`CancelUnpaidOrders` command:
- Order → 'cancelled'
- Transaction → 'failed'
- `OrderCancelled` event dispatched
- `PaymentFailed` event dispatched
- **Cart is expired** via `expireSingleCart()`:
  - Items deleted, inventory released
  - `releaseItem()` is called for each item
  - When last item is removed: coupon is cleared (from releaseItem's logic)
  - Cart → `status='expired'`

### Key Finding

15. **On payment failure/cancellation: coupon stays on cart** — The user can retry checkout without re-applying the coupon.
16. **On payment expiration: coupon is removed** — Because the cart's items are deleted, `releaseItem()` clears the coupon when the last item goes.

---

## 14. Retry Scenarios

### Retry after payment failure

1. Cart: status='active', coupon intact, items reserved
2. User re-submits `POST /checkout`
3. `OrderService::addItemsInOrder()` re-validates coupon
4. If valid: applies discount again, creates new order
5. If invalid (e.g., limiter reached since first attempt): clears coupon from cart, throws exception

### Retry after payment cancellation

Same as failure — coupon stays, user retries.

### Retry after payment expiry

1. Cart has been expired, items deleted, coupon cleared
2. User must re-add items and re-apply coupon (if still valid)
3. A new active cart is created (the old expired one is reused with status reset)

### Retry after checkout validation failure

1. Coupon cleared from cart (if it was invalid)
2. User re-applies with `POST /coupons/apply`
3. If coupon still valid: re-attached
4. User retries checkout

---

## 15. Critical Questions Answered

### If checkout fails, should coupon remain?

**YES.** The coupon stays on the cart. The DB transaction in `addItemsInOrder()` is rolled back on failure, leaving the cart unchanged.

### If payment pending, should coupon remain?

**YES.** The coupon string stays on the cart. It's only cleared when:
- The cart is finalized (items deleted → coupon cleared)
- An explicit `coupon = null` update happens

### If payment cancelled, should coupon remain?

**YES.** Cancellation goes through `checkoutErrorCallback()` which does NOT modify the cart.

### If payment expired, should coupon remain?

**YES in theory, but cleared in practice.** Payment expiry goes through `CancelUnpaidOrders` which deletes cart items. Since `releaseItem()` clears the coupon when the last item is removed, the coupon ends up cleared.

**CURRENT BEHAVIOR**: Coupon is cleared on payment expiry (because items deletion triggers coupon cleanup).

**OPTIMAL BEHAVIOR**: This is debatable. Since the user's original coupon was valid, and the expiry is due to timeout (not coupon invalidity), the coupon could arguably remain. However, since the cart items are deleted (inventory released), the coupon string on an empty cart is meaningless. When the user re-adds items, they need to re-apply.

### If user edits cart, should coupon remain?

**YES (most cases).** Coupon code string persists across:
- Add item → coupon stays
- Update quantity → coupon stays
- Remove item → coupon stays (unless it was the last item)

Only when the **last item** is removed does the coupon get cleared (`releaseItem()` → remaining=0 → clear coupon).

### If coupon becomes invalid, when exactly is it removed?

| Invalidation Scenario | Removal Time | Mechanism |
|----------------------|--------------|-----------|
| Coupon disabled (status=false) | At next checkout validation | `$cart->update(['coupon' => null])` |
| Coupon expired (end_date past) | At next checkout validation | Same |
| Coupon usage limit reached | At next checkout validation | Same |
| Coupon deleted from DB | At next checkout validation | `Coupon::where('code', ...)->first()` returns null → clear |
| Assignment expires | At next checkout validation | `CouponAssignmentValidator` returns invalid |
|  | At next coupon apply | `CouponOrchestrator::validateByCode()` fails |

### If coupon already consumed, how is it prevented from reuse?

**Public coupons**: `CouponUsage::firstOrCreate()` with unique `(coupon_id, user_id)` constraint. If a row already exists, `wasRecentlyCreated` is false and `$coupon->increment('used')` is NOT called.

**Assigned coupons**: `CouponAssignmentUsage::where('coupon_assignment_id', $id)->where('order_id', $order->id)->exists()` check prevents the same order from being recorded twice.

**Global limiter**: `CouponValidator` checks `$coupon->used >= $coupon->limiter` at validation time.

---

## 16. Bugs Found

| ID | Severity | File:Line | Description |
|----|----------|-----------|-------------|
| CPN-1 | MEDIUM | `OrderService:168-179` | Stale `$cart->coupon` in-memory after invalid coupon cleared. `$cart->update(['coupon' => null])` updates DB but `$cart->coupon` in PHP still holds old value. Called later by `calculatePriceByCoupon()` which reads `$cart->coupon` → re-finds the deleted coupon by code → re-applies it. Need `$cart->refresh()`. Already documented in previous session. |
| CPN-2 | LOW | `CouponService:72` | `$user->cart` accessed without `lockForUpdate`. Concurrent apply requests could race. |
| CPN-3 | LOW | `CouponService:91-104` | `updateCartTotalPrice()` calculates `$couponTotal` using `$cart->total_price` but does NOT update the cart's `total_price`. The response returns computed values but the cart record only stores the coupon code string. Since `total_price` on cart is only informational (recalculated at checkout), this is not a financial bug but could confuse API consumers. |
| CPN-4 | INFO | `CouponAssignmentValidator:23` | Assignment lookup uses `->first()` without `lockForUpdate`. Between validation and checkout, the assignment could be modified. At checkout time, the coupon IS locked via `lockForUpdate` in `OrderService:169`, protecting the `coupons.used` counter, but the assignment is re-read without lock in `recordCouponUsage()`. |
| CPN-5 | INFO | `recordCouponUsage` | `recordCouponUsage()` is called from `changeOrderStatus('completed')`. For COD/cashier workflows, this happens when admin marks paid. But the cart's `coupon` field might have been cleared by then (if the cart was finalized and items were deleted). The order's snapshot will have the correct coupon value. However, `recordCouponUsage` reads `$order->coupon` (the snapshot), so this is safe. |
| CPN-6 | LOW | `CartInventoryService:180` | `releaseItem()` clears coupon when last item is removed from cart. But `finalizeCart()` (used for checked_out) deletes all items WITHOUT calling `releaseItem()` for each — it uses `$item->delete()` directly. This means the coupon string persists on a checked_out cart. If that cart is later reactivated (because the user_id unique constraint causes it to be reused), the old coupon code would still be there. See CART-1. |
