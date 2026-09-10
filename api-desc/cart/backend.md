# Cart Module — Backend Architecture

## Overview

The Cart module manages authenticated user shopping carts with inventory reservation. A user has **exactly one cart** (`carts.user_id` UNIQUE). Items support two shipping methods (SCHEDULED / FAST), variant products, price snapshotting at reservation, coupon application, and promotion-based gift items. Inventory is reserved on add and released on delete/expiry/checkout.

## Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/cart` | `auth:sanctum` | List user cart (paginated) |
| POST | `/api/v1/cart` | `auth:sanctum` | Add item to cart |
| GET | `/api/v1/cart/{id}` | `auth:sanctum` | Show specific cart |
| POST | `/api/v1/cart/bulk-items` | `auth:sanctum` | Bulk add items (per-item error handling) |
| PUT | `/api/v1/cart/update-item` | `auth:sanctum` | Update item quantity (operation: increment/decrement) |
| DELETE | `/api/v1/cart/delete-item/{itemId}` | `auth:sanctum` | Remove single item |
| DELETE | `/api/v1/cart/delete-items` | `auth:sanctum` | Clear entire cart |

## Route Definitions

**File:** `packages/marvel/src/Rest/Routes.php` (lines 149-157)
**Prefix:** `api/v1` (registered in `RestAPIServiceProvider.php:29-31`)

```php
Route::middleware(['auth:sanctum', "throttle:cart"])->group(function () {
    Route::get('cart', [CartController::class, 'index']);
    Route::post('cart', [CartController::class, 'store']);
    Route::get('cart/{id}', [CartController::class, 'show'])->whereNumber('id');
    Route::post('cart/bulk-items', [CartController::class, 'pluckItemsToCart']);
    Route::put('cart/update-item', [CartController::class, 'update']);
    Route::delete('cart/delete-item/{itemId}', [CartController::class, 'deleteItemFromCart']);
    Route::delete('cart/delete-items', [CartController::class, 'destroy']);
});
```

## Middleware

| Middleware | Applied At | Purpose |
|------------|------------|---------|
| `auth:sanctum` | Route group | Require authentication |
| `throttle:cart` | Route group | Rate limit (20 req/min per user) |

**Rate Limiter Definition** (`app/Providers/RouteServiceProvider.php:111-113`):
```php
RateLimiter::for('cart', function (Request $request) {
    return Limit::perMinute(20)->by(optional($request->user())->id ?: $request->ip());
});
```

## Controller Flow

**File:** `packages/marvel/src/Http/Controllers/CartController.php`

**Constructor injection:**
- `CartRepository $repository`
- `CartInventoryService $inventoryService`

```
GET /cart
  → CartController::index(Request)
    → $limit = $request->limit ?? 15
    → Cart::where('user_id', auth()->id())  (via repository __call → Builder)
    → Eager load: items.product, items.productVariant.attributeProducts.attributeValue.attribute
    → Paginate($limit)
    → CartResource::collection() → flatten pagination meta (page, current_page, from, to, last_page, path, per_page, total, next/prev/last/first_page_url)
    → apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)

POST /cart
  → CartController::store(CartCreateRequest)
    → try: $this->repository->storeCart($request)   // persistCart('add')
           $this->repository->revalidatePromotion($cart)
           apiResponse(CREATE_CART_SUCCESSFULLY, 201, true, CartResource::make($cart))
    → catch: apiResponse($e->getMessage(), 400, false)

GET /cart/{id}
  → CartController::show(Request, $id)
    → findOrFail($id)  (404 on missing)
    → if $cart->user_id !== auth()->id() → throw AuthorizationException(NOT_AUTHORIZED)  // 403
    → CartResource::make($cart) → apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)

PUT /cart/update-item
  → CartController::update(CartUpdateRequest)
    → try: $this->repository->updateCart($request)   // persistCart('set')
           $this->repository->revalidatePromotion($cart)
           apiResponse(UPDATE_CART_SUCCESSFULLY, 200, true, CartResource::make($cart))
    → catch: apiResponse($e->getMessage(), 400, false)

DELETE /cart/delete-item/{itemId}
  → CartController::deleteItemFromCart(Request, $itemId)
    → $cart = auth()->user()->cart  (null → 400 DELETE_CART_ITEM_FAILED)
    → ownership check (mismatch → 400)
    → $item = $cart->items()->where('id', $itemId)->first()  (missing → 400)
    → $this->inventoryService->releaseItem($item, true)      (false → 400)
    → $this->repository->revalidatePromotion($cart)
    → $cart->update(['total_price' => round(sum(items.total_price), 2)])
    → apiResponse(DELETE_CART_ITEM_SUCCESSFULLY, 200, true)

DELETE /cart/delete-items
  → CartController::destroy(Request)
    → $cart = auth()->user()->cart  (null → 404 CART_NOT_FOUND)
    → ownership check (mismatch → 400 DELETE_CART_ITEM_FAILED)
    → if $cart->coupon && !$request->boolean('confirm'):
        apiResponse(COUPON_DELETE_CART_WARNING, 200, true)   // 200 + success:true, NOT 400
    → $this->inventoryService->releaseCart($cart, true)
    → apiResponse(DELETE_CART_SUCCESSFULLY, 200, true)

POST /cart/bulk-items
  → CartController::pluckItemsToCart(Request)
    → If request body is JSON with items (not form-encoded), merge into request
    → Inline $request->validate([...])  (422 on failure)
    → Normalize shipping_method to uppercase (default SCHEDULED)
    → $existingIds = Product::whereIn(id)->whereNull('deleted_at')->pluck('id')
    → Split items: validItems / skippedIds (non-existent or soft-deleted)
    → NO outer transaction. For each valid item:
        try { $tempRequest->replace(['item' => $item]); storeCart($tempRequest); }
        catch (\Exception $e) { $failedItems[] = [product_id, product_variant_id, reason]; }
    → $cart = Cart::where('user_id', userId)->first()
    → apiResponse(CREATE_CART_SUCCESSFULLY, 201, true, [
        'cart' => $cart ? CartResource::make($cart->load([...])) : null,
        'skipped_product_ids' => $skippedIds,
        'failed_items' => $failedItems,
      ])
```

## Repository

**File:** `packages/marvel/src/Database/Repositories/CartRepository.php`
**Extends:** `BaseRepository` (Prettus repository + caching)

| Method | Description |
|--------|-------------|
| `model()` | Returns `Cart::class` |
| `storeCart($request)` | Add mode — delegates to `persistCart($request, 'add')` |
| `updateCart($request)` | Set mode — delegates to `persistCart($request, 'set')` |
| `persistCart($request, $mode)` | Core logic: create/find cart, sync items, recalculate total |
| `syncItems($cart, $item, $mode)` | Validate product/variant/stock/FAST eligibility; route to increment/decrement |
| `revalidatePromotion($cart)` | Clear promotion_id/discount_amount on all items and reset total_price |

### `persistCart()` Flow
```
1. DB::beginTransaction()
2. $userId = $request->user()?->id  (null → AuthorizationException → 401 HttpException)
3. Cart::where('user_id', $userId)->lockForUpdate()->first()
4. Create cart if not exists (status = 'active')
5. Always reset status to 'active'
6. If $request->filled('item'): syncItems($cart, $item, $mode) — returns false → Exception(INVALID_ITEM_DATA)
7. $cart->update(['total_price' => $cart->items()->sum('total_price')])
8. DB::commit()
9. Return cart with eager loads (items.product, items.productVariant.attributeProducts.attributeValue.attribute)
10. On Exception → DB::rollBack(); throw HttpException(400, message)
```

### `syncItems()` Flow
```
1. Extract product_id, quantity, product_variant_id, attributes, shipping_method, operation
2. Normalize shipping_method to uppercase (default ShippingMethod::SCHEDULED)
3. Find existing non-gift item (product + variant + shipping_method)
4. If mode='set' and no shipping_method provided and existing item exists → preserve existing method
5. if (!$productId || $quantity < 1) → return false
6. Product::findOrFail($productId)
7. FAST check: if shipping == FAST && !$product->is_fast_shipping_available → Exception(FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE)
8. Variant lookup if variantId given (missing → Exception INVALID_ITEM_DATA)
9. If product_type == 'variable' && !variantId → Exception INVALID_ITEM_DATA
10. operation == INCREMENT → inventoryService->incrementItem(...)
    operation == DECREMENT → inventoryService->decrementItem(...)
```

## Inventory Service

**File:** `app/Services/General/CartInventoryService.php` (614 lines)

### Constants
```php
private const CART_TTL_DAYS = 3;
```

### Public Methods

| Method | Description |
|--------|-------------|
| `incrementItem($cart, $product, $variant, $qty, $attrs, $shipping)` | Transactional add — desired = existing + qty |
| `decrementItem($cart, $product, $variant, $qty, $shipping)` | Transactional decrement; deletes item at 0; clears coupon if last |
| `reserveItem($cart, $product, $variant, $qty, $mode, $attrs, $shipping)` | Reserve inventory, create/update cart item, snapshot price |
| `reserveGiftItem($cart, $product, $promotion, $qty, $variantId, $shipping)` | Reserve inventory for a free gift item (price = 0) |
| `releaseItem($item, $deleteItem)` | Release reserved inventory, optionally delete item |
| `releaseCart($cart, $deleteItems)` | Release all inventory in a cart |
| `finalizeCart($cart)` | Convert reserved inventory to sold (at checkout) |
| `finalizeItemsByShippingMethod($cart, $shippingMethod)` | Finalize one shipping group, **delete** the other group's items |
| `ensureCartReservation($cart)` | Sync reservation quantities to current item quantities |
| `getActiveCartForUser($user)` | Get active cart with eager loaded relations |
| `expireCarts()` | Chunked expiration of expired carts |
| `expireSingleCart($cart)` | Expire a single cart |
| `deductStockForOrder($order)` | Legacy order-based stock deduction (dual inventory path) |

### `incrementItem()` Detailed Flow
```
1. DB::transaction
2. lockForUpdate on cart row
3. findCartItemForLock(cart, product, variant, shipping) — existing non-gift item
4. desiredQuantity = existingQuantity + quantity; delta = desired - reserved
5. If delta > 0:
     lockForUpdate product/variant inventory row
     availableStock = max(0, stock_quantity - reserved_quantity)
     if availableStock < delta → Exception(VARIANT_STOCK_EXCEEDED / PRODUCT_STOCK_EXCEEDED)
6. reserveItem(cart, product, variant, quantity, 'add', attributes, shipping)
```

### `reserveItem()` Detailed Flow
```
1. DB::transaction; lockForUpdate on cart row
2. findCartItemForLock()
3. desiredQuantity = mode == 'set' ? quantity : (existing.quantity + quantity)
4. if desiredQuantity < 1 → Exception(QUANTITY_MINIMUM)
5. lockInventoryRow(product, variant); delta = desired - reserved
6. delta > 0 → reserveStock(stock, delta); delta < 0 → releaseStock(stock, abs(delta))
7. Snapshot price:
     variant  → ProductPricingService::calculateVariantCurrentPrice(product, variant)
     product  → ProductPricingService::calculateProductCurrentPrice(product)
8. Load variant attributeProducts if not loaded
9. Payload:
     product_id, product_variant_id, quantity = desired, reserved_quantity = desired,
     price, total_price = round(price * desired, 2),
     attributes = variant attributes (or provided), shipping_method,
     promotion_id = null, discount_amount = 0
10. Update existing item OR create new CartItem
11. touchCartReservation() → status=active, reserved_at=now, expires_at=now+3days
12. Return refreshed item
```

### `decrementItem()` Detailed Flow
```
1. DB::transaction; lockForUpdate on cart row
2. findCartItemForLock(); if !item → Exception(INVALID_ITEM_DATA)
3. targetQuantity = item.quantity - quantity
4. If targetQuantity >= 1 → reserveItem(mode='set', targetQuantity)
5. Else:
     lockInventoryRow; releaseStock(stock, reserved_quantity); item->delete()
     if no items remain → cart->update(['coupon' => null])
     touchCartReservation()
     return null
```

### `releaseItem()` / `releaseCart()` Flow
```
releaseItem($item, $deleteItem):
  1. lockForUpdate on cart item
  2. if reserved_quantity > 0 → lockInventoryRowByItem; releaseStock
  3. if deleteItem → delete item; if last → clear coupon; return deleted
  4. else → update reserved_quantity = 0

releaseCart($cart, $deleteItems):
  1. lockForUpdate on cart + with items
  2. releaseItem() each item
  3. cart->update(status='active', expires_at=null, reserved_at=null, total_price = deleteItems ? 0 : sum)
```

### Stock Helper Methods
| Method | Description |
|--------|-------------|
| `lockInventoryRow($product, $variant)` | Row-lock product or variant inventory row |
| `lockInventoryRowByItem($item)` | Row-lock inventory from a CartItem reference |
| `getAvailableStock($stock)` | `max(0, stock_quantity - reserved_quantity)` |
| `reserveStock($stock, $qty)` | Availability check, increment reserved_quantity, update in_stock |
| `releaseStock($stock, $qty)` | Decrement reserved_quantity (floor 0), update in_stock |
| `finalizeStock($stock, $qty)` | Verify reserved+physical stock, decrement both, increment sold |
| `findCartItemForLock($cart, $productId, $variantId, $shipping)` | Lock existing non-gift item match |
| `touchCartReservation($cart)` | status=active, reserved_at=now, expires_at=now+3days |
| `getVariantAttributes($variant)` | Map attributeProducts to key-value array |

## Transaction Concurrency Model

Every inventory mutation runs inside `DB::transaction()` with `lockForUpdate()`:

| Lock Target | Method | Reason |
|-------------|--------|--------|
| Cart row | `persistCart()`, `incrementItem()`, `decrementItem()`, `reserveItem()` | Serialize cart mutations |
| Cart item row | `findCartItemForLock()`, `releaseItem()` | Prevent race on same product+variant+shipping |
| Product / ProductVariant | `lockInventoryRow()` | Serialize stock mutations |

## Models

### Cart Model
**File:** `packages/marvel/src/Database/Models/Cart.php`
**Table:** `carts`

| Property | Details |
|----------|---------|
| Fillable | `user_id`, `coupon`, `total_price`, `status`, `reserved_at`, `expires_at` |
| Casts | `reserved_at` → datetime, `expires_at` → datetime |

**Relationships:** `user()` (BelongsTo), `items()` (HasMany), `scheduledItems()` (HasMany SCHEDULED), `fastItems()` (HasMany FAST).

### CartItem Model
**File:** `packages/marvel/src/Database/Models/CartItem.php`
**Table:** `cart_items`

| Property | Details |
|----------|---------|
| Fillable | `cart_id`, `product_id`, `quantity`, `product_variant_id`, `price`, `total_price`, `attributes`, `reserved_quantity`, `discount_amount`, `shipping_method`, `is_gift`, `promotion_id` |
| Casts | `attributes` → array, `is_gift` → boolean, `shipping_method` → string, `price`/`total_price`/`discount_amount` → float |

**Relationships:** `cart()` (BelongsTo), `product()` (BelongsTo **withTrashed**), `productVariant()` (BelongsTo), `promotion()` (BelongsTo).

## Resources

### CartResource
**File:** `packages/marvel/src/Http/Resources/CartResource.php`

Fields: `id`, `user_id`, `coupon` (CouponResource | null), `coupon_code`, `status`, `reserved_at`, `expires_at`, `total_items`, `total_quantity`, `total_price`, `subtotal`, `coupon_discount`, `total_after_coupon`, `normal_items_count`, `fast_items_count`, `normal_items`, `fast_items`, `has_eligible_promotion`.

Notes:
- Splits loaded items by `shipping_method` into `normal_items` (SCHEDULED) / `fast_items` (FAST).
- `coupon` object resolved via `Coupon::where('code', $this->coupon)->first()` + `CouponResource`.
- `coupon_discount` computed at serialization via `CouponCalculator::calculate()`.
- `has_eligible_promotion` computed via `PromotionService::hasEligiblePromotion()`.
- **Observation:** the resource executes business logic (coupon calculation, promotion eligibility). This is serialization-time work and issues additional queries per serialized cart.

### CartItemResource
**File:** `packages/marvel/src/Http/Resources/CartItemResource.php`

Fields: `id`, `product_id`, `product_variant_id`, `quantity`, `price` (round 2), `total_price` (round 2), `attributes`, `shipping_method`, `promotion_id`, `discount_amount` (round 2), `is_gift`, `product` → `{ id, name, slug, thumbnail } | null`.

Note: `thumbnail` uses `getFirstMediaUrl('products')`. The cart eager-load set does **not** include media, so this issues one query per product in the collection (minor N+1 on media).

## Request Validation

### CartCreateRequest
| Field | Rules |
|-------|-------|
| `item` | `required`, `array`, `min:1` |
| `item.product_id` | `required`, `integer`, `exists:products,id` |
| `item.quantity` | `required`, `integer`, `min:1` |
| `item.product_variant_id` | `sometimes`, `nullable`, `integer`, `exists:product_variants,id` |
| `item.attributes` | `sometimes`, `array` |
| `item.shipping_method` | `required`, `string`, `in:SCHEDULED,FAST,scheduled,fast` |

### CartUpdateRequest
| Field | Rules |
|-------|-------|
| `item` | `required`, `array`, `min:1` |
| `item.product_id` | `required_with:item`, `integer`, `exists:products,id` |
| `item.quantity` | `required_with:item`, `integer`, `min:1` |
| `item.product_variant_id` | `sometimes`, `nullable`, `integer`, `exists:product_variants,id` |
| `item.attributes` | `sometimes`, `array` |
| `item.shipping_method` | `sometimes`, `string`, `in:SCHEDULED,FAST,scheduled,fast` |
| `item.operation` | `required`, `string`, `in:increment,decrement` |

Both Form Requests override `failedValidation()` to return a plain 422 JSON error object.

## Inventory Lifecycle

```
ADD ITEM (POST /cart):
  persistCart('add') → syncItems → CartInventoryService::incrementItem → reserveItem
  (lock, delta-reserve, price snapshot, create/update item, touch TTL, revalidatePromotion)

UPDATE ITEM (PUT /cart/update-item):
  persistCart('set') → syncItems
    operation=increment → incrementItem
    operation=decrement → decrementItem (deletes item when < 1)

DELETE ITEM:
  releaseItem($item, true) → releaseStock + delete + clear coupon if last → recalc total → revalidatePromotion

CLEAR CART:
  coupon && !confirm → 200 COUPON_DELETE_CART_WARNING
  else releaseCart($cart, true) → release all + reset totals

EXPIRE (TTL = 3 days, scheduled every 5 min):
  carts:expire → expireCarts() chunkById(100) → expireCart()
  (lock, double-check expires_at not future, release stock, delete items, status=expired)

FINALIZE (at checkout, outside this module):
  finalizeCart() / finalizeItemsByShippingMethod()
```

## Shipping Method Handling

**Enum:** `Marvel\Enums\ShippingMethod` — `SCHEDULED` (default) / `FAST` (requires `product.is_fast_shipping_available`).
Normalized to uppercase in `syncItems()` and `pluckItemsToCart()`. Items split into `normal_items` / `fast_items` in CartResource.

## Coupon on Cart

- `carts.coupon` stores the coupon **code** string.
- Applied via `POST /api/v1/coupons/add-to-cart` (CouponController — outside this module).
- Cleared automatically when the last item is removed (`releaseItem`, `decrementItem`, `releaseCart`).
- `CartResource` computes `coupon_discount` at serialization via `CouponCalculator::calculate()`.

## Promotion on Cart

- `CartResource` calls `PromotionService::hasEligiblePromotion($cart)` for `has_eligible_promotion`.
- Cleared on every mutation by `revalidatePromotion()`:
  ```php
  items()->whereNotNull('promotion_id')->orWhere('discount_amount', '>', 0)
    ->update(['promotion_id' => null, 'discount_amount' => 0, 'total_price' => DB::raw('ROUND(price * quantity, 2)')]);
  ```

## Price Calculation

- Prices are **snapshotted at reservation time** via `ProductPricingService::calculateProductCurrentPrice()` / `calculateVariantCurrentPrice()`.
- Cart item `total_price = round(price * quantity, 2)`.
- Cart `total_price = sum(items.total_price)` (repository).
- `CartResource`: `subtotal = round(sum, 2)`, `total_after_coupon = max(0, subtotal - coupon_discount)`.

## Scheduled Commands

| Command | File | Registered in Kernel | Schedule |
|---------|------|----------------------|----------|
| `carts:expire` | `app/Console/Commands/ExpireCarts.php` | YES (`Kernel.php:17,23`) | everyFiveMinutes + withoutOverlapping |
| `cart:expire` | `app/Console/Commands/ExpireAbandonedCarts.php` | NO — orphan class | none |

## Database Schema

### Table: `carts`
| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `coupon` | string | NULLABLE |
| `user_id` | bigint unsigned | FK → users.id (nullOnDelete), **UNIQUE** |
| `total_price` | decimal(10,2) | DEFAULT 0 |
| `status` | enum(active, expired, checked_out) | DEFAULT 'active' |
| `reserved_at` | timestamp | NULLABLE |
| `expires_at` | timestamp | NULLABLE |
| `created_at` / `updated_at` | timestamp | NULLABLE |

Indexes: `unique('user_id')`, `(user_id, status)`, `(status, expires_at)`.

### Table: `cart_items`
| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK |
| `cart_id` | bigint unsigned | FK → carts.id **cascadeOnDelete** |
| `product_id` | bigint unsigned | FK → products.id (nullOnDelete) |
| `quantity` | integer | NOT NULL |
| `product_variant_id` | bigint unsigned | FK → product_variants.id (nullOnDelete), NULLABLE |
| `price` | decimal(10,2) | Snapshotted unit price |
| `total_price` | decimal(10,2) | `round(price * quantity, 2)` |
| `attributes` | json | NULLABLE |
| `reserved_quantity` | integer | DEFAULT 0 |
| `discount_amount` | decimal(10,2) | DEFAULT 0 |
| `shipping_method` | string(20) | DEFAULT 'scheduled' |
| `is_gift` | boolean | DEFAULT false |
| `promotion_id` | bigint unsigned | FK → promotions.id (nullOnDelete), NULLABLE |
| `created_at` / `updated_at` | timestamp | NULLABLE |

Indexes: `(cart_id, product_id, product_variant_id)`, `(cart_id, is_gift)`.

### Foreign Key Fix Migration
`2026_07_17_000001_fix_cart_foreign_key_cascades.php` changes `carts.user_id` and `cart_items.product_id` to `nullOnDelete()` (skips SQLite).

## User Relationship

**File:** `packages/marvel/src/Database/Models/User.php` (line 154-157)
```php
public function cart()
{
    return $this->hasOne(Cart::class);
}
```
One cart per user (enforced by UNIQUE `user_id`).

## Translation Keys Used

| Key | Context |
|-----|---------|
| `MESSAGE.FETCH_DATA_SUCCESSFULLY` | GET responses |
| `MESSAGE.CREATE_CART_SUCCESSFULLY` | POST + bulk-items response |
| `MESSAGE.UPDATE_CART_SUCCESSFULLY` | PUT response |
| `MESSAGE.DELETE_CART_SUCCESSFULLY` | DELETE /delete-items |
| `MESSAGE.DELETE_CART_ITEM_SUCCESSFULLY` | DELETE /delete-item |
| `ERROR.DELETE_CART_ITEM_FAILED` | delete failures |
| `ERROR.CART_NOT_FOUND` | cart missing (destroy) |
| `MESSAGE.COUPON_DELETE_CART_WARNING` | clear cart with coupon without confirm (HTTP 200) |
| `MESSAGE.FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE` | FAST on non-eligible product |
| `ERROR.INVALID_ITEM_DATA` | invalid variant / variable product missing variant |
| `cart.inventory.quantity_minimum` | desired quantity < 1 |
| `cart.inventory.quantity_exceeds_stock` | requested > available |
| `cart.inventory.reserved_stock_insufficient` | reserved stock < quantity (finalize) |
| `cart.inventory.physical_stock_insufficient` | physical stock < quantity (finalize) |
| `cart.inventory.gift_variant_not_available` / `gift_variant_no_stock` | gift variant resolution |

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Cart route definitions (lines 149-157) |
| `packages/marvel/src/Providers/RestAPIServiceProvider.php` | `api/v1` prefix registration |
| `packages/marvel/src/Http/Controllers/CartController.php` | Cart controller |
| `packages/marvel/src/Http/Requests/CartCreateRequest.php` | Create validation |
| `packages/marvel/src/Http/Requests/CartUpdateRequest.php` | Update validation |
| `packages/marvel/src/Http/Resources/CartResource.php` | Cart API resource |
| `packages/marvel/src/Http/Resources/CartItemResource.php` | Cart item API resource |
| `packages/marvel/src/Http/Resources/ProductVariantResource.php` | (related product variant serialization) |
| `app/Http/Resources/Coupons/CouponResource.php` | Coupon object in cart response |
| `packages/marvel/src/Database/Models/Cart.php` | Cart model |
| `packages/marvel/src/Database/Models/CartItem.php` | Cart item model |
| `packages/marvel/src/Database/Repositories/CartRepository.php` | Cart repository |
| `packages/marvel/src/Database/Repositories/BaseRepository.php` | Prettus base + caching |
| `app/Services/General/CartInventoryService.php` | Inventory management |
| `app/Services/General/PromotionService.php` | Promotion eligibility |
| `app/Services/Coupon/CouponCalculator.php` | Coupon discount calculation |
| `packages/marvel/src/Services/Pricing/ProductPricingService.php` | Price snapshotting (frozen ADR) |
| `packages/marvel/src/Enums/ShippingMethod.php` | Shipping method enum |
| `packages/marvel/src/Enums/CartOperation.php` | increment/decrement enum |
| `app/Providers/RouteServiceProvider.php` | Rate limiter config |
| `app/Console/Commands/ExpireCarts.php` | Cart expiration command (scheduled) |
| `app/Console/Commands/ExpireAbandonedCarts.php` | Duplicate/orphan command (not scheduled) |
| `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` | carts + cart_items schema |
| `packages/marvel/database/migrations/2026_07_17_000001_fix_cart_foreign_key_cascades.php` | FK fix migration |
| `tests/Feature/CartApiTest.php` | Feature tests (80 methods) |
| `tests/Feature/CartExpirationTest.php` | Expiration tests (8 methods) |

## Test Status

- `tests/Feature/CartApiTest.php` — 80 test methods; `tests/Feature/CartExpirationTest.php` — 8 test methods. Total 88.
- Last recorded state (2026-07-29): 61/65 passing with 4 pre-existing failures (gift promotion, finalization, resource structure).
- **Environment note (2026-08-04):** the full suite could not be executed in this session — every test errors during bootstrap with `Class "Role" not found` raised while loading `packages/marvel/src/Rest/Routes.php:699` (route registration). This is a global test-bootstrap/autoload issue, not a cart-specific defect. Results must be re-verified once the bootstrap is fixed.
