# Checkout Flow

## Endpoint

```
POST /api/v1/checkout
Content-Type: application/json
Authorization: Bearer <token>
```

## Request Body

```json
{
  "name": "Customer Name",
  "user_phone": "+201234567890",
  "user_email": "customer@example.com",
  "address": { ... },
  "notes": "optional",
  "selected_promotion_id": 1,
  "selected_gift_product_id": null,
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "governorate_id": 1,
  "pickup_location_id": null,
  "type": "web"
}
```

## Full Execution Trace

### Step 1: Route + Middleware

```
routes/api.php:75
  Route::post('checkout', [OrderController::class, 'checkout'])->middleware('auth:sanctum');
```

**Middleware stack:**
1. `auth:sanctum` — Authenticates user via personal access token
2. `throttle:cart` — Rate limiter (defined in RouteServiceProvider)

---

### Step 2: OrderCreateRequest Validation

```
OrderController::checkout()
  → $request->validated() triggers OrderCreateRequest validation
```

**Validation rules:**

| Field | Rules |
|-------|-------|
| `name` | required, string, max:255 |
| `user_phone` | required, string, max:255 |
| `user_email` | required, email, max:255 |
| `address` | required, array |
| `notes` | nullable, string |
| `selected_promotion_id` | nullable, integer, exists:promotions,id |
| `selected_gift_product_id` | nullable, integer, exists:products,id |
| `type` | nullable, in:mobile,web |
| `fulfillment_type` | nullable, string, in:delivery,pickup (or pickup-only if pay_at_cashier) |
| `payment_method` | nullable, string, in:online,cod,pay_at_cashier |
| `gateway` | nullable, string, max:50 |
| `governorate_id` | required_if:fulfillment_type=delivery, integer, exists:governorates,id |
| `pickup_location_id` | required_if:fulfillment_type=pickup, nullable, integer, exists:pickup_locations,id |

---

### Step 3: Controller — Get Active Cart

```
File: app/Http/Controllers/Api/General/OrderController.php:71

$cart = $this->cartInventoryService->getActiveCartForUser($request->user());
```

**`getActiveCartForUser()` (CartInventoryService:307):**
```php
return Cart::query()
    ->where('user_id', $user->id)
    ->where('status', 'active')
    ->with([
        'items.product.flash_sales' => fn($q) => $q->valid(),
        'items.productVariant.attributeProducts.attributeValue.attribute',
    ])
    ->first();
```

**NOTABLE:** No `lockForUpdate` at this point. The cart is loaded for read.

**If cart not found:**
```php
if (!$cart) {
    return $this->apiResponse(CART_NOT_FOUND, 400, false);
}
```

---

### Step 4: Ensure Cart Reservation

```
File: OrderController:77

$this->cartInventoryService->ensureCartReservation($cart);
```

**`ensureCartReservation()` (CartInventoryService:291):**
```php
DB::transaction(function () use ($cart) {
    $cart = Cart::whereKey($cart->id)
        ->lockForUpdate()
        ->with(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute'])
        ->firstOrFail();
    
    foreach ($cart->items as $item) {
        $this->syncCartItemReservation($item);
    }
    
    $this->touchCartReservation($cart);
    
    return $cart->refresh();
});
```

**`syncCartItemReservation()`:**
- Locks the cart item row
- Locks the inventory row (product or variant)
- Calculates `delta = desiredQuantity - reservedQuantity`
- If delta > 0: reserves more stock (throws if insufficient)
- If delta < 0: releases excess stock
- Updates `reserved_quantity` on the item

**`touchCartReservation()`:**
- Sets `reserved_at = now()`
- Sets `expires_at = now() + 3 days`
- Sets `status = 'active'`

---

### Step 5: Payment Method Validation

```
File: OrderController:82-93

$paymentMethod = $request->input('payment_method', 'online');
$gateway = $request->input('gateway', config('payment.default_gateway', 'myfatoorah'));
$fulfillmentType = $request->input('fulfillment_type', 'delivery');

if ($paymentMethod === 'cod' && $fulfillmentType === 'pickup') {
    return $this->apiResponse(COD_NOT_AVAILABLE_FOR_PICKUP, 422, false);
}
```

**Request merge:**
```php
$request->merge([
    'fulfillment_type' => $fulfillmentType,
    'payment_method' => $paymentMethod,
    'payment_gateway' => $paymentMethod === 'online' ? $gateway : null,
]);
```

---

### Step 6: Add Items In Order (Main Transaction)

```
File: OrderController:97

$order = $this->orderService->addItemsInOrder($request);
```

This is wrapped in a try-catch for `InvalidArgumentException` (422) and general exceptions (500).

#### 6a: Lock Cart + Load Items

```
OrderService::addItemsInOrder() (OrderService:148)
```

```php
DB::beginTransaction();

$cart = Cart::query()
    ->where('user_id', auth()->id())
    ->where('status', 'active')
    ->lockForUpdate()
    ->with([
        'items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED),
        'items.product.flash_sales' => fn($q) => $q->valid(),
        'items.productVariant',
    ])
    ->first();
```

**CRITICAL:** Only SCHEDULED items are loaded. FAST items are invisible to this checkout flow.

**If no cart or empty items:**
```php
if (!$cart || $cart->items->isEmpty()) {
    DB::rollBack();
    return null;
}
```

#### 6b: Refresh Cart Item Prices

```
OrderService::refreshCartItemPrices($cart) (OrderService:405)
```

```php
foreach ($cart->items as $item) {
    if ($item->is_gift) continue;
    
    $currentPrice = $item->productVariant
        ? $pricingService->calculateVariantCurrentPrice($product, $item->productVariant)
        : $pricingService->calculateProductCurrentPrice($product);
    
    if ($currentPrice !== null && (float)$currentPrice !== (float)$item->price) {
        $item->forceFill([
            'price' => $currentPrice,
            'total_price' => round($currentPrice * $quantity, 2),
        ])->save();
    }
}

$cart->refresh();
$cart->load(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED), ...]);
```

**Key behavior:** Only updates if price has changed. `$cart->refresh()` reloads from DB. Then a fresh load of SCHEDULED items.

#### 6c: Coupon Validation

```
OrderService:167-179
```

```php
$freeShippingCoupon = false;
if ($cart->coupon) {
    $lockedCoupon = Coupon::where('code', $cart->coupon)->lockForUpdate()->first();
    if ($lockedCoupon) {
        $validation = CouponOrchestrator::validate($lockedCoupon, $request->user(), $cart->items);
        if (!$validation['valid']) {
            $cart->update(['coupon' => null]);
        } elseif ($lockedCoupon->discount_type === DiscountType::FREE_SHIPPING) {
            $freeShippingCoupon = true;
        }
    } else {
        $cart->update(['coupon' => null]);
    }
}
```

**BUG CPN-1:** After `$cart->update(['coupon' => null])`, the in-memory `$cart->coupon` still holds the old value. `$cart->refresh()` is missing.

#### 6d: Determine Selected Promotion

```
OrderService:182-188
```

```php
$selectedPromotionId = $cart->items
    ->firstWhere(fn($item) => !is_null($item->promotion_id))
    ?->promotion_id;

$selectedGiftProductId = $cart->items
    ->firstWhere('is_gift', true)
    ?->product_id;
```

Reads promotion_id and gift product from cart items. These were set by the frontend's promotion selection before checkout.

#### 6e: Calculate Checkout Totals

```
OrderService:190-195
```

```php
$checkoutTotals = $this->calculateCheckoutTotals(
    $cart,
    $selectedPromotionId ? (int) $selectedPromotionId : null,
    $selectedGiftProductId ? (int) $selectedGiftProductId : null,
    ShippingMethod::SCHEDULED,
);
```

**`calculateCheckoutTotals()` (OrderService:436):**

```php
// Step 1: Apply promotion
$promotionTotals = $this->promotionService->applySelectedPromotion(
    $cart, $selectedPromotionId, $selectedGiftProductId, $shippingMethod
);
$priceAfterPromotion = $promotionTotals->finalTotal;

// Step 2: Apply coupon on top of promotion result
$couponResult = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
$finalTotal = round(max(0, (float) $couponResult['finalPrice']), 2);

// Step 3: Look up coupon metadata
$coupon = null;
$couponDiscountMaxAmount = null;
if ($cart->coupon) {
    $couponModel = Coupon::valid()->where('code', $cart->coupon)->first();
    if ($couponModel) {
        $coupon = $couponModel->code;
        $couponDiscountMaxAmount = $couponModel->max_discount_amount;
    }
}

// Step 4: Build CheckoutTotals DTO
return new CheckoutTotals(
    subtotal: $promotionTotals->subtotal,
    promotionDiscount: $promotionTotals->promotionDiscount,
    couponDiscount: round(max(0, $priceAfterPromotion - $finalTotal), 2),
    finalTotal: $finalTotal,
    promotion: $promotionTotals->promotion,
    giftItems: $promotionTotals->giftItems,
    coupon: $coupon,
    couponDiscountType: $couponResult['discountType'],
    couponDiscountMaxAmount: $couponDiscountMaxAmount,
);
```

**BUG CPN-1 (continued):** `calculatePriceByCoupon()` reads `$cart->coupon` which may be stale if the coupon was just invalidated in step 6c:

```php
private function calculatePriceByCoupon($cart, $totalPrice): array
{
    if ($cart->coupon === null) {  // ← reads stale in-memory value!
        return ['finalPrice' => $totalPrice, ...];
    }
    $coupon = Coupon::valid()->where('code', $cart->coupon)->first();
    // ...
}
```

**Promotion Application (inside `applySelectedPromotion()`):**

1. `removeGiftItems($cart)` — releases inventory and deletes existing gift items
2. Locks promotion row (`lockForUpdate`)
3. `PromotionEligibilityResolver::resolve()` — eligibility check + outcome computation (read-only)
4. `PromotionApplicator::applyOutcome()`:
   - Locks cart + items
   - Re-evaluates matched eligibility
   - For discount: proportional allocation using largest remainder
   - For gift: `reserveGiftItem()` (inventory reservation)
5. Returns `CheckoutTotals` with recalculated values

#### 6f: Minimum Order Check

```
OrderService:197-203
```

```php
$minimumOrderAmount = (float) (Settings::first()?->minimum_order_amount ?? 0);
if ($minimumOrderAmount > 0 && $checkoutTotals->subtotal < $minimumOrderAmount) {
    DB::rollBack();
    throw new InvalidArgumentException(__('Minimum order amount is :amount', ...));
}
```

**Note:** Uses `subtotal` (BEFORE discounts), not `finalTotal`. This is correct — minimum order should be based on pre-discount amount.

#### 6g: Shipping Resolution

```
OrderService:210-215
```

```php
$shippingInfo = $this->resolveShippingPrice((int) ($orderData['governorate_id'] ?? null));
$shippingPrice = $this->resolveFreeShippingByThreshold(
    $checkoutTotals->subtotal,
    $shippingInfo['free_shipping_over'],
    $shippingInfo['price']
);
if ($freeShippingCoupon) {
    $shippingPrice = 0;
}
```

**`resolveShippingPrice()`:**
1. Look up governorate by ID (where status = true)
2. Find active shipping price for governorate
3. Return `['price' => price, 'free_shipping_over' => threshold, 'governorate_id' => id]`

**`resolveFreeShippingByThreshold()`:**
```php
if ($freeShippingOver !== null && $subtotal > $freeShippingOver) {
    return 0;  // Free shipping threshold met
}
return $shippingPrice;
```

#### 6h: Order Creation or Update

```
OrderService:217-238
```

**Find existing pending order:**
```php
$pendingOrder = $this->orderCreationService->findPendingOrderForUser((int) $request->user()->id);

if ($pendingOrder) {
    // Update existing pending order
    $order = $this->orderCreationService->updateOrder(
        $pendingOrder, $orderData, $cart, $checkoutTotals, null, null, null, $shippingPrice, $governorateId,
    );
    $this->orderCreationService->syncOrderItems($order, $cart);
    $this->orderCreationService->updateTransactionAmount($order);
} else {
    // Create new order
    $order = $this->orderCreationService->createOrder(
        $orderData, $cart, $checkoutTotals, null, null, null, $shippingPrice, $governorateId,
    );
    if (!$order) { DB::rollBack(); return null; }
    
    $this->orderCreationService->createOrderItems($order, $cart);
    $this->orderCreationService->finalizeOrder($order, $checkoutTotals);
}
```

**`createOrder()` (OrderCreationService:27):**
Inserts into `orders` table with:
- User info, address, governorate
- `price` = subtotal (pre-discount)
- `total_price` = finalTotal + shipping
- `coupon` = coupon code, `coupon_discount`, `coupon_discount_type`
- `promotion_id`, `promotion_code`, `promotion_type`, `promotion_discount`
- `status` = 'pending'

**`createOrderItems()` (OrderCreationService:117):**
For each cart item:
- Calculates `effectiveUnitPrice` = total_price / quantity
- Calculates `promotionDiscountAmount` = (price * quantity) - lineTotal
- Resolves flash sale and discount pricing from ProductPricingService
- Creates `order_items` row with full snapshot data

**`finalizeOrder()` (OrderCreationService:231):**
```php
OrderCreated::dispatch($order);
```

Dispatches `App\Events\OrderCreated` which triggers `SendNewOrderNotification` listener (queued on 'medium').

#### 6i: Commit Transaction

```php
DB::commit();
return $order->load(['orderItems.product', 'orderItems.productVariant']);
```

---

### Step 7: Post-Order Payment Routing

```
OrderController:106-122
```

**Online Payment:**
```php
if ($paymentMethod === 'online') {
    $orderPrice = round((float) $order->total_price, 2);
    if ($orderPrice <= 0) {
        return $this->apiResponse(FILED_TO_CREATE_ORDER_TRY_AGAIN, 500, false);
    }
    return $this->paymentCheckoutHandler->handleOnlinePayment($request, $order, $orderPrice, $gateway);
}
```

**COD:**
```php
if ($paymentMethod === 'cod') {
    return $this->paymentCheckoutHandler->handleCodPayment($request, $order);
}
```

**Pay at Cashier:**
```php
if ($paymentMethod === 'pay_at_cashier') {
    return $this->paymentCheckoutHandler->handleCashierQrPayment($request, $order);
}
```

---

### Step 8: Payment Handler Details

#### handleOnlinePayment()

1. Creates gateway instance via factory
2. Calls `$gateway->createInvoice($order, $amount, $callbackUrl, $errorUrl)`
3. If failed: returns error response
4. Creates Transaction record with status='pending'
5. Returns success response with redirect URL

#### handleCodPayment()

1. Creates Transaction record with payment_method='cod', status='pending'
2. Returns success response with order_id
3. **Inventory stays reserved** (cart not modified yet)

#### handleCashierQrPayment()

1. Creates Transaction record with payment_method='pay_at_cashier', status='pending'
2. Generates QR code from CashierQrService
3. Returns success response with order_id, transaction_uuid, qr_code
4. **Inventory stays reserved** (cart not modified yet)

---

## Summary: Database Writes During Checkout

| Write | Location | Within Transaction? |
|-------|----------|-------------------|
| Cart lock + item price refresh | `ensureCartReservation()` | YES (inner txn) |
| `cart_items.promotion_id`, `discount_amount`, `total_price` | `PromotionApplicator::applyOutcome()` | YES (inner txn) |
| `cart.total_price` (updated after promotion) | `PromotionApplicator::applyOutcome()` | YES (inner txn) |
| Cart coupon cleared (if invalid) | `addItemsInOrder()` | YES (outer txn) |
| `orders` row created | `createOrder()` | YES (outer txn) |
| `order_items` rows created | `createOrderItems()` | YES (outer txn) |
| `flash_sale_reservations` (if applicable) | implied by pricing service | YES (outer txn) |
| Transaction record (pending) | after order creation | NO (new txn) |

## Summary: Events Dispatched

| Event | When | Listeners |
|-------|------|-----------|
| `App\Events\OrderCreated` | After order creation, before commit | `SendNewOrderNotification` (queued) |

## Critical Bugs in Checkout Flow

| ID | Severity | Location | Description |
|----|----------|----------|-------------|
| CPN-1 | MEDIUM | `OrderService:173-179` | Stale `$cart->coupon` in-memory after `$cart->update(['coupon' => null])`. `calculatePriceByCoupon()` reads stale value. Need `$cart->refresh()`. |
| CHK-1 | LOW | `OrderService:157` | Only SCHEDULED items loaded for checkout. FAST items are invisible. If a cart has only FAST items, checkout will say "cart empty". |
| CHK-2 | LOW | `OrderService:217` | `findPendingOrderForUser()` does not lock the pending order. Between finding and updating, another request could modify it. |
