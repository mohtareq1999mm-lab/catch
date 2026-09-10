# Cart Two-Section Refactoring: Normal + Fast Shipping

## Overview

This refactoring enables a single cart to hold two independent item sections — **Normal (SCHEDULED)** and **Fast (FAST)** — each with its own checkout button. Both sections share the same `carts` table row (one cart per user), but items are split via the `cart_items.shipping_method` column.

---

## Architecture

```
Cart (one per user)
├── coupon (shared — consumed by first successful checkout)
├── normal_items (shipping_method = 'SCHEDULED')
│   └── Checkout via POST /general/checkout
└── fast_items (shipping_method = 'FAST')
    └── Checkout via POST /general/checkout/fast
```

**Key principle:** No new tables. The existing `cart_items.shipping_method` column (default `'SCHEDULED'`) is the sole discriminator.

---

## Files Changed

### 1. `packages/marvel/src/Database/Models/CartItem.php`

**Added cast:**
```php
protected $casts = [
    'attributes' => 'array',
    'is_gift' => 'boolean',
    'shipping_method' => 'string',  // NEW
];
```

**Why:** Ensures `shipping_method` is always returned as a string from the database.

---

### 2. `packages/marvel/src/Database/Models/Cart.php`

**Added scoped relationships:**
```php
public function scheduledItems()
{
    return $this->hasMany(CartItem::class)->where('shipping_method', 'SCHEDULED');
}

public function fastItems()
{
    return $this->hasMany(CartItem::class)->where('shipping_method', 'FAST');
}
```

**Why:** Allows convenient querying of items by shipping type when needed (e.g., for the cart badge showing total count, or admin panels).

---

### 3. `packages/marvel/src/Http/Resources/CartItemResource.php`

**Added field:**
```php
'shipping_method' => $this->shipping_method ?? 'SCHEDULED',
```

**Why:** The frontend needs to know each item's shipping method to render it in the correct section.

---

### 4. `packages/marvel/src/Http/Resources/CartResource.php`

**Restructured response — items split into sections:**
```php
$items = $this->whenLoaded('items');

if ($items) {
    $normalItems = $items->where('shipping_method', 'SCHEDULED')->values();
    $fastItems = $items->where('shipping_method', 'FAST')->values();
} else {
    $normalItems = collect();
    $fastItems = collect();
}
```

**New response shape:**
```json
{
  "id": 1,
  "user_id": 5,
  "coupon": "SUMMER20",
  "status": "active",
  "total_items": 4,
  "total_quantity": 6,
  "total_price": 450.00,
  "normal_items_count": 2,
  "fast_items_count": 2,
  "normal_items": [ /* SCHEDULED cart items */ ],
  "fast_items": [ /* FAST cart items */ ]
}
```

**Backward compatibility:** The `total_items`, `total_quantity`, and `total_price` still reflect the entire cart. New `normal_items_count`, `fast_items_count`, `normal_items`, and `fast_items` fields are added.

---

### 5. `packages/marvel/src/Http/Requests/CartCreateRequest.php`

**Added validation rule:**
```php
'item.shipping_method' => ['sometimes', 'string', 'in:SCHEDULED,FAST'],
```

**Why:** Allows the frontend to specify which section to add the item to when adding to cart.

---

### 6. `packages/marvel/src/Http/Requests/CartUpdateRequest.php`

**Same addition as CartCreateRequest:**
```php
'item.shipping_method' => ['sometimes', 'string', 'in:SCHEDULED,FAST'],
```

---

### 7. `packages/marvel/src/Database/Repositories/CartRepository.php`

**Two changes to `syncItems()`:**

1. Extracts `shipping_method` from the item payload (defaults to `'SCHEDULED'`):
   ```php
   $shippingMethod = $item['shipping_method'] ?? 'SCHEDULED';
   ```

2. Validates FAST eligibility:
   ```php
   if ($shippingMethod === 'FAST' && !$product->is_fast_shipping_available) {
       throw new Exception(FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE);
   }
   ```

3. Passes `$shippingMethod` to `reserveItem()`:
   ```php
   $inventoryService->reserveItem($cart, $product, $variant, $quantity, $mode, $attributes, $shippingMethod);
   ```

**Why:** Only products with `is_fast_shipping_available = true` can be added as FAST items. These checks happen at the add-to-cart level.

---

### 8. `app/Services/General/CartInventoryService.php`

#### `reserveItem()`

**New parameter:**
```php
public function reserveItem(
    Cart $cart,
    Product $product,
    ?ProductVariant $variant,
    int $quantity,
    string $mode = 'add',
    array $attributes = [],
    string $shippingMethod = 'SCHEDULED'  // NEW
): CartItem
```

**Payload includes new field:**
```php
$payload = [
    // ... existing fields ...
    'shipping_method' => $shippingMethod,  // NEW
];
```

#### New method: `finalizeItemsByShippingMethod()`

```php
public function finalizeItemsByShippingMethod(Cart $cart, string $shippingMethod): bool
```

**Logic:**
1. Locks the cart row.
2. Queries only cart items matching the given `$shippingMethod`.
3. For each matching item: deducts reserved stock from inventory (finalizeStock), then deletes the item.
4. Checks remaining items in cart:
   - If **zero items remain** → marks cart as `checked_out`.
   - If **items remain** → updates `total_price` to reflect remaining items only (cart stays `active`).

**Why:** After a successful checkout of one section, the other section's items must remain in the cart with their reservations intact. The cart should only be marked `checked_out` when all items are gone.

---

### 9. `app/Services/General/OrderService.php`

**`getCartUser()` now filters by SCHEDULED:**
```php
private function getCartUser()
{
    return Cart::query()
        ->where('user_id', auth()->id())
        ->where('status', 'active')
        ->with([
            'items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED),
            'items.product',
            'items.productVariant'
        ])
        ->first();
}
```

**Why:** The normal checkout (`POST /general/checkout`) should only process SCHEDULED items. This is the single gateway method used by `calcInvoicePrice()`, `addItemsInOrder()`, and `eligiblePromotionsForUser()` — filtering at this level propagates to all downstream logic.

**Added imports:**
```php
use Marvel\Database\Models\CartItem;
use Marvel\Enums\ShippingMethod;
```

---

### 10. `app/Services/General/FastShippingService.php`

**`createFastOrder()` now filters by FAST before processing:**
```php
$cart->load([
    'items' => fn($q) => $q->where('shipping_method', ShippingMethod::FAST),
    'items.product',
    'items.productVariant'
]);

if ($cart->items->isEmpty()) {
    throw new \InvalidArgumentException('No fast shipping items in cart.');
}
```

**Why:** The fast checkout (`POST /general/checkout/fast`) should only process FAST items. The `createOrderItems()` and `calculateCheckoutTotals()` methods receive a cart with only FAST items loaded.

---

### 11. `app/Http/Controllers/Api/General/OrderController.php`

**`checkoutCallback()` now finalizes by shipping method:**
```php
$order = $this->orderService->changeOrderStatus($invoiceId, 'completed');
if ($order) {
    if ($user = User::find($order->user_id)) {
        $cart = $this->cartInventoryService->getActiveCartForUser($user);
        if ($cart) {
            $shippingMethod = $order->shipping_method ?? ShippingMethod::SCHEDULED;
            $this->cartInventoryService->finalizeItemsByShippingMethod($cart, $shippingMethod);

            // Consume coupon on first successful checkout
            if ($order->coupon && $cart->fresh()->coupon === $order->coupon) {
                $cart->fresh()->update(['coupon' => null]);
            }
        }
    }
}
```

**How it determines the checkout type:**
- Fast orders are created with `'shipping_method' => ShippingMethod::FAST` (set in `FastShippingService::createFastOrder()`).
- Normal orders don't set `shipping_method`, so it's `null` → resolves to `ShippingMethod::SCHEDULED`.

**Coupon consumption:** After a successful checkout, if the order used a coupon and the cart still has that coupon, it is cleared — preventing the other section from also using it.

**Added import:**
```php
use Marvel\Enums\ShippingMethod;
```

---

### 12. `packages/marvel/config/constants.php`

No new constants added for this refactoring (existing `FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE` reused).

---

### 13. `resources/lang/en/message.php` & `resources/lang/ar/message.php`

No new translation keys added for this refactoring (existing `FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE` key reused).

---

## API Endpoint Changes

### Modified Responses

**GET /api/v1/cart** — Cart response now includes:
| Field | Type | Description |
|-------|------|-------------|
| `normal_items_count` | int | Count of SCHEDULED items |
| `fast_items_count` | int | Count of FAST items |
| `normal_items` | array | SCHEDULED cart items |
| `fast_items` | array | FAST cart items |
| `coupon` | string\|null | Shared coupon code |

Each cart item now includes `shipping_method` field.

### Modified Request Bodies

**POST /api/v1/cart** — Optional new field:
```json
{
  "item": {
    "product_id": 1,
    "quantity": 2,
    "shipping_method": "FAST"
  }
}
```

---

## Checkout Flow (Updated)

### Normal Checkout `POST /general/checkout`

1. `getActiveCartForUser()` loads cart with ALL items.
2. `ensureCartReservation()` reserves ALL items in cart.
3. `OrderService::getCartUser()` loads only SCHEDULED items.
4. `calcInvoicePrice()` calculates price based on SCHEDULED items.
5. Invoice created in MyFatoorah for SCHEDULED total.
6. `addItemsInOrder()` creates order with only SCHEDULED items.
7. User pays on MyFatoorah.
8. **Callback:** `finalizeItemsByShippingMethod($cart, 'SCHEDULED')` — deletes only SCHEDULED items, deducts stock. FAST items remain.
9. Coupon cleared from cart (if used).

### Fast Checkout `POST /general/checkout/fast`

1. `getActiveCartForUser()` loads cart with ALL items.
2. `ensureCartReservation()` reserves ALL items in cart.
3. `createFastOrder()` filters cart items to only FAST.
4. `calculateCheckoutTotals()` calculates price based on FAST items.
5. Invoice created in MyFatoorah for FAST total.
6. `createOrderItems()` creates order with only FAST items.
7. User pays on MyFatoorah.
8. **Callback:** `finalizeItemsByShippingMethod($cart, 'FAST')` — deletes only FAST items, deducts stock. SCHEDULED items remain.
9. Coupon cleared from cart (if used).

### Failure Case

If payment fails, `releaseCart($cart, false)` releases ALL reservations (both SCHEDULED and FAST). This is correct because `ensureCartReservation()` reserved everything together.

---

## Coupon Sharing Rules

- Coupon is stored on `carts.coupon` (shared).
- The **first successful checkout** (normal or fast) consumes the coupon:
  - `recordCouponUsage()` records usage against the user.
  - `cart.coupon` is set to `null`.
- The second section's checkout will see no coupon and proceed without discount.
- This prevents double-discount on a single shared coupon.

---

## Edge Cases

### Product in FAST section but `is_fast_shipping_available` changed to `false`
- The product stays in the FAST section but appears greyed/disabled on the frontend.
- The frontend should prevent the user from proceeding with fast checkout if any FAST item is ineligible.
- The move-item API (`PATCH .../shipping-method`) also validates eligibility when changing TO `FAST`.

### Cart badge count
- The frontend should sum `normal_items_count + fast_items_count` for the badge.

### Empty section checkout
- Normal checkout with 0 SCHEDULED items → `getCartUser()` returns `null` or cart with empty items → `CART_NOT_FOUND` or order creation fails gracefully.
- Fast checkout with 0 FAST items → explicit `'No fast shipping items in cart.'` error.

### Multiple checkout attempts
- After successful normal checkout, FAST items remain in the cart. The user can later do a fast checkout with those items.

---

## Verification Checklist

- [ ] CartItem has `shipping_method` cast
- [ ] Cart model has `scheduledItems()` / `fastItems()` relationships
- [ ] CartItemResource returns `shipping_method` field
- [ ] CartResource returns `normal_items`, `fast_items`, counts, and `coupon`
- [ ] CartCreateRequest accepts optional `item.shipping_method`
- [ ] CartRepository validates FAST eligibility and passes `shipping_method` to `reserveItem()`
- [ ] CartInventoryService `reserveItem()` stores `shipping_method` on cart items
- [ ] CartInventoryService has `finalizeItemsByShippingMethod()` for partial finalization
- [ ] OrderService `getCartUser()` filters to SCHEDULED items only
- [ ] FastShippingService `createFastOrder()` filters to FAST items only
- [ ] Checkout callback uses `$order->shipping_method` to determine finalization type
- [ ] Coupon cleared from cart after first successful checkout
- [ ] Language keys and constants added
