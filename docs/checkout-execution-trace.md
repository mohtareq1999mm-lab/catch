# ULTRA DEEP CHECKOUT EXECUTION TRACE & FINANCIAL FLOW AUDIT

> **Date:** 2026-07-26
> **Method:** POST /checkout
> **Scope:** Full execution trace from HTTP request to response

---

## TABLE OF CONTENTS

1. [Route Resolution](#1-route-resolution)
2. [Middleware](#2-middleware)
3. [Request Validation](#3-request-validation)
4. [Controller: checkout()](#4-controller-checkout)
5. [CartInventoryService: getActiveCartForUser](#5-cartinventoryservice-getactivecartforuser)
6. [CartInventoryService: ensureCartReservation](#6-cartinventoryservice-ensurecartreservation)
7. [OrderService: addItemsInOrder](#7-orderservice-additemsinorder)
8. [refreshCartItemPrices](#8-refreshcartitemprices)
9. [Coupon Validation in addItemsInOrder](#9-coupon-validation-in-additemsinorder)
10. [calculateCheckoutTotals](#10-calculatecheckouttotals)
11. [PromotionService: applySelectedPromotion](#11-promotionservice-applyselectedpromotion)
12. [PromotionEligibilityResolver: resolve](#12-promotioneligibilityresolver-resolve)
13. [Promotion Strategy: computeOutcome](#13-promotion-strategy-computeoutcome)
14. [PromotionApplicator: applyOutcome](#14-promotionapplicator-applyoutcome)
15. [Coupon Calculation](#15-coupon-calculation)
16. [Shipping Price Resolution](#16-shipping-price-resolution)
17. [OrderCreationService: createOrder](#17-ordercreationservice-createorder)
18. [OrderCreationService: createOrderItems](#18-ordercreationservice-createorderitems)
19. [OrderCreationService: finalizeOrder](#19-ordercreationservice-finalizeorder)
20. [Return to Controller - Payment Dispatch](#20-return-to-controller---payment-dispatch)
21. [Online Payment Flow](#21-online-payment-flow)
22. [COD Payment Flow](#22-cod-payment-flow)
23. [Cashier/QR Payment Flow](#23-cashierqr-payment-flow)
24. [Payment Callback Flow](#24-payment-callback-flow)
25. [Order Status Change](#25-order-status-change)
26. [Events & Listeners](#26-events--listeners)
27. [Financial Consistency Check](#27-financial-consistency-check)
28. [Bugs & Issues Found](#28-bugs--issues-found)

---

## 1. ROUTE RESOLUTION

```
File: routes/api.php:75
```

```
Route::post('checkout', [OrderController::class, 'checkout'])->middleware('auth:sanctum');
```

| Property | Value |
|---|---|
| URI | `/api/v1/checkout` (prefix applied by RouteServiceProvider) |
| Controller | `App\Http\Controllers\Api\General\OrderController` |
| Method | `checkout()` |
| Middleware | `auth:sanctum` |
| Named | No name |

---

## 2. MIDDLEWARE

### 2.1 auth:sanctum

Laravel Sanctum authenticates the user via Bearer token or session cookie.

**What happens:**
- Extracts token from `Authorization: Bearer <token>` header
- Validates token against `personal_access_tokens` table
- Sets `auth()->user()` to the authenticated `User` model instance
- If invalid/expired: returns 401

**Data entering:**
- Raw HTTP request with Bearer token

**Data exiting:**
- Authenticated `$request->user()` as a `User` model with id, name, email, phone, etc.

---

## 3. REQUEST VALIDATION

### Class: `Marvel\Http\Requests\OrderCreateRequest`

**File:** `packages/marvel/src/Http/Requests/OrderCreateRequest.php`

**Validation Rules:**

| Field | Rules |
|---|---|
| `name` | required, string, max:255 |
| `user_phone` | required, string, max:255 |
| `user_email` | required, email, max:255 |
| `address` | required, array |
| `notes` | nullable, string |
| `selected_promotion_id` | nullable, integer, exists:promotions,id |
| `selected_gift_product_id` | nullable, integer, exists:products,id |
| `type` | nullable, in:mobile,web |
| `fulfillment_type` | nullable, string, in:delivery,pickup (if pay_at_cashier: pickup only) |
| `payment_method` | nullable, string, in:online,cod,pay_at_cashier |
| `gateway` | nullable, string, max:50 |
| `governorate_id` | required_if:fulfillment_type=delivery, integer, exists:governorates,id |
| `pickup_location_id` | required_if:fulfillment_type=pickup, integer, exists:pickup_locations,id |

**Special validation:**
- If `payment_method === 'pay_at_cashier'`, fulfillment_type must be `pickup` only
- Custom failure response: 422 with validation errors JSON

**Data entering:**
```json
{
  "name": "John Doe",
  "user_phone": "+201234567890",
  "user_email": "john@example.com",
  "address": { "street": "..." },
  "notes": "optional",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "fulfillment_type": "delivery",
  "governorate_id": 1,
  "selected_promotion_id": null,
  "selected_gift_product_id": null
}
```

---

## 4. CONTROLLER: checkout()

**File:** `app/Http/Controllers/Api/General/OrderController.php:66`

```
public function checkout(OrderCreateRequest $request)
```

### Step 4.1: Extract validated data

```php
$orderDataUser = $request->validated();
$orderDataUser['user_id'] = $request->user()->id;
```

**Data entering:** Validated request array
**Data exiting:** `$orderDataUser` with `user_id` added

### Step 4.2: Get active cart

```php
$cart = $this->cartInventoryService->getActiveCartForUser($request->user());
if (!$cart) {
    return $this->apiResponse(CART_NOT_FOUND, 400, false);
}
```

**If no active cart:** returns 400 with CART_NOT_FOUND. Execution ends.

---

## 5. CartInventoryService: getActiveCartForUser

**File:** `app/Services/General/CartInventoryService.php:307`

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

### What it does:

**Database READ:**

| Table | Columns | Filter |
|---|---|---|
| `carts` | All | `user_id = X` AND `status = 'active'` |
| `cart_items` (via items) | All | `cart_id = X` |
| `products` (via items.product) | All | `product_id` |
| `flash_sales` (via items.product.flash_sales) | Valid only | Status=active, date range valid |
| `product_variants` (via items.productVariant) | All | |
| `attribute_products` / `attribute_values` / `attributes` | For variant display | |

**Returns:** `Cart` model with loaded relations, or `null`

### Data structure returned:

```
Cart {
    id: int
    user_id: int
    coupon: string|null
    total_price: float
    status: 'active'
    reserved_at: datetime|null
    expires_at: datetime|null
    items: Collection<CartItem> [
        CartItem {
            id, cart_id, product_id, product_variant_id,
            quantity, price, total_price, reserved_quantity,
            discount_amount, shipping_method, is_gift, promotion_id,
            attributes,
            product: Product { flash_sales, ... }
            productVariant: ProductVariant { attributeProducts }
        }
    ]
}
```

---

## 6. CartInventoryService: ensureCartReservation

**File:** `app/Services/General/CartInventoryService.php:291`

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

### Step 6.1: Lock cart row

```sql
SELECT * FROM carts WHERE id = ? FOR UPDATE
```

### Step 6.2: For each cart item, sync reservation

```
foreach: syncCartItemReservation(CartItem $item)
```

**File:** `CartInventoryService.php:319`

```php
private function syncCartItemReservation(CartItem $item): void
{
    $item = CartItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
    $stock = $this->lockInventoryRowByItem($item);
    $desiredQuantity = (int) $item->quantity;
    $reservedQuantity = (int) $item->reserved_quantity;
    $delta = $desiredQuantity - $reservedQuantity;

    if ($delta > 0) {
        $this->reserveStock($stock, $delta);
    } elseif ($delta < 0) {
        $this->releaseStock($stock, abs($delta));
    } else {
        $physicalQuantity = (int) ($stock->stock_quantity ?? 0);
        if ($physicalQuantity < $desiredQuantity) {
            throw new Exception(__(QUANTITY_EXCEEDS_STOCK));
        }
    }

    if ($delta !== 0) {
        $item->update(['reserved_quantity' => $desiredQuantity]);
    }
}
```

**For each item:**
- Locks the `cart_items` row: `SELECT * FROM cart_items WHERE id = ? FOR UPDATE`
- Locks the inventory row:

**If product (no variant):**
```sql
SELECT * FROM products WHERE id = ? FOR UPDATE
```

**If variant:**
```sql
SELECT * FROM product_variants WHERE id = ? FOR UPDATE
```

**reserveStock() logic:**
```php
private function reserveStock($stock, int $quantity): void
{
    $availableStock = max(0, (int) ($stock->stock_quantity ?? 0) - (int) ($stock->reserved_quantity ?? 0));
    if ($availableStock < $quantity) {
        throw new Exception(__(QUANTITY_EXCEEDS_STOCK));
    }
    $stock->reserved_quantity = (int) ($stock->reserved_quantity ?? 0) + $quantity;
    $stock->in_stock = $availableStock - $quantity > 0;
    $stock->save();
}
```

**Formula:**
```
availableStock = max(0, stock_quantity - reserved_quantity)
new_reserved_quantity = reserved_quantity + delta
in_stock = (availableStock - delta) > 0
```

**Database UPDATE:**
```
UPDATE products SET reserved_quantity = ?, in_stock = ? WHERE id = ?
```

### Step 6.3: Touch cart reservation

```php
private function touchCartReservation(Cart $cart): void
{
    $cart->update([
        'status' => 'active',
        'reserved_at' => now(),
        'expires_at' => Carbon::now()->addDays(3),  // CART_TTL_DAYS = 3
    ]);
}
```

**Database UPDATE:**
```
UPDATE carts SET status = 'active', reserved_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE id = ?
```

### Step 6.4: Transaction commit

If any exception is thrown here, the entire transaction rolls back, all locks are released, and the controller returns a 400 error.

---

## 7. OrderService: addItemsInOrder

**File:** `app/Services/General/OrderService.php:148`

```php
public function addItemsInOrder($request)
{
    try {
        DB::beginTransaction();
        // ... entire order creation
        DB::commit();
        return $order->load(['orderItems.product', 'orderItems.productVariant']);
    } catch (\InvalidArgumentException $e) {
        DB::rollBack();
        throw $e;
    } catch (\Exception $e) {
        DB::rollBack();
        report($e);
        return null;
    }
}
```

This is the CORE of the checkout process. Everything from here until commit is inside `DB::beginTransaction()`.

### Step 7.1: Lock and load the cart

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

**Database READ:**
```sql
SELECT * FROM carts WHERE user_id = ? AND status = 'active' FOR UPDATE
```

**Critical:** Only loads items with `shipping_method = 'SCHEDULED'`. Any items with `FAST` shipping are EXCLUDED from this query.

**If no cart or empty items:**
```php
if (!$cart || $cart->items->isEmpty()) {
    DB::rollBack();
    return null;
}
```

---

## 8. refreshCartItemPrices

**File:** `OrderService.php:405`

```php
private function refreshCartItemPrices(Cart $cart): void
{
    $pricingService = app(ProductPricingService::class);
    $cart->load(['items.product', 'items.productVariant']);

    foreach ($cart->items as $item) {
        if ($item->is_gift) {
            continue;  // <-- GIFT ITEMS SKIPPED
        }

        $product = $item->product;
        if (!$product) {
            continue;  // <-- DELETED PRODUCTS SKIPPED
        }

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
    $cart->load(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED),
                 'items.product.flash_sales' => fn($q) => $q->valid(),
                 'items.productVariant']);
}
```

### What it does:

For every non-gift cart item:
1. Calls `ProductPricingService::calculateProductCurrentPrice($product)` **OR** `calculateVariantCurrentPrice()`
2. Compares with the stored `$item->price`
3. If different: updates `price` and `total_price = price * quantity`

**Database UPDATE (if price changed):**
```
UPDATE cart_items SET price = ?, total_price = ? WHERE id = ?
```

**After refresh:** loads the cart again with fresh relations.

---

## 9. Coupon Validation in addItemsInOrder

```php
$freeShippingCoupon = false;
if ($cart->coupon) {
    $lockedCoupon = Coupon::where('code', $cart->coupon)->lockForUpdate()->first();
    if ($lockedCoupon) {
        $validation = CouponOrchestrator::validate($lockedCoupon, $request->user(), $cart->items);
        if (!$validation['valid']) {
            $cart->update(['coupon' => null]);  // Remove invalid coupon
        } elseif ($lockedCoupon->discount_type === DiscountType::FREE_SHIPPING) {
            $freeShippingCoupon = true;
        }
    } else {
        $cart->update(['coupon' => null]);  // Remove non-existent coupon
    }
}
```

### CouponOrchestrator::validate()

**File:** `CouponOrchestrator.php:22`

```
Input: Coupon $coupon, User $user, Collection $items
```

**Execution path:**

1. **CouponAssignmentValidator::validate($coupon, $user)**

   Checks:
   - Does this coupon have assignments?
   - If yes: is this user assigned? Is the assignment expired? Is the usage quota exceeded?

2. **CouponValidator::validate($coupon, $user, $items)**

   Checks:
   - `$coupon->status` is true?
   - `$coupon->start_date` is not in the future?
   - `$coupon->end_date` is not in the past?
   - `$coupon->limiter` is null OR `$coupon->used < $coupon->limiter`?
   - For public coupons: user hasn't already used this coupon? (Checks `coupon_usages` table)
   - If coupon has restricted products: cart contains at least one of them?

**If validation fails:** `$cart->coupon` is set to null, clearing the coupon from cart.

**If valid and FREE_SHIPPING:** `$freeShippingCoupon = true`

**Database UPDATE (if coupon removed):**
```
UPDATE carts SET coupon = NULL WHERE id = ?
```

### Step 9.1: Extract promotion info from cart items

```php
$selectedPromotionId = $cart->items
    ->firstWhere(fn($item) => !is_null($item->promotion_id))
    ?->promotion_id;

$selectedGiftProductId = $cart->items
    ->firstWhere('is_gift', true)
    ?->product_id;
```

Scans cart items to find if any item has a promotion attached.

---

## 10. calculateCheckoutTotals

**File:** `OrderService.php:436`

```php
public function calculateCheckoutTotals(
    Cart $cart,
    ?int $selectedPromotionId,
    ?int $selectedGiftProductId = null,
    ?string $shippingMethod = null
): CheckoutTotals
```

**Execution:**

```php
$promotionTotals = $this->promotionService->applySelectedPromotion(
    $cart, $selectedPromotionId, $selectedGiftProductId, $shippingMethod
);
$priceAfterPromotion = $promotionTotals->finalTotal;
$couponResult = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
$finalTotal = round(max(0, (float) $couponResult['finalPrice']), 2);
```

**Formula chain:**
```
1. subtotal = sum(price × quantity) for non-gift items  ← from PromotionService
2. finalTotal after promotion = promotionTotals->finalTotal
3. Apply coupon: couponResult = calculatePriceByCoupon(cart, finalTotalAfterPromotion)
4. finalTotal = max(0, couponResult['finalPrice'])
```

**Coupon calculation:**

```php
private function calculatePriceByCoupon($cart, $totalPrice): array
{
    if ($cart->coupon === null) {
        return ['finalPrice' => $totalPrice, 'discountType' => null, 'freeShipping' => false];
    }
    $coupon = Coupon::valid()->where('code', $cart->coupon)->first();
    if (!$coupon) {
        return ['finalPrice' => $totalPrice, 'discountType' => null, 'freeShipping' => false];
    }
    return CouponCalculator::calculate($coupon, (float) $totalPrice);
}
```

**CouponCalculator::calculate():**

**File:** `CouponCalculator.php:10`

```php
public static function calculate(Coupon $coupon, float $price): array
{
    $discount = (float) $coupon->discount;
    $discountAmount = 0.0;

    if ($coupon->discount_type === DiscountType::PERCENTAGE) {
        $discountAmount = $price * ($discount / 100);
        if ($coupon->max_discount_amount !== null) {
            $discountAmount = min($discountAmount, (float) $coupon->max_discount_amount);
        }
    } elseif ($coupon->discount_type === DiscountType::FIXED_RATE) {
        $discountAmount = min($discount, $price);
    }

    $freeShipping = $coupon->discount_type === DiscountType::FREE_SHIPPING;
    $discountAmount = round(max(0, $discountAmount), 2);
    $finalPrice = round(max(0, $price - $discountAmount), 2);

    return [
        'discountAmount' => $discountAmount,
        'finalPrice' => $finalPrice,
        'discountType' => $coupon->discount_type,
        'freeShipping' => $freeShipping,
    ];
}
```

**Coupon formulas:**
```
PERCENTAGE:  discountAmount = price × (discount_percent / 100)
             capped by max_discount_amount if set

FIXED_RATE:  discountAmount = min(coupon_discount, price)

FREE_SHIPPING: discountAmount = 0, freeShipping = true (handled elsewhere)
```

### CheckoutTotals DTO construction:

```php
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
```

**couponDiscount formula:**
```
couponDiscount = max(0, priceAfterPromotion - finalTotal)
```

This means: couponDiscount = priceAfterPromotion - (priceAfterPromotion - couponDiscountAmount) = couponDiscountAmount

---

## 11. PromotionService: applySelectedPromotion

**File:** `PromotionService.php:57`

```php
public function applySelectedPromotion(
    Cart $cart,
    ?int $promotionId,
    ?int $selectedGiftProductId = null,
    ?string $shippingMethod = null
): CheckoutTotals
```

### Step 11.1: Remove existing gift items

```php
$this->removeGiftItems($cart);
```

This releases inventory and deletes any existing gift items from the cart:
```php
$cart->items()
    ->where('is_gift', true)
    ->get()
    ->each(fn($item) => $this->inventoryService->releaseItem($item, true));
```

`releaseItem()`: releases reserved stock and deletes the cart_item row.

### Step 11.2: Calculate subtotal

```php
$subtotal = round((float) $cart->items
    ->reject(fn($item) => (bool) ($item->is_gift ?? false))
    ->sum(function ($item) {
        $baseLineTotal = ((float) ($item->price ?? 0)) * ((int) ($item->quantity ?? 0));
        if ($baseLineTotal > 0) {
            return $baseLineTotal;
        }
        return (float) ($item->total_price ?? 0);
    }), 2);
```

**Subtotal formula:**
```
for each non-gift item:
    baseLineTotal = price × quantity
    if baseLineTotal > 0: use baseLineTotal
    else: use total_price
subtotal = round(sum, 2)
```

Also converts to cents:
```php
$subtotalCents = (int) round((float) $subtotal * 100);
```

### Step 11.3: If no promotion selected

```php
if (!$promotionId) {
    return $this->clearPromotionFromCart($cart);
}
```

**clearPromotionFromCart()** removes all promotion data from cart items:
```php
$cart->items()
    ->where(function ($q) {
        $q->whereNotNull('promotion_id')->orWhere('discount_amount', '>', 0);
    })
    ->update([
        'promotion_id' => null,
        'discount_amount' => 0,
        'total_price' => DB::raw('ROUND(price * quantity, 2)'),
    ]);
```

**Database UPDATE:**
```
UPDATE cart_items SET promotion_id = NULL, discount_amount = 0, total_price = ROUND(price * quantity, 2)
WHERE cart_id = ? AND (promotion_id IS NOT NULL OR discount_amount > 0)
```

Returns: `CheckoutTotals` with 0 promotionDiscount, finalTotal = undiscounted total

### Step 11.4: If promotion selected - LOAD AND LOCK IT

```php
$promotion = Promotion::valid()
    ->whereKey($promotionId)
    ->with([
        'products:id',
        'giftProducts:id,name,sku,product_type,stock_quantity,reserved_quantity',
        'giftProducts.variations:id,product_id,stock_quantity,reserved_quantity,price,height,width,length,weight',
        'giftProducts.variations.attributeProducts.attributeValue.attribute',
    ])
    ->lockForUpdate()   // <-- LOCKS THE PROMOTION ROW
    ->first();
```

**Database READ:**
```sql
SELECT * FROM promotions WHERE id = ? AND status = 1 
  AND (limiter IS NULL OR usage < limiter)
  AND (start_at IS NULL OR start_at <= CURDATE())
  AND (end_at IS NULL OR end_at >= CURDATE())
FOR UPDATE
```

**If not found:** throws `InvalidArgumentException('Selected promotion is not valid.')`

### Step 11.5: Evaluate promotion eligibility (read-only)

```php
$result = $this->resolver->resolve($cart, $promotion, $subtotalCents);

if (!$result) {
    throw new \InvalidArgumentException('Selected promotion is not eligible for this cart.');
}
```

---

## 12. PromotionEligibilityResolver: resolve

**File:** `PromotionEligibilityResolver.php:59`

```php
public function resolve(Cart $cart, Promotion $promotion, int $subtotalCents): ?PromotionResult
```

### Step 12.1: Get strategy by promotion type

```php
$strategy = $this->strategies[$promotion->type_amount] ?? null;
if (!$strategy) {
    return null;
}
```

Supported types (from `PromotionMountType` enum):
- `percentage` → `PercentagePromotionStrategy`
- `fixed_rate` → `FixedPromotionStrategy`
- `gift` → `GiftPromotionStrategy`

### Step 12.2: Product filter check

```php
if (!$promotion->appliesToAllProducts() && $promotion->products->isEmpty()) {
    return null;
}
```

If promotion is for specific products but has no products attached → INELIGIBLE.

### Step 12.3: matchedEligibility

**File:** `PromotionEligibilityResolver.php:101`

```php
public function matchedEligibility(Cart $cart, Promotion $promotion, int $subtotalCents): PromotionEvaluation
```

**What it does:**

1. **Get required product IDs** from the promotion's pivot table:
   ```php
   $requiredProductIds = $promotion->products->pluck('id')->map(fn($id) => (int) $id)->all();
   ```

2. **Filter cart items** that match the promotion scope:
   ```php
   $matchedItems = $cart->items->filter(function ($item) use ($promotion, $requiredProductIds) {
       if ((bool) ($item->is_gift ?? false)) {
           return false;  // gifts NEVER counted
       }
       if ($promotion->appliesToAllProducts()) {
           return true;   // all products eligible
       }
       return in_array((int) $item->product_id, $requiredProductIds, true);
   });
   ```

3. **Calculate matched quantity:**
   ```php
   $matchedQuantity = $matchedItems->sum(fn($item) => (int) $item->quantity);
   ```

4. **Calculate matched subtotal in cents:**
   ```php
   $matchedSubtotalCents = $matchedItems->sum(function ($item) {
       $unitPrice = (float) ($item->price ?? 0);
       $quantity = (int) ($item->quantity ?? 0);
       $baseLineTotal = $unitPrice * $quantity;
       if ($baseLineTotal > 0) {
           return (int) round($baseLineTotal * 100);
       }
       return (int) round((float) ($item->total_price ?? 0) * 100);
   });
   ```

5. **If appliesToAllProducts: override with full subtotal:**
   ```php
   if ($promotion->appliesToAllProducts()) {
       $matchedSubtotalCents = $subtotalCents;
   }
   ```

6. **Return PromotionEvaluation DTO:**
   ```php
   new PromotionEvaluation(matchedItems, matchedSubtotalCents, matchedQuantity)
   ```

### Step 12.4: Strategy eligibility check

```php
if (!$strategy->eligible($promotion, $cart, $subtotalCents, $evaluation)) {
    return null;
}
```

**AbstractPromotionStrategy::eligible():**

```php
public function eligible(Promotion $promotion, Cart $cart, int $subtotal, PromotionEvaluation $evaluation): bool
{
    if (!$promotion->isValid()) {
        return false;  // status, dates, usage limit
    }

    $minimumCents = (int) round(((float) ($promotion->minimum_order_amount ?? 0)) * 100);
    if ($evaluation->matchedSubtotalCents < $minimumCents) {
        return false;  // minimum order amount not met
    }

    return $promotion->isRequiredQuantityTrue($evaluation->matchedQuantity);
}
```

**Eligibility checks (ALL must pass):**

| Check | Condition |
|---|---|
| isValid() | status = true, start_at ≤ today, end_at ≥ today, limiter null OR usage < limiter |
| minimum_order_amount | matchedSubtotalCents >= minimum_order_amount_cents |
| required_quantity_type | null (always passes) OR matchedQuantity >= required_quantity_type |

### Step 12.5: GiftPromotionStrategy extra check

For gift promotions, also checks:
```php
$promotion->giftProducts->isNotEmpty();
```

### Step 12.6: Compute outcome (read-only)

```php
$outcome = $strategy->computeOutcome($promotion, $cart, $subtotalCents, $evaluation);
```

---

## 13. Promotion Strategy: computeOutcome

### PercentagePromotionStrategy

```php
public function computeOutcome(Promotion $promotion, Cart $cart, int $subtotal, PromotionEvaluation $evaluation): PromotionOutcome
{
    $amountDecimal = $promotion->discountAmount(
        $evaluation->matchedSubtotalCents / 100.0,
        $evaluation->matchedQuantity
    );
    $amountCents = (int) round($amountDecimal * 100);
    return new DiscountOutcome($amountCents, $evaluation->matchedSubtotalCents);
}
```

### FixedPromotionStrategy

```php
// Identical logic - calls promotion->discountAmount() which handles fixed rate
$amountDecimal = $promotion->discountAmount(
    $evaluation->matchedSubtotalCents / 100.0,
    $evaluation->matchedQuantity
);
$amountCents = (int) round($amountDecimal * 100);
return new DiscountOutcome($amountCents, $evaluation->matchedSubtotalCents);
```

### Promotion::discountAmount() (shared by both strategies)

```php
public function discountAmount(float $price, int $qty = 1): float
{
    if ($price === null || $price <= 0) return 0.0;
    if (!$this->isRequiredQuantityTrue($qty)) return 0.0;

    $price = (float) $price;
    $value = (float) ($this->discount ?? $this->value);
    $maxValue = $this->max_discount_amount !== null ? (float) $this->max_discount_amount : null;

    if ($this->isPercentagePromotion()) {
        $discount = $price * ($value / 100);
        if ($maxValue !== null) {
            $discount = min($discount, $maxValue);
        }
        return round(max(0.0, $discount), 2);
    }

    if ($this->isFixedRatePromotion()) {
        return round(max(0.0, min($price, $value)), 2);
    }

    if ($this->isGiftPromotion()) return 0.0;
    return 0.0;
}
```

**Percentage formula:**
```
Raw discount = matchedSubtotal × (discount_percent / 100)
Capped by: max_discount_amount if set
Final: max(0, discount)
```

**Fixed rate formula:**
```
discount = min(matchedSubtotal, fixed_value)
```

### GiftPromotionStrategy

```php
public function computeOutcome(Promotion $promotion, Cart $cart, int $subtotal, PromotionEvaluation $evaluation): PromotionOutcome
{
    $giftItems = $promotion->giftProducts
        ->map(function ($product) {
            $variantId = (int) ($product->pivot->product_variant_id ?? 0);
            $variant = $variantId ? $this->resolveVariant($product, $variantId) : null;

            if ($variantId && (!$variant || (int) ($variant->available_stock ?? 0) <= 0)) {
                return null;
            }

            if (!$variantId && !$this->hasAvailableStock($product)) {
                return null;
            }

            $quantity = max(1, (int) ($product->pivot->quantity ?? 1));

            return new GiftItem(
                (int) $product->id,
                $variantId > 0 ? $variantId : null,
                $variantPayload,
                (string) $product->name,
                (string) $product->sku,
                $product->getFirstMediaUrl('products'),
                $quantity,
                0,  // price_cents = 0
                true // is_gift
            );
        })
        ->filter()
        ->values()
        ->all();

    return new GiftOutcome($giftItems);
}
```

Gift promotions: discount = 0, gift items at price_cents = 0.

### Step 13.1: Build PromotionResult

```php
if ($outcome instanceof DiscountOutcome) {
    return new PromotionResult(
        $promotion,
        $outcome->amountCents / 100.0,  // convert cents back to decimal
        $giftItems,
        $evaluation->matchedSubtotalCents
    );
}
```

---

## 14. PromotionApplicator: applyOutcome

**File:** `PromotionApplicator.php`

This is where the discount is **actually written to the cart**.

```php
public function applyOutcome(Cart $cart, Promotion $promotion, PromotionOutcome $outcome, ?string $shippingMethod = null): array
{
    return DB::transaction(function () use ($cart, $promotion, $outcome, $shippingMethod) {
        // Lock promotion
        $lockedPromotion = Promotion::whereKey($promotion->id)->lockForUpdate()->first();
        // Lock cart with items
        $cart = Cart::whereKey($cart->id)->lockForUpdate()->with(['items'])->firstOrFail();

        // Re-evaluate matched items at apply time
        $subtotalCents = (int) round($cart->items
            ->reject(fn($i) => (bool) ($i->is_gift ?? false))
            ->sum(fn($i) => ((float) ($i->price ?? 0)) * ((int) ($i->quantity ?? 0))) * 100);

        $evaluation = $this->resolver->matchedEligibility($cart, $promotion, $subtotalCents);
```

### DiscountOutcome: Proportional Allocation

```php
if ($outcome instanceof DiscountOutcome) {
    $amountCents = min($subtotalCents, $outcome->amountCents);
    $baseCents = max(0, $outcome->baseAmountCents);

    $matchedItems = $evaluation->matchedItems;

    // Build lines array with line totals in cents
    $lines = $matchedItems->map(function ($item) {
        $baseLineTotal = ((float) ($item->price ?? 0)) * ((int) ($item->quantity ?? 0));
        $lineTotalCents = (int) round(
            (($baseLineTotal > 0 ? $baseLineTotal : (float) ($item->total_price ?? 0))) * 100
        );
        return ['item' => $item, 'line_total_cents' => $lineTotalCents];
    })->values();

    $sumLineCents = $lines->sum(fn($l) => $l['line_total_cents']);
```

**LARGEST REMAINDER METHOD for fair allocation:**

```php
$allocations = [];
$allocatedSum = 0;
$remainders = [];

foreach ($lines as $index => $entry) {
    $line = $entry['line_total_cents'];
    $exactShare = ($line * $amountCents) / $sumLineCents;
    $floorShare = (int) floor($exactShare);
    $allocations[$index] = min($floorShare, $line);
    $allocatedSum += $allocations[$index];
    $remainders[$index] = $exactShare - $floorShare;
}

// Distribute remaining cents by largest remainder
$remaining = $amountCents - $allocatedSum;
arsort($remainders);
foreach ($remainders as $idx => $rem) {
    if ($remaining <= 0) break;
    $available = $lines[$idx]['line_total_cents'] - $allocations[$idx];
    if ($available <= 0) continue;
    $give = min($available, 1);
    $allocations[$idx] += $give;
    $remaining -= $give;
}
```

**Allocation formula (per line):**
```
exactShare_i = (lineTotalCents_i / sumLineCents) × totalDiscountCents
floorShare_i = floor(exactShare_i)
remainder_i = exactShare_i - floorShare_i

Allocate floor shares first. Then distribute remaining cents
one-by-one to items with largest remainder.
```

**Persist to database:**

```php
foreach ($lines as $index => $entry) {
    $item = $entry['item'];
    $lineTotalCents = $entry['line_total_cents'];
    $alloc = $allocations[$index] ?? 0;
    $alloc = max(0, min($alloc, $lineTotalCents));

    $newTotalPrice = ($lineTotalCents - $alloc) / 100.0;

    $item->forceFill([
        'promotion_id' => $promotion->id,
        'discount_amount' => number_format($alloc / 100.0, 2, '.', ''),
        'total_price' => number_format($newTotalPrice, 2, '.', ''),
    ])->save();
}
```

**Database UPDATE per cart item:**
```
UPDATE cart_items SET 
    promotion_id = ?,
    discount_amount = ?,
    total_price = ?
WHERE id = ?
```

**Cart total update:**
```php
$discountedSubtotalCents = sum of (lineTotalCents - alloc) for all lines
$cart->forceFill(['total_price' => round($discountedSubtotalCents / 100.0, 2)])->save();
```

**Database UPDATE:**
```
UPDATE carts SET total_price = ? WHERE id = ?
```

### GiftOutcome: Reserve Gift Items

```php
if ($outcome instanceof GiftOutcome) {
    foreach ($outcome->giftItems as $gift) {
        $product = Product::query()->whereKey($gift->productId)->lockForUpdate()->first();
        if (!$product) continue;
        $item = $this->inventoryService->reserveGiftItem(
            $cart, $product, $promotion,
            max(1, (int) $gift->quantity),
            $gift->productVariantId, $shippingMethod
        );
        $reserved[] = $item->id;
        break;  // Only ONE gift item per promotion
    }
}
```

gift item price = 0, total_price = 0.

### Step 14.1: Return to PromotionService

```php
$amountCents = (int) round((float) ($result->discount ?? 0) * 100);

if ($amountCents > 0) {
    $discountOutcome = new DiscountOutcome($amountCents, $result->matchedSubtotalCents);
    $discountDetails = $this->applicator->applyOutcome($cart, $promotion, $discountOutcome);
    // Refresh cart
    $cart->refresh();
    $cart->load(['items' => ...]);
}
```

### Step 14.2: Build CheckoutTotals and return

```php
return new CheckoutTotals(
    subtotal: round((float) $subtotal, 2),
    promotionDiscount: round((float) ($discountDetails['discount'] ?? 0), 2),
    couponDiscount: 0,
    finalTotal: round(
        (float) $cart->items
            ->reject(fn($item) => (bool) ($item->is_gift ?? false))
            ->sum('total_price'),
        2
    ),
    promotion: $result ? [
        'id' => $result->promotion->id,
        'type' => $result->promotion->type_amount,
        'code' => $result->promotion->code,
    ] : null,
    giftItems: $giftDetails['gift_items'] ?? [],
);
```

**finalTotal after promotion:**
```
finalTotal = sum of cart_items.total_price for non-gift items
(where total_price already has promotion discount subtracted)
```

---

## 15. Coupon Calculation

**Back in OrderService::calculateCheckoutTotals()** (line 436):

```php
$priceAfterPromotion = $promotionTotals->finalTotal;
$couponResult = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
$finalTotal = round(max(0, (float) $couponResult['finalPrice']), 2);
```

**Coupon is applied to the price AFTER promotion.**
Coupon discount = percentage or fixed of `$priceAfterPromotion`.

**Stacking order (verified):**
```
1. Flash Sale → product price (applied at pricing layer, not here)
2. Product Discount → product price
3. Promotion → applied proportionally to cart items
4. Coupon → applied to priceAfterPromotion (finalTotal)
5. Shipping → added after coupon
```

### Step 15.1: Minimum order check

```php
$minimumOrderAmount = (float) (Settings::first()?->minimum_order_amount ?? 0);
if ($minimumOrderAmount > 0 && $checkoutTotals->subtotal < $minimumOrderAmount) {
    DB::rollBack();
    throw new \InvalidArgumentException(...);
}
```

**Uses subtotal** (price × quantity before any discounts) **NOT finalTotal** for minimum check.

---

## 16. Shipping Price Resolution

### Step 16.1: Build order data array

```php
$orderData = $request->only(array_merge($this->dataArray, [
    'fulfillment_type', 'payment_method', 'payment_gateway', 'pickup_location_id',
]));
$orderData['user_id'] = $request->user()->id;
```

### Step 16.2: Resolve shipping price

**File:** `OrderService.php:302`

```php
private function resolveShippingPrice(?int $governorateId): array
{
    if (!$governorateId) {
        return ['price' => 0, 'free_shipping_over' => null, 'governorate_id' => null];
    }

    $governorate = Governorate::query()->where('id', $governorateId)->where('status', true)->first();
    if (!$governorate) {
        return ['price' => 0, 'free_shipping_over' => null, 'governorate_id' => null];
    }

    $shippingPrice = $governorate->shippingPrice()
        ->where('status', true)
        ->first();

    if (!$shippingPrice) {
        return ['price' => 0, 'free_shipping_over' => null, 'governorate_id' => $governorateId];
    }

    return [
        'price' => (float) $shippingPrice->price,
        'free_shipping_over' => $shippingPrice->free_shipping_over !== null ? (float) $shippingPrice->free_shipping_over : null,
        'governorate_id' => $governorateId,
    ];
}
```

**Database READ:**
```sql
SELECT * FROM governorates WHERE id = ? AND status = 1
SELECT * FROM shipping_prices WHERE governorate_id = ? AND status = 1
```

### Step 16.3: Free shipping by threshold

```php
$shippingPrice = $this->resolveFreeShippingByThreshold(
    $checkoutTotals->subtotal, $shippingInfo['free_shipping_over'], $shippingInfo['price']
);
```

```php
public function resolveFreeShippingByThreshold(float $subtotal, ?float $freeShippingOver, float $shippingPrice): float
{
    if ($freeShippingOver !== null && $subtotal > $freeShippingOver) {
        return 0;
    }
    return $shippingPrice;
}
```

**Formula:**
```
if freeShippingOver is set AND subtotal > freeShippingOver:
    shipping = 0
else:
    shipping = shippingPrice
```

### Step 16.4: Free shipping by coupon

```php
if ($freeShippingCoupon) {
    $shippingPrice = 0;
}
```

FREE_SHIPPING coupon overrides any shipping price.

---

## 17. OrderCreationService: createOrder

**File:** `OrderCreationService.php:27`

```php
public function createOrder(
    array $orderData, Cart $cart, CheckoutTotals $checkoutTotals,
    ?string $shippingMethod = null, ?\DateTime $eta = null, ?float $fastShippingFee = null,
    ?float $shippingPrice = null, ?int $governorateId = null
): ?Order
```

### Total price calculation:

```php
$shippingPrice = $shippingPrice ?? 0;
$totalPrice = round((float) $checkoutTotals->finalTotal + $shippingPrice + ($fastShippingFee ?? 0), 2);
```

**Grand total formula:**
```
grandTotal = finalTotal + shippingPrice + fastShippingFee
```

### Step 17.1: Pickup location snapshot

```php
$pickupLocationId = $orderData['pickup_location_id'] ?? null;
$pickupSnapshot = $this->resolvePickupLocationSnapshot($pickupLocationId);
```

Takes a snapshot of pickup location data (name, address, phone, coordinates) at order time. Even if the pickup location changes later, the order retains the original values.

### Step 17.2: INSERT into orders table

```php
$order = Order::create([
    'user_id'                    => $orderData['user_id'],
    'governorate_id'             => $governorateId,
    'name'                       => $orderData['name'],
    'user_phone'                 => $orderData['user_phone'],
    'user_email'                 => $orderData['user_email'],
    'address'                    => $orderData['address'],
    'notes'                      => $orderData['notes'],
    'shipping_method'            => ShippingMethod::SCHEDULED,
    'expected_delivery_at'       => null,
    'fast_shipping_fee'          => 0,
    'fulfillment_type'           => $orderData['fulfillment_type'] ?? 'delivery',
    'payment_method'             => $orderData['payment_method'] ?? 'online',
    'payment_gateway'            => $orderData['payment_gateway'] ?? null,
    'pickup_location_id'         => $pickupLocationId,
    'pickup_location_name'       => $pickupSnapshot['name'],
    'pickup_location_address'    => $pickupSnapshot['address'],
    'pickup_location_phone'      => $pickupSnapshot['phone'],
    'pickup_location_coordinates'=> $pickupSnapshot['coordinates'],
    'price'                      => $checkoutTotals->subtotal,
    'shipping_price'             => $shippingPrice,
    'total_price'                => $totalPrice,
    'coupon'                     => $checkoutTotals->coupon ?? $cart->coupon,
    'coupon_discount'            => $checkoutTotals->couponDiscount ?: null,
    'coupon_discount_type'       => $checkoutTotals->couponDiscountType,
    'coupon_discount_max_amount' => $checkoutTotals->couponDiscountMaxAmount,
    'promotion_id'               => $checkoutTotals->promotionId(),
    'promotion_code'             => $checkoutTotals->promotionCode(),
    'promotion_type'             => $checkoutTotals->promotionType(),
    'promotion_discount'         => $checkoutTotals->promotionDiscount,
    'status'                     => 'pending',
]);
```

**Database INSERT — orders table:**

| Column | Source | Value Example |
|---|---|---|
| `user_id` | auth user | 5 |
| `price` | `checkoutTotals->subtotal` | 500.00 |
| `shipping_price` | resolved shipping | 50.00 |
| `total_price` | `finalTotal + shipping + fastFee` | 550.00 |
| `coupon` | `checkoutTotals->coupon` | "SAVE10" or null |
| `coupon_discount` | couponDiscount | 40.00 or null |
| `coupon_discount_type` | coupon discount_type | "percentage" |
| `coupon_discount_max_amount` | coupon max_discount_amount | 100.00 or null |
| `promotion_id` | promotionId() | 3 or null |
| `promotion_code` | promotionCode() | "SUMMER" or null |
| `promotion_type` | promotionType() | "percentage" or null |
| `promotion_discount` | promotionDiscount | 100.00 or null |
| `status` | hardcoded | "pending" |

---

## 18. OrderCreationService: createOrderItems

**File:** `OrderCreationService.php:117`

```php
public function createOrderItems(Order $order, Cart $cart): bool
{
    foreach ($cart->items as $item) {
        $quantity = max(1, (int) ($item->quantity ?? 0));
        $lineTotal = (float) ($item->total_price ?? 0);
        $effectiveUnitPrice = $quantity > 0 ? $lineTotal / $quantity : 0;
        $promotionDiscountAmount = round(max(0, ((float) ($item->price ?? 0) * $quantity) - $lineTotal), 2);

        $product = $item->product ?? null;
        $variant = $item->productVariant ?? null;
        $productName = $product->name ?? 'No Name';
        $productSku = $product->sku ?? null;

        // Recalculate flash sale and product discount for snapshot
        $pricingService = app(\Marvel\Services\Pricing\ProductPricingService::class);
        $flashSale = $pricingService->resolveActiveFlashSale($product);

        if ($variant && $variant->price !== null) {
            $basePrice = (float) $variant->price;
            $flashSalePrice = $pricingService->calculateFlashSalePrice($flashSale, $basePrice);
            $discountPrice = $flashSalePrice === null && $product->has_discount && $pricingService->isDiscountActive($product)
                ? $pricingService->calculateDiscountedPrice($basePrice, $product->discount_type ?? 'percentage', $product->discount_amount ?? 0)
                : null;
        } else {
            $pricing = $pricingService->calculateProductPricing($product, $flashSale);
            $flashSalePrice = $pricing['price_after_flash_sale'];
            $discountPrice = $flashSalePrice === null && $product->has_discount && $pricingService->isDiscountActive($product)
                ? $pricingService->calculateDiscountedPrice($product->price, $product->discount_type ?? 'percentage', $product->discount_amount ?? 0)
                : null;
        }

        $orderItem = $order->orderItems()->create([
            'product_id'                => $item->product_id,
            'product_variant_id'        => $item->product_variant_id,
            'product_name'              => $productName,
            'product_quantity'          => $quantity,
            'product_price'             => $effectiveUnitPrice,
            'product_total_price'       => round($lineTotal, 2),
            'product_sku'               => $productSku,
            'product_flash_sale_price'  => $flashSalePrice,
            'product_discount_price'    => $discountPrice,
            'promotion_discount_amount' => $promotionDiscountAmount,
            'attributes'                => $item->attributes ?? null,
            'is_gift'                   => (bool) ($item->is_gift ?? false),
            'promotion_id'              => $item->promotion_id,
        ]);
    }
    return true;
}
```

### Order item field breakdown:

| Column | Formula | Example |
|---|---|---|
| `product_id` | `item->product_id` | 42 |
| `product_variant_id` | `item->product_variant_id` | null or 7 |
| `product_name` | `$product->name` | "Gaming Laptop" |
| `product_sku` | `$product->sku` | "PRD-042" |
| `product_quantity` | `max(1, (int) item->quantity)` | 2 |
| `product_price` | **`lineTotal / quantity`** (unit price after promotion) | 200.00 |
| `product_total_price` | `round(lineTotal, 2)` (total after promotion, per item) | 400.00 |
| `product_flash_sale_price` | Recalculated from pricing service | 450.00 or null |
| `product_discount_price` | Recalculated from pricing service | 425.00 or null |
| `promotion_discount_amount` | `max(0, (price * qty) - lineTotal)` | 100.00 |
| `attributes` | `item->attributes` | [{"attribute":"Color","value":"Red"}] |
| `is_gift` | `(bool) (item->is_gift ?? false)` | false |
| `promotion_id` | `item->promotion_id` | 3 or null |

### Snapshot values explanation:

| Field | Why it's stored |
|---|---|
| `product_name` | Product may be renamed later; order must show the name at purchase time |
| `product_price` | Unit price after ALL discounts (promotion); may differ from current product price |
| `product_total_price` | Line total after ALL discounts; used for accounting |
| `product_flash_sale_price` | Price after flash sale (before promotion); snapshot for transparency |
| `product_discount_amount` | Price after product discount (before promotion); snapshot |
| `promotion_discount_amount` | How much promotion discount was applied to THIS item specifically |
| `attributes` | Variant attributes at purchase time (e.g. "Size: Large") |

**CRITICAL NOTE:** `product_flash_sale_price` and `product_discount_price` are **recalculated** in `createOrderItems` using the pricing service at that moment. This means they could differ from what was shown on the product page if prices changed between adding to cart and checkout. However, `product_price` and `product_total_price` are taken from the cart item values, which were refreshed in `refreshCartItemPrices()`.

---

## 19. OrderCreationService: finalizeOrder

```php
public function finalizeOrder(Order $order, CheckoutTotals $checkoutTotals): void
{
    try {
        OrderCreated::dispatch($order);
    } catch (\Throwable $e) {
        report($e);
    }
}
```

Fires the `OrderCreated` event (see [Events & Listeners](#26-events--listeners) section).

### Step 19.1: Commit

```php
DB::commit();
```

All database writes become permanent.

### Step 19.2: Load and return

```php
return $order->load(['orderItems.product', 'orderItems.productVariant']);
```

---

## 20. Return to Controller - Payment Dispatch

Back in `OrderController::checkout()`:

```php
if ($paymentMethod === 'online') {
    $orderPrice = round((float) $order->total_price, 2);
    if ($orderPrice <= 0) {
        return $this->apiResponse(FILED_TO_CREATE_ORDER_TRY_AGAIN, 500, false);
    }
    return $this->paymentCheckoutHandler->handleOnlinePayment($request, $order, $orderPrice, $gateway);
}

if ($paymentMethod === 'cod') {
    return $this->paymentCheckoutHandler->handleCodPayment($request, $order);
}

if ($paymentMethod === 'pay_at_cashier') {
    return $this->paymentCheckoutHandler->handleCashierQrPayment($request, $order);
}

return $this->apiResponse(INVALID_PAYMENT_METHOD, 422, false);
```

---

## 21. Online Payment Flow

**File:** `PaymentCheckoutHandler.php:25`

```php
public function handleOnlinePayment(
    Request $request, Order $order, float $amount, string $gateway,
    ?string $callbackUrl = null, ?string $errorUrl = null
): JsonResponse
```

### Step 21.1: Create gateway instance

```php
$gatewayInstance = $this->paymentGatewayFactory->make($gateway);
```

Factory resolves: `'myfatoorah'` → `MyFatoorahGateway`

### Step 21.2: Build callback URLs

```php
$callbackUrl ??= route('api.checkout.callback');
$errorUrl ??= route('api.checkout.errorCallback');
```

### Step 21.3: Create invoice with gateway

```php
$result = $gatewayInstance->createInvoice($order, $amount, $callbackUrl, $errorUrl);
```

**MyFatoorahGateway::createInvoice():**

1. Builds payload:
```php
[
    'InvoiceValue' => $amount,
    'CustomerName' => $order->name,
    'NotificationOption' => 'LNK',
    'DisplayCurrencyIso' => 'EGP',
    'MobileCountryCode' => '+20',
    'CustomerMobile' => $order->user_phone,
    'CustomerEmail' => $order->user_email,
    'language' => 'en',
    'CallBackUrl' => $callbackUrl,
    'ErrorUrl' => $errorUrl,
]
```

2. Calls `MyfatoraService::createInvoice($data)` → API call to MyFatoorah
3. Extracts `InvoiceURL` and `InvoiceId` from response

**Returns GatewayResult:**
```php
new GatewayResult(
    success: true,
    redirectUrl: $invoiceUrl,
    gatewayTransactionId: (string) $invoiceId,
    status: 'pending',
    rawResponse: $response,
);
```

### Step 21.4: Create transaction record

```php
$transaction = Transaction::create([
    'order_id'              => $order->id,
    'user_id'               => $request->user()->id,
    'invoice_id'            => $result->gatewayTransactionId,
    'payment_method'        => $gateway,
    'status'                => 'pending',
    'amount'                => $amount,
    'currency'              => config('payment.default_currency', 'EGP'),
    'gateway_transaction_id'=> $result->gatewayTransactionId,
    'gateway_response'      => $result->rawResponse,
]);
```

**Database INSERT — transactions table:**

| Column | Value |
|---|---|
| `order_id` | Order ID |
| `user_id` | Auth user ID |
| `invoice_id` | MyFatoorah InvoiceId |
| `uuid` | Auto-generated UUID |
| `payment_method` | `"myfatoorah"` |
| `status` | `"pending"` |
| `amount` | `$order->total_price` |
| `currency` | `"EGP"` |
| `gateway_transaction_id` | Same as invoice_id |
| `gateway_response` | Raw API response array |

### Step 21.5: Return redirect URL to client

```php
return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, ['url' => $result->redirectUrl]);
```

**Response to client:**
```json
{
    "success": true,
    "message": "Checkout successful",
    "data": {
        "url": "https://myfatoorah.com/InvoicePay/..."
    }
}
```

The client redirects the user to the MyFatoorah payment page.

---

## 22. COD Payment Flow

**File:** `PaymentCheckoutHandler.php:77`

```php
public function handleCodPayment(Request $request, Order $order): JsonResponse
{
    $transaction = Transaction::create([
        'order_id'       => $order->id,
        'user_id'        => $request->user()->id,
        'payment_method' => 'cod',
        'status'         => 'pending',
        'amount'         => $order->total_price,
        'currency'       => config('payment.default_currency', 'EGP'),
    ]);

    return $this->apiResponse(__('checkout.cod_success'), 200, true, [
        'order_id' => $order->id,
    ]);
}
```

**Database INSERT — transactions table:**
```
INSERT INTO transactions (order_id, user_id, payment_method, status, amount, currency, uuid)
VALUES (?, ?, 'cod', 'pending', ?, 'EGP', ?)
```

**NO inventory finalization happens here.** The cart is still active with reserved stock.

**COD order remains in `pending` status** until an admin manually calls `markCodAsPaid()`.

---

## 23. Cashier/QR Payment Flow

**File:** `PaymentCheckoutHandler.php:97`

```php
public function handleCashierQrPayment(Request $request, Order $order): JsonResponse
{
    $transaction = Transaction::create([
        'order_id'       => $order->id,
        'user_id'        => $request->user()->id,
        'payment_method' => 'pay_at_cashier',
        'status'         => 'pending',
        'amount'         => $order->total_price,
        'currency'       => config('payment.default_currency', 'EGP'),
    ]);

    $qrDataUri = $this->cashierQrService->generateBase64DataUri($transaction);

    return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
        'order_id'        => $order->id,
        'transaction_uuid'=> $transaction->uuid,
        'qr_code'         => $qrDataUri,
    ]);
}
```

**Database INSERT — transactions table:**
```
INSERT INTO transactions (order_id, user_id, payment_method, status, amount, currency, uuid)
VALUES (?, ?, 'pay_at_cashier', 'pending', ?, 'EGP', ?)
```

QR code contains the transaction UUID. Cashier scans it to mark paid.

**Also no inventory finalization** until `markCashierPaid()` is called.

---

## 24. Payment Callback Flow

**File:** `OrderController.php:170`

```php
public function checkoutCallback(Request $request)
```

**Triggered by:** MyFatoorah redirecting the user back to `api.checkout.callback` URL.

### Step 24.1: Get payment ID

```php
$paymentId = $request->query('paymentId', $request->input('paymentId'));
if (!$paymentId) {
    return $this->apiResponse(MISSING_PAYMENT_ID, 400, false);
}
```

### Step 24.2: Find transaction

```php
$transaction = Transaction::where('gateway_transaction_id', $paymentId)
    ->orWhere('invoice_id', $paymentId)
    ->first();

$gatewayName = $transaction?->payment_method ?? 'myfatoorah';
```

### Step 24.3: Create gateway and verify payment

```php
$gateway = $this->paymentGatewayFactory->make($gatewayName);
$result = $gateway->verifyPayment($paymentId);
```

**MyFatoorahGateway::verifyPayment():**
1. Calls `MyfatoraService::checkInvoice()` with `Key = paymentId, KeyType = 'PaymentId'`
2. Checks `InvoiceStatus === 'Paid'`
3. Returns `GatewayResult` with success, amount, currency, status

### Step 24.4: Handle failure

```php
if (!$result->success) {
    $transaction->update([
        'status' => $result->status ?? 'failed',
        'gateway_response' => $result->rawResponse,
        'error_message' => $result->errorMessage,
    ]);
    event(new PaymentFailed($order));
    // Redirect to frontend payment/failed page
}
```

**Database UPDATE (on failure):**
```
UPDATE transactions SET status = 'failed', gateway_response = ?, error_message = ? WHERE id = ?
```

### Step 24.5: Amount/currency mismatch check

```php
if ($result->amount !== null && abs((float) $result->amount - (float) $order->total_price) > 0.01) {
    $hasMismatch = true;  // Difference > 1 cent
}

if (!$hasMismatch && $result->currency !== null && $result->currency !== 'EGP') {
    $hasMismatch = true;
}
```

**If mismatch detected:**
- Transaction updated with error_message
- `PaymentFailed` event fired
- Redirect to payment/failed page

### Step 24.6: Process successful payment (with locks)

```php
DB::transaction(function () use ($order, $transaction, $paymentId, $verifiedInvoiceId, $result, &$processed) {
    // Lock transaction row
    $lockedTransaction = Transaction::where('gateway_transaction_id', $paymentId)
        ->orWhere('invoice_id', $paymentId)
        ->lockForUpdate()
        ->first();

    // Lock order row
    $lockedOrder = $lockedTransaction->order()->lockForUpdate()->first();

    // Idempotency check: skip if already processed
    if ($lockedTransaction->status === 'paid' && $lockedOrder->status === 'completed') {
        return;  // Already processed, skip
    }

    // UPDATE transaction to paid
    $lockedTransaction->update([
        'status' => 'paid',
        'gateway_response' => $result->rawResponse,
        'error_message' => $result->errorMessage,
        'paid_at' => now(),
    ]);

    // Finalize inventory (SCHEDULED items)
    if ($user = User::find($lockedOrder->user_id)) {
        $cart = $this->cartInventoryService->getActiveCartForUser($user);
        if ($cart) {
            $shippingMethod = $lockedOrder->shipping_method ?? ShippingMethod::SCHEDULED;
            $this->cartInventoryService->finalizeItemsByShippingMethod($cart, $shippingMethod);
        }
    }

    // Finalize promotion usage
    $this->orderService->finalizePromotionUsageAfterPayment($lockedOrder);

    // Change order status to completed
    $this->orderService->changeOrderStatus($lockedTransaction->invoice_id, 'completed');

    $processed = true;
});
```

### Step 24.7: finalizeItemsByShippingMethod

**File:** `CartInventoryService.php:235`

```php
public function finalizeItemsByShippingMethod(Cart $cart, string $shippingMethod): bool
{
    return DB::transaction(function () use ($cart, $shippingMethod) {
        $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

        $items = CartItem::where('cart_id', $cart->id)
            ->where('shipping_method', $shippingMethod)
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if ($item->reserved_quantity > 0) {
                $stock = $this->lockInventoryRowByItem($item);
                $this->finalizeStock($stock, (int) $item->reserved_quantity);
            }
            $item->delete();
        }

        $remainingItems = CartItem::where('cart_id', $cart->id)->count();
        if ($remainingItems === 0) {
            $cart->update(['status' => 'checked_out', 'expires_at' => null, 'reserved_at' => null, 'total_price' => 0]);
        } else {
            $cart->update(['total_price' => CartItem::where('cart_id', $cart->id)->sum('total_price')]);
        }
    });
}
```

**finalizeStock() logic:**
```php
private function finalizeStock($stock, int $quantity): void
{
    $reservedQuantity = (int) ($stock->reserved_quantity ?? 0);
    $physicalQuantity = (int) ($stock->stock_quantity ?? 0);

    if ($reservedQuantity < $quantity) throw new Exception('reserved insufficient');
    if ($physicalQuantity < $quantity) throw new Exception('physical insufficient');

    $stock->stock_quantity = $physicalQuantity - $quantity;
    $stock->reserved_quantity = $reservedQuantity - $quantity;
    $stock->sold_quantity = (int) ($stock->sold_quantity ?? 0) + $quantity;
    $stock->in_stock = $this->getAvailableStock($stock) > 0;
    $stock->save();
}
```

**Database UPDATE — product (finalize):**
```
UPDATE products SET 
    stock_quantity = stock_quantity - ?, 
    reserved_quantity = reserved_quantity - ?,
    sold_quantity = sold_quantity + ?,
    in_stock = ?
WHERE id = ?
```

**Database DELETE:** cart_items are deleted. Cart becomes `checked_out`.

### Step 24.8: finalizePromotionUsageAfterPayment

```php
public function finalizePromotionUsageAfterPayment(Order $order): void
{
    $promotionId = $order->promotion_id ? (int) $order->promotion_id : null;
    if ($promotionId) {
        $this->promotionService->incrementUsage($promotionId);
    }
}
```

**Database UPDATE:**
```
UPDATE promotions SET usage = usage + 1 WHERE id = ? AND (limiter IS NULL OR usage < limiter)
```

### Step 24.9: changeOrderStatus

This is covered in detail in the next section.

### Step 24.10: Fire PaymentSucceeded event

```php
if ($processed) {
    event(new PaymentSucceeded($order->fresh()));
}
```

---

## 25. Order Status Change

**File:** `OrderService.php:495`

```php
public function changeOrderStatus($invoiceId, $status, $orderId = null)
{
    return DB::transaction(function () use ($invoiceId, $status, $orderId) {
        // Find order by invoice ID or order ID
        if ($invoiceId) {
            $transaction = Transaction::where('invoice_id', $invoiceId)->first();
            if ($transaction) {
                $order = $transaction->order()->lockForUpdate()->first();
            }
        }

        // State machine validation
        $previousStatus = $order->status;
        if (!$this->canTransitionOrderStatus($previousStatus, $status)) {
            throw new \RuntimeException('Invalid status transition');
        }

        // UPDATE order
        $order->update(['status' => $status]);

        // On completed: record coupon usage
        if ($status === 'completed') {
            $this->recordCouponUsage($order);
        }

        // Sync transaction status
        if ($transaction) {
            if ($status === 'completed') {
                $transaction->update(['status' => 'paid', 'paid_at' => now()]);
            }
            if ($status === 'cancelled') {
                $transaction->update(['status' => 'failed']);
            }
        }

        // On cancel: decrement promotion usage
        if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
            $this->promotionService->decrementUsage($order->promotion_id);
        }

        event(new OrderStatusChanged($order));

        if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
            event(new OrderCancelled($order));
        }
    });
}
```

### State machine transitions:

```
pending    → pending, processing, completed, cancelled
processing → processing, completed, cancelled
completed  → completed, delivered
delivered  → delivered
cancelled  → cancelled (no transition out)
```

### recordCouponUsage

**File:** `OrderService.php:667`

```php
private function recordCouponUsage($order): void
{
    if (!$order->coupon) return;

    $coupon = Coupon::where('code', $order->coupon)->first();
    if (!$coupon) return;

    $hasAssignments = $coupon->assignments()->exists();

    if ($hasAssignments) {
        // Assigned coupon flow
        $assignment = CouponAssignment::where('coupon_id', $coupon->id)
            ->where('user_id', $order->user_id)
            ->lockForUpdate()
            ->first();

        if ($assignment && $assignment->used < $assignment->max_uses) {
            $coupon->increment('used');
            $assignment->increment('used');
            CouponAssignmentUsage::create([
                'coupon_assignment_id' => $assignment->id,
                'order_id' => $order->id,
                'used_at' => now(),
            ]);

            // Fire event after commit
            DB::afterCommit(fn() => event(new AssignedCouponConsumed(...)));
        }
    } else {
        // Public coupon flow
        $couponUsage = CouponUsage::firstOrCreate(
            ['coupon_id' => $coupon->id, 'user_id' => $order->user_id],
            ['order_id' => $order->id, 'used_at' => now()]
        );
        if ($couponUsage->wasRecentlyCreated) {
            $coupon->increment('used');
        }
    }
}
```

**Policy:** Coupon usage is recorded on payment success. It is NEVER returned on cancellation (prevents abuse).

---

## 26. Events & Listeners

### Event Flow Summary

```
checkout()
  └─ orderCreationService->createOrder()
       └─ OrderCreated::dispatch($order)
            ├─ SendNewOrderNotification (queue: medium)
            │    ├─ Notification::send(admins, NewOrderNotification)
            │    └─ LogActivityJob::dispatch('order_created')
            └─ [Any other listeners registered]

payment callback / COD mark paid / cashier mark paid
  └─ OrderStatusChanged event (inside changeOrderStatus)
       └─ SendOrderStatusChangedNotification (queue: medium)
            └─ LogActivityJob::dispatch('order_status_changed')

  └─ PaymentSucceeded::dispatch($order)
       └─ SendPaymentSucceededNotification (queue: medium)
            └─ LogActivityJob::dispatch('payment_succeeded')

  └─ If cancelled:
       OrderCancelled::dispatch($order)
         └─ SendOrderCancelledNotification (queue: medium)
              └─ LogActivityJob::dispatch('order_cancelled')

  └─ If cancelled (via RestoreProductInventory listener):
       RestoreProductInventory (queue: medium) — listens for OrderCancelled
         └─ Restores stock_quantity and sold_quantity for each order item

Payment failure:
  └─ PaymentFailed::dispatch($order)
       └─ SendPaymentFailedNotification (queue: medium)
            └─ LogActivityJob::dispatch('payment_failed')
```

### All Events

| Event | Dispatched By | Listeners |
|---|---|---|
| `OrderCreated` | `OrderCreationService::finalizeOrder()` | `SendNewOrderNotification` |
| `PaymentSucceeded` | Callback / markCodAsPaid / markCashierPaid | `SendPaymentSucceededNotification` |
| `PaymentFailed` | Callback / errorCallback | `SendPaymentFailedNotification` |
| `OrderStatusChanged` | `changeOrderStatus()` | `SendOrderStatusChangedNotification` |
| `OrderCancelled` | `changeOrderStatus()` on cancel | `SendOrderCancelledNotification`, `RestoreProductInventory` |
| `AssignedCouponConsumed` | `recordCouponUsage()` via `DB::afterCommit` | None registered (fire-and-forget) |

### Listener Details

**RestoreProductInventory (on OrderCancelled):**
- Locks the order row (checks `inventory_restored_at`)
- For each non-gift order item: restores `stock_quantity` (+quantity), `sold_quantity` (-quantity)
- For each variant: same restoration
- Uses `lockForUpdate()` to prevent race conditions
- Only runs once per order (guarded by `inventory_restored_at`)

---

## 27. Financial Consistency Check

### 27.1 Price Chain Verification

```
Product Page Price  ───→  Cart Item Price  ───→  Order Item Price  ───→  Invoice Amount
       │                       │                        │                       │
       ▼                       ▼                        ▼                       ▼
   ProductPricing        refreshCartItemPrices      product_price =          Transaction
   Service               recalculates from          lineTotal / quantity      .amount = 
   calculates            pricing service if                                     order->total_price
   current_price         changed
```

**Verification:**
- Cart price is refreshed at checkout time via `refreshCartItemPrices()`
- Order item `product_price` = `lineTotal / quantity` where `lineTotal` is the cart item's `total_price` (after promotion)
- Transaction `amount` = `order->total_price` = `finalTotal + shipping`
- All values use `float` (decimal), NOT integer cents

**FINDING:** There is a **potential inconsistency** between the product page price and the checkout price if:
1. A flash sale starts/ends between adding to cart and checkout
2. A product discount is activated/deactivated

This is MITIGATED by `refreshCartItemPrices()` which recalculates at checkout time.

### 27.2 No Cents Conversion in Core Path

| Location | Cents Used? | File | Line |
|---|---|---|---|
| Subtotal calculation | YES | `PromotionService.php` | 63 |
| matchedEligibility | YES | `PromotionEligibilityResolver.php` | 116-120 |
| DiscountOutcome | YES (constructor) | `DiscountOutcome.php` | 10 |
| Proportional allocation | YES | `PromotionApplicator.php` | 70-110 |
| CouponCalculator | NO | `CouponCalculator.php` | All |
| Order totals | NO | `OrderCreationService.php` | 30 |
| Shipping resolution | NO | `OrderService.php` | 302 |
| Transaction amount | NO | `PaymentCheckoutHandler.php` | 58 |

**FINDING:** Cents conversion exists ONLY in the promotion engine (internal calculation). It is properly converted back to decimal before persisting:
- `promotion_id` and `discount_amount` stored as decimal in `cart_items`
- `total_price` stored as decimal in `cart_items`
- All order fields stored as decimal

**No precision loss risk:** The conversion pattern is:
```
decimal → cents (int) → calculate → cents (int) → decimal
```

Example: `500.50 × 100 = 50050` → allocate → `50050 / 100 = 500.50`

This pattern is mathematically safe for monetary values up to 2 decimal places.

### 27.3 Stacking Order

```
1. Original product price
       │
2. Flash Sale (if active):
   price_after_flash_sale = flashSale->calcPrice(product->price)
   ↓ Only if flash sale valid. Stored as snapshot in order_item.
       │
3. Product Discount (if active, AND no flash sale):
   discount_price = calcDiscountedPrice(product->price, type, amount)
   ↓ Only if has_discount and isDiscountActive(). Stored as snapshot in order_item.
       │
4. Promotion (if selected):
   → Computed as percentage/fixed of matched subtotal
   → Allocated proportionally to line items
   → Subtracted from cart item total_price
   ↓ promotion_discount_amount stored per order_item.
       │
5. Coupon (if applied):
   → percentage or fixed of (subtotal - promotionDiscount)
   → FREE_SHIPPING coupon overrides shipping cost
   ↓ coupon_discount stored on order.
       │
6. Shipping (additive):
   shipping_price = governorate shipping price
   → Overridden to 0 if free_shipping_threshold met
   → Overridden to 0 if FREE_SHIPPING coupon
       │
7. Grand Total:
   finalTotal + shippingPrice + fastShippingFee
```

### 27.4 Order Snapshot Immutability

Once the Order is created (via `createOrder`), the following fields become SNAPSHOTS:

| Field | Source | Mutated Later? |
|---|---|---|
| `order.price` | `checkoutTotals->subtotal` | NO |
| `order.shipping_price` | resolved at checkout | NO |
| `order.total_price` | grand total at checkout | NO |
| `order.coupon` | from cart | NO |
| `order.coupon_discount` | from calculation | NO |
| `order.promotion_discount` | from calculation | NO |
| `order_item.product_price` | `lineTotal / quantity` | NO |
| `order_item.product_total_price` | `lineTotal` | NO |
| `order_item.product_flash_sale_price` | recalculated | NO |
| `order_item.product_discount_price` | recalculated | NO |
| `order_item.promotion_discount_amount` | from cart | NO |

**Transaction `amount`** is set at transaction creation time. For online payments, it matches `order->total_price`.

---

## 28. Bugs & Issues Found

### ISSUE 1: Potential Race Condition — Cart Query Filters by SCHEDULED Only

**File:** `OrderService.php:153-158`

```php
$cart = Cart::query()
    ->where('user_id', auth()->id())
    ->where('status', 'active')
    ->lockForUpdate()
    ->with(['items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED), ...])
    ->first();
```

This query only loads items with `shipping_method = 'SCHEDULED'`. If the user has items with `FAST` shipping in their cart, those items are completely invisible to the checkout process. They remain in the cart with reserved stock that is never finalized.

**Impact:** Stock reserved for FAST items is never released during this checkout. The user would need a separate checkout flow for FAST items (which doesn't appear to exist). Those items expire after 3 days via the cron job.

**Severity:** LOW (items eventually expire)

### ISSUE 2: `refreshCartItemPrices()` Double-Load Issue

**File:** `OrderService.php:405-434`

The method:
1. `$cart->load(['items.product', 'items.productVariant'])` 
2. Updates items that need price refresh
3. `$cart->refresh()`
4. `$cart->load([...])` again

The final load at step 4 does NOT filter by SCHEDULED shipping method. However, the earlier cart query at step 7.1 only loaded SCHEDULED items. This may be intentional but is worth noting.

### ISSUE 3: Promotional Discount Cap on Line Items

**File:** `PromotionApplicator.php:87`

```php
$allocations[$index] = min($floorShare, $line); // cap to line total
```

Individual line items are capped such that discount cannot exceed that item's total. This means if there are few items, some discount may be lost (allocated sum < total discount). The remaining cents distribution handles 1-cent leftovers, but a scenario where one line total is very small could lose discount.

**Example:** 
- 3 items with line totals: 100, 1, 1 (total: 102)
- Discount: 20 cents
- Allocation: 100 gets floor(100*20/102)=19, 1 gets floor(1*20/102)=0, 1 gets floor(1*20/102)=0
- Remaining: 1 distributed to largest remainder (item 1 @ 0.607)
- Final: 20, 0, 0 — correct (no loss)

**Severity:** NONE (largest remainder method handles this correctly)

### ISSUE 4: `flash_sale_price` and `discount_price` Recalculated in createOrderItems

**File:** `OrderCreationService.php:131-146`

These values are recalculated from scratch at order creation time, not taken from the cart item. This means:
- If a flash sale ends between adding to cart and checkout, `product_flash_sale_price` will be null despite the product being in cart during the flash sale
- The `product_price` (actual charged amount) comes from the cart item `total_price` which WAS refreshed at checkout time

**Impact:** Snapshot values may differ from what the user saw on the product page or cart. This is an INFORMATIONAL discrepancy only — the charged amount (`product_price` / `product_total_price`) is still correct.

**Severity:** LOW (snapshot fields only, not actual charged amounts)

### ISSUE 5: `product_price` Formula in createOrderItems

**File:** `OrderCreationService.php:123`

```php
$effectiveUnitPrice = $quantity > 0 ? $lineTotal / $quantity : 0;
```

This formula divides the post-promotion `total_price` by quantity to get the effective unit price. For gift items (price=0, total_price=0), this correctly produces 0. For promotion-discounted items, it gives the per-unit price AFTER promotion discount.

This is correct.

### ISSUE 6: No INTEGER CENTS in Main Path — VERIFIED

**FINDING:** The project does NOT use integer cents arithmetic for order totals, pricing, or financial storage. All monetary values in the database (`orders.price`, `orders.total_price`, `cart_items.price`, `cart_items.total_price`, `order_products.product_price`, `transactions.amount`) are stored as DECIMAL/floats.

Cents conversion is used ONLY within the promotion engine for allocation precision and is converted back to decimal before storage.

**VERDICT:** NO unnecessary multiplication/division by 100. NO integer money conversion. Correct decimal rounding at all boundaries.

### ISSUE 7: Free Shipping Over Threshold Uses Subtotal (Before Discounts)

**File:** `OrderService.php:288`

```php
public function resolveFreeShippingByThreshold(float $subtotal, ?float $freeShippingOver, float $shippingPrice): float
{
    if ($freeShippingOver !== null && $subtotal > $freeShippingOver) {
        return 0;
    }
    return $shippingPrice;
}
```

Uses `checkoutTotals->subtotal` (price × quantity, BEFORE all discounts). This is the **correct behavior** — free shipping thresholds are typically based on the cart's gross value, not net after promotions.

### ISSUE 8: Minimum Order Check Uses Subtotal (Before Discounts)

**File:** `OrderService.php:197-203`

```php
$minimumOrderAmount = (float) (Settings::first()?->minimum_order_amount ?? 0);
if ($minimumOrderAmount > 0 && $checkoutTotals->subtotal < $minimumOrderAmount) {
```

Also uses `checkoutTotals->subtotal` which is **price × quantity before any discounts**. This means a user cannot bypass the minimum order by using a large coupon.

**This is correct.**

### ISSUE 9: Cart `coupon` Cleared but Not Reloaded

**File:** `OrderService.php:174`

```php
$cart->update(['coupon' => null]);
```

If the coupon is invalid, `$cart->coupon` is set to null in the database, but `$cart` in memory still has `coupon` set to the old value (unless refreshed). However, `calculatePriceByCoupon()` reads `$cart->coupon`, so this could use the stale value.

**Wait** — let me re-read the code. After `$cart->update(['coupon' => null])`, subsequent `$cart->coupon` in the same request would still be the old value (Eloquent doesn't automatically refresh). BUT `calculatePriceByCoupon()` queries the database for the coupon using the code:

```php
$coupon = Coupon::valid()->where('code', $cart->coupon)->first();
```

So if `$cart->coupon` is still the stale value in memory, it would try to find the coupon. But the coupon was just invalidated... Actually wait, the coupon validation happens BEFORE `calculateCheckoutTotals()`, and if the coupon was invalid, it's set to null in the DB. But the in-memory model still has it.

Then `calculatePriceByCoupon()` checks `$cart->coupon` which is the in-memory value. **This IS a bug** — the coupon that was just invalidated would still be used in the calculation.

**Wait, let me trace more carefully:**

```php
$cart->update(['coupon' => null]);  // DB is updated
// ... later ...
$checkoutTotals = $this->calculateCheckoutTotals($cart, ...);
```

Inside `calculateCheckoutTotals()`:
```php
$couponResult = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
```

Inside `calculatePriceByCoupon()`:
```php
if ($cart->coupon === null) {  // <--- reads in-memory, NOT database
    return ['finalPrice' => $totalPrice, ...];
}
$coupon = Coupon::valid()->where('code', $cart->coupon)->first();
```

If `$cart` was refreshed before this point, `coupon` would be null. But looking at the code flow more carefully:

After coupon validation:
```php
$cart->update(['coupon' => null]);
```

Then `$this->calculateCheckoutTotals($cart, ...)` is called. The `$cart` object's `coupon` property may still hold the old value unless `refresh()` was called.

**BUT** — looking at the exact code flow again:

1. `$cart` is loaded at line 153 with `lockForUpdate()`
2. If coupon invalid, line 174: `$cart->update(['coupon' => null])` — this updates DB but doesn't refresh in-memory
3. Lines 182-188 extract promotion info from items
4. Line 190: `calculateCheckoutTotals($cart, ...)`

So yes, `$cart->coupon` in memory would still be the old code, even though DB has null. Then `calculatePriceByCoupon()` would find the coupon by code (it's still valid in DB — it hasn't been deleted), and apply the discount.

**BUT** wait — let me re-check. The coupon was invalidated because validation failed. But the coupon still EXISTS in the database. `Coupon::valid()->where('code', $cart->coupon)->first()` would still return it if the coupon is valid (status, dates, etc.). The _cart-level_ validation failed (e.g., product_not_eligible), not the coupon's own validity.

So the coupon was removed from the cart because the cart's products don't match the coupon's products, but `calculatePriceByCoupon()` would re-apply it because it looks up by code.

**ACTUAL BUG:** `$cart->update(['coupon' => null])` updates the database but the in-memory `$cart->coupon` is stale. When `calculatePriceByCoupon()` checks `$cart->coupon`, it reads the old in-memory value (not null), finds the coupon, and applies it. The coupon discount is calculated despite being "removed".

**Fix:** Call `$cart->refresh()` after `$cart->update(['coupon' => null])`.

**Impact:** If a coupon is invalidated at checkout (e.g., product_not_eligible), it is STILL applied to the calculation because the in-memory model wasn't refreshed.

**Severity:** MEDIUM

### ISSUE 10: No check for inactive/deleted products at checkout

**File:** `OrderService.php:153-163`

The cart items load the `product` relation without checking if the product is active or deleted. If a product was deactivated or soft-deleted between adding to cart and checkout, it will still be processed.

`refreshCartItemPrices()` does check:
```php
if (!$product) {
    continue;  // Skip deleted products — price update skipped
}
```

But the product is still included in the order (quantity, product_id, etc.). The price won't be refreshed but the item still gets ordered.

**Impact:** Users can order products that are no longer active.

**Severity:** LOW (expected behavior in many e-commerce systems — last-price-in-cart wins)

---

### BUG SUMMARY

| # | Severity | File | Description |
|---|---|---|---|
| 1 | LOW | `OrderService.php:157` | Only SCHEDULED items loaded; FAST items ignored |
| 2 | LOW | `OrderCreationService.php:131` | Snapshot values recalculated, not from cart |
| 3 | NONE | Various | Proportional allocation mathematically correct |
| 4 | NONE | Various | No integer cents in storage — decimal only |
| 5 | NONE | Various | Stacking order verified correct |
| 6 | **MEDIUM** | **`OrderService.php:174`** | **`$cart->coupon` in-memory stale after `update(['coupon' => null])` — invalidated coupon still applied** |
| 7 | LOW | `OrderService.php:163` | Deleted/inactive products not filtered out at checkout |

---

## Full Execution Path Diagram

```
POST /checkout
  │
  ├─ Sanctum auth middleware
  │
  ├─ OrderCreateRequest validation
  │
  ├─ OrderController::checkout()
  │    │
  │    ├─ cartInventoryService->getActiveCartForUser()
  │    │    └─ SELECT FROM carts WHERE user_id=? AND status='active'
  │    │
  │    ├─ cartInventoryService->ensureCartReservation()
  │    │    └─ DB::transaction
  │    │         ├─ LOCK carts FOR UPDATE
  │    │         ├─ For each item:
  │    │         │    ├─ LOCK cart_items FOR UPDATE
  │    │         │    ├─ LOCK products/product_variants FOR UPDATE
  │    │         │    └─ UPDATE reserved_quantity
  │    │         └─ UPDATE carts SET reserved_at, expires_at
  │    │
  │    ├─ orderService->addItemsInOrder($request)
  │    │    │
  │    │    └─ DB::beginTransaction()
  │    │         │
  │    │         ├─ LOCK carts FOR UPDATE (with SCHEDULED items)
  │    │         │
  │    │         ├─ refreshCartItemPrices()
  │    │         │    └─ For each non-gift item:
  │    │         │         ├─ Calculate current price via ProductPricingService
  │    │         │         └─ UPDATE cart_items SET price, total_price (if changed)
  │    │         │
  │    │         ├─ Coupon validation
  │    │         │    ├─ LOCK coupons FOR UPDATE
  │    │         │    ├─ CouponAssignmentValidator::validate()
  │    │         │    ├─ CouponValidator::validate()
  │    │         │    └─ UPDATE carts SET coupon=NULL (if invalid)
  │    │         │
  │    │         ├─ calculateCheckoutTotals()
  │    │         │    │
  │    │         │    ├─ promotionService->applySelectedPromotion()
  │    │         │    │    │
  │    │         │    │    ├─ removeGiftItems()
  │    │         │    │    │    └─ releaseItem() for each gift → release stock + delete
  │    │         │    │    │
  │    │         │    │    ├─ Calculate subtotal (price × qty, non-gift)
  │    │         │    │    │
  │    │         │    │    ├─ If no promotion selected:
  │    │         │    │    │    └─ clearPromotionFromCart()
  │    │         │    │    │         └─ UPDATE cart_items SET promotion_id=NULL, discount=0, total_price=price×qty
  │    │         │    │    │
  │    │         │    │    ├─ If promotion selected:
  │    │         │    │    │    ├─ LOCK promotions FOR UPDATE
  │    │         │    │    │    ├─ resolver->resolve()
  │    │         │    │    │    │    ├─ matchedEligibility()
  │    │         │    │    │    │    ├─ strategy->eligible()
  │    │         │    │    │    │    │    └─ AbstractPromotionStrategy::eligible()
  │    │         │    │    │    │    │         ├─ isValid() check
  │    │         │    │    │    │    │         ├─ minimum_order_amount check
  │    │         │    │    │    │    │         └─ required_quantity_type check
  │    │         │    │    │    │    └─ strategy->computeOutcome()
  │    │         │    │    │    │
  │    │         │    │    │    └─ applicator->applyOutcome()
  │    │         │    │    │         └─ DB::transaction
  │    │         │    │    │              ├─ LOCK promotion FOR UPDATE
  │    │         │    │    │              ├─ LOCK cart FOR UPDATE
  │    │         │    │    │              ├─ LOCK cart_items
  │    │         │    │    │              ├─ Proportional allocation (largest remainder)
  │    │         │    │    │              └─ UPDATE each cart_item SET discount_amount, total_price, promotion_id
  │    │         │    │    │
  │    │         │    │    ├─ Handle gift items (if gift promotion)
  │    │         │    │    │    └─ reserveGiftItem() for selected gift
  │    │         │    │    │
  │    │         │    │    └─ Return CheckoutTotals(subtotal, promotionDiscount, finalTotal, ...)
  │    │         │    │
  │    │         │    └─ calculatePriceByCoupon()
  │    │         │         └─ CouponCalculator::calculate()
  │    │         │              ├─ PERCENTAGE: price × (discount/100), capped by max_discount
  │    │         │              └─ FIXED_RATE: min(discount, price)
  │    │         │
  │    │         ├─ Minimum order check
  │    │         │
  │    │         ├─ Resolve shipping price
  │    │         │    ├─ SELECT FROM governorates WHERE id=?
  │    │         │    └─ SELECT FROM shipping_prices WHERE governorate_id=?
  │    │         │
  │    │         ├─ Free shipping by threshold (subtotal > free_shipping_over)
  │    │         └─ Free shipping by coupon (FREE_SHIPPING type)
  │    │
  │    │         ├─ orderCreationService->createOrder()
  │    │         │    ├─ Resolve pickup location snapshot
  │    │         │    └─ INSERT INTO orders (...)
  │    │         │
  │    │         ├─ orderCreationService->createOrderItems()
  │    │         │    └─ For each cart item:
  │    │         │         ├─ Recalculate flash sale / product discount prices
  │    │         │         └─ INSERT INTO order_products (...)
  │    │         │
  │    │         ├─ orderCreationService->finalizeOrder()
  │    │         │    └─ OrderCreated::dispatch($order)
  │    │         │         └─ [queued] SendNewOrderNotification
  │    │         │
  │    │         └─ DB::commit()
  │    │
  │    ├─ If online:
  │    │    └─ PaymentCheckoutHandler->handleOnlinePayment()
  │    │         ├─ Gateway->createInvoice() → MyFatoorah API
  │    │         └─ INSERT INTO transactions (...)
  │    │         └─ Return { url: "https://myfatoorah.com/..." }
  │    │
  │    ├─ If cod:
  │    │    └─ PaymentCheckoutHandler->handleCodPayment()
  │    │         └─ INSERT INTO transactions (...)
  │    │         └─ Return { order_id }
  │    │
  │    └─ If pay_at_cashier:
  │         └─ PaymentCheckoutHandler->handleCashierQrPayment()
  │              ├─ INSERT INTO transactions (...)
  │              ├─ Generate QR code
  │              └─ Return { order_id, transaction_uuid, qr_code }
  │
  └─ Response to client

=== PAYMENT CALLBACK (separate request) ===

POST /checkout/callback?paymentId=xxx
  │
  ├─ Find transaction by paymentId
  ├─ Gateway->verifyPayment()
  ├─ Amount/currency mismatch check
  │
  └─ DB::transaction
       ├─ LOCK transactions FOR UPDATE
       ├─ LOCK orders FOR UPDATE
       ├─ Idempotency check (skip if already paid)
       ├─ UPDATE transactions SET status='paid', paid_at=NOW()
       ├─ Finalize inventory (SCHEDULED items):
       │    ├─ LOCK cart_items FOR UPDATE
       │    ├─ LOCK products FOR UPDATE
       │    ├─ UPDATE products SET stock_quantity, reserved_quantity, sold_quantity
       │    └─ DELETE cart_items
       │    └─ UPDATE carts SET status='checked_out' (if no remaining items)
       ├─ promotionService->incrementUsage()
       │    └─ UPDATE promotions SET usage = usage + 1
       ├─ changeOrderStatus('completed')
       │    ├─ UPDATE orders SET status='completed'
       │    ├─ recordCouponUsage()
       │    │    ├─ UPDATE coupons SET used = used + 1
       │    │    ├─ UPDATE coupon_assignments SET used = used + 1
       │    │    └─ INSERT INTO coupon_assignment_usages
       │    ├─ UPDATE transactions SET status='paid', paid_at=NOW()
       │    └─ OrderStatusChanged::dispatch()
       │         └─ [queued] SendOrderStatusChangedNotification
       │
       └─ PaymentSucceeded::dispatch($order)
            └─ [queued] SendPaymentSucceededNotification
```
