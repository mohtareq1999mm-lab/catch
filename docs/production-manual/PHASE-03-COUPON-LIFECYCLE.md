# Phase 3: Coupon Lifecycle

## Executive Summary

The coupon system supports two distinct consumption models — assigned coupons (admin grants a per-user usage quota) and public coupons (any authenticated user may use once). Validation is stateless at apply time; quota is consumed only after payment succeeds and is **never** reversed on cancellation or refund. This is by design to prevent quota-reuse abuse.

---

## Current Implementation

### Apply Flow

```
POST /coupons/apply
  ├─ CouponController::applyCoupon()
  └─ CouponService::addCouponToCart($code)
       └─ CouponOrchestrator::validateByCode($code, $user, $cart->items)
            ├─ CouponAssignmentValidator::validate($coupon, $user)
            │    Checks: coupon has assignments? → user is assigned? → assignment expired? → used >= max_uses?
            │    Returns: has_assignments (bool), valid (bool), assignment (Model|null)
            └─ CouponValidator::validate($coupon, $user, $items)
                 Checks: status=true, start_date ≤ today, end_date ≥ today, limiter > used, user not already in coupon_usages, cart has at least one product in coupon_products restriction
       └─ CouponCalculator::calculate($coupon, $price)
            Returns: discountAmount, finalPrice, discountType, freeShipping (bool)
       └─ Cart::forceFill(['coupon' => $code])->save()
            Only stores the coupon CODE on the cart row — NO reservation of quota
```

### Validation Rule Table

| Rule | Source | Code Location |
|---|---|---|
| Coupon exists | `Coupon::where('code', $code)->first()` | `CouponOrchestrator:13` |
| Status active | `$coupon->status` must be `true` | `CouponValidator:14` |
| Not started | `start_date->gt(today())` → invalid | `CouponValidator:20` |
| Expired | `end_date->lt(today())` → invalid | `CouponValidator:24` |
| Usage limit | `limiter !== null && used >= limiter` → invalid | `CouponValidator:28` |
| Already used (public) | `CouponUsage::where(coupon_id, user_id)->whereNotNull('used_at')->exists()` | `CouponValidator:33-36` |
| Product restriction | If coupon has `coupon_product` relations, cart must contain at least one of those products | `CouponValidator:44-52` |
| Assigned to user | `CouponAssignment::where(coupon_id, user_id)` must exist | `CouponAssignmentValidator:23-28` |
| Assignment expired | `assignment->expires_at->isPast()` | `CouponAssignmentValidator:31` |
| Assignment quota | `assignment->used >= assignment->max_uses` | `CouponAssignmentValidator:35` |

### Reservation

**Quota is NOT reserved at apply time.** The coupon code is stored on the cart's `coupon` column. Between apply and checkout, the same quota slot can be taken by another concurrent checkout. The coupon's `used` counter, `CouponUsage` rows, `CouponAssignment.used`, and `CouponAssignmentUsage` rows are all created only **after** payment succeeds.

### Consumption (recordCouponUsage)

Called from three locations:

```
changeOrderStatus($invoiceId, 'completed')
  └─ recordCouponUsage($order)

markCodAsPaid($order)
  └─ recordCouponUsage($order)

markCashierPaid($order)
  └─ recordCouponUsage($order)
```

#### Guard: `coupon_consumed` flag

```php
if (!$order->coupon || $order->coupon_consumed) {
    return;
}
```

The `coupon_consumed` column is checked on the order. If already `true`, consumption is skipped (idempotent). After consumption the column is set to `true`:

```php
if (Schema::hasColumn('orders', 'coupon_consumed')) {
    $order->update(['coupon_consumed' => true]);
}
```

#### Path A: Assigned Coupons

```
Coupon::where('code', $order->coupon)->first()
  ├─ coupon->assignments()->exists() → true
  ├─ CouponAssignment::where(coupon_id, user_id)->lockForUpdate()
  │    └─ if used >= max_uses → return (safety guard)
  ├─ CouponAssignmentUsage::where(coupon_assignment_id, order_id)->lockForUpdate()
  │    └─ if exists → return (double-count prevention)
  ├─ coupon->increment('used')           ← global counter
  ├─ assignment->increment('used')       ← per-user quota counter
  ├─ CouponAssignmentUsage::create(...)  ← audit trail row
  └─ DB::afterCommit → event(new AssignedCouponConsumed(...))
       └─ Fired via afterCommit (safe after transaction commits)
```

**Tables involved:**

| Table | Role |
|---|---|
| `coupons.used` | Global usage counter |
| `coupon_assignments.used` | Per-user quota counter |
| `coupon_assignment_usages` | Immutable audit trail (coupon_assignment_id, order_id, used_at) |

#### Path B: Public Coupons

```
  ├─ coupon->assignments()->exists() → false
  └─ CouponUsage::firstOrCreate(
       ['coupon_id' => $coupon->id, 'user_id' => $order->user_id],
       ['order_id' => $order->id, 'used_at' => now()]
     )
       └─ if wasRecentlyCreated → coupon->increment('used')
```

Enforced by **unique constraint** on `coupon_usages(coupon_id, user_id)` — a user can only ever have one usage row per coupon. If the row already exists (e.g. from a previous order), `firstOrCreate` returns it without incrementing `used` again.

### FREE_SHIPPING Coupon Type

Handled in `OrderService::addItemsInOrder()` at line 167:

```php
if ($lockedCoupon->discount_type === DiscountType::FREE_SHIPPING) {
    $freeShippingCoupon = true;
}
```

Then at line 214:

```php
if ($freeShippingCoupon) {
    $shippingPrice = 0;
}
```

The coupon code is applied as normal; only the shipping cost is zeroed out.

### Expiration at Checkout (Silent Clear)

Validated in two places:

1. **`calcInvoicePrice()`** at `OrderService:118`:
```php
if ($cart->coupon) {
    $validation = CouponOrchestrator::validateByCode($cart->coupon, $request->user(), $cart->items);
    if (!$validation['valid']) {
        $cart->update(['coupon' => null]);  // ← silently cleared
    }
}
```

2. **`addItemsInOrder()`** at `OrderService:168-181`:
```php
if ($cart->coupon) {
    $lockedCoupon = Coupon::where('code', $cart->coupon)->lockForUpdate()->first();
    if ($lockedCoupon) {
        $validation = CouponOrchestrator::validate($lockedCoupon, $request->user(), $cart->items);
        if (!$validation['valid']) {
            $cart->update(['coupon' => null]);  // ← silently cleared
        }
    } else {
        $cart->update(['coupon' => null]);
    }
}
```

### Event: AssignedCouponConsumed

Fired via `DB::afterCommit()`:

```php
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
```

**Properties carried:**
- `coupon` — the Coupon model
- `couponAssignment` — the CouponAssignment model
- `user` — the User model
- `order` — the Order model
- `remainingUses` — computed as `max(0, max_uses - fresh()->used)`
- `consumedAt` — `now()`

**No listener is currently registered for this event** in `App\Providers\EventServiceProvider`. The event is dispatch-only. This is a gap — any post-consumption side effects (notification, analytics, webhook) would need a listener.

---

## Database Tables

### `coupons`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| code | varchar(191) | Stored on cart/order |
| name | json (translatable) | |
| discount_type | varchar(191) | `percentage`, `fixed_rate`, `free_shipping` |
| discount | decimal | |
| max_discount_amount | decimal nullable | Cap for percentage type |
| start_date | date nullable | |
| end_date | date nullable | |
| limiter | int nullable | Max global uses |
| used | int | Global usage counter |
| status | tinyint(1) | |
| border_color, borderless | UI fields | |

### `coupon_assignments`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| coupon_id | bigint FK | |
| user_id | bigint FK | |
| max_uses | int | Per-user quota |
| used | int | Per-user counter |
| assigned_at | datetime | |
| expires_at | datetime nullable | |

### `coupon_assignment_usages`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| coupon_assignment_id | bigint FK | |
| order_id | bigint FK | |
| used_at | datetime | |

### `coupon_usages`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| coupon_id | bigint FK | |
| user_id | bigint FK | |
| order_id | bigint FK | |
| used_at | datetime | |
| UNIQUE(coupon_id, user_id) | | Enforces one-use-per-user |

### `orders` (relevant columns)

| Column | Type | Notes |
|---|---|---|
| coupon | varchar(191) nullable | Stores coupon code |
| coupon_discount | decimal nullable | |
| coupon_discount_type | varchar nullable | |
| coupon_discount_max_amount | decimal nullable | |
| coupon_consumed | tinyint(1) | Guard flag (Schema::hasColumn-checked) |

---

## Problems

### P3-C1: Coupon code cleared silently on expiration

When a coupon expires between the time a user adds it to their cart and checkout, the code is silently removed from the cart. The user receives no notification. The checkout simply proceeds without the discount.

**Impact:** Customer confusion. User may proceed with a higher total than expected and abandon checkout.

**Location:** `OrderService::calcInvoicePrice():121`, `OrderService::addItemsInOrder():173`

### P3-C2: Stale coupon on cart page

The coupon stored on the cart (`cart.coupon`) is not refreshed or re-validated until the user initiates a price calculation or checkout. If a coupon becomes invalid (expired, usage limit reached, admin disabled), the cart page will still display it as applied until the user explicitly triggers a checkout action.

**Location:** `CouponService::addCouponToCart()` stores code; no periodic re-validation.

### P3-C3: No concurrent-quota safety gap

Between `POST /coupons/apply` and checkout completion, the coupon's remaining quota is not reserved. Two users with the same coupon in their cart can both proceed to checkout; whichever completes payment first gets the quota. The second will have `recordCouponUsage` silently skip consumption (the `CouponUsage::firstOrCreate` already exists path), but the order was already created with the discount applied.

**Impact:** The second order has a coupon discount applied but the coupon's `used` counter is not incremented. The coupon effectively gives away one extra discount than its limiter allows.

**Location:** `CouponUsage::firstOrCreate` returns existing row without incrementing.

### P3-C4: AssignedCouponConsumed event has no listeners

The event is dispatched with rich payload (remaining uses, user, order) but no listener is registered. Any downstream system (notification, analytics, admin alert) would need to add a listener.

---

## Production Recommendations

### R3-1: Notify on coupon expiry during checkout

In `addItemsInOrder()` and `calcInvoicePrice()`, when the coupon is silently cleared, return a flash message or API warning that the coupon is no longer valid. For frontend, add an optional `warning` field to the checkout response payload:

```json
{
  "warning": "coupon.expired"
}
```

### R3-2: Validate coupon on cart page load

Add middleware or an API endpoint that re-validates the coupon whenever the cart is fetched. Remove the coupon code from the cart and return a warning if it is no longer valid.

### R3-3: Reserve coupon quota at apply time (optional, high traffic)

For high-traffic flash sales where coupon quota contention is expected, consider reserving the quota at `POST /coupons/apply` time using a `coupon_reservations` table with TTL, and releasing unconfirmed reservations on cart expiry.

### R3-4: Register a listener for AssignedCouponConsumed

Add a listener for `App\Events\AssignedCouponConsumed` to notify the admin when an assigned coupon is fully consumed (e.g., when `remainingUses === 0`).

### R3-5: Add regression tests for concurrent quota exhaustion

Write a test that dispatches two concurrent checkouts with the same public coupon and verifies that only one gets the discount.
