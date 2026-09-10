# Checkout Pricing Fix — Split Shipping Method Totals

## Overview

This document explains three bugs found in the checkout flow and their fixes. The core issue was that **normal checkout** (`/general/checkout`) and **fast checkout** (`/general/checkout/fast`) were both using the **combined total** of all cart items (SCHEDULED + FAST) instead of only their respective shipping method's items.

---

## Bug 1: `final_total` Included Both Shipping Methods

### Root Cause

`PromotionService::applySelectedPromotion()` called `$cart->load(['items.product', 'items.productVariant'])` which **reloads ALL cart items** from the database, completely discarding the shipping method filter (`SCHEDULED` or `FAST`) set by the caller.

This affected both normal checkout and fast checkout:

| Checkout Type | Caller Filter | After `applySelectedPromotion` | Result |
|---|---|---|---|
| Normal (`/general/checkout`) | `getCartUser()` → SCHEDULED only | `$cart->load(...)` reloads ALL items | `final_total` = SCHEDULED + FAST |
| Fast (`/general/checkout/fast`) | `$cart->load(['items' => fn($q) => $q->where('shipping_method', 'FAST'), ...])` | `$cart->load(...)` reloads ALL items | `final_total` = SCHEDULED + FAST |

Additionally, `applySelectedPromotion()` returned `$cart->total_price` as `final_total` — this is a column on the `carts` table that stores the **combined total** of all items, not the filtered subset.

### Fix (3 changes in `PromotionService.php`)

**Change 1 — Line 57: Don't reload items, just load their relations**
```php
// Before:
$cart->load(['items.product', 'items.productVariant']);

// After:
$cart->items->load(['product', 'productVariant']);
```
`$collection->load('relation')` eager-loads the relation on each existing model in the collection without re-querying the parent records. This preserves the shipping method filter.

**Change 2 — Lines 99-100 & 107-108: Preserve item IDs before `refresh()`**
```php
// Before:
$cart->refresh();
$cart->load(['items.product', 'items.productVariant']);

// After:
$itemIds = $cart->items->pluck('id');
$cart->refresh();
$cart->load(['items' => fn($q) => $q->whereIn('id', $itemIds), 'items.product', 'items.productVariant']);
```
`refresh()` clears all loaded relations and reloads everything from the database. By saving item IDs before refresh and constraining the reload with `whereIn('id', $itemIds)`, we preserve the original items (and their shipping method filter).

**Change 3 — Line 114: Compute `final_total` from filtered items, not `cart.total_price`**
```php
// Before:
'final_total' => round((float) $cart->total_price, 2),

// After:
'final_total' => round(
    (float) $cart->items
        ->reject(fn($item) => (bool) ($item->is_gift ?? false))
        ->sum('total_price'),
    2
),
```
`$cart->total_price` is the combined total from the `carts` table. `$cart->items->sum('total_price')` sums the `total_price` column from the **filtered** cart items only.

---

## Bug 2: `getCheckoutTotalsFromCart` Used `cart.total_price`

### Root Cause

In `OrderService::getCheckoutTotalsFromCart()`, the `final_total` was calculated as:
```php
$finalTotal = round((float) ($cart->total_price ?? 0), 2);
```
This used the cart's combined total (e.g., 302.20 = 252.50 SCHEDULED + 49.70 FAST) instead of summing only the filtered items (e.g., 252.50 SCHEDULED only).

### Fix (1 change in `OrderService.php`)

```php
// Before (line 276):
$finalTotal = round((float) ($cart->total_price ?? 0), 2);

// After:
$finalTotal = round((float) $items->sum('total_price'), 2);
```
`$items` is already filtered (non-gift items from `$cart->items`, which are SCHEDULED-only when called from `addItemsInOrder` via `getCartUser()`). Using `sum('total_price')` gives the correct subtotal for only the relevant shipping method.

---

## Bug 3: `OrderCreated` Broadcast Crash Silently Failed the Order

### Root Cause

In `OrderService::addItemsInOrder()`, the event dispatch happens AFTER `DB::commit()`:
```php
DB::commit();
OrderCreated::dispatch($order);  // <-- This can throw
return $order;
```

The listener `SendNewOrderNotification` calls `Notification::send()` which broadcasts via Pusher. If the Pusher configuration is incorrect (wrong cluster/key), a `BroadcastException` is thrown. The generic `catch (\Exception $e)` block catches it and returns `null` — even though the order was **already committed to the database**.

Additionally, the generic catch block did NOT call `report($e)`, making it impossible to diagnose the error from logs.

### Fix (2 changes in `OrderService.php`)

**Wrap dispatch in try-catch:**
```php
// Before:
DB::commit();
OrderCreated::dispatch($order);
return $order;

// After:
DB::commit();
try {
    OrderCreated::dispatch($order);
} catch (\Throwable $e) {
    report($e);
}
return $order;
```

**Add `report($e)` to the generic catch block:**
```php
} catch (\Exception $e) {
    DB::rollBack();
    report($e);  // <-- Added
    return null;
}
```

This ensures:
- A broadcast failure doesn't nullify a successful order
- All exceptions are visible in `storage/logs/laravel.log`

---

## Files Modified

| File | Changes |
|---|---|
| `app/Services/General/PromotionService.php` | 4 edits (lines 57, 99-100, 107-108, 114) |
| `app/Services/General/OrderService.php` | 3 edits (lines 115-116, 122, 276) |

---

## Verification Results

### Test: FAST-only cart → `applySelectedPromotion` preserves filter

```
FAST items: 1
final_total=99.4 (expected: 99.40)
Items after: 1 (expected: 1)
=> PASS
```

### Test: `getCartUser()` returns only SCHEDULED items
```
Cart has FAST item only (SCHEDULED items deleted by previous checkout)
getCartUser() returns cart with 0 items (correct — no SCHEDULED items exist)
```

---

## Updated APIDOG Test Cases

---

### Test 7: Normal Checkout — Processes Only SCHEDULED Items

**Prerequisites:**
- Cart has at least one SCHEDULED item AND at least one FAST item
- Products are in stock
- Cart reservation is valid

**Endpoint:** `POST /api/v1/general/checkout`

**Auth:** Customer (Bearer token) — middleware: `auth:sanctum`, `check-email`

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Customer full name |
| `user_phone` | string | Yes | Customer phone number |
| `user_email` | email | Yes | Customer email |
| `address` | object | Yes | Shipping address |
| `notes` | string | No | Delivery notes |
| `selected_promotion_id` | integer | No | Must exist in `promotions` table |
| `selected_gift_product_id` | integer | No | Must exist in `products` table |

**Example Request Body:**
```json
{
    "name": "Test User",
    "user_phone": "01000000000",
    "user_email": "test@example.com",
    "address": {
        "address": "123 Street",
        "city": "Cairo",
        "country": "Egypt"
    },
    "notes": "Test normal checkout"
}
```

**Expected Response (200):**
```json
{
    "success": true,
    "message": "Checkout successful",
    "data": {
        "url": "https://myfatoorah.com/payment/..."
    }
}
```

**Invoice Value Verification (CRITICAL):**
- The `InvoiceValue` sent to MyFatoorah must equal only the SCHEDULED items' total
- Example: if cart has SCHEDULED (252.50) + FAST (49.70), invoice = **252.50**, NOT 302.20

**Verify After Payment Callback Success:**
- `GET /api/v1/cart` → `normal_items_count = 0` (SCHEDULED items removed)
- `fast_items_count` unchanged (FAST items remain)
- Cart `status` is still `"active"` (because FAST items remain)
- Order created with only SCHEDULED items (no FAST items in order_items)
- Order `total_price` = SCHEDULED items' total only
- FAST items remain reserved in cart

**Verify After Payment Failure:**
- Cart is released (all items still present, reservation cleared)
- Order status = `"cancelled"`

---

### Test 8: Normal Checkout with Only FAST Items — SHOULD FAIL

**Prerequisites:**
- Cart has FAST items but NO SCHEDULED items

**Endpoint:** `POST /api/v1/general/checkout`

**Auth:** Customer (Bearer token) — middleware: `auth:sanctum`, `check-email`

**Expected Response (500):**
```json
{
    "success": false,
    "message": "Filed to create order try again"
}
```
*Note: The cart exists (with FAST items), but `getCartUser()` finds no SCHEDULED items. `calcInvoicePrice()` returns null because the SCHEDULED-filtered items collection is empty.*

**Verify:**
- No order is created
- FAST items remain in cart

---

### Test 9: Fast Checkout — Processes Only FAST Items

**Prerequisites:**
- Cart has at least one SCHEDULED item AND at least one FAST item
- Fast shipping globally enabled (via admin settings)
- Within working hours (default 08:00 - 22:00)
- Governorate has `is_fast_shipping_enabled = true`
- All FAST items have `is_fast_shipping_available = true`
- Products are in stock

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Auth:** Customer (Bearer token) — middleware: `auth:sanctum`

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Customer full name (max 255) |
| `user_phone` | string | Yes | Customer phone number (max 255) |
| `user_email` | email | Yes | Customer email (max 255) |
| `address` | object | Yes | Shipping address |
| `notes` | string | No | Delivery notes |
| `governorate_id` | integer | Yes | Must exist in `governorates` table |
| `selected_promotion_id` | integer | No | Must exist in `promotions` table |
| `selected_gift_product_id` | integer | No | Must exist in `products` table |

**Example Request Body:**
```json
{
    "name": "Test User",
    "user_phone": "01000000000",
    "user_email": "test@example.com",
    "address": {
        "address": "123 Street",
        "city": "Cairo",
        "country": "Egypt"
    },
    "notes": "Fast checkout test",
    "governorate_id": 1
}
```

**Expected Response (200):**
```json
{
    "success": true,
    "message": "Checkout successful",
    "data": {
        "url": "https://myfatoorah.com/payment/..."
    }
}
```

**Invoice Value Verification (CRITICAL):**
- The `InvoiceValue` sent to MyFatoorah must equal FAST items' total + fast shipping fee
- Example: if cart has SCHEDULED (252.50) + FAST (49.70), and shipping fee is 10.00:
  - `final_total` = 49.70 + 10.00 = **59.70**, NOT 302.20 + 10.00 = 312.20

**Verify After Payment Callback Success:**
- `GET /api/v1/cart` → `fast_items_count = 0` (FAST items removed)
- `normal_items_count` unchanged (SCHEDULED items remain)
- Cart `status` is still `"active"` (because SCHEDULED items remain)
- Order created with `shipping_method = "FAST"`
- Order has `expected_delivery_at` and `fast_shipping_fee`
- Order `total_price` = FAST items' total + shipping fee
- SCHEDULED items remain reserved in cart

**Verify After Payment Failure:**
- Cart is released (all items still present)
- Order status = `"cancelled"`

---

### Test 10: Fast Checkout with Only SCHEDULED Items — SHOULD FAIL

**Prerequisites:**
- Cart has SCHEDULED items but NO FAST items

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Auth:** Customer (Bearer token) — middleware: `auth:sanctum`

**Request Body:**
```json
{
    "name": "Test User",
    "user_phone": "01000000000",
    "user_email": "test@example.com",
    "address": {
        "address": "123 Street",
        "city": "Cairo",
        "country": "Egypt"
    },
    "governorate_id": 1
}
```

**Expected Response (422):**
```json
{
    "success": false,
    "message": "No fast shipping items in cart."
}
```

**Verify:**
- No order is created
- SCHEDULED items remain in cart

---

### Test 13: Pricing Isolation — Verify No Cross-Contamination

**Prerequisites:**
- Cart has exactly 1 SCHEDULED item (price: 252.50) AND 1 FAST item (price: 49.70)

**Step 1 — Get cart and verify individual prices:**
```
GET /api/v1/cart
```
Verify response shows:
- `total_price` = 302.20 (sum of all items)
- `normal_items[0].total_price` = 252.50
- `fast_items[0].total_price` = 49.70

**Step 2 — Trigger `calcInvoicePrice` for normal checkout:**
- Hit `POST /api/v1/general/checkout/invoice` (or trigger via checkout flow)
- Verify the invoice amount sent to MyFatoorah = **252.50** (SCHEDULED only)

**Step 3 — Verify cart `total_price` unchanged for FAST items:**
- `GET /api/v1/cart` → `total_price` should now be 49.70 (SCHEDULED items removed after callback, `total_price` recalculated to remaining FAST items)

**Step 4 — Do fast checkout on remaining FAST items:**
- `POST /api/v1/general/checkout/fast`
- Verify invoice amount = 49.70 + shipping fee

---

### Test 14: `OrderCreated` Broadcast Failure — Order Still Succeeds

**Prerequisites:**
- Pusher configuration is intentionally broken (wrong key/cluster)
- Cart has at least one SCHEDULED item

**Endpoint:** `POST /api/v1/general/checkout`

**Expected Response (200):**
```json
{
    "success": true,
    "message": "Checkout successful",
    "data": {
        "url": "https://myfatoorah.com/payment/..."
    }
}
```
Even though Pusher broadcast fails with `BroadcastException`, the order IS committed to the database and the API returns success.

**Verify:**
- Order exists in database with `status = 'pending'`
- `storage/logs/laravel.log` contains the Pusher error
- The admin notification was NOT sent (because broadcast failed)
- The order can still be completed via payment callback

---

## Summary

| Before Fix | After Fix |
|---|---|
| Normal checkout invoice = SCHEDULED + FAST total | Normal checkout invoice = SCHEDULED total ONLY |
| Fast checkout invoice = SCHEDULED + FAST total + fee | Fast checkout invoice = FAST total + fee ONLY |
| `PromotionService::applySelectedPromotion` reloads ALL items, destroying filter | Items filter is preserved across promotion application |
| `getCheckoutTotalsFromCart` uses `cart.total_price` (combined) | Uses `items->sum('total_price')` (filtered) |
| Broadcast crash → 500 error, order in DB but user sees failure | Broadcast crash → 200 success, error logged |
| Generic exceptions silently swallowed | `report($e)` called for all caught exceptions |
