# Checkout System — Complete Architecture Documentation

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## Executive Summary

The checkout system is the core financial transaction pipeline of the commerce platform. It handles the complete lifecycle from cart validation through order creation, payment processing, and post-payment inventory finalization. Three payment methods are supported: **online** (via MyFatoorah gateway), **cash on delivery (COD)**, and **pay at cashier** (QR code). The system is designed with pessimistic locking (`lockForUpdate`) throughout to prevent race conditions on stock, coupons, and promotions. Orders support **pending order resumption** — if a user refreshes the page or retries, the existing pending order is reused rather than creating duplicates.

---

## 1. Entry Point

### 1.1 Route

**File**: `routes/api.php:75`

```
POST /api/v1/v1/general/checkout
```

**Middleware stack**:
1. `auth:sanctum` — authenticates the user via Sanctum token
2. `throttle:checkout` — rate limiting for the checkout endpoint

### 1.2 Controller Method

**File**: `app/Http/Controllers/Api/General/OrderController.php:66-123`

```php
public function checkout(OrderCreateRequest $request)
```

The method receives the validated request, retrieves the active cart, ensures inventory reservation, delegates to `OrderService::addItemsInOrder()` for order creation, and routes to the appropriate payment handler.

---

## 2. Request Validation

**File**: `packages/marvel/src/Http/Requests/OrderCreateRequest.php`

### 2.1 Authorization
```php
public function authorize() { return true; }
```
Any authenticated user can checkout. No additional authorization checks (beyond being logged in).

### 2.2 Validation Rules

| Field | Rules | Purpose |
|-------|-------|---------|
| `name` | required, string, max:255 | Customer name |
| `user_phone` | required, string, max:255 | Customer phone |
| `user_email` | required, email, max:255 | Customer email |
| `address` | required, array | Shipping address (JSON object) |
| `notes` | nullable, string | Order notes |
| `selected_promotion_id` | nullable, integer, exists:promotions,id | Applied promotion ID |
| `selected_gift_product_id` | nullable, integer, exists:products,id | Selected gift product ID |
| `type` | nullable, in:mobile,web | Client platform |
| `fulfillment_type` | nullable, string, in:delivery,pickup | If pay_at_cashier: only pickup allowed |
| `payment_method` | nullable, string, in:online,cod,pay_at_cashier | Payment method |
| `gateway` | nullable, string, max:50 | Payment gateway name |
| `governorate_id` | required_if:fulfillment_type=delivery, integer, exists:governorates,id | Shipping governorate |
| `pickup_location_id` | required_if:fulfillment_type=pickup, nullable, integer, exists:pickup_locations,id | Pickup location |

### 2.3 Special Validation Logic

If `payment_method === 'pay_at_cashier'`, the `fulfillment_type` is restricted to only `pickup` via `Rule::in([FulfillmentType::PICKUP])`. This is enforced in the validation rules array dynamically.

### 2.4 Failure Response

```json
{
  "errors": { "field": ["error message"] }
}
```

HTTP Status: `422`

---

## 3. Full Execution Flow

### Step 1: Get Validated Data

**File**: `OrderController.php:68`
```php
$orderDataUser = $request->validated();
$orderDataUser['user_id'] = $request->user()->id;
```

Extracts validated input. Appends `user_id` from the authenticated user.

### Step 2: Get Active Cart

**File**: `CartInventoryService.php:307-317`

```php
public function getActiveCartForUser(User $user): ?Cart
{
    return Cart::query()
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->with([
            'items.product.flash_sales' => fn($q) => $q->valid(),
            'items.productVariant.attributeProducts.attributeValue.attribute',
        ])
        ->first();
}
```

**What it does**: Fetches the single active cart for the user. A user can only have **one** active cart at a time (enforced by business logic in `CartRepository::persistCart`). If no cart exists, returns `null` and the controller responds with `CART_NOT_FOUND` (400).

**Loaded relationships**:
- `items.product.flash_sales` — only valid flash sales via `valid()` scope
- `items.productVariant.attributeProducts.attributeValue.attribute` — variant attribute chain

### Step 3: Ensure Cart Reservation

**File**: `CartInventoryService.php:291-305`

```php
public function ensureCartReservation(Cart $cart): Cart
{
    return DB::transaction(function () use ($cart) {
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
}
```

**Purpose**: Ensures the actual reserved stock in the database matches the cart item quantities. This is the "freshness check" — between when items were added to the cart and when checkout happens, stock may have changed.

**Locking**: Locks the cart row with `lockForUpdate()` — no other transaction can read/modify this cart concurrently.

**Sub-call: `syncCartItemReservation`** (`CartInventoryService.php:319-341`):
1. Locks the cart item row (`lockForUpdate`)
2. Locks the product/variant inventory row (`lockInventoryRowByItem`)
3. Compares `item.quantity` with `item.reserved_quantity`
4. If quantity increased: calls `reserveStock()` to increase `reserved_quantity` (throws if insufficient)
5. If quantity decreased: calls `releaseStock()` to decrease `reserved_quantity`
6. If quantity unchanged: validates stock hasn't dropped below desired quantity
7. Updates `item.reserved_quantity` if changed

**Stock formula used**:
```
availableStock = max(0, stock_quantity - reserved_quantity)
```

**Sub-call: `touchCartReservation`** (`CartInventoryService.php:463-470`):
```php
$cart->update([
    'status' => 'active',
    'reserved_at' => now(),
    'expires_at' => Carbon::now()->addDays(3), // CART_TTL_DAYS = 3
]);
```

Updates the reservation timestamp and extends expiration by 3 days from now.

### Step 4: Determine Payment Details

**File**: `OrderController.php:82-94`

```php
$paymentMethod = $request->input('payment_method', 'online');
$gateway = $request->input('gateway', config('payment.default_gateway', 'myfatoorah'));
$fulfillmentType = $request->input('fulfillment_type', 'delivery');
```

Defaults: `online` payment, `myfatoorah` gateway, `delivery` fulfillment.

### Step 5: Validate COD + Pickup Constraint

```php
if ($paymentMethod === 'cod' && $fulfillmentType === 'pickup') {
    return $this->apiResponse(COD_NOT_AVAILABLE_FOR_PICKUP, 422, false);
}
```

Cash on delivery is not available for pickup orders. Reason: COD requires delivery address for the delivery person to collect payment.

### Step 6: Create the Order

**File**: `OrderService.php:148-253` — `addItemsInOrder($request)`

This is the most critical method. Let's trace every sub-step.

#### 6a. Begin Transaction

```php
DB::beginTransaction();
```

Everything from here to commit/rollback is atomic.

#### 6b. Lock and Load Cart

```php
$cart = Cart::query()
    ->where('user_id', auth()->id())
    ->where('status', 'active')
    ->lockForUpdate()
    ->with([
        'items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED),
        'items.product.flash_sales' => fn($q) => $q->valid(),
        'items.productVariant'
    ])
    ->first();
```

**Key detail**: Only loads items with `shipping_method = SCHEDULED`. The FAST shipping items are handled separately (split shipping model). This means the main checkout flow only processes scheduled delivery items.

**Lock**: `lockForUpdate()` on the cart row prevents concurrent checkout by the same user.

If no cart or empty items → `DB::rollBack()`, returns `null`.

#### 6c. Refresh Cart Item Prices

**File**: `OrderService.php:407-436`

```php
private function refreshCartItemPrices(Cart $cart): void
{
    $pricingService = app(ProductPricingService::class);
    $cart->load(['items.product', 'items.productVariant']);

    foreach ($cart->items as $item) {
        if ($item->is_gift) continue;

        $product = $item->product;
        if (!$product) continue;

        $currentPrice = $item->productVariant
            ? $pricingService->calculateVariantCurrentPrice($product, $item->productVariant)
            : $pricingService->calculateProductCurrentPrice($product);

        if ($currentPrice !== null && (float) $currentPrice !== (float) $item->price) {
            $item->forceFill([
                'price' => $currentPrice,
                'total_price' => round($currentPrice * max(1, (int) ($item->quantity ?? 0)), 2),
            ])->save();
        }
    }

    $cart->refresh();
    $cart->load(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED), ...]);
}
```

**Purpose**: Re-fetches current prices from the pricing engine at checkout time. If a flash sale started or ended between cart-add and checkout, the price is updated. This prevents users from paying outdated prices.

**Important**: Gift items are skipped (price remains 0).

**Formula**:
```
new total_price = currentPrice × quantity
```

#### 6d. Re-Validate Coupon

```php
$freeShippingCoupon = false;
if ($cart->coupon) {
    $lockedCoupon = Coupon::where('code', $cart->coupon)->lockForUpdate()->first();
    if ($lockedCoupon) {
        $validation = CouponOrchestrator::validate($lockedCoupon, $request->user(), $cart->items);
        if (!$validation['valid']) {
            $cart->update(['coupon' => null]);
            $cart->refresh();
        } elseif ($lockedCoupon->discount_type === DiscountType::FREE_SHIPPING) {
            $freeShippingCoupon = true;
        }
    } else {
        $cart->update(['coupon' => null]);
        $cart->refresh();
    }
}
```

**Locking**: The coupon row is locked with `lockForUpdate()` to prevent race condition on usage counts.

**What happens**:
1. If the coupon code exists on the cart, load the `Coupon` model with lock
2. Validate via `CouponOrchestrator::validate()` — checks: status, dates, limiter, already-used, product restrictions, assignments
3. If invalid: silently remove the coupon from the cart (the user won't get the discount)
4. If valid AND type is `FREE_SHIPPING`: set `$freeShippingCoupon = true` for later use
5. If coupon code doesn't exist in DB: remove from cart

**Why**: The coupon applied at cart time may have expired or reached its usage limit by the time the user checks out. Re-validation ensures only currently valid coupons are applied.

#### 6e. Detect Applied Promotion from Cart Items

```php
$selectedPromotionId = $cart->items
    ->firstWhere(fn($item) => !is_null($item->promotion_id))
    ?->promotion_id;

$selectedGiftProductId = $cart->items
    ->firstWhere('is_gift', true)
    ?->product_id;
```

**Purpose**: Reads the currently applied promotion from the cart items themselves. Promotion data is stored on individual cart items (not on the cart itself).

**Gift detection**: If any cart item has `is_gift = true`, its `product_id` is extracted for gift processing.

#### 6f. Calculate Checkout Totals

**File**: `OrderService.php:438-466`

```php
public function calculateCheckoutTotals(Cart $cart, ?int $selectedPromotionId, ?int $selectedGiftProductId = null, ?string $shippingMethod = null): CheckoutTotals
{
    $promotionTotals = $this->promotionService->applySelectedPromotion($cart, $selectedPromotionId, $selectedGiftProductId, $shippingMethod);
    $priceAfterPromotion = $promotionTotals->finalTotal;
    $couponResult = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
    $finalTotal = round(max(0, (float) $couponResult['finalPrice']), 2);

    // ... load coupon metadata ...

    return new CheckoutTotals(
        subtotal: $promotionTotals->subtotal,
        promotionDiscount: $promotionTotals->promotionDiscount,
        couponDiscount: round(max(0, (float) $priceAfterPromotion - (float) $finalTotal), 2),
        finalTotal: $finalTotal,
        promotion: $promotionTotals->promotion,
        giftItems: $promotionTotals->giftItems,
        coupon: $coupon,
        couponDiscountType: $couponResult['discountType'],
        couponDiscountMaxAmount: $couponDiscountMaxAmount,
    );
}
```

**Order of operations**:
1. **First**: Apply promotion discounts to cart items (per-item proportional allocation)
2. **Second**: Apply coupon discount on the post-promotion total
3. **Third**: Calculate final total

**Formula chain**:
```
priceAfterPromotion = promotionTotals.finalTotal
finalTotal = max(0, couponCalculator.calculate(coupon, priceAfterPromotion).finalPrice)
couponDiscount = max(0, priceAfterPromotion - finalTotal)
```

**Calculation method `calculatePriceByCoupon`** (`OrderService.php:339-359`):
- If no coupon on cart: returns finalPrice = subtotal (no discount)
- If coupon code exists but is invalid/expired: returns finalPrice = subtotal (no discount)
- If valid: delegates to `CouponCalculator::calculate($coupon, $totalPrice)`
  - Percentage: `discountAmount = price × (discount / 100)`, capped by `max_discount_amount`
  - Fixed: `discountAmount = min(discount, price)`
  - Free shipping: `discountAmount = 0`, `freeShipping = true`

#### 6g. Validate Minimum Order Amount

```php
$minimumOrderAmount = (float) (Settings::first()?->minimum_order_amount ?? 0);
if ($minimumOrderAmount > 0 && $checkoutTotals->subtotal < $minimumOrderAmount) {
    DB::rollBack();
    throw new \InvalidArgumentException(__('Minimum order amount is :amount', ...));
}
```

**Source**: `settings` table, `minimum_order_amount` column. If the pre-discount subtotal is below the configured minimum, the order is rejected.

**Note**: This checks `subtotal` (pre-discount), not `finalTotal`. This means promotions and coupons don't help meet the minimum — the raw cart value must be sufficient.

#### 6h. Resolve Shipping Price

```php
$shippingInfo = $this->resolveShippingPrice((int) ($orderData['governorate_id'] ?? null));
$shippingPrice = $this->resolveFreeShippingByThreshold($checkoutTotals->subtotal, $shippingInfo['free_shipping_over'], $shippingInfo['price']);
if ($freeShippingCoupon) {
    $shippingPrice = 0;
}
```

**`resolveShippingPrice`** (`OrderService.php:304-328`):
1. If no governorate_id: price = 0, free_shipping_over = null
2. Look up governorate by id (must be active)
3. Look up associated shipping price (must be active)
4. Returns: `{ price, free_shipping_over, governorate_id }`

**`resolveFreeShippingByThreshold`** (`OrderService.php:288-294`):
```php
if ($freeShippingOver !== null && $subtotal > $freeShippingOver) {
    return 0;
}
return $shippingPrice;
```

If the governorate offers free shipping above a threshold AND the subtotal exceeds it, shipping becomes free.

**Free shipping coupon**: If the applied coupon is `FREE_SHIPPING` type, shipping is forced to 0, overriding both the base price and the threshold logic.

#### 6i. Create or Reuse Pending Order

```php
$pendingOrder = $this->orderCreationService->findPendingOrderForUser((int) $request->user()->id);

if ($pendingOrder) {
    // Reuse: update existing pending order + sync items + update transaction amount
    $order = $this->orderCreationService->updateOrder(...);
    $this->orderCreationService->syncOrderItems($order, $cart);
    $this->orderCreationService->updateTransactionAmount($order);
} else {
    // Create new: create order + create items + finalize
    $order = $this->orderCreationService->createOrder(...);
    // ... createOrderItems, finalizeOrder
}
```

**`findPendingOrderForUser`** (`OrderCreationService.php:19-26`):
```php
return Order::query()
    ->where('user_id', $userId)
    ->where('status', 'pending')
    ->lockForUpdate()
    ->first();
```

**Why pending order resumption exists**: If a user:
- Refreshes the checkout page
- Submits checkout twice
- The first attempt created an order but the redirect failed

Instead of creating duplicate orders, the system finds the existing pending order and updates it with fresh data.

**`updateOrder`** (`OrderCreationService.php:76-116`): Updates all order fields with new data from `$orderData`, `$checkoutTotals`, and `$cart`. Uses `??` operators to preserve existing values if not provided.

**`syncOrderItems`** (`OrderCreationService.php:176-181`):
```php
$order->orderItems()->delete();  // Delete existing items
return $this->createOrderItems($order, $cart);  // Re-create from current cart
```

**`createOrderItems`** (`OrderCreationService.php:118-174`):

For each cart item:
1. Calculate effective unit price: `total_price / quantity`
2. Calculate promotion discount: `max(0, (price × quantity) - total_price)`
3. Resolve flash sale and discount prices at order time (snapshot)
4. Create `OrderProduct` record with all pricing snapshot data

**Key: Price snapshotting**: The order item stores `product_flash_sale_price`, `product_discount_price`, and `product_price` at the moment of order creation. This preserves the pricing for historical/audit purposes even if the product price changes later.

**`createOrder`** (`OrderCreationService.php:28-74`):

Creates the `Order` record with:
- `price` = `checkoutTotals.subtotal` (pre-discount subtotal)
- `total_price` = `subtotal + shipping + fast_fee` (after all discounts)
- All coupon/promotion metadata
- Pickup location snapshot (if pickup fulfillment)

**`finalizeOrder`** (`OrderCreationService.php:232-239`):
```php
try {
    OrderCreated::dispatch($order);
} catch (\Throwable $e) {
    report($e);
}
```

Dispatches the `OrderCreated` event. Exceptions are caught and reported but NOT re-thrown — the order creation succeeds regardless of event listener failures.

#### 6j. Commit Transaction

```php
DB::commit();
return $order->load(['orderItems.product', 'orderItems.productVariant']);
```

**Rollback paths**:
- `\InvalidArgumentException` → rollback + re-throw (caught by controller as 422)
- `\Exception` (generic) → rollback + report + return null (caught by controller as 500)

### Step 7: Route to Payment Handler

**File**: `OrderController.php:106-122`

#### 7a. Online Payment

```php
if ($paymentMethod === 'online') {
    $orderPrice = round((float) $order->total_price, 2);
    if ($orderPrice <= 0) {
        return $this->apiResponse(FILED_TO_CREATE_ORDER_TRY_AGAIN, 500, false);
    }
    return $this->paymentCheckoutHandler->handleOnlinePayment($request, $order, $orderPrice, $gateway);
}
```

**Zero-price guard**: If the total is ≤ 0 after all discounts, the order is rejected. This prevents negative-price orders or free orders through the online payment path.

**`PaymentCheckoutHandler::handleOnlinePayment`** (`PaymentCheckoutHandler.php:25-75`):

1. **Gateway instantiation**: `PaymentGatewayFactory::make($gateway)` — currently only supports `myfatoorah`
2. **URL setup**: Sets callback/error URLs, appends `?type=mobile` if mobile request
3. **Invoice creation**: Calls `$gatewayInstance->createInvoice($order, $amount, $callbackUrl, $errorUrl)`
4. **Transaction creation**:
   ```php
   Transaction::create([
       'order_id' => $order->id,
       'user_id' => $request->user()->id,
       'invoice_id' => $result->gatewayTransactionId,
       'payment_method' => $gateway,
       'status' => 'pending',
       'amount' => $amount,
       'currency' => config('payment.default_currency', 'EGP'),
       'gateway_transaction_id' => $result->gatewayTransactionId,
       'gateway_response' => $result->rawResponse,
   ]);
   ```
5. **Response**: Returns `{ url: result.redirectUrl }` — frontend redirects user to MyFatoorah payment page

#### 7b. COD Payment

```php
return $this->paymentCheckoutHandler->handleCodPayment($request, $order);
```

**`PaymentCheckoutHandler::handleCodPayment`** (`PaymentCheckoutHandler.php:77-95`):

1. Creates pending transaction:
   ```php
   Transaction::create([
       'order_id' => $order->id,
       'user_id' => $request->user()->id,
       'payment_method' => 'cod',
       'status' => 'pending',
       'amount' => $order->total_price,
       'currency' => config('payment.default_currency', 'EGP'),
   ]);
   ```
2. Returns `{ order_id }` with success message
3. No gateway interaction — the order is created as pending with COD transaction

#### 7c. Cashier QR Payment

```php
return $this->paymentCheckoutHandler->handleCashierQrPayment($request, $order);
```

**`PaymentCheckoutHandler::handleCashierQrPayment`** (`PaymentCheckoutHandler.php:97-119`):

1. Creates pending transaction (same pattern as COD)
2. Generates QR code:
   ```php
   $qrDataUri = $this->cashierQrService->generateBase64DataUri($transaction);
   ```
3. Returns `{ order_id, transaction_uuid, qr_code }` — frontend displays QR for cashier scanning

---

## 4. Database Changes During Checkout

### Tables Modified
| Table | Operation | When |
|-------|-----------|------|
| `carts` | UPDATE (status, total_price, reserved_at, expires_at) | Step 3 (ensureCartReservation) |
| `cart_items` | UPDATE (reserved_quantity) | Step 3 (syncCartItemReservation) |
| `products` / `product_variants` | UPDATE (reserved_quantity, in_stock) | Step 3 (reserveStock/releaseStock) |
| `cart_items` | UPDATE (price, total_price) | Step 6c (refreshCartItemPrices) |
| `carts` | UPDATE (coupon = null) | Step 6d (invalid coupon removal) |
| `cart_items` | UPDATE (promotion_id, discount_amount, total_price) | Step 6f (applySelectedPromotion) |
| `orders` | INSERT or UPDATE | Step 6i (create/update order) |
| `order_products` | INSERT or DELETE+INSERT | Step 6i (syncOrderItems) |
| `transactions` | INSERT | Step 7 (payment handler) |

### Rows NOT Modified During Checkout
- `coupons.used` — NOT incremented at checkout; only after successful payment
- `coupon_usages` — NOT inserted at checkout
- `coupon_assignment_usages` — NOT inserted at checkout
- `promotions.usage` — NOT incremented at checkout; only after payment (via `finalizePromotionUsageAfterPayment` or callback)

---

## 5. Locking Summary

| Lock Target | Type | Location | Duration |
|-------------|------|----------|----------|
| Cart row | `lockForUpdate` | `ensureCartReservation()` + `addItemsInOrder()` | Until transaction commit |
| CartItem row | `lockForUpdate` | `syncCartItemReservation()` | Until transaction commit |
| Product/Variant row | `lockForUpdate` | `lockInventoryRow()` / `lockInventoryRowByItem()` | Until transaction commit |
| Coupon row | `lockForUpdate` | `addItemsInOrder()` line 69 | Until transaction commit |
| Promotion row | `lockForUpdate` | `PromotionApplicator::applyOutcome()` | Until transaction commit |
| Order row (pending) | `lockForUpdate` | `findPendingOrderForUser()` | Until transaction commit |

---

## 6. Transaction Boundaries

### Transaction 1: `ensureCartReservation`
- Scope: Cart locking + stock sync
- Duration: Until completion
- Rollback: Automatic on exception

### Transaction 2: `addItemsInOrder`
- Scope: Price refresh → coupon validation → promotion application → order creation
- Duration: Until commit or rollback
- Rollback: On `\InvalidArgumentException` (re-thrown), on `\Exception` (returns null)

### Transaction 3: `handleOnlinePayment` (gateway call, NOT in transaction)
- Transaction creation happens outside the `addItemsInOrder` transaction
- **Critical**: If the gateway invoice creation succeeds but the transaction INSERT fails, there's no rollback of the order. However, since `handleOnlinePayment` is called after the order transaction commits, the order already exists.

---

## 7. Error Handling Matrix

| Failure Point | HTTP Status | Response | Cart State | Order State |
|---------------|-------------|----------|------------|-------------|
| No cart | 400 | `CART_NOT_FOUND` | Unchanged | N/A |
| Cart empty | Rollback in `addItemsInOrder` | 500 | Unchanged | N/A |
| Stock insufficient (ensureCartReservation) | 400 | Error message from thrown exception | Rolled back | N/A |
| COD + pickup | 422 | `COD_NOT_AVAILABLE_FOR_PICKUP` | Unchanged | N/A |
| Invalid coupon (re-validation) | — | Proceeds without coupon | Coupon removed | Pending (no coupon) |
| Below minimum order | 422 | Error message | Rolled back | N/A |
| Order creation fails | 500 | `ERROR_ADDING_ITEMS_TO_ORDER` | Rolled back | N/A |
| Online total ≤ 0 | 500 | `FILED_TO_CREATE_ORDER_TRY_AGAIN` | Order created, no transaction | Pending |
| Gateway unavailable | 422 | Error message | Order created, no transaction | Pending |
| Gateway invoice creation fails | 500 | Gateway error message | Order created, no transaction | Pending |
| Transaction creation fails | 500 | `ERROR_CREATING_TRANSACTION` | Order created, no transaction | Pending |

---

## 8. Events Dispatched During Checkout

| Event | Dispatched At | Location |
|-------|---------------|----------|
| `OrderCreated` | After order creation (new, not update) | `OrderCreationService::finalizeOrder()` |
| `PaymentSucceeded` | After callback processes successful payment | `OrderController::checkoutCallback()` |
| `PaymentFailed` | After gateway verification fails | `OrderController::checkoutCallback()` |

Note: `OrderCreated` is dispatched inside a `try/catch` block — failures are reported but don't prevent checkout success.

---

## 9. Edge Cases

### 9.1 Concurrent Checkout by Same User
**Protection**: `lockForUpdate()` on the cart row in `addItemsInOrder()`. The second request blocks until the first completes. After the first commits, the second finds the cart's status changed (the first transaction doesn't change cart status; `finalizeItemsByShippingMethod` does, but that happens in the callback, not during checkout). However, `ensureCartReservation` already ran before `addItemsInOrder`'s transaction, so the second request would see the same reserved stock.

### 9.2 Checkout After Cart Expiration
`getActiveCartForUser()` only returns carts with `status = 'active'`. Expired carts have `status = 'expired'`. The user would get "cart not found".

### 9.3 Checkout After Item Removed from Cart
Stock reservation in `ensureCartReservation()` re-syncs all items. If an item was deleted from the cart outside the checkout flow, it won't be in the loaded cart.

### 9.4 Price Change Between Cart Add and Checkout
`refreshCartItemPrices()` updates all cart item prices to current values at checkout time. If a flash sale ends between "add to cart" and "checkout", the price reverts to the base price.

### 9.5 Coupon Expires Between Apply and Checkout
Coupon re-validation at checkout step 6d silently removes expired coupons from the cart.

### 9.6 Promotion Becomes Invalid Between Apply and Checkout
`PromotionService::applySelectedPromotion()` re-validates the promotion by loading it with `valid()` scope + `lockForUpdate()`. If the promotion is no longer valid, `applySelectedPromotion` throws `\InvalidArgumentException`.

### 9.7 Pending Order Exists from Previous Attempt
The system reuses the pending order (updates it + syncs items). This prevents duplicate orders.

---

## 10. Failure Recovery

| Scenario | Recovery |
|----------|----------|
| User closes browser after checkout submit | Order exists as `pending`; transaction is `pending`; no payment collected; user can retry and reuse the same pending order |
| User retries checkout after timeout | `findPendingOrderForUser()` finds the existing pending order → updates it → creates new transaction |
| Payment gateway timeout | No transaction is created (it's created after gateway response). Order remains pending with no transaction |
| Callback never received | Order remains `pending` with `pending` transaction. Admin can manually mark as paid or cancel |
| Double callback | First callback: processes payment, marks order completed. Second callback: detects `$lockedTransaction->status === 'paid' && $lockedOrder->status === 'completed'` → returns early (idempotent) |

---

## 11. API Contract

### Request
```
POST /api/v1/v1/general/checkout
Authorization: Bearer <token>
Content-Type: application/json
```

### Request Body
```json
{
  "name": "John Doe",
  "user_phone": "+201234567890",
  "user_email": "john@example.com",
  "address": { "street": "123 Main St", "city": "Cairo" },
  "notes": "Leave at door",
  "selected_promotion_id": 1,
  "selected_gift_product_id": null,
  "type": "web",
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "governorate_id": 1,
  "pickup_location_id": null
}
```

### Success Response (Online)
```json
{
  "success": true,
  "message": "Checkout successful",
  "data": { "url": "https://myfatoorah.com/payment/..." }
}
```

### Success Response (COD)
```json
{
  "success": true,
  "message": "Order placed successfully",
  "data": { "order_id": 123 }
}
```

### Success Response (Cashier QR)
```json
{
  "success": true,
  "message": "Checkout successful",
  "data": {
    "order_id": 123,
    "transaction_uuid": "abc-123-def",
    "qr_code": "data:image/png;base64,..."
  }
}
```

### Error Response
```json
{
  "success": false,
  "message": "Cart not found",
  "data": []
}
```

---

## 12. Related Files

| File | Role |
|------|------|
| `app/Http/Controllers/Api/General/OrderController.php` | Checkout + callback controller |
| `app/Services/General/OrderService.php` | Order creation, coupon validation, totals calculation |
| `app/Services/General/CartInventoryService.php` | Cart reservation, stock sync |
| `app/Services/General/PromotionService.php` | Promotion eligibility and application |
| `app/Services/General/CouponService.php` | Coupon add-to-cart |
| `app/Services/Coupon/CouponOrchestrator.php` | Coupon validation orchestration |
| `app/Services/Coupon/CouponCalculator.php` | Coupon discount calculation |
| `app/Services/Checkout/OrderCreationService.php` | Order/order-item persistence |
| `app/Services/Payment/PaymentCheckoutHandler.php` | Payment method routing |
| `app/Services/Payment/PaymentGatewayFactory.php` | Gateway instantiation |
| `app/Services/Gateway/MyFatoorahGateway.php` | MyFatoorah gateway implementation |
| `app/DTOs/CheckoutTotals.php` | Totals DTO |
| `app/DTOs/GatewayResult.php` | Gateway response DTO |
| `packages/marvel/src/Http/Requests/OrderCreateRequest.php` | Checkout validation |
| `packages/marvel/src/Database/Models/Cart.php` | Cart model |
| `packages/marvel/src/Database/Models/CartItem.php` | Cart item model |
| `packages/marvel/src/Database/Models/Order.php` | Order model |
| `packages/marvel/src/Database/Models/Transaction.php` | Transaction model |
| `packages/marvel/src/Database/Models/Coupon.php` | Coupon model |
| `packages/marvel/src/Database/Models/Promotion.php` | Promotion model |
| `packages/marvel/src/Enums/ShippingMethod.php` | Shipping method enum |
| `packages/marvel/src/Enums/DiscountType.php` | Discount type enum |
| `packages/marvel/src/Services/Pricing/ProductPricingService.php` | Pricing engine |
| `app/Services/General/PromotionEngine/PromotionApplicator.php` | Promotion application with allocation |
