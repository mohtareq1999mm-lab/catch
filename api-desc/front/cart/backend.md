# Cart Module — Backend Architecture (Authenticated API)

## Endpoints

| Method | URL | Auth | Middleware | Purpose |
|--------|-----|------|-----------|---------|
| GET | `/api/v1/cart` | auth:sanctum | api, throttle:cart | List user carts (paginated) |
| POST | `/api/v1/cart` | auth:sanctum | api, throttle:cart | Add item to cart |
| GET | `/api/v1/cart/{id}` | auth:sanctum | api, throttle:cart | Show specific cart |
| POST | `/api/v1/cart/bulk-items` | auth:sanctum | api, throttle:cart | Bulk add items (all-or-nothing) |
| PUT | `/api/v1/cart/update-item` | auth:sanctum | api, throttle:cart | Update item quantity (set mode) |
| DELETE | `/api/v1/cart/delete-item/{itemId}` | auth:sanctum | api, throttle:cart | Remove single item |
| DELETE | `/api/v1/cart/delete-items` | auth:sanctum | api, throttle:cart | Clear entire cart |

## Route Definitions

**File:** `packages/marvel/src/Rest/Routes.php` (lines 838-846)

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

Loaded via `RestAPIServiceProvider` with `prefix('api/v1')` + `middleware('api')`.

## Request Flow

### Flow 1: List Carts
```
GET /api/v1/cart?limit=15&page=1
  → CartController@index
    → CartRepository::with(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute'])
    → where('user_id', $user->id)
    → paginate($limit)
    → CartResource::collection($carts)
    → Manual pagination extraction
    → Response: 200 { data, page, total, ... }
```

### Flow 2: Add Item
```
POST /api/v1/cart { item: { product_id, quantity, shipping_method, ... } }
  → CartCreateRequest validation
  → CartRepository::storeCart($request)
    → DB::transaction
      → Cart::lockForUpdate()->where('user_id', $userId)->first()
      → Create cart if not exists
      → syncItems($cart, $item, 'add')
        → Validate product, variant, stock
        → CartInventoryService::reserveItem()
          → lockForUpdate on product/variant inventory
          → Check available stock
          → Calculate price via ProductPricingService
          → Create/update CartItem with reserved_quantity
          → touchCartReservation (set expires_at = now + 3 days)
      → Update cart total_price
    → revalidatePromotion($cart)
    → CartResource::make($cart)
    → Response: 201
```

### Flow 3: Bulk Add Items
```
POST /api/v1/cart/bulk-items { items: [...] }
  → Inline validation
  → DB::transaction
    → Loop: repository->storeCart() for each item
    → (all succeed or all rollback)
  → CartResource::make($cart)
  → Response: 201
```

### Flow 4: Update Item
```
PUT /api/v1/cart/update-item { item: { product_id, quantity, ... } }
  → CartUpdateRequest validation (shipping_method optional in update)
  → CartRepository::updateCart($request)
    → persistCart($request, 'set')
      → Mode 'set': quantity replaces existing, shipping_method preserved if not sent
    → CartInventoryService::reserveItem(mode: 'set')
      → Calculates delta: desiredQuantity - reservedQuantity
      → Reserves or releases stock accordingly
  → Response: 200
```

### Flow 5: Delete Item
```
DELETE /api/v1/cart/delete-item/{itemId}
  → Authorization check (cart belongs to user)
  → CartInventoryService::releaseItem($item, deleteItem: true)
    → releaseStock (restore inventory)
    → delete CartItem
    → If no items remain, clear coupon
  → Recalculate total_price
  → Response: 200
```

### Flow 6: Clear Cart
```
DELETE /api/v1/cart/delete-items?confirm=false
  → If cart has coupon and !confirm: return 200 with warning message
  → CartInventoryService::releaseCart($cart, deleteItems: true)
    → Release all items' stock
    → Delete all CartItems
    → Reset cart: status=active, expires_at=null, total_price=0
  → Response: 200
```

## Key Classes

| Class | Key Methods | Responsibility |
|-------|-------------|----------------|
| `CartController` | index, store, show, update, deleteItemFromCart, destroy, pluckItemsToCart | HTTP entry points |
| `CartRepository` | storeCart, updateCart, revalidatePromotion, syncItems | Persistence logic |
| `CartInventoryService` | reserveItem, releaseItem, releaseCart, finalizeCart, expireCarts | Inventory management |
| `CartResource` | toArray | Response transformation with shipping split + coupon discount display |
| `CartItemResource` | toArray | Single cart item response |
| `ProductPricingService` | calculateProductCurrentPrice, calculateVariantCurrentPrice | Price with discounts/flash sales |
| `CouponCalculator` | calculate | Static coupon discount calculation (percentage/fixed_rate) |
| `CouponService` | addCouponToCart | Coupon validation + cart application orchestration |

## Model Schema

### `carts`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | Primary key |
| user_id | bigint UNSIGNED | FK to users |
| coupon | varchar(255), nullable | Applied coupon code |
| total_price | decimal, default 0 | Cart total |
| status | varchar(255) | active, checked_out, expired |
| reserved_at | timestamp, nullable | Last inventory reservation |
| expires_at | timestamp, nullable | Reservation expiry (3 days) |

### `cart_items`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | Primary key |
| cart_id | bigint UNSIGNED | FK to carts |
| product_id | bigint UNSIGNED | FK to products (withTrashed) |
| product_variant_id | bigint UNSIGNED, nullable | FK to product_variants |
| quantity | int | Desired quantity |
| reserved_quantity | int | Currently reserved in inventory |
| price | decimal | Unit price at time of add |
| total_price | decimal | price * quantity |
| discount_amount | decimal, default 0 | Promotion discount |
| promotion_id | bigint UNSIGNED, nullable | FK to promotions (gift items) |
| shipping_method | string | SCHEDULED or FAST |
| attributes | json, nullable | Variant attribute selections |
| is_gift | boolean | Gift item from promotion |

## Inventory Reservation

- Items are reserved immediately on add with `lockForUpdate()` pessimistic locking
- Reservation TTL: 3 days (configurable via `CartInventoryService::CART_TTL_DAYS`)
- Expired carts are released via `expireCarts()` (chunked batch job)
- On checkout: `finalizeCart()` decrements physical stock and deletes items
- On delete/clear: `releaseItem()` / `releaseCart()` restores available stock
