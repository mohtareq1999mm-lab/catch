# Request Flows — Cart Module (Authenticated API)

## Flow 1: Add Item to Cart — Success

```
Client → POST /api/v1/cart { item: { product_id: 10, quantity: 2, shipping_method: "SCHEDULED" } }
         ↓
    [auth:sanctum] → authenticate user
    [throttle:cart] → rate limit check
         ↓
    CartCreateRequest::rules() validation
         ↓
    CartController@store(Request)
         ↓
    CartRepository::storeCart($request)
         ↓
    DB::beginTransaction
         ↓
    Cart::query()->where('user_id', $userId)->lockForUpdate()->first()
         ├─ EXISTS? YES → use existing cart, set status=active
         └─ EXISTS? NO  → Cart::create(['user_id' => $userId, 'status' => 'active'])
         ↓
    syncItems($cart, $item, 'add')
         ↓
    Product::findOrFail(10)
         ↓
    Check: shipping_method=FAST && !product.is_fast_shipping_available? NO (SCHEDULED)
         ↓
    Check: variantId? NO → product.product_type === 'variable'? NO (simple)
         ↓
    Check: available_stock >= 2? YES
         ↓
    CartInventoryService::reserveItem($cart, $product, null, 2, 'add', [], 'SCHEDULED')
         ↓
    [nested DB::transaction]
         ↓
    Cart::whereKey($cart->id)->lockForUpdate()
    CartItem::findCartItemForLock($cart, 10, null, 'SCHEDULED') → null (new item)
         ↓
    Stock: Product::whereKey(10)->lockForUpdate()
           available = stock_quantity - reserved_quantity = 50 - 0 = 50
           delta = 2 - 0 = 2
           reserveStock: reserved_quantity = 2, in_stock = true
         ↓
    Price: ProductPricingService::calculateProductCurrentPrice($product) → 49.99
         ↓
    CartItem::create({
        product_id: 10, quantity: 2, reserved_quantity: 2,
        price: 49.99, total_price: 99.98,
        shipping_method: 'SCHEDULED', attributes: null
    })
         ↓
    touchCartReservation: expires_at = now + 3 days
         ↓
    Cart total_price = items()->sum('total_price') = 99.98
         ↓
    DB::commit
         ↓
    revalidatePromotion($cart) → no promotion items → return early
         ↓
    CartResource::make($cart->load(['items.product', ...]))
         ↓
    [CartResource::toArray]
         ↓
    Items split by shipping_method → normal_items (SCHEDULED), fast_items (FAST)
         ↓
    Coupon model = Coupon::where('code', $cart->coupon)->first()
         ├─ EXISTS? YES → CouponCalculator::calculate($couponModel, $subtotal)
         │                Returns: { discountAmount, finalPrice, discountType, freeShipping }
         │                → coupon_discount = discountAmount
         │                → total_after_coupon = subtotal - coupon_discount
         └─ EXISTS? NO  → coupon_discount = 0, total_after_coupon = subtotal
         ↓
    PromotionService::hasEligiblePromotion($cart) → has_eligible_promotion
         ↓
    Response fields:
      total_price = 99.98 (raw sum before coupon)
      subtotal = 99.98
      coupon_discount = 9.998 (10% of 99.98, or 0 if no coupon)
      total_after_coupon = 89.98 (floored at 0)
    Response: 201 { CartResource }
```

## Flow 2: Add Item — Stock Exceeded

```
Client → POST /api/v1/cart { item: { product_id: 10, quantity: 999, ... } }
         ↓
    ... (same as Flow 1 until inventory check)
         ↓
    CartInventoryService::reserveItem(...)
         ↓
    Stock: available = 50 < 999
         ↓
    throw Exception: "Stock exceeded for product 'T-Shirt'"
         ↓
    DB::rollBack
         ↓
    CartController::store → catch(Exception) → 400
         ↓
    Response: 400 { "message": "Stock exceeded...", "success": false }
```

## Flow 3: Update Item — Set Mode

```
Client → PUT /api/v1/cart/update-item { item: { product_id: 10, quantity: 1 } }
         ↓
    CartUpdateRequest validation (shipping_method is optional)
         ↓
    CartRepository::updateCart($request)
         ↓
    DB::beginTransaction
         ↓
    Get/create cart (same as Flow 1)
         ↓
    syncItems($cart, $item, 'set')
         ↓
    Mode 'set': shipping_method not in request
         → Find existing item with product_id=10, variant_id=null, is_gift=false
         → Use existing item's shipping_method ('SCHEDULED')
         ↓
    CartInventoryService::reserveItem($cart, ...)
         ↓
    findCartItemForLock → existing item with quantity=2, reserved_quantity=2
         ↓
    desiredQuantity = 1 (set mode = absolute)
    delta = 1 - 2 = -1 (release 1 unit)
         ↓
    releaseStock: reserved_quantity = 2 - 1 = 1
         ↓
    Item update: quantity=1, reserved_quantity=1, total_price=49.99
         ↓
    Cart total_price = 49.99
         ↓
    DB::commit
         ↓
    CartResource::make($cart->load(...))
         ↓
    [CartResource coupon calculation — same as Flow 1]
    Recalculates coupon_discount based on new subtotal
         ↓
    Response: 200 { CartResource }
```

## Flow 4: Delete Item — Release Inventory

```
Client → DELETE /api/v1/cart/delete-item/1
         ↓
    CartController@deleteItemFromCart(Request, 1)
         ↓
    Get user's cart → $user->cart
         ↓
    Cart exists? YES, user_id matches? YES
         ↓
    $cart->items()->where('id', 1)->first() → exists
         ↓
    CartInventoryService::releaseItem($item, deleteItem: true)
         ↓
    [transaction]
    CartItem::whereKey(1)->lockForUpdate()
    Product::whereKey(10)->lockForUpdate()
    releaseStock: reserved_quantity -= delta
    $item->delete()
    CartItem count for this cart = 0 → clear coupon (set cart.coupon = null)
         ↓
    revalidatePromotion($cart)
    Cart total_price = items sum
         ↓
    [No coupon → coupon_discount = 0, total_after_coupon = total_price]
         ↓
    Response: 200 { "message": "Cart item deleted successfully", "success": true }
```

## Flow 5: Clear Cart — With Coupon Warning

```
Client → DELETE /api/v1/cart/delete-items (no ?confirm)
         ↓
    CartController@destroy(Request)
         ↓
    Get user's cart → exists? YES
    user_id matches? YES
         ↓
    Cart has coupon? YES → $request->boolean('confirm') = false
         ↓
    Response: 200 {
      "message": "You have a coupon applied to your cart...",
      "success": true
    }
    (No inventory released — items remain reserved)
```

## Flow 6: Clear Cart — Confirmed

```
Client → DELETE /api/v1/cart/delete-items?confirm=true
         ↓
    Cart has coupon? YES → $request->boolean('confirm') = true
         ↓
    CartInventoryService::releaseCart($cart, deleteItems: true)
         ↓
    [transaction]
    Cart::lockForUpdate()->with('items')
    For each item: releaseStock, delete CartItem
    Cart update: status=active, expires_at=null, total_price=0
         ↓
    Response: 200 { "message": "Cart deleted successfully", "success": true }
```
