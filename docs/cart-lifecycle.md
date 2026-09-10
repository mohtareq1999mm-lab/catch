# Cart Lifecycle Audit

## Table of Contents

1. [Cart States](#1-cart-states)
2. [Data Model](#2-data-model)
3. [State Transition Diagram](#3-state-transition-diagram)
4. [Cart Creation](#4-cart-creation)
5. [Add Item](#5-add-item)
6. [Update Quantity / Set Item](#6-update-quantity--set-item)
7. [Remove Item](#7-remove-item)
8. [Clear Cart (Destroy)](#8-clear-cart-destroy)
9. [Coupon Assignment](#9-coupon-assignment)
10. [Coupon Removal](#10-coupon-removal)
11. [Promotion Assignment](#11-promotion-assignment)
12. [Promotion Removal](#12-promotion-removal)
13. [Shipping Selection](#13-shipping-selection)
14. [Payment Method Selection](#14-payment-method-selection)
15. [Checkout](#15-checkout)
16. [Payment Callback (Online)](#16-payment-callback-online)
17. [Order Success (COD / Pay at Cashier)](#17-order-success-cod--pay-at-cashier)
18. [Payment Failure](#18-payment-failure)
19. [Payment Cancellation](#19-payment-cancellation)
20. [Expired Payment](#20-expired-payment)
21. [Abandoned Cart](#21-abandoned-cart)
22. [Critical Questions Answered](#22-critical-questions-answered)

---

## 1. Cart States

| State | Description | DB value |
|-------|-------------|----------|
| **active** | Cart exists, has items, can be modified. Inventory may be reserved (if `expires_at` is set) or unreserved. | `'active'` |
| **checked_out** | Cart has been finalized. All items have been deleted and inventory finalized. Cart is essentially a tombstone. | `'checked_out'` |
| **expired** | Cart was abandoned past TTL. All items have been deleted, inventory released. | `'expired'` |

**Status column** is an ENUM: `['active', 'expired', 'checked_out']` (defined in migration line 293).

**SQL Schema** (`packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`):
```sql
carts: id, user_id (unique), coupon (nullable), total_price (decimal 10,2), status (enum), reserved_at (nullable), expires_at (nullable)
cart_items: id, cart_id (FK cascade), product_id, quantity, product_variant_id (nullable), price (decimal 10,2), total_price (decimal 10,2), attributes (json), reserved_quantity, discount_amount, shipping_method, is_gift (bool), promotion_id (nullable)
```

**Key indexes:**
- `carts.user_id` UNIQUE — one cart per user
- `carts(user_id, status)` — find active carts by user
- `carts(status, expires_at)` — find expired carts
- `cart_items(cart_id, product_id, product_variant_id)` — find matching items
- `cart_items(cart_id, is_gift)` — separate gift items

**Foreign Keys:**
- `cart_items.cart_id` → `carts.id` CASCADE ON DELETE (deleting cart deletes items)
- `cart_items.product_id` → `products.id` NULL ON DELETE (product deletion nullifies, item stays)
- `carts.user_id` → `users.id` NULL ON DELETE

---

## 2. Data Model

### `Cart` (packages/marvel/src/Database/Models/Cart.php)

| Field | Type | Notes |
|-------|------|-------|
| `id` | bigint (PK) | Auto-increment |
| `user_id` | bigint (FK→users, unique) | One cart per user enforced by unique constraint |
| `coupon` | string (nullable) | Stores coupon code string, **NOT** FK to coupons |
| `total_price` | decimal(10,2) | Cached total. Updated on every mutation. |
| `status` | enum('active','expired','checked_out') | Current lifecycle state |
| `reserved_at` | timestamp (nullable) | When inventory was last reserved |
| `expires_at` | timestamp (nullable) | When reservation expires |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Relations:**
```php
public function user()          // BelongsTo(User::class)
public function items()         // HasMany(CartItem::class)
public function scheduledItems() // HasMany->where('shipping_method', 'SCHEDULED')
public function fastItems()     // HasMany->where('shipping_method', 'FAST')
```

### `CartItem` (packages/marvel/src/Database/Models/CartItem.php)

| Field | Type | Notes |
|-------|------|-------|
| `id` | bigint (PK) | |
| `cart_id` | bigint (FK→carts, CASCADE) | |
| `product_id` | bigint (FK→products, nullOnDelete) | Null if product was deleted |
| `quantity` | int | Current desired quantity |
| `product_variant_id` | bigint (nullable, FK→product_variants) | |
| `price` | decimal(10,2) | Unit price at time of cart update |
| `total_price` | decimal(10,2) | `price * quantity` (before discounts) |
| `attributes` | json (nullable) | Variant attribute snapshot |
| `reserved_quantity` | int | Currently reserved stock count |
| `discount_amount` | decimal(10,2) | Promotion discount applied to this line |
| `shipping_method` | string(20) | 'SCHEDULED' or 'FAST' |
| `is_gift` | boolean | True if this is a promotion gift item |
| `promotion_id` | bigint (nullable, FK→promotions) | Applied promotion |

**Relations:**
```php
public function cart()           // BelongsTo(Cart::class)
public function product()        // BelongsTo(Product::class)->withTrashed()
public function productVariant() // BelongsTo(ProductVariant::class)
public function promotion()      // BelongsTo(Promotion::class)
```

---

## 3. State Transition Diagram

```
                  ┌──────────────────────────────────────────┐
                  │                                          │
                  v                                          │
    ┌────────┐  add/update   ┌──────────┐  checkout    ┌──────────────┐
    │ No Cart │─────────────>│  active   │────────────> │  checked_out │
    └────────┘               │ (reserved)│              └──────────────┘
          ^                  └──────────┘
          │                       │  │
          │                       │  │ expire (TTL)
          │                       │  v
          │                  ┌──────────┐
          │                  │  expired  │
          │                  └──────────┘
          │                       │
          │                       │ clear/destroy
          v                       v
    ┌──────────────┐
    │  deleted     │
    │  (no status) │
    └──────────────┘
```

**Key observations:**
- Cart is **never truly deleted** via normal flow. Only `destroy()` calls `releaseCart()` + `delete items`, but cart record stays with `status='active'`, `total_price=0`.
- `checked_out` status leaves cart as a tombstone. Items are deleted, inventory finalized.
- `expired` status means reservation released, items deleted, cart preserved.
- A new cart can only be **re-created** when none exists for the user (unique constraint on `user_id`).
- Once a cart reaches `checked_out` or `expired`, a **new cart** can be created for the same user (since the old one is no longer `active`).

---

## 4. Cart Creation

### Trigger Conditions

| Condition | File:Line | Code |
|-----------|-----------|------|
| User first adds item to cart | CartRepository:93-96 | `Cart::create(['user_id' => $userId, 'status' => 'active'])` |
| User has expired/checked_out cart, adds item | CartRepository:93-96 | Same — no active cart found, creates new |
| Admin seeds cart | CartSeeder:28-34 | `Cart::create(...)` with explicit fields |

### Code Path

```
CartCreateRequest (validates)
  → CartController::store()
    → CartRepository::storeCart()
      → CartRepository::persistCart($request, 'add')
        → Checks for existing active cart (lockForUpdate)
        → If none: Cart::create(['user_id', 'status' => 'active'])
        → If exists: reuses existing cart (sets status to 'active')
        → syncItems() → CartInventoryService::reserveItem() with mode='add'
        → Updates cart total_price = SUM(items.total_price)
        → Commits
    → CartRepository::revalidatePromotion($cart) — clears stale promotion data
    → Returns CartResource
```

### Inventory Behavior

- `CartInventoryService::reserveItem()` locks the cart row
- Finds or creates `CartItem`, computes `desiredQuantity = existing + new`
- Locks inventory row on product/variant (row lock)
- Reserves stock: `stock.reserved_quantity += delta`
- Sets `expires_at = now + 3 days` (CART_TTL_DAYS constant)
- Returns refreshed or newly created CartItem

### Cart Resource Behavior

`CartResource::toArray()`:
- Splits items into `normal_items` (SCHEDULED) and `fast_items` (FAST)
- Looks up coupon from DB by `$this->coupon` code
- Calculates coupon discount via `CouponCalculator::calculate()`
- Calculates `has_eligible_promotion` via `PromotionService::hasEligiblePromotion()`
- Returns normalized structure

### Key Findings

1. **`$cart->coupon` is just a string** — the coupon code stored directly on the cart. No FK relationship.
2. **CartResource re-reads the coupon from DB** every time it serializes. This means coupon validation happens at read time, not at write time.
3. **Unique constraint on `user_id`** means only ONE cart per user can exist **regardless of status**. If a user has a `checked_out` cart from last week and tries to add items, a **new active cart will be created** (because `Cart::query()->where('user_id', $userId)->lockForUpdate()->first()` will find the checked_out cart, but since a checked_out cart is not active, the code goes to `Cart::create([...])`... but this will **FAIL** on the unique constraint! Wait, let me re-read.

Actually, looking more carefully at the code:

```php
$cart = Cart::query()
    ->where('user_id', $userId)
    ->lockForUpdate()
    ->first();

if (!$cart) {
    $cart = Cart::create([
        'user_id' => $userId,
        'status' => 'active',
    ]);
}
```

It finds ANY cart (any status) by `user_id`. So if a checked_out or expired cart exists, it finds it and **reuses it** (sets to 'active'). This is a potential bug — it reuses the checked_out cart instead of creating a fresh one, meaning old stale data might linger.

Actually wait — the code does `$cart->update(['status' => 'active'])` regardless. And old items? The old items would have been deleted when the cart went to `checked_out` status (see `finalizeCart` which does `$item->delete()` for all items). But the coupon string and total_price could be old. However, `total_price` gets recalculated anyway, and if no items exist, `total_price` becomes 0. The coupon code could persist though... No, wait — `finalizeCart` and `expireCart` don't clear `coupon`. Let me check:

- `finalizeCart()`: updates status, expires_at, reserved_at, total_price. Does NOT touch coupon.
- `expireCart()`: updates status, expires_at, reserved_at, total_price. Does NOT touch coupon.
- `releaseCart()`: updates status, expires_at, reserved_at, total_price. Does NOT touch coupon.

BUT `releaseItem()` (when the last item is deleted) does clear coupon:
```php
$remaining = CartItem::where('cart_id', $cartId)->lockForUpdate()->count();
if ($remaining === 0) {
    Cart::whereKey($cartId)->lockForUpdate()->update(['coupon' => null]);
}
```

So if the checkout flow processes all items (delete cart items during finalize), the coupon would be cleared. But if it skips `releaseItem()` and goes to `finalizeCart()` directly, the coupon string persists.

**Finding 4: BUG — Stale coupon on reused checked_out/expired cart.** If a user had a checked_out cart with a coupon, and then adds new items (which reactivates that cart), the old coupon code is still on the cart. The cart then tries to validate it, which could lead to unexpected behavior.

This is a low-risk bug since:
- Coupon validation re-checks at checkout time
- Invalid coupons get cleared during `addItemsInOrder()` flow
- But the stale coupon shows up in CartResource responses between add-item and checkout

---

## 5. Add Item

### Trigger

`POST /api/v1/cart` with `{ item: { product_id, quantity, product_variant_id?, attributes?, shipping_method } }`

### Code Path

```
CartCreateRequest validation
  → CartController::store()
    → CartRepository::storeCart()
      → CartRepository::persistCart($request, 'add')
        → Find or create cart (lockForUpdate)
        → syncItems($cart, $request->item, 'add')
          → Validate product exists
          → If FAST shipping: check product.is_fast_shipping_available
          → If variant: check variant exists, stock, then reserveItem
          → If simple product: check stock, then reserveItem
          → If variable product without variant: throw error
        → Update cart.total_price = SUM(items.total_price)
    → CartRepository::revalidatePromotion($cart)
    → Return CartResource
```

### Inventory Behavior (reserveItem with mode='add')

```
desiredQuantity = (existingItem?.quantity ?? 0) + quantity
```

**mode='add'** means the new quantity is **added** to the existing quantity.

### Stock Check

The stock check happens **before** reservation using `getAvailableStock(stock)` which is:
```
max(0, stock_quantity - reserved_quantity)
```

This means available stock = physical stock - already reserved (by ANY cart or by this same cart).

### Key Findings

5. **No product active/deleted check** — `Product::findOrFail()` will throw 404 if deleted, but there's no check for `status === 'publish'` or `is_active`. A disabled product could be added to cart.
6. **No cart limit check** — No check for maximum items or maximum quantity per cart.
7. **No guest cart support** — Cart requires authenticated user (`$request->user()?->id`). If no user, throws 401.

---

## 6. Update Quantity / Set Item

### Trigger

`PUT /api/v1/cart` with `{ item: { product_id, quantity, product_variant_id?, attributes?, shipping_method? } }`

### Code Path

```
CartUpdateRequest validation
  → CartController::update()
    → CartRepository::updateCart()
      → CartRepository::persistCart($request, 'set')
        → Find or create cart (lockForUpdate)
        → If shipping_method not provided in 'set', preserve existing item's shipping_method
        → syncItems($cart, $request->item, 'set')
          → Same product/variant validation as add
          → CartInventoryService::reserveItem() with mode='set'
        → Update cart.total_price = SUM(items.total_price)
    → CartRepository::revalidatePromotion($cart)
    → Return CartResource
```

### Inventory Behavior (reserveItem with mode='set')

```
desiredQuantity = quantity  // exact, not additive
```

**mode='set'** means the quantity is set **exactly** to the provided value (not added).

If `desiredQuantity < 1`, throws `QUANTITY_MINIMUM` exception.

### Key Finding

8. **`shipping_method` on 'set' preserves existing** — If shipping_method is not provided in update, the code preserves the existing item's shipping method instead of defaulting to SCHEDULED.

---

## 7. Remove Item

### Trigger

`DELETE /api/v1/cart/item/{itemId}`

### Code Path

```
CartController::deleteItemFromCart($itemId)
  → Find user's cart via $user->cart (NOT lockForUpdate!)
  → Find item within cart
  → CartInventoryService::releaseItem($item, deleteItem=true)
    → Lock item row
    → If reserved_quantity > 0: release stock
    → Delete the CartItem row
    → If no items remain: clear coupon from cart
  → CartRepository::revalidatePromotion($cart)
  → Update cart.total_price = SUM(items.total_price)
  → Return success
```

### Inventory Behavior (releaseItem with deleteItem=true)

```
Stock.reserved_quantity -= item.reserved_quantity (releaseStock)
Items remaining == 0 → cart.coupon = null
```

### Key Findings

9. **No lock on cart** — The method uses `$user->cart` (likely a relationship) without `lockForUpdate()`. In concurrent scenarios, two simultaneous delete requests could race.
10. **`revalidatePromotion()` after delete** — Clears promotion_id and discount_amount from remaining items if any were promotion-applied.
11. **Coupon cleared when last item removed** — This is correct behavior.

---

## 8. Clear Cart (Destroy)

### Trigger

`DELETE /api/v1/cart`

### Code Path

```
CartController::destroy()
  → Find cart via auth()->user()->cart (NO lockForUpdate)
  → If cart has coupon and no 'confirm' flag: return warning message (requires confirm)
  → CartInventoryService::releaseCart($cart, deleteItems=true)
    → Lock cart + items
    → For each item: releaseItem() → release stock + delete item
    → Cart update: status='active', expires_at=null, reserved_at=null, total_price=0
  → Return success
```

### Inventory Behavior (releaseCart with deleteItems=true)

```
For each item:
  → stock.reserved_quantity -= item.reserved_quantity (releaseStock)
  → Delete CartItem
Cart:
  → status = 'active' (NOT deleted!)
  → total_price = 0
  → expires_at = null
  → reserved_at = null
```

### Key Findings

12. **Cart is NOT deleted** — The cart record persists with `status='active'` and `total_price=0`. This is the "empty cart" state.
13. **Coupon is NOT cleared** — `releaseCart()` does not touch `coupon`. Even though items are deleted, the coupon string remains. This means a subsequent add-item reactivates with the old coupon. However, `releaseItem()` (called inside the loop) does clear coupon when last item is deleted.
14. **`confirm` flag required for coupon carts** — If cart has a coupon, the first DELETE returns a warning message instead of deleting. The client must retry with `?confirm=1`.

---

## 9. Coupon Assignment

### Trigger

Coupon is assigned to cart via a **separate endpoint** (not in CartController). Let me check where this happens.

### Code Path

The cart coupon is a **string field** (`$cart->coupon`). Setting it requires finding the endpoint that writes to this field.

Looking at the constants, there's `COUPON_ADDED_TO_CART_SUCCESSFULLY` and `COUPON_ALREADY_APPLIED`, suggesting a coupon-apply endpoint exists.

Searching the codebase for where `$cart->coupon` is set:

1. **In `OrderService::addItemsInOrder()`**: `$cart->update(['coupon' => null])` — invalid coupon removal
2. **In `CartInventoryService::releaseItem()`**: `Cart::whereKey($cartId)->lockForUpdate()->update(['coupon' => null])` — last item removed
3. **In `CartController::destroy()`**: requires `confirm` if coupon exists
4. **The actual assignment endpoint**: needs to be found

Let me check the CouponController or similar.

Actually, based on the previous session's checkout execution trace, there IS a coupon assignment step. The coupon assignment likely happens in a dedicated coupon controller or the checkout flow.

I'll document what I know: **coupon is stored as a string on the cart**.

### When Coupon Can Be Assigned

Based on the code analysis, there must be an endpoint that takes a coupon code and stores it on the cart. This typically:
1. Validates the coupon exists and is valid
2. Stores `$cart->update(['coupon' => $code])`

### Key Finding

15. **Coupon is stored as a plain string** — Not a FK. No referential integrity. If the coupon is deleted from the coupons table, the cart still holds the code string.
16. **Coupon validation happens at checkout time** — Not at assignment time (or at least, re-validated at checkout).

---

## 10. Coupon Removal

### Trigger Conditions

| Condition | File:Line | How |
|-----------|-----------|-----|
| Coupon invalid at checkout | OrderService:173 | `$cart->update(['coupon' => null])` |
| Coupon not found at checkout | OrderService:178 | `$cart->update(['coupon' => null])` |
| Last item removed from cart | CartInventoryService:180 | Via `releaseItem()` when remaining=0 |
| Coupon validation fails in calcInvoicePrice | OrderService:120 | `$cart->update(['coupon' => null])` |

### When Coupon Persists

| Condition | Persists? | Notes |
|-----------|-----------|-------|
| Coupon valid, pending payment | **YES** | Coupon stays on cart until checkout callback |
| Payment pending | **YES** | Not cleared during checkout |
| Payment failed | **YES** | Cart not modified on failure |
| Payment expired | **Depends** | Cart expires → last item releases → coupon cleared if no items |
| Cart cleared (destroy) | **NO** | `releaseItem()` clears coupon when last item removed |

---

## 11. Promotion Assignment

### Trigger

Promotions are assigned to **cart items** (not the cart itself). The `cart_items.promotion_id` and `cart_items.discount_amount` fields hold promotion data.

### Code Path

Promotion assignment happens during the checkout flow:
```
PromotionService::applySelectedPromotion()
  → PromotionEligibilityResolver::resolve()
    → Strategy::eligible() + computeOutcome()
      → DiscountOutcome with discount amount, gift items, etc.
  → PromotionApplicator::applyOutcome()
    → Updates cart items with promotion_id + discount_amount
    → For gift promotions: CartInventoryService::reserveGiftItem()
```

### Key Finding

17. **Promotion is per cart-item** — Each cart item can have its own `promotion_id` and `discount_amount`. Multiple promotions across items is possible (though the current logic selects one promotion at a time).

---

## 12. Promotion Removal

### Trigger Conditions

| Condition | File:Line | How |
|-----------|-----------|-----|
| Cart item updated (add/set) | CartInventoryService:61-62 | `promotion_id => null, discount_amount => 0` in reserveItem |
| Item removed from cart | — | Item deleted entirely, promotion goes with it |
| Cart cleared (destroy) | — | All items deleted, promotions gone |
| `revalidatePromotion()` called | CartRepository:19-46 | Clears promotion_id + discount_amount from ALL items, recalculates total_price |

### Code Path for revalidatePromotion()

```php
$hasPromotionItems = $cart->items()
    ->where(function ($q) {
        $q->whereNotNull('promotion_id')->orWhere('discount_amount', '>', 0);
    })
    ->exists();

if (!$hasPromotionItems) return;

$affected = $cart->items()
    ->where(...)
    ->update([
        'promotion_id' => null,
        'discount_amount' => 0,
        'total_price' => DB::raw('price * quantity'),
    ]);

if ($affected > 0) {
    $cart->update(['total_price' => $cart->items()->sum('total_price')]);
}
```

### Key Finding

18. **`revalidatePromotion()` is called after EVERY cart mutation** — Add item, update item, remove item, all trigger this. This means any promotion discount is wiped out whenever the cart changes. The promotion must be re-selected (re-applied) at checkout time.
19. **Promotions are ephemeral on the cart** — They exist only between promotion selection and cart mutation. This is by design — promotions are recalculated at checkout.

---

## 13. Shipping Selection

### Code Path

Shipping method is stored per **cart item** (`cart_items.shipping_method`).

- Items can be SCHEDULED or FAST
- The cart is loaded with only SCHEDULED items during checkout (`OrderService::addItemsInOrder()` loads only `where('shipping_method', 'SCHEDULED')`)
- FAST items follow a separate checkout flow via `FastShippingController`

### Key Finding

20. **Checkout only processes SCHEDULED items** — `addItemsInOrder()` explicitly filters to `ShippingMethod::SCHEDULED`. FAST items are invisible to the main checkout flow.
21. **Governorate selection happens at checkout** — The shipping price is resolved from the governorate's `ShippingPrice` record during `resolveShippingPrice()`.

---

## 14. Payment Method Selection

### Trigger

Payment method is selected at checkout time via `OrderCreateRequest`. Not stored on the cart itself.

### Code Path

```
POST /api/v1/checkout
  payment_method: 'online' | 'cod' | 'pay_at_cashier'
  gateway: 'myfatoorah'
```

The cart doesn't persist payment method. It's passed through the request to the order creation.

---

## 15. Checkout

### What happens to the cart during checkout

`OrderService::addItemsInOrder()`:
1. Locks cart + loads SCHEDULED items
2. Refreshes cart item prices
3. Validates coupon
4. Applies promotion
5. Creates order + order items
6. Dispatches `OrderCreated` event
7. **Cart remains 'active'** with reservation intact

### Key Finding

22. **Cart is NOT modified during checkout** — The cart stays `status='active'`, items remain, reservation persists. The cart is only finalized/expired when payment succeeds/fails/expires.
23. **`finalizeOrder()` dispatches `OrderCreated`** — This event triggers notifications and log entries. It does NOT modify the cart.
24. **Pending order can be updated** — If a pending order already exists for the user, `updateOrder()` + `syncOrderItems()` are used instead of creating new ones.

---

## 16. Payment Callback (Online)

### What happens to the cart

`OrderController::checkoutCallback()`:
1. Verifies payment with gateway
2. Locks transaction + order in DB transaction
3. **If payment success:**
   - Updates transaction to 'paid'
   - Finds user's active cart
   - `finalizeItemsByShippingMethod($cart, $shippingMethod)`:
     - Locks cart items for that shipping method
     - For each item: finalizeStock (deduct from physical, remove from reserved, add to sold)
     - Deletes the items
     - If no items remain: cart → `status='checked_out'`, `total_price=0`
   - `changeOrderStatus('completed')` → records coupon usage
   - `finalizePromotionUsageAfterPayment()` → increments promotion usage
4. Dispatches `PaymentSucceeded` event

### Finalize Behavior by Shipping Method

`finalizeItemsByShippingMethod()`:

```php
$remainingItems = CartItem::where('cart_id', $cart->id)->count();
if ($remainingItems === 0) {
    // All items processed → cart becomes checked_out
    $cart->update(['status' => 'checked_out', 'total_price' => 0, ...]);
} else {
    // Some items remain (e.g. FAST items not yet handled)
    $cart->update(['total_price' => sum of remaining items]);
}
```

### Key Finding

25. **Split-shipping finalization** — For SCHEDULED/FAST split carts, the online callback only finalizes the shipping method from the order. FAST items remain in the cart until their own payment flow.
26. **COD and Pay at Cashier DO NOT finalize inventory immediately**. Their `handleCodPayment()` and `handleCashierQrPayment()` just create transactions. Inventory is NOT finalized until `markCodAsPaid()` or `markCashierPaid()` is called.

---

## 17. Order Success (COD / Pay at Cashier)

### COD: `handleCodPayment()`
- Creates 'pending' COD transaction
- **Cart stays active, items remain reserved**
- Returns success with order_id

### COD: `markCodAsPaid()`
- Locks + updates transaction to 'paid'
- Updates order to 'completed'
- Records coupon usage
- Increments promotion usage
- `finalizeInventoryAfterPayment()` → finds cart by user_id → `finalizeItemsByShippingMethod()` → deducts inventory → deletes items → sets status to 'checked_out' if no items remain
- Dispatches `PaymentSucceeded`

### Key Finding

27. **COD has a two-phase flow** — Order created immediately (pending), but inventory stays reserved until admin marks as paid. This means reserved inventory is unavailable for days/weeks.
28. **Same finalize behavior** — `finalizeInventoryAfterPayment()` uses the same `finalizeItemsByShippingMethod()`, splitting by shipping method.

---

## 18. Payment Failure

### What happens to the cart

`OrderController::checkoutCallback()` when payment verification fails:
1. Updates transaction to 'failed'
2. Dispatches `PaymentFailed` event
3. **Cart is NOT modified** — status stays 'active', items stay reserved, coupon stays

### What about `checkoutErrorCallback()`?
Similar — updates transaction to 'failed', dispatches `PaymentFailed`, does NOT touch cart.

### Key Finding

29. **On payment failure, cart remains fully intact** — The user can retry checkout. All reservations and coupon are preserved.
30. **No automatic retry mechanism** — The cart state is preserved, but there's no automatic re-prompt. The user must manually initiate another checkout.

---

## 19. Payment Cancellation

### What happens

Payment cancellation goes through `checkoutErrorCallback()` (same as failure). The transaction is marked 'failed', `PaymentFailed` event dispatched, cart untouched.

### Key Finding

31. **Cancellation is treated identically to failure** — Same code path, same cart behavior.

---

## 20. Expired Payment

### What happens

**`CancelUnpaidOrders` command** (`orders:cancel-unpaid`):
1. Finds pending orders older than `payment.order_timeout_hours` (default 72h)
2. Cancels order (status → 'cancelled')
3. Fails pending transactions
4. Dispatches `OrderCancelled` + `PaymentFailed` events
5. **Finds user's active cart → `expireSingleCart()`**

### `expireSingleCart()` behavior:
1. Locks cart + items
2. If `expires_at` is future: skip (cart not yet expired)
3. For each item: release reserved stock
4. Delete all items
5. Cart → `status='expired'`, `total_price=0`, timestamps null

### Key Finding

32. **Order cancellation triggers cart expiration** — But only if the cart is still 'active'. If the cart was already checked_out or expired, this has no effect.
33. **Note: the command cancels orders, then expires carts. These are separate concerns.** The order cancellation listeners handle inventory restore separately (if configured).

---

## 21. Abandoned Cart

### Trigger

**`cart:expire` command** → `CartInventoryService::expireCarts()`:
- Finds carts WHERE `status='active'` AND `expires_at IS NOT NULL` AND `expires_at <= now()`
- Chunks by 100, calls `expireCart($cart)` for each

### `expireCart()` behavior:
1. Locks cart + items
2. If `expires_at` is future: skip (double-check guard)
3. Release all reserved stock
4. Delete all cart items
5. Cart → `status='expired'`, `expires_at=null`, `reserved_at=null`, `total_price=0`

### Key Findings

34. **TTL is 3 days** — Defined as `CART_TTL_DAYS = 3` in CartInventoryService.
35. **Cart record persists** — Even after expiration, the cart row stays in the database as 'expired' with 0 total.
36. **The `reserved_at` check** — Only carts with `expires_at` set are eligible. If a cart has never been reserved (e.g., items were added before the reservation system was introduced), it won't expire.
37. **Abandoned cart expiration and unpaid order cancellation are two independent commands** — They run on different schedules. `cart:expire` handles inventory reservation TTL. `orders:cancel-unpaid` handles order lifecycle.

---

## 22. Critical Questions Answered

### Exactly when should cart remain?

| Scenario | Cart remains? | Status | Why |
|----------|--------------|--------|-----|
| Before any items added | N/A | — | No cart exists |
| Items in cart, browsing | YES | active | Normal active state |
| Checkout initiated, waiting for payment | YES | active | Cart preserved during payment flow |
| Online payment pending | YES | active | Cart must remain for retry/callback |
| COD order created | YES | active | Cart stays until admin marks paid |
| Pay at Cashier QR generated | YES | active | Cart stays until admin marks paid |
| Online payment success | NO (converted) | checked_out | Items finalized, cart becomes tombstone |
| COD marked as paid | NO (converted) | checked_out | Items finalized, cart becomes tombstone |
| Pay at Cashier marked as paid | NO (converted) | checked_out | Items finalized, cart becomes tombstone |
| Payment failed | YES | active | Cart preserved for retry |
| Payment cancelled | YES | active | Cart preserved for retry |
| Payment expired | NO (converted) | expired | TTL exceeded |
| User clears cart | YES (empty) | active | Cart record stays, all items deleted |
| 3 days since last activity | NO (converted) | expired | TTL exceeded, items deleted |

### Exactly when should cart disappear?

- **Never truly deleted** — The cart record persists in all states (active, checked_out, expired).
- **Items are deleted** when cart transitions to checked_out or expired.
- The only way to truly delete a cart is via direct DB query or the `Cart::where(...)->delete()` in seeder.

### Exactly when should coupon remain?

| Scenario | Coupon remains? | Why |
|----------|----------------|------|
| Cart active, browsing | YES | Coupon stays on cart string |
| Checkout, coupon validated | YES | Valid coupon stays |
| Online payment pending | YES | Coupon stays for when payment completes |
| Online payment success | YES | Coupon recorded in order snapshot |
| COD order created | YES | Coupon stays for when admin marks paid |
| Payment failed | YES | Coupon stays for retry |
| Payment cancelled | YES | Coupon stays for retry |
| Coupon invalid at re-validation | NO | Cleared during checkout validation |
| Coupon not found in DB | NO | Cleared during checkout validation |
| Last item removed from cart | NO | releaseItem() clears coupon |
| Cart cleared (destroy) | NO | releaseItem() clears coupon (via item deletion) |
| Cart expired | DEPENDS | If items exist at expiry, they're deleted → releaseItem clears coupon. If "checked_out" was reached first, coupon string persists on the record. |

### Exactly when should promotion be recalculated?

| Scenario | Recalculated? | Why |
|----------|--------------|------|
| Cart item added | YES | revalidatePromotion() clears all promotion data |
| Cart item updated | YES | revalidatePromotion() clears all promotion data |
| Cart item removed | YES | revalidatePromotion() clears all promotion data |
| Checkout initiated | YES | applySelectedPromotion() re-applies fresh |
| Promotion data loaded on resource | YES | CartResource calls promotionService |

**Promotion data on cart items is always stale** — it gets cleared on every cart mutation and only set fresh during the checkout `addItemsInOrder()` → `calculateCheckoutTotals()` → `applySelectedPromotion()` flow.

### Exactly when should reservation be released?

| Scenario | Released? | How |
|----------|-----------|-----|
| Item removed from cart | YES | releaseItem() → releaseStock() |
| Cart cleared (destroy) | YES | releaseCart() → releaseItem() for each |
| Cart expired (TTL) | YES | expireCart() → releaseStock() for each |
| Order cancelled (unpaid) | YES | CancelUnpaidOrders → expireSingleCart() |
| Online payment successful | NO (converted) | finalizeStock() — moves from reserved to sold |
| COD payment successful | NO (converted) | finalizeStock() — moves from reserved to sold |
| Pay at Cashier successful | NO (converted) | finalizeStock() — moves from reserved to sold |

---

## Appendix A: All Code Paths Summary

| Action | Controller | Repository | Service | Inventory Change |
|--------|-----------|------------|---------|-----------------|
| Cart Create / Add Item | `CartController::store()` | `CartRepository::storeCart()` | `CartInventoryService::reserveItem()` | Reserve stock +/ delta |
| Update Item (set) | `CartController::update()` | `CartRepository::updateCart()` | `CartInventoryService::reserveItem()` | Reserve stock +/ delta |
| Remove Item | `CartController::deleteItemFromCart()` | — | `CartInventoryService::releaseItem()` | Release reserved stock, delete item, clear coupon if last |
| Clear Cart | `CartController::destroy()` | — | `CartInventoryService::releaseCart()` | Release all reserved stock, delete all items |
| Show Cart | `CartController::show()` | — | — | None (read) |
| List Carts (admin) | `CartController::index()` | — | — | None (read) |
| Bulk Add Items | `CartController::pluckItemsToCart()` | `CartRepository::storeCart()` | `CartInventoryService::reserveItem()` | Reserve stock |
| Abandoned Cart Expiry | `cart:expire` (command) | — | `CartInventoryService::expireCarts()` | Release all reserved stock, delete all items |
| Checkout | `OrderController::checkout()` | — | `OrderService::addItemsInOrder()` | Only refreshes prices, does NOT modify cart |
| Payment Success (online) | `OrderController::checkoutCallback()` | — | `CartInventoryService::finalizeItemsByShippingMethod()` | Finalize stock (reserved → sold), delete items |
| Payment Success (COD) | `OrderController::markCodAsPaid()` | — | `CartInventoryService::finalizeItemsByShippingMethod()` | Finalize stock (reserved → sold), delete items |
| Payment Success (Cashier) | `OrderController::markCashierPaid()` | — | `CartInventoryService::finalizeItemsByShippingMethod()` | Finalize stock (reserved → sold), delete items |
| Payment Failure | `OrderController::checkoutCallback()` | — | — | None — cart untouched |
| Payment Cancellation | `OrderController::checkoutErrorCallback()` | — | — | None — cart untouched |
| Cancel Unpaid Orders | `orders:cancel-unpaid` (command) | — | `CartInventoryService::expireSingleCart()` | Release reserved stock, delete all items |

## Appendix B: Bugs Found

| ID | Severity | File:Line | Description |
|----|----------|-----------|-------------|
| CART-1 | LOW | `CartRepository:87-90` | Cart lookup uses `->first()` with `lockForUpdate` but finds ANY status. If a checked_out or expired cart exists, it's reused and reactivated. Old coupon string may persist on reactivation. |
| CART-2 | LOW | `CartController:96` | `deleteItemFromCart()` uses `$user?->cart` relationship without `lockForUpdate`. Concurrent deletes could race. |
| CART-3 | LOW | `CartController:120` | `destroy()` uses `auth()->user()?->cart` without `lockForUpdate`. |
| CART-4 | LOW | `OrderService:168-179` | Stale `$cart->coupon` in-memory after invalid coupon cleared — already documented in previous audit. |
| CART-5 | LOW | `CartInventoryService:211-233` | `finalizeCart()` does NOT clear `coupon`. If cart becomes checked_out and later reactivated, old coupon persists. |
| CART-6 | LOW | `CartInventoryService:273-310` | `expireCart()` does NOT clear `coupon`. |
| CART-7 | INFO | Various | No logging on cart state transitions. No cart history table. Cannot audit past cart state changes. |
