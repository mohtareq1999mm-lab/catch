# Request Flows — Cart Module

> Source of truth: `CartController.php`, `CartRepository.php`, `CartInventoryService.php` (verified against source on 2026-08-04, Revision 4).

---

## Flow 1: List Cart — GET /api/v1/cart

```
Client → GET /api/v1/cart?page=1&limit=15
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [throttle:cart] → 20 req/min per user (IP fallback)
         ↓
    CartController::index(Request)
         ↓
    $limit = $request->limit ?? 15   (NOTE: reads "limit", NOT "per_page")
         ↓
    repository->with(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute'])
         ↓
    Cart::where('user_id', $user->id)   (via Prettus __call → Builder)
         ↓
    paginate($limit)->withQueryString()
         ↓
    CartResource::collection($carts) → flatten pagination meta:
      (data, page, current_page, from, to, last_page, path, per_page, total,
       next_page_url, prev_page_url, last_page_url, first_page_url)
         ↓
    Return: { status:200, message:FETCH_DATA_SUCCESSFULLY, success:true, data:{...} }
```

**Notes:**
- Because `carts.user_id` is UNIQUE, this returns at most 1 cart.
- `cart` field of each resource resolves a Coupon via `Coupon::where('code', ...)`; `coupon_discount` computed via `CouponCalculator`; `has_eligible_promotion` via `PromotionService` — these run per serialized cart.

---

## Flow 2: Add Item to Cart — POST /api/v1/cart

```
Client → POST /api/v1/cart { "item": { "product_id": 10, "quantity": 2, "shipping_method": "SCHEDULED" } }
         ↓
    [auth:sanctum] + [throttle:cart]
         ↓
    CartCreateRequest → validation (422 on failure):
      - item: required|array|min:1
      - item.product_id: required|integer|exists:products,id
      - item.quantity: required|integer|min:1
      - item.product_variant_id: sometimes|nullable|integer|exists:product_variants,id
      - item.attributes: sometimes|array
      - item.shipping_method: required|string|in:SCHEDULED,FAST,scheduled,fast
         ↓
    CartController::store(CartCreateRequest)
         ↓
    CartRepository::storeCart($request) → persistCart($request, 'add')
         ↓
    DB::beginTransaction()
      ├─ $userId = $request->user()?->id          (null → AuthorizationException → 401)
      ├─ Cart::where('user_id', $userId)->lockForUpdate()->first()
      ├─ Create cart if not exists (status='active'); always reset status='active'
      ├─ if filled('item'): syncItems($cart, $item, 'add')
      │   ├─ product_id, quantity, variant_id, attributes, shipping_method, operation (default INCREMENT)
      │   ├─ normalize shipping_method → uppercase
      │   ├─ find existing non-gift item (product + variant [+ shipping])
      │   ├─ if !$productId || $quantity < 1 → return false → Exception(INVALID_ITEM_DATA)
      │   ├─ Product::findOrFail($productId)
      │   ├─ FAST check: shipping==FAST && !product.is_fast_shipping_available → 400 FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE
      │   ├─ variant lookup if variantId (missing → 400 INVALID_ITEM_DATA)
      │   ├─ variable product without variantId → 400 INVALID_ITEM_DATA
      │   └─ operation INCREMENT → CartInventoryService::incrementItem(...)
      │       ├─ DB::transaction; lockForUpdate cart row
      │       ├─ findCartItemForLock()
      │       ├─ desired = existing.quantity + quantity; delta = desired - reserved
      │       ├─ if delta > 0: lock inventory row; available = stock - reserved;
      │       │                 if available < delta → 400 VARIANT/PRODUCT_STOCK_EXCEEDED
      │       └─ reserveItem(...)
      │           ├─ lock cart row; lock inventory row
      │           ├─ delta = desired - reserved → reserveStock / releaseStock
      │           ├─ price = ProductPricingService::calculateProductCurrentPrice /
      │           │         calculateVariantCurrentPrice   (snapshot)
      │           ├─ payload: quantity=desired, reserved_quantity=desired, price,
      │           │   total_price=round(price*desired,2), attributes, shipping_method,
      │           │   promotion_id=null, discount_amount=0
      │           ├─ update existing item OR create CartItem
      │           └─ touchCartReservation() → status=active, expires_at=now+3days
      └─ $cart->update(['total_price' => $cart->items()->sum('total_price')])
         ↓
    DB::commit()
         ↓
    revalidatePromotion($cart)  (clear promotion_id/discount_amount, reset total_price)
         ↓
    CartResource::make($cart)
         ↓
    Return: { status:201, message:CREATE_CART_SUCCESSFULLY, success:true, data }
```

**Error paths:**
- `AuthorizationException` → HttpException **401**; other exceptions → HttpException **400** (both re-thrown from `persistCart`); controller catch → `apiResponse($e->getMessage(), 400, false)`.
- Note: the controller catches all exceptions and returns 400, so the repository's 401 is practically unreachable via the route (user is always authenticated by middleware).

---

## Flow 3: Show Cart — GET /api/v1/cart/{id}

```
Client → GET /api/v1/cart/1   (route has ->whereNumber('id'))
         ↓
    [auth:sanctum] + [throttle:cart]
         ↓
    CartController::show(Request, $id)
         ↓
    $cart = repository->with([...same eager loads...])->findOrFail($id)   (404 on missing)
         ↓
    if $cart->user_id !== auth()->id() → throw AuthorizationException(NOT_AUTHORIZED)   (403)
         ↓
    CartResource::make($cart)
         ↓
    Return: { status:200, message:FETCH_DATA_SUCCESSFULLY, success:true, data }
```

---

## Flow 4: Update Item Quantity — PUT /api/v1/cart/update-item

```
Client → PUT /api/v1/cart/update-item
         { "item": { "product_id": 10, "quantity": 1, "operation": "increment" } }
         ↓
    [auth:sanctum] + [throttle:cart]
         ↓
    CartUpdateRequest → validation (422 on failure) — additional rule:
      - item.operation: required|string|in:increment,decrement
         ↓
    CartController::update(CartUpdateRequest)
         ↓
    CartRepository::updateCart($request) → persistCart($request, 'set')
         ↓
    DB::beginTransaction()
      ├─ lockForUpdate on cart row (create if absent)
      ├─ syncItems($cart, $item, 'set')
      │   ├─ if mode='set' && shipping_method omitted && existing item exists → preserve existing method
      │   ├─ operation INCREMENT → incrementItem(...)   (desired = existing + qty)
      │   └─ operation DECREMENT → decrementItem(...)
      │       ├─ lock cart row; findCartItemForLock (missing → 400 INVALID_ITEM_DATA)
      │       ├─ targetQuantity = item.quantity - quantity
      │       ├─ targetQuantity >= 1 → reserveItem(mode='set', targetQuantity)   (release/re-reserve delta)
      │       └─ targetQuantity < 1 → releaseStock(all reserved), delete item,
      │                              if no items remain → cart.coupon = null, touchCartReservation
      └─ $cart->update(['total_price' => sum(items.total_price)])
         ↓
    DB::commit()
         ↓
    revalidatePromotion($cart)
         ↓
    Return: { status:200, message:UPDATE_CART_SUCCESSFULLY, success:true, data }
```

---

## Flow 5: Delete Single Item — DELETE /api/v1/cart/delete-item/{itemId}

```
Client → DELETE /api/v1/cart/delete-item/1
         ↓
    [auth:sanctum] + [throttle:cart]
         ↓
    CartController::deleteItemFromCart(Request, $itemId)
         ↓
    $cart = auth()->user()->cart        (null → 400 DELETE_CART_ITEM_FAILED)
         ↓
    ownership check                      (mismatch → 400)
         ↓
    $item = $cart->items()->where('id', $itemId)->first()   (missing → 400)
         ↓
    CartInventoryService::releaseItem($item, true)  (false → 400)
      ├─ DB::transaction; lockForUpdate on cart item
      ├─ if reserved_quantity > 0: lockInventoryRowByItem → releaseStock(reserved_quantity)
      ├─ delete item
      └─ if no items remain → cart.coupon = null
         ↓
    revalidatePromotion($cart)
         ↓
    $cart->update(['total_price' => round((float)$cart->items()->sum('total_price'), 2)])
         ↓
    Return: { status:200, message:DELETE_CART_ITEM_SUCCESSFULLY, success:true }
```

---

## Flow 6: Clear Entire Cart — DELETE /api/v1/cart/delete-items

```
Client → DELETE /api/v1/cart/delete-items   [optional body {"confirm": true}]
         ↓
    [auth:sanctum] + [throttle:cart]
         ↓
    CartController::destroy(Request)
         ↓
    $cart = auth()->user()->cart        (null → 404 CART_NOT_FOUND)
         ↓
    ownership check                      (mismatch → 400 DELETE_CART_ITEM_FAILED)
         ↓
    if $cart->coupon && !$request->boolean('confirm'):
        Return: { status:200, message:COUPON_DELETE_CART_WARNING, success:true }   ⚠️ 200 + success:true
         ↓
    CartInventoryService::releaseCart($cart, true)
      ├─ DB::transaction; lockForUpdate on cart + eager load items
      ├─ foreach item → releaseItem($item, true)
      │   ├─ lockForUpdate; releaseStock(reserved_quantity); delete item
      │   └─ if no items remain → coupon = null
      └─ cart->update(status='active', expires_at=null, reserved_at=null, total_price=0)
         ↓
    Return: { status:200, message:DELETE_CART_SUCCESSFULLY, success:true }
```

---

## Flow 7: Bulk Add Items — POST /api/v1/cart/bulk-items

```
Client → POST /api/v1/cart/bulk-items { "items": [{...}, {...}] }
         ↓
    [auth:sanctum] + [throttle:cart]
         ↓
    CartController::pluckItemsToCart(Request)
         ↓
    If request body is raw JSON with 'items' → merge into request
         ↓
    Inline validation (422 on failure):
      - items: required|array
      - items.*.product_id: required|integer
      - items.*.quantity: required|integer|min:1
      - items.*.product_variant_id: nullable|integer
      - items.*.shipping_method: nullable|string|in:scheduled,fast,SCHEDULED,FAST
         ↓
    Normalize shipping_method → uppercase (default SCHEDULED)
         ↓
    $existingIds = Product::whereIn(id, ...)->whereNull('deleted_at')->pluck('id')
         ↓
    Split: validItems (exist) / skippedIds (non-existent or soft-deleted)
         ↓
    NO outer transaction — for each valid item:
      try {
        $tempRequest->replace(['item' => $item]);
        CartRepository::storeCart($tempRequest)   // persistCart('add') — its own transaction
      } catch (\Exception $e) {
        $failedItems[] = { product_id, product_variant_id, reason: $e->getMessage() }
      }
         ↓
    $cart = Cart::where('user_id', $userId)->first()
         ↓
    Return: { status:201, message:CREATE_CART_SUCCESSFULLY, success:true,
              data: {
                cart: CartResource | null (null when ALL items failed),
                skipped_product_ids: [...],
                failed_items: [{product_id, product_variant_id, reason}]
              } }
```

---

## Flow 8: Cart Expiration (Scheduled Task) — `carts:expire`

```
Scheduler → php artisan carts:expire (every 5 min, withoutOverlapping)
         ↓
    ExpireCarts::handle() → CartInventoryService::expireCarts()
         ↓
    Cart::where('status','active')
        ->whereNotNull('expires_at')
        ->where('expires_at','<=', now())
        ->orderBy('id')
        ->chunkById(100, ...)          ⚠️ no lock on the chunk query (BUG-CART-006)
         ↓
    For each cart → expireCart($cart)
      ├─ DB::transaction; lockForUpdate on cart + items
      ├─ double-check: if expires_at->isFuture() → return (skip)
      │                  ⚠️ does NOT check status !== 'active' (BUG-CART-007)
      ├─ foreach item where reserved_quantity > 0:
      │     lockInventoryRowByItem → releaseStock(reserved_quantity)
      ├─ $cart->items()->delete()
      └─ $cart->update(status='expired', expires_at=null, reserved_at=null, total_price=0)
         ↓
    Return expired count
```

---

## Flow 9: Cart Finalization (at Checkout) — outside this module

```
Checkout → CartInventoryService::finalizeCart($cart)
         ↓
    DB::transaction; lockForUpdate on cart + eager load items
         ↓
    foreach item where reserved_quantity > 0:
      ├─ lockInventoryRowByItem
      ├─ finalizeStock(stock, reserved_quantity)
      │   ├─ reserved_quantity < quantity → Exception(RESERVED_STOCK_INSUFFICIENT)
      │   ├─ stock_quantity < quantity    → Exception(PHYSICAL_STOCK_INSUFFICIENT)
      │   ├─ stock_quantity -= quantity
      │   ├─ reserved_quantity -= quantity
      │   ├─ sold_quantity += quantity
      │   └─ update in_stock
      └─ $item->delete()
         ↓
    cart->update(status='checked_out', expires_at=null, reserved_at=null, total_price=0)

--- (variant) ---
    finalizeItemsByShippingMethod($cart, $method)
      ├─ lock cart; lock items of the given shipping method
      ├─ foreach → finalizeStock + delete item
      ├─ REMAINING items (other shipping group):
      │   foreach → releaseStock(reserved_quantity) AND $item->delete()   ⚠️ BUG-CART-002
      └─ cart->update(status='checked_out', ...)
```

---

## Concurrency Summary

Every mutation path serializes on these row locks:

| Lock target | Acquired by |
|-------------|-------------|
| `carts` row | `persistCart`, `incrementItem`, `decrementItem`, `reserveItem`, `releaseItem`, `releaseCart`, `expireCart`, `finalizeCart` |
| `cart_items` row | `findCartItemForLock`, `releaseItem` |
| `products` / `product_variants` row | `lockInventoryRow`, `lockInventoryRowByItem` |

Exception: `pluckItemsToCart` has **no outer transaction** — each item commit is independent (per-item try/catch).
