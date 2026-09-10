# Promotion System Lifecycle Audit

> **Document Type**: Architecture Audit
> **Scope**: Analysis only — no code was modified
> **Date**: 2026-07-13
> **Architecture Status**: Frozen (ADR-001)

---

## Table of Contents

1. [Promotion Lifecycle](#1-promotion-lifecycle)
2. [Cart Flow](#2-cart-flow)
3. [Eligibility Flow](#3-eligibility-flow)
4. [Cart Behaviour](#4-cart-behaviour)
5. [Apply Promotion Audit](#5-apply-promotion-audit)
6. [Calculation Audit](#6-calculation-audit)
7. [Cart Persistence Audit](#7-cart-persistence-audit)
8. [Checkout Audit](#8-checkout-audit)
9. [Order Audit](#9-order-audit)
10. [Usage Audit](#10-usage-audit)
11. [Inventory Audit](#11-inventory-audit)
12. [Coupon Integration Audit](#12-coupon-integration-audit)
13. [Architecture Audit](#13-architecture-audit)
14. [API Audit](#14-api-audit)
15. [New Cart Indicator Evaluation](#15-new-cart-indicator-evaluation)
16. [Performance Review](#16-performance-review)
17. [Edge Cases](#17-edge-cases)
18. [Technical TODO](#18-technical-todo)

---

## 1. Promotion Lifecycle

### 1.1 Complete Runtime Lifecycle

```
Customer opens cart
    │
    ▼
GET /api/v1/general/carts/{id}
    ├── CartController::show()
    │       └── CartRepository::findOrFail() with items.product, items.productVariant
    └── CartResource / CartItemResource
        └── NO promotion evaluation


Customer views eligible promotions
    │
    ▼
GET /api/v1/general/checkout/promotions
    ├── OrderController::eligiblePromotions()
    ├── OrderService::eligiblePromotionsForUser()
    │       └── PromotionService::eligiblePromotionsPayload()
    │               └── PromotionService::eligiblePromotions()
    │                       ├── Load cart with items.product, items.productVariant
    │                       ├── Load ALL valid promotions + giftProducts + variations
    │                       └── PromotionEligibilityResolver::eligible()
    │                               └── For each promotion:
    │                                       ├── matchedEligibility() — scope matching items
    │                                       ├── Strategy::eligible() — business rules
    │                                       └── Strategy::computeOutcome() — discount/gift
    └── PromotionResult::toArray()
        └── Response: { eligible_promotions: [...] }


Customer selects promotion and checks out
    │
    ▼
POST /api/v1/general/checkout
    ├── OrderController::checkout()
    │
    ├── Step 1: calcInvoicePrice()
    │   └── OrderService::calcInvoicePrice($request)
    │       └── DB::transaction
    │           ├── getCartUser() — load cart with scheduled items only
    │           └── calculateCheckoutTotals($cart, $promotionId, $giftProductId)
    │               ├── PromotionService::applySelectedPromotion()
    │               │       ├── removeGiftItems() — clear previous gifts
    │               │       ├── Load promotion with products + giftProducts (lockForUpdate)
    │               │       ├── PromotionEligibilityResolver::resolve() — eligibility + outcome
    │               │       ├── PromotionEligibilityResolver::matchedEligibility() — redundant re-eval
    │               │       ├── PromotionApplicator::applyOutcome() — persist to cart_items
    │               │       │       ├── DB::transaction
    │               │       │       ├── Lock promotion row
    │               │       │       ├── Lock cart + items
    │               │       │       ├── Re-evaluate matchedEligibility()
    │               │       │       ├── Proportional allocation (discount) or reserveGiftItem (gift)
    │               │       │       └── Update cart_items + cart totals
    │               │       └── Return CheckoutTotals DTO
    │               └── calculatePriceByCoupon() — coupon on price-after-promotion
    │           ├── resolveShippingPrice() — governorate shipping
    │           └── Update cart.total_price with shipping
    │
    ├── Step 2: addItemsInOrder()
    │   └── OrderService::addItemsInOrder($request)
    │       └── DB::transaction
    │           ├── getCartUser() — load cart with scheduled items
    │           ├── Validate coupon
    │           ├── getCheckoutTotalsFromCart() — ⚠️ READS persisted data, does NOT recalculate
    │           │       └── Reads promotion_id, discount_amount, total_price from cart_items
    │           ├── OrderCreationService::createOrder() — writes promotion data to orders table
    │           ├── OrderCreationService::createOrderItems() — writes promotion data per item
    │           └── OrderCreationService::finalizeOrder()
    │                   ├── PromotionService::incrementUsage()
    │                   └── OrderCreated::dispatch()
    │
    └── Payment handling (online, COD, cashier)


Payment callback (for online)
    │
    ▼
GET /api/v1/general/checkout/callback
    ├── OrderController::checkoutCallback()
    ├── Verify payment with gateway
    ├── finalizeItemsByShippingMethod() — finalize inventory
    └── changeOrderStatus() → 'completed'
        └── recordCouponUsage() (only if completed)
```

### 1.2 Key Observation: Two Different Checkout Flows

| Flow | Endpoint | Promotion Calculation | Order Creation |
|------|----------|----------------------|----------------|
| Regular (scheduled) | `POST /general/checkout` | `calcInvoicePrice()` → `calculateCheckoutTotals()` (applies to cart) | `addItemsInOrder()` → `getCheckoutTotalsFromCart()` (reads persisted) |
| Fast shipping | `POST /general/checkout/fast` | `createFastOrder()` → `calculateCheckoutTotals()` (applies to cart) | `createFastOrder()` (same method, same transaction) |

The regular checkout has a **split** between price calculation (Step 1) and order creation (Step 2). The fast shipping checkout is **atomic** — everything in one transaction inside `createFastOrder()`.

### 1.3 Layer Distribution

| Layer | Files Involved | Responsibility |
|-------|---------------|----------------|
| Controller | `OrderController`, `CartController`, `FastShippingController`, `PromotionController` (app), `PromotionController` (marvel) | Route orchestration |
| Service | `OrderService`, `PromotionService`, `PromotionDataService`, `FastShippingService`, `CartInventoryService` | Business logic |
| Engine | `PromotionEligibilityResolver`, `PromotionApplicator` | Eligibility + apply |
| Strategies | `PercentagePromotionStrategy`, `FixedPromotionStrategy`, `GiftPromotionStrategy` | Type-specific calculation |
| Outcomes | `DiscountOutcome`, `GiftOutcome` | Immutable result types |
| DTOs | `CheckoutTotals`, `PromotionEvaluation`, `PromotionResult`, `GiftItem` | Data transfer |
| Repository | `CartRepository` | Cart persistence |
| Resource | `CartResource`, `CartItemResource`, `PromotionResource` (app), `PromotionResource` (marvel), `OrderResource`, `OrderItemResource` | Serialization |
| Model | `Promotion`, `Cart`, `CartItem` | Data containers |

---

## 2. Cart Flow

### 2.1 Every Endpoint That Returns the Customer Cart

| # | Method | Endpoint | Controller | Function |
|---|--------|----------|------------|----------|
| 1 | GET | `/api/v1/carts` | `CartController` (marvel) | `index()` |
| 2 | POST | `/api/v1/carts` | `CartController` (marvel) | `store()` |
| 3 | GET | `/api/v1/carts/{id}` | `CartController` (marvel) | `show()` |
| 4 | PUT | `/api/v1/carts` | `CartController` (marvel) | `update()` |
| 5 | DELETE | `/api/v1/carts/{itemId}` | `CartController` (marvel) | `deleteItemFromCart()` |
| 6 | DELETE | `/api/v1/carts` | `CartController` (marvel) | `destroy()` |
| 7 | POST | `/api/v1/carts/pluck-items` | `CartController` (marvel) | `pluckItemsToCart()` |

### 2.2 Endpoint Details

#### 2.2.1 GET /api/v1/carts — `CartController::index()`

```
CartController::index($request)
    ├── $repository->with(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute'])
    ├── where('user_id', $user->id)
    ├── paginate($limit)
    └── CartResource::collection($carts)
```

**Eager loading**: `items.product`, `items.productVariant.attributeProducts.attributeValue.attribute`

**Response**: `CartResource` collection with `CartItemResource` for each item.

**Promotion involvement**: NONE.

#### 2.2.2 GET /api/v1/carts/{id} — `CartController::show()`

```
CartController::show($request, $id)
    ├── $repository->with(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute'])
    ├── findOrFail($id)
    ├── authorize: user_id match
    └── CartResource::make($cart)
```

Same eager loading pattern. Same missing promotion data.

#### 2.2.3 POST /api/v1/carts — `CartController::store()`

```
CartController::store($request)
    └── CartRepository::storeCart($request)
            └── persistCart($request, 'add')
                    ├── DB::transaction
                    ├── Lock cart row (lockForUpdate)
                    ├── Create cart if not exists
                    ├── syncItems() → CartInventoryService::reserveItem()
                    │       └── ProductPricingService::calculateVariantCurrentPrice() or calculateProductCurrentPrice()
                    └── Load: items.product, items.productVariant.attributeProducts.attributeValue.attribute
```

**Price calculation**: Uses `ProductPricingService` for the unit price at add-to-cart time. No promotion evaluation.

#### 2.2.4 PUT /api/v1/carts — `CartController::update()`

```
CartController::update($request)
    └── CartRepository::updateCart($request)
            └── persistCart($request, 'set')
```

Same as store but with `mode = 'set'` (replaces quantity instead of adding). No promotion evaluation.

#### 2.2.5 DELETE /api/v1/carts/{itemId} — `CartController::deleteItemFromCart()`

```
CartController::deleteItemFromCart($request, $ItemId)
    ├── Find item on user's cart
    ├── CartInventoryService::releaseItem($item, true) — release stock + delete item
    └── cart->update(['total_price' => sum of remaining items])
```

No promotion reset. If the deleted item had a promotion discount, the remaining items retain their promotion_id and discount_amount. Cart total_price is recalculated as the sum of remaining items' total_price (which includes discount amounts).

#### 2.2.6 DELETE /api/v1/carts — `CartController::destroy()`

```
CartController::destroy($request)
    ├── Check coupon warning (if coupon exists, require confirm flag)
    └── CartInventoryService::releaseCart($cart, true) — release all stock + delete all items
```

Clears everything. Resets coupon to null.

#### 2.2.7 POST /api/v1/carts/pluck-items — `CartController::pluckItemsToCart()`

```
CartController::pluckItemsToCart($request)
    ├── Inline validation
    ├── For each item: clone request → CartRepository::storeCart()
    └── Return CartResource::make($cart->load('items'))
```

Batch add. No promotion evaluation.

### 2.3 CartResource Serialization

```php
return [
    'id' => $this->id,
    'user_id' => $this->user_id,
    'coupon' => $couponObject,              // CouponResource for applied coupon
    'coupon_code' => $this->coupon,
    'status' => $this->status,
    'reserved_at' => $this->reserved_at,
    'expires_at' => $this->expires_at,
    'total_items' => $items->count(),
    'total_quantity' => $items->sum('quantity'),
    'total_price' => $items->sum('total_price'),
    'normal_items_count' => $normalItems->count(),
    'fast_items_count' => $fastItems->count(),
    'normal_items' => CartItemResource::collection($normalItems),
    'fast_items' => CartItemResource::collection($fastItems),
];
```

**Fields present in cart response**: 13 fields.

**Fields absent from cart response**: `has_eligible_promotion`, `eligible_promotions`, `applied_promotion`, `promotion_discount` — zero promotion-related data.

### 2.4 CartItemResource Serialization

```php
return [
    'id' => $this->id,
    'product_id' => $this->product_id,
    'product_variant_id' => $this->product_variant_id,
    'quantity' => $this->quantity,
    'price' => $this->price,
    'total_price' => $this->total_price,
    'attributes' => $this?->attributes,
    'shipping_method' => $this->shipping_method,
    'product' => [...],
];
```

**Fields present**: 9 fields.

**Fields absent from DB columns**: `promotion_id` (exists), `discount_amount` (exists), `is_gift` (exists), `reserved_quantity` (exists).

### 2.5 Summary

| Question | Answer |
|----------|--------|
| Do cart endpoints evaluate promotions? | NO |
| Do cart responses include promotion data? | NO |
| Is `items.promotion` relation ever eager loaded? | NO |
| Are `discount_amount`, `promotion_id`, or `is_gift` serialized? | NO |
| Is `has_eligible_promotion` present in cart response? | NO |

---

## 3. Eligibility Flow

### 3.1 Entry Points

There are exactly **two** entry points for promotion eligibility:

| # | Trigger | Code Path |
|---|---------|-----------|
| 1 | `GET /api/v1/general/checkout/promotions` | `OrderController::eligiblePromotions()` → `OrderService::eligiblePromotionsForUser()` → `PromotionService::eligiblePromotionsPayload()` → `PromotionService::eligiblePromotions()` |
| 2 | `POST /api/v1/general/checkout` (during `calcInvoicePrice`) | `OrderService::calculateCheckoutTotals()` → `PromotionService::applySelectedPromotion()` → `PromotionEligibilityResolver::resolve()` |

### 3.2 Entry Point 1: Eligible Listing

```
OrderController::eligiblePromotions()
    └── OrderService::eligiblePromotionsForUser()
            ├── getCartUser() — load cart (scheduled items only)
            └── PromotionService::eligiblePromotionsPayload($cart)
                    └── PromotionService::eligiblePromotions($cart)
                            ├── $cart->load(['items.product', 'items.productVariant'])
                            ├── subtotal() — compute from $item->price * $item->quantity
                            ├── Convert to cents
                            ├── Promotion::valid() scope — status, dates, usage < limiter
                            │       └── eager load: products:id, giftProducts + variations + attributes
                            └── PromotionEligibilityResolver::eligible($cart, $promotions, $subtotalCents)
                                    └── For each promotion:
                                            └── resolve($cart, $promotion, $subtotalCents)
```

### 3.3 Entry Point 2: Apply Selected Promotion

```
PromotionService::applySelectedPromotion()
    ├── removeGiftItems() — clear previous gifts
    ├── Load promotion with products + giftProducts (lockForUpdate)
    └── PromotionEligibilityResolver::resolve($cart, $promotion, $subtotalCents) — eligibility check
```

### 3.4 PromotionEligibilityResolver — Single Source of Truth

`PromotionEligibilityResolver` is indeed the **single source of truth** for eligibility. All eligibility evaluation passes through it:

**`eligible()`** — Batch evaluation for listing:
```php
public function eligible(Cart $cart, Collection $promotions, int $subtotalCents): Collection
{
    return $promotions
        ->map(fn(Promotion $p) => $this->resolve($cart, $p, $subtotalCents))
        ->filter()
        ->values();
}
```

**`resolve()`** — Single promotion evaluation:
```php
public function resolve(Cart $cart, Promotion $promotion, int $subtotalCents): ?PromotionResult
{
    // 1. Select strategy by type_amount
    $strategy = $this->strategies[$promotion->type_amount] ?? null;

    // 2. Guard: no strategy → null
    // 3. Guard: specific products but none attached → null

    // 4. Evaluate matched items
    $evaluation = $this->matchedEligibility($cart, $promotion, $subtotalCents);

    // 5. Business rules via strategy
    if (!$strategy->eligible($promotion, $cart, $subtotalCents, $evaluation)) return null;

    // 6. Compute outcome
    $outcome = $strategy->computeOutcome($promotion, $cart, $subtotalCents, $evaluation);

    // 7. Wrap into PromotionResult
    return new PromotionResult($promotion, $discount, $giftItems);
}
```

**`matchedEligibility()`** — Item scoping:
```php
public function matchedEligibility(Cart $cart, Promotion $promotion, int $subtotalCents): PromotionEvaluation
{
    // 1. Filter cart items
    //    - Exclude gifts
    //    - If specific_products: only items whose product_id is in promotion.products
    //    - If all_products: all non-gift items

    // 2. Compute matched quantity

    // 3. Compute matched subtotal (cents)
    //    - Uses $item->price * $item->quantity (original pricing)
    //    - Falls back to $item->total_price if baseLineTotal is 0
    //    - If all_products: matchedSubtotal = full subtotal

    return new PromotionEvaluation(matchedItems, matchedSubtotalCents, matchedQuantity);
}
```

### 3.5 Strategy Selection

Strategy is selected by `$promotion->type_amount`:

| `type_amount` | Strategy Class | Outcome |
|---------------|---------------|---------|
| `percentage` | `PercentagePromotionStrategy` | `DiscountOutcome` |
| `fixed_rate` | `FixedPromotionStrategy` | `DiscountOutcome` |
| `gift` | `GiftPromotionStrategy` | `GiftOutcome` |

Mapping is done in the constructor:
```php
$this->strategies = [
    PromotionMountType::PERCENTAGE => app(PercentagePromotionStrategy::class),
    PromotionMountType::FIXED_RATE => app(FixedPromotionStrategy::class),
    PromotionMountType::GIFT => app(GiftPromotionStrategy::class),
];
```

### 3.6 Business Rules Enforced

| Rule | Where | Code |
|------|-------|------|
| Status must be active | `AbstractPromotionStrategy::eligible()` | `$promotion->isValid()` |
| Start date must be <= today | `Promotion::isValid()` | `$this->start_at->lte($today)` |
| End date must be >= today | `Promotion::isValid()` | `$this->end_at->gte($today)` |
| Usage must be < limiter | `Promotion::isValid()` | `is_null($this->limiter) \|\| $this->usage < $this->limiter` |
| Minimum order amount | `AbstractPromotionStrategy::eligible()` | `$evaluation->matchedSubtotalCents >= $minimumCents` |
| Required quantity | `AbstractPromotionStrategy::eligible()` | `$promotion->isRequiredQuantityTrue($evaluation->matchedQuantity)` |
| Specific products only | `PromotionEligibilityResolver::resolve()` | `$promotion->products->isEmpty()` → null |
| Gift products exist | `GiftPromotionStrategy::eligible()` | `$promotion->giftProducts->isNotEmpty()` |
| Gift stock available | `GiftPromotionStrategy::hasAvailableStock()` | `available_stock > 0` for simple, or variation check |

### 3.7 DTOs

| DTO | Fields | Purpose |
|-----|--------|---------|
| `PromotionEvaluation` | `matchedItems` (Collection), `matchedSubtotalCents` (int), `matchedQuantity` (int) | Scoped cart data for eligibility |
| `PromotionResult` | `promotion` (Promotion), `discount` (float), `giftItems` (GiftItem[]) | Eligibility result for consumers |
| `GiftItem` | `productId`, `productVariantId`, `productVariant`, `productName`, `productSku`, `productImage`, `quantity`, `priceCents`, `isGift` | Gift product details |
| `DiscountOutcome` | `amountCents` (int), `baseAmountCents` (int) | Computed discount in cents |
| `GiftOutcome` | `giftItems` (GiftItem[]) | Computed gift details |
| `CheckoutTotals` | `subtotal`, `promotionDiscount`, `couponDiscount`, `finalTotal`, `promotion`, `giftItems`, `coupon`, `couponDiscountType`, `couponDiscountMaxAmount`, `currency` | Complete checkout financial summary |

### 3.8 Is PromotionEligibilityResolver the Single Source of Truth?

**Yes.** All eligibility evaluation goes through `PromotionEligibilityResolver`. The only code that performs eligibility logic outside the resolver is:

1. **`Promotion::isValid()`** — Existence check (status, dates, limiter). Called by the strategy's `eligible()`. This is correctly at the model layer as a data attribute reader, not business logic duplication.

2. **`Promotion::discountAmount()`** — Model method that duplicates discount formulas. Called by the strategies' `computeOutcome()`. **This is the one violation** — the model contains discount calculation logic that duplicates what the strategies do. However, both strategies delegate to this method, so at least it's centralized, not duplicated across strategies.

3. **`Promotion::isRequiredQuantityTrue()`** — Model method for quantity threshold. Called by the strategy. This is a lightweight attribute reader (acceptable per the architecture).

### 3.9 Eligibility Is Fully Stateless

`PromotionEligibilityResolver::resolve()` is read-only. It does not modify the database. It only evaluates and returns a result. The actual database writes happen in `PromotionApplicator::applyOutcome()`.

---

## 4. Cart Behaviour

### 4.1 What Happens When Customer Retrieves Cart

When any cart endpoint is called (`GET /api/v1/carts/{id}`, `GET /api/v1/carts`, etc.):

**Promotions are completely ignored.**

The system:
1. Loads cart items with product and variant relations
2. Serializes them via `CartResource` / `CartItemResource`
3. Returns the response

No promotion eligibility is evaluated. No promotion data is loaded. No promotion fields are serialized.

### 4.2 Three Distinct States

| State | When | What Cart Shows |
|-------|------|-----------------|
| No promotion applied | Cart items have `promotion_id = null` | Cart response has no promotion fields |
| Promotion applied but refresh happened | Cart items have `promotion_id` and `discount_amount` set from previous `calcInvoicePrice()` | Cart response still shows **zero** promotion data (fields not serialized) |
| Promotion listing requested | `GET /checkout/promotions` | Dedicated response with `eligible_promotions` array |

### 4.3 The API Gap

```
Cart response (GET /api/v1/carts/{id}):
{
    "id": 1,
    "total_price": 1000.00,
    "total_quantity": 3,
    "normal_items": [...],
    "fast_items": [...]
    // NO promotion fields
}

Eligible promotions response (GET /api/v1/general/checkout/promotions):
{
    "eligible_promotions": [
        { "id": 1, "type": "percentage", "discount": 50.00, ... },
        { "id": 2, "type": "gift", "gift_items": [...], ... }
    ]
}
```

The frontend must make **two separate API calls** to determine:
1. What items are in the cart
2. Whether any promotions apply to those items

There is no way for the frontend to know from the cart response alone whether the cart qualifies for any promotion.

---

## 5. Apply Promotion Audit

### 5.1 Full `applySelectedPromotion()` Flow

```php
PromotionService::applySelectedPromotion(Cart $cart, ?int $promotionId, ?int $selectedGiftProductId = null): CheckoutTotals
```

**Step 1**: Clear previous gifts
```php
$this->removeGiftItems($cart);
// Queries cart_items WHERE is_gift = true
// For each: inventoryService->releaseItem($item, true) — releases stock + deletes
```

**Step 2**: Load cart item relations
```php
$cart->items->load(['product', 'productVariant']);
```

**Step 3**: Compute subtotal
```php
$subtotal = $this->subtotal($cart); // price * quantity, exclude gifts
$subtotalCents = (int) round($subtotal * 100);
```

**Step 4**: If promotion selected
```php
// 4a. Load promotion with lockForUpdate
$promotion = Promotion::valid()->whereKey($promotionId)
    ->with(['products:id', 'giftProducts...'])
    ->lockForUpdate()
    ->first();

if (!$promotion) throw new \InvalidArgumentException('Selected promotion is not valid.');

// 4b. Evaluate eligibility (read-only)
$result = $this->resolver->resolve($cart, $promotion, $subtotalCents);
if (!$result) throw new \InvalidArgumentException(...);

// 4c. ⚠️ REDUNDANT: re-evaluate matched eligibility
$evaluation = $this->resolver->matchedEligibility($cart, $promotion, $subtotalCents);

// 4d. Handle discount
$amountCents = (int) round(($result->discount ?? 0) * 100);
if ($amountCents > 0) {
    $discountOutcome = new DiscountOutcome($amountCents, $evaluation->matchedSubtotalCents);
    $discountDetails = $this->applicator->applyOutcome($cart, $promotion, $discountOutcome);
    // Refresh cart after modification
    $cart->refresh();
    $cart->load(['items' => fn($q) => $q->whereIn('id', $itemIds), ...]);
}

// 4e. Handle gift
if (!empty($result->giftItems)) {
    $selectedGiftItem = $this->resolveSelectedGiftItem($result->giftItems, $selectedGiftProductId);
    $giftOutcome = new GiftOutcome([$selectedGiftItem]);
    $giftDetails = $this->applicator->applyOutcome($cart, $promotion, $giftOutcome);
    // Refresh cart again
    $cart->refresh();
    $cart->load(['items' => fn($q) => $q->whereIn('id', $itemIds), ...]);
}
```

**Step 5**: Return DTO
```php
return new CheckoutTotals(
    subtotal: $subtotal,
    promotionDiscount: $discountDetails['discount'],
    couponDiscount: 0,
    finalTotal: sum of non-gift item total_price,
    promotion: ['id' => $promotion->id, 'type' => $promotion->type_amount, 'code' => $promotion->code],
    giftItems: $giftDetails['gift_items'],
);
```

### 5.2 Validation and Error Handling

| Condition | Behaviour | Correct? |
|-----------|-----------|----------|
| `$promotionId` is null | Skips entire block, returns CheckoutTotals with zero discount | ✅ Yes |
| Promotion not found / not valid | Throws `\InvalidArgumentException` | ✅ Yes |
| Promotion not eligible for cart | Throws `\InvalidArgumentException` | ✅ Yes |
| Gift no stock | `reserveGiftItem()` throws exception, caught by outer catch | ✅ Yes |
| Gift product not in available list | `resolveSelectedGiftItem()` throws | ✅ Yes |
| Cart empty | Caught earlier in `calcInvoicePrice()` | ✅ Yes |

### 5.3 Transactions and Database Locking

| Lock | Where | Why |
|------|-------|-----|
| `Promotion::lockForUpdate()` | Lines 67-76 of PromotionService | Prevent concurrent usage of same promotion |
| Inside `applyOutcome()`: promotion lock | Line 35 of PromotionApplicator | Re-lock inside transaction for safety |
| Inside `applyOutcome()`: cart + items lock | Line 41 of PromotionApplicator | Prevent concurrent cart modifications |
| Inside `reserveGiftItem()`: cart, items, stock | Separate transaction in CartInventoryService | Inventory reservation atomicity |

### 5.4 Bugs and Issues

#### Issue 5.4.1: Redundant `matchedEligibility()` Call

**Location**: `PromotionService::applySelectedPromotion()`, lines 83-91.

```php
// Line 83: already called inside resolve()
$result = $this->resolver->resolve($cart, $promotion, $subtotalCents);

// Line 91: called again
$evaluation = $this->resolver->matchedEligibility($cart, $promotion, $subtotalCents);
```

The `resolve()` method already calls `matchedEligibility()` internally. The service needs the `PromotionEvaluation` DTO for `DiscountOutcome` construction, but this creates a **double computation** of the same matched items.

**Impact**: Minor performance issue (collection operations on cart items, no extra DB queries). Not a correctness bug.

#### Issue 5.4.2: `$cart->refresh()` + `$cart->load()` After Each Apply

**Location**: Lines 100-102 and 109-111.

```php
$itemIds = $cart->items->pluck('id');
$cart->refresh();
$cart->load(['items' => fn($q) => $q->whereIn('id', $itemIds), 'items.product', 'items.productVariant']);
```

After `applyOutcome()` modifies cart items in the database, the in-memory `$cart` collection still has old references. The refresh + filtered load is needed because:
1. `$cart->refresh()` reloads the cart model from DB
2. The filtered `$cart->load()` only reloads the specific items that were in the cart before (not gift items added during apply)

**Problem**: When both discount and gift are applied (lines 97-112), the cart is refreshed **twice**, causing two round-trips to the database. Additionally, the first `$cart->load()` (line 102) reloads the items, then the second one (line 111) reloads them again with the same IDs. The second refresh could lose changes made by the first apply (gift items added during discount step... but in practice discount and gift are separate branches: `amountCents > 0` handles discount, `!empty($result->giftItems)` handles gift. A promotion is either discount OR gift, not both).

**Verdict**: The `amountCents > 0` and `!empty($result->giftItems)` branches are mutually exclusive for a single promotion (percentage/fixed → discount, gift → gift). So the double refresh never actually happens in practice. No bug.

#### Issue 5.4.3: No CartItem Relation Reload After PromoId Change

**Location**: Lines 58-59.

```php
$this->removeGiftItems($cart);     // deletes gift items from DB
$cart->items->load(['product', 'productVariant']);   // reloads remaining items
```

After removing gift items, the service reloads product and variant relations. But the promotion data (`promotion_id`, `discount_amount`) is **not** explicitly needed for the evaluation (since `matchedEligibility()` uses `$item->price * quantity`, not `total_price`). However, `$cart->items` still contains the old in-memory references of deleted items until `$cart->refresh()` is called later.

**Impact**: If a previous promotion's discount amounts exist on items, the `matchedEligibility()` ignores them (uses `$item->price * quantity`). No bug.

#### Issue 5.4.4: Edge — Cart Items Loaded Without `promotion` Relation

The `$cart->items->load(['product', 'productVariant'])` on line 58 does not include `'promotion'`. The `CartItem` model has a `promotion()` relation (belongsTo Promotion), but it's never eager loaded. If any downstream code accesses `$item->promotion`, it triggers a lazy load. Currently, no code in `applySelectedPromotion()` accesses `$item->promotion` — the `$item->promotion_id` attribute is read directly from the model's attributes array.

**Impact**: Zero — no lazy load occurs. But this is a latent fragility.

### 5.5 Summary

| Aspect | Verdict |
|--------|---------|
| Validation | Correct |
| Eligibility verification | Correct (re-validated at apply time) |
| Strategy selection | Correct |
| Discount calculation | Correct |
| Gift calculation | Correct |
| Transactions | Correct |
| Database locking | Correct (pessimistic locking throughout) |
| Rollback | Correct (exception propagates to outer transaction in `calcInvoicePrice()`) |
| Error handling | Correct (specific exceptions for each failure mode) |

---

## 6. Calculation Audit

### 6.1 Matched Subtotal Calculation

**Location**: `PromotionEligibilityResolver::matchedEligibility()`

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

**Key behaviour**: Uses `$item->price * quantity` (original unit price from add-to-cart time). Falls back to `$item->total_price` only if line total is zero.

**Potential issue**: `$item->price` is the price at add-to-cart time, not the current price. If a product's price changed after adding to cart, the matched subtotal uses the stale price. This affects:
- Whether `minimum_order_amount` threshold is met
- The discount amount for percentage promotions
- The `min(price, value)` cap for fixed promotions

**Is this a bug?** By design, the price at add-to-cart time is the agreed price. However, the promotion is evaluated at checkout time, which could be hours later. The promotion eligibility should arguably be based on the current price (or minimum of current and agreed price). But changing this would be a business decision, not a code bug.

### 6.2 Quantity Rules

**Location**: `Promotion::isRequiredQuantityTrue()`

```php
public function isRequiredQuantityTrue($qty): bool
{
    return is_null($this->required_quantity_type) || $qty >= $this->required_quantity_type;
}
```

- NULL `required_quantity_type` → always passes
- Set value → quantity must be >= threshold
- Quantity is from matched items only (not full cart)

**Correctness**: ✅ Matched quantity is computed from scoped items. For `all_products`, this is the full cart quantity minus gifts. For `specific_products`, it's only the matching products' quantities.

### 6.3 Percentage Promotion

**Location**: `PercentagePromotionStrategy::computeOutcome()`

```php
$amountDecimal = $promotion->discountAmount($evaluation->matchedSubtotalCents / 100.0, $evaluation->matchedQuantity);
$amountCents = (int) round($amountDecimal * 100);
return new DiscountOutcome($amountCents, $evaluation->matchedSubtotalCents);
```

Delegates to `Promotion::discountAmount()`:

```php
// In Promotion::discountAmount():
if ($this->isPercentagePromotion()) {
    $discount = $price * ($value / 100);            // price = matchedSubtotal
    if ($maxValue !== null) {
        $discount = min($discount, $maxValue);       // capped by max_discount_amount
    }
    return round(max(0.0, $discount), 2);
}
```

**Math**: `discount = matchedSubtotal × (value/100)`, capped by `max_discount_amount`.

**Check**: Does the cents conversion lose precision?
- `matchedSubtotalCents / 100.0` → converts cents back to decimal (e.g., 10050 → 100.50)
- `discountAmount()` returns float rounded to 2 decimals
- `round($amountDecimal * 100)` → converts back to cents (e.g., 10.05 → 1005)

This double conversion (cents → decimal → cents) can theoretically lose precision for very small amounts due to floating point, but in practice:
- `matchedSubtotalCents / 100.0` for 10050 = 100.5 (exact in IEEE 754)
- `discountAmount()` returns `round(max(0.0, ...), 2)` → 2 decimal places (exact)
- `round(10.05 * 100)` = `round(1005.0)` = 1005 (exact)

**Verdict**: ✅ Correct for all practical amounts.

### 6.4 Fixed Promotion

**Location**: `FixedPromotionStrategy::computeOutcome()`

```php
$amountDecimal = $promotion->discountAmount($evaluation->matchedSubtotalCents / 100.0, $evaluation->matchedQuantity);
$amountCents = (int) round($amountDecimal * 100);
```

Delegates to `Promotion::discountAmount()`:

```php
if ($this->isFixedRatePromotion()) {
    return round(max(0.0, min($price, $value)), 2);
}
```

**Math**: `discount = min(matchedSubtotal, value)`.

**Correctness**: ✅ Fixed rate is capped to matched subtotal (cannot discount more than the total).

### 6.5 Gift Promotion

**Location**: `GiftPromotionStrategy::computeOutcome()`

```php
$giftItems = $promotion->giftProducts
    ->map(function ($product) {
        // Check stock
        if ($variantId && (!$variant || $variant->available_stock <= 0)) return null;
        if (!$variantId && !$this->hasAvailableStock($product)) return null;

        // Build GiftItem DTO
        return new GiftItem(
            productId: (int) $product->id,
            productVariantId: $variantId > 0 ? $variantId : null,
            variantPayload: $variantPayload,
            productName: (string) $product->name,
            productSku: (string) $product->sku,
            productImage: method_exists($product, 'getFirstMediaUrl') ? ... : null,
            quantity: max(1, (int) ($product->pivot->quantity ?? 1)),
            priceCents: 0,
            isGift: true,
        );
    })
    ->filter()
    ->values()
    ->all();
```

**Correctness**: ✅ Gift items are priced at zero. Stock-checked. Filtered to remove out-of-stock gifts.

### 6.6 Max Discount Enforcement

| Promotion Type | Max Discount Enforced? | Where |
|---------------|----------------------|-------|
| Percentage | ✅ Yes, via `max_discount_amount` | `Promotion::discountAmount()` |
| Fixed Rate | ✅ Implicitly via `min(price, value)` | `Promotion::discountAmount()` |
| Gift | N/A (no monetary discount) | — |

### 6.7 Allocation Algorithm

**Location**: `PromotionApplicator::applyOutcome()`, lines 49-133.

**Algorithm**: Largest remainder proportional allocation.

```
For each matched item:
    exact_share     = (line_total_cents × total_discount_cents) / base_subtotal_cents
    floor_share     = floor(exact_share)
    allocation      = min(floor_share, line_total_cents)  // capped to line total
    allocated_sum  += allocation
    remainders[i]   = exact_share - floor_share

remaining = total_discount_cents - allocated_sum
Sort remainders descending
For each item (while remaining > 0):
    give = min(line_total_cents - allocation[i], 1)
    allocation[i] += give
    remaining -= give

For each item:
    new_total_price = (line_total_cents - allocation) / 100.0
    save: promotion_id, discount_amount, total_price
```

**Correctness**: ✅ This is the standard largest remainder method for proportional allocation. It guarantees:
1. Total allocated sum equals the total discount (no lost cents)
2. Each item gets at most its line total (no negative prices)
3. Allocation is proportional to each item's contribution to the matched subtotal

**Edge case check**: If `$baseCents` (from `DiscountOutcome`) differs from `$sumLineCents` (computed from current items), allocation could be slightly off. This happens because:
- `$baseCents` comes from `matchedEligibility()` at eligibility time
- `$sumLineCents` is recomputed at apply time from the locked cart items
- These should be the same if the cart hasn't changed

**Potential issue**: If a promotion applies to `all_products`, `$baseCents` is the full subtotal (from `matchedEligibility()` line 118-119), while `$sumLineCents` is also the full subtotal (from the re-computation). These should match. ✅

### 6.8 Duplicated Calculations

| Calculation | Appears In | Also In | Duplicated? |
|------------|-----------|---------|-------------|
| Subtotal (price * qty, exclude gifts) | `PromotionService::subtotal()` | `PromotionEligibilityResolver::matchedEligibility()` | ✅ Yes (same logic, different callers) |
| Discount formula | `Promotion::discountAmount()` | Called by both strategies | ⚠️ Centralized in model (model purity violation, but not duplicate) |
| Matched eligibility | `PromotionEligibilityResolver::matchedEligibility()` | Called from `resolve()`, then again from `PromotionService::applySelectedPromotion()`, then again from `PromotionApplicator::applyOutcome()` | ✅ Yes (3 times for same apply) |
| Subtotal reconstruction | `PromotionService::subtotal()` | `OrderService::getCheckoutTotalsFromCart()` | ✅ Yes (same formula, different contexts) |

---

## 7. Cart Persistence Audit

### 7.1 After Discount Application

**Write 1**: Each matched cart item gets:

| Column | Value | Source |
|--------|-------|--------|
| `promotion_id` | `$promotion->id` | Always set |
| `discount_amount` | `$alloc / 100.0` (formatted to 2 decimals) | Proportional allocation |
| `total_price` | `(line_total_cents - alloc) / 100.0` | New reduced total |

**Write 2**: Cart row gets:

| Column | Value | Source |
|--------|-------|--------|
| `total_price` | Sum of all non-gift items' new total_price | Recomputed |

**Fields NOT modified**:
- `price` (original unit price) — **unchanged**
- `quantity` — **unchanged**
- `reserved_quantity` — **unchanged**
- `is_gift` — remains `false` or `null`

### 7.2 After Gift Application

**Write 1**: New CartItem created (or existing gift updated) with:

| Column | Value |
|--------|-------|
| `product_id` | Gift product ID |
| `product_variant_id` | Selected variant (or null) |
| `quantity` | Gift quantity |
| `reserved_quantity` | Same as quantity |
| `price` | **0** |
| `total_price` | **0** |
| `is_gift` | **true** |
| `promotion_id` | Promotion ID |
| `attributes` | `null` |
| `shipping_method` | **NOT SET** (relies on column default) |

**Write 2**: Cart row gets:

| Column | Value |
|--------|-------|
| `total_price` | Sum of non-gift items' total_price |

### 7.3 What Gets Reset — Nothing

Discount application sets `promotion_id`, `discount_amount`, and `total_price` on matched items. But:

| Scenario | Does it reset? |
|----------|---------------|
| Previous promotion's discount on items | **NO** — removed gift items are cleared, but discount_amount on non-gift items remains until overwritten by new apply |
| Cart item added after promotion applied | New item has `promotion_id = null`, `discount_amount = null` |
| Item deleted from cart after promotion | Item deleted, but remaining items retain their discount |
| Cart updated (qty changed) | `CartRepository::persistCart()` recalculates `total_price = SUM(items.total_price)` but **does NOT clear promotion_id or discount_amount** |

### 7.4 Stale Data Risk

**Scenario**:
1. User has items A ($100) and B ($50) in cart
2. Promotion applies 10% off on all products → $15 discount
3. Allocation: A gets $10 off (total_price = 90), B gets $5 off (total_price = 45)
4. Cart total_price = 135
5. User removes item A
6. `deleteItemFromCart()`: releases stock for A, deletes item A
7. `cart->update(['total_price' => items()->sum('total_price')])` → cart total_price = 45

**After this**, item B has `promotion_id` set and `discount_amount = 5` and `total_price = 45`. This is **correct** — item B's price reflects the promotion discount. But the removal of item A means the total discount applied ($15) is now partially invalid (only $5 applies to remaining items).

**Is this a problem?** The frontend will call `checkout` next, which calls `getCheckoutTotalsFromCart()` to read the persisted data. The subtotal will be `price * quantity = 50 * 1 = 50` (not `total_price = 45`). The `promotionDiscount` will be computed as `sum(discount_amount) = 5`. The `finalTotal` will be `sum(total_price) = 45`. These values are internally consistent: `50 - 5 = 45`.

**Verdict**: No data corruption. The persisted values remain self-consistent even after cart modifications.

### 7.5 Gift Item Shipping Method

**Bug**: `CartInventoryService::reserveGiftItem()` does not set `shipping_method` in the payload:

```php
$payload = [
    'product_id' => $product->id,
    'product_variant_id' => $variant?->id,
    'quantity' => $desiredQuantity,
    'reserved_quantity' => $desiredQuantity,
    'price' => 0,
    'total_price' => 0,
    'attributes' => null,
    'is_gift' => true,
    'promotion_id' => $promotion->id,
    // 🚩 NO 'shipping_method'
];
```

The `cart_items` table has a `shipping_method` column with a default value (likely `SCHEDULED`). Gift items will always have `shipping_method = 'SCHEDULED'`, which means they get finalized during the scheduled items finalization. This works but is **implicit** — if the column default changes or is removed, gift items could get a NULL shipping method and be missed during finalization.

**Risk**: Low (works with current column default). But it's a latent bug.

---

## 8. Checkout Audit

### 8.1 The Two Checkout Paths

#### Path A: Regular Checkout (Scheduled)

```
OrderController::checkout()
    ├── ensureCartReservation()
    ├── calcInvoicePrice()       ← applies promotion, persists to cart_items
    │       └── calculateCheckoutTotals()
    │               ├── PromotionService::applySelectedPromotion()  ← WRITES to cart_items
    │               └── calculatePriceByCoupon()
    │
    └── addItemsInOrder()        ← READS persisted data from cart_items
            └── getCheckoutTotalsFromCart()  ← READS discount_amount, total_price from DB
```

#### Path B: Fast Checkout

```
FastShippingController::checkout()
    └── FastShippingService::createFastOrder()
            ├── ensureCartReservation()
            ├── calculateCheckoutTotals()    ← applies promotion, persists to cart_items
            ├── createOrder()                ← same transaction
            ├── createOrderItems()           ← same transaction
            └── finalizeOrder()              ← same transaction
```

### 8.2 The Reuse vs Recalculate Problem

**Regular checkout** has a **critical architectural issue**: `calcInvoicePrice()` (Step 1) is called in a separate transaction from `addItemsInOrder()` (Step 2). Between them:

1. `calcInvoicePrice()` applies the promotion (writes to cart_items in its own transaction)
2. The user is redirected to the payment gateway
3. After payment, `addItemsInOrder()` reads the persisted data from cart_items

**If the cart is modified between Step 1 and Step 2** (e.g., user opens another tab and changes quantities), the `getCheckoutTotalsFromCart()` in Step 2 reads stale data.

### 8.3 `getCheckoutTotalsFromCart()` — The Dangerous Method

```php
private function getCheckoutTotalsFromCart(Cart $cart): CheckoutTotals
{
    $items = $cart->items->reject(fn($item) => (bool) ($item->is_gift ?? false));

    $subtotal = round((float) $items->sum(function ($item) {
        $baseLineTotal = ((float) ($item->price ?? 0)) * ((int) ($item->quantity ?? 0));
        if ($baseLineTotal > 0) { return $baseLineTotal; }
        return (float) ($item->total_price ?? 0);
    }), 2);

    $promotionDiscount = round((float) $items->sum(fn($item) => (float) ($item->discount_amount ?? 0)), 2);
    $finalTotal = round((float) $items->sum('total_price'), 2);

    // Reconstruct promotion data from first item that has promotion_id
    $promotionItem = $items->first(fn($item) => !is_null($item->promotion_id));
    $promotionData = null;
    if ($promotionItem) {
        $promotion = Promotion::query()->find((int) $promotionItem->promotion_id);
        $promotionData = $promotion ? [ ... ] : null;
    }

    // Compute coupon discount as difference
    $couponDiscount = round(max(0, $subtotal - $promotionDiscount - $finalTotal), 2);

    return new CheckoutTotals(...);
}
```

**Problems**:

1. **Promotion ID from first item**: Takes `promotion_id` from the first item that has one. If items have different promotion_ids (shouldn't happen, but no guard), this returns a wrong result.

2. **Promotion re-query**: Queries `Promotion::find()` for the promotion model. This is a separate DB query that could return null if the promotion was deleted.

3. **Coupon discount as residual**: Computes `couponDiscount = subtotal - promotionDiscount - finalTotal`. But `subtotal` uses `price * quantity` (original), while `finalTotal` uses `total_price` (discounted). And `promotionDiscount` is `sum(discount_amount)` — which was computed during the proportional allocation. For a single item: subtotal = 100, promotionDiscount = 10, finalTotal = 90. `couponDiscount = 100 - 10 - 90 = 0`. Correct.

4. **Stale data**: If the cart was modified between `calcInvoicePrice()` and `addItemsInOrder()`, the `discount_amount` values on items no longer reflect the correct proportional allocation for the current cart.

### 8.4 `calculateCheckoutTotals()` — The Fresh Method

This is the method that `calcInvoicePrice()` calls:

```php
public function calculateCheckoutTotals(Cart $cart, ?int $selectedPromotionId, ?int $selectedGiftProductId = null): CheckoutTotals
{
    $promotionTotals = $this->promotionService->applySelectedPromotion($cart, $selectedPromotionId, $selectedGiftProductId);
    $priceAfterPromotion = $promotionTotals->finalTotal;
    $couponResult = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
    $finalTotal = round(max(0, (float) $couponResult['finalPrice']), 2);

    return new CheckoutTotals(
        subtotal: $promotionTotals->subtotal,
        promotionDiscount: $promotionTotals->promotionDiscount,
        couponDiscount: round(max(0, (float) $priceAfterPromotion - (float) $finalTotal), 2),
        finalTotal: $finalTotal,
        promotion: $promotionTotals->promotion,
        ...
    );
}
```

**Problems**:

1. **Promotion applied twice**: If `calcInvoicePrice()` is called, then `calcInvoicePrice()` is called again (user opens two tabs), the second call re-applies the same promotion. As analyzed earlier, this is **idempotent** due to `lockForUpdate()` and the use of `$item->price * quantity` (not `total_price`) for allocation math.

2. **Coupon discount calculation difference**: `calculateCheckoutTotals()` uses `priceAfterPromotion - finalTotal` while `getCheckoutTotalsFromCart()` uses `subtotal - promotionDiscount - finalTotal`. These are mathematically equivalent but use different intermediate values (DTO vs recomputed), so floating-point differences could produce slightly different coupon discounts.

### 8.5 Subtotal Consistency

| Check | `calculateCheckoutTotals()` | `getCheckoutTotalsFromCart()` |
|-------|---------------------------|------------------------------|
| Subtotal formula | `price * qty` (exclude gifts) | `price * qty` (exclude gifts) |
| Promotion discount | From `CheckoutTotals` DTO | `sum(discount_amount)` from cart_items |
| Final total | From `CheckoutTotals` DTO | `sum(total_price)` from cart_items |
| Coupon discount | `priceAfterPromotion - finalTotal` | `subtotal - promotionDiscount - finalTotal` |

The two methods should produce the same results IF the cart hasn't changed. But they compute values from different sources (DTO vs DB), which means they could diverge.

### 8.6 The Fast Shipping Path

`FastShippingService::createFastOrder()` uses `calculateCheckoutTotals()` **in the same transaction** as order creation. This is the correct approach:

```
DB::transaction
    ├── calculateCheckoutTotals()  ← fresh calculation
    ├── createOrder()
    ├── createOrderItems()
    └── finalizeOrder()
```

**The regular checkout should follow the same pattern** but doesn't due to the split between `calcInvoicePrice()` and `addItemsInOrder()`.

---

## 9. Order Audit

### 9.1 Promotion Fields on `orders` Table

When `OrderCreationService::createOrder()` runs:

```php
Order::create([
    ...
    'promotion_id' => $checkoutTotals->promotionId(),       // $this->promotion['id'] ?? null
    'promotion_code' => $checkoutTotals->promotionCode(),   // $this->promotion['code'] ?? null
    'promotion_type' => $checkoutTotals->promotionType(),   // $this->promotion['type'] ?? null
    'promotion_discount' => $checkoutTotals->promotionDiscount,
]);
```

| Column | Type | Source | Always Set? |
|--------|------|--------|-------------|
| `promotion_id` | FK → promotions (nullable) | `CheckoutTotals::promotionId()` | Only if promotion was selected |
| `promotion_code` | string (nullable) | `CheckoutTotals::promotionCode()` | Only if promotion was selected |
| `promotion_type` | string (nullable) | `CheckoutTotals::promotionType()` | Only if promotion was selected |
| `promotion_discount` | decimal (default 0) | `CheckoutTotals->promotionDiscount` | Always (0 if no promotion) |

**Correctness**: ✅ All four fields are correctly populated from the DTO.

**Potential data loss**: `giftItems` array in the DTO is **NOT written to the orders table**. Gift items are written as individual `order_products` rows with `is_gift = true`, but the order itself has no aggregate "gift items" field. This is correct design — gift items are tracked at the line-item level.

### 9.2 Promotion Fields on `order_products` Table

When `OrderCreationService::createOrderItems()` runs:

```php
$orderItem = $order->orderItems()->create([
    ...
    'promotion_discount_amount' => $promotionDiscountAmount,
    'is_gift' => (bool) ($item->is_gift ?? false),
    'promotion_id' => $item->promotion_id,
]);
```

Where `$promotionDiscountAmount` is:
```php
$promotionDiscountAmount = round(max(0, ((float) ($item->price ?? 0) * $quantity) - $lineTotal), 2);
```

**Formula**: `max(0, original_price × quantity - total_price)`

For a discounted item:
- `price = 100`, `quantity = 1`, `total_price = 90`
- `promotionDiscountAmount = max(0, 100 - 90) = 10` ✅

For a non-discounted item:
- `price = 100`, `quantity = 1`, `total_price = 100`
- `promotionDiscountAmount = max(0, 100 - 100) = 0` ✅

For a gift item:
- `price = 0`, `quantity = 1`, `total_price = 0`
- `promotionDiscountAmount = max(0, 0 - 0) = 0` ✅

| Column | Type | Source | Always Set? |
|--------|------|--------|-------------|
| `promotion_id` | FK → promotions (nullable) | `$item->promotion_id` | Only if item had promotion applied |
| `is_gift` | boolean | `$item->is_gift` | Always (default false) |
| `promotion_discount_amount` | decimal (default 0) | Computed from `(price × qty) - total_price` | Always |

### 9.3 Can Promotion Information Be Lost?

| Scenario | Result | Loss? |
|----------|--------|-------|
| Promotion exists at checkout time | All fields written correctly | ✅ No |
| Promotion deleted before checkout | `getCheckoutTotalsFromCart()` → `Promotion::find()` returns null → `$promotionData = null` → Order has null promotion fields | ⚠️ Yes, promotion metadata lost on order |
| Promotion exists but cart had no promotion | All promotion fields null → correct | ✅ No |
| Gift item exists | Written as order_product with `is_gift = true` and `promotion_id` set | ✅ No |
| Discount item exists | Written with `promotion_discount_amount` set | ✅ No |

**The one scenario that loses promotion information**: If the promotion is deleted from the database between promotion apply and order creation. In this case:
- `cart_items` still has `promotion_id` pointing to the deleted row (no FK constraint)
- `getCheckoutTotalsFromCart()` calls `Promotion::find($promotionId)` → null
- `$promotionData` becomes null
- Order is created with null `promotion_id`, `promotion_code`, `promotion_type`
- `$item->promotion_id` is still set on order_items (the raw attribute, not a relation)
- So `order_products.promotion_id` may hold a deleted FK value

**Impact**: Low. The promotion discount amount is still captured in `promotion_discount` and `promotion_discount_amount`. The metadata (type, code) is lost.

### 9.4 OrderResource Serialization

```php
return [
    ...
    'discount' => round(coupon_discount + promotion_discount, 2),
    'coupon' => $this->coupon,
    'coupon_discount' => round($this->coupon_discount, 2),
    'promotion_discount' => round($this->promotion_discount, 2),
    'promotion' => $this->promotion_id ? [
        'id' => $this->promotion_id,
        'type' => $this->promotion_type,
        'code' => $this->promotion_code,
    ] : null,
    ...
];
```

**Fields present**: `discount` (combined), `coupon`, `coupon_discount`, `promotion_discount`, `promotion` (object with id/type/code).

**Fields absent**: `promotion_id` at top level (only inside nested object), `promotion_name`, `gift_items` at order level.

**Correctness**: ✅ All promotion data is exposed through OrderResource.

---

## 10. Usage Audit

### 10.1 incrementUsage() Implementation

```php
public function incrementUsage(?int $promotionId): void
{
    if (!$promotionId) {
        return;
    }

    Promotion::query()
        ->whereKey($promotionId)
        ->where(function ($query) {
            $query->whereNull('limiter')
                ->orWhereColumn('usage', '<', 'limiter');
        })
        ->lockForUpdate()
        ->first()
        ?->increment('usage');
}
```

### 10.2 Timing

| Step | Time | Usage Counter |
|------|------|---------------|
| Promotion eligibility check (`Promotion::isValid()`) | During `eligible()` | Usage NOT yet incremented |
| Promotion application (`applyOutcome()`) | During `calcInvoicePrice()` / `calculateCheckoutTotals()` | Usage NOT yet incremented (lock held) |
| Order creation (`createOrder()` + `createOrderItems()`) | During `addItemsInOrder()` / `createFastOrder()` | Usage NOT yet incremented |
| **Usage increment** (`finalizeOrder()` → `incrementUsage()`) | After order and items are created | **Usage incremented** |

### 10.3 Race Condition Analysis

**Scenario**: Two users simultaneously apply the same limited-usage promotion.

```
User A                                      User B
│                                           │
├── isValid() → usage=5, limiter=10 → OK    │
│                                           ├── isValid() → usage=5, limiter=10 → OK
├── applyOutcome() locks promotion          │
├── writes discount to cart_items           │
├── commits → usage still 5                │
│                                           ├── applyOutcome() locks promotion
│                                           ├── reads usage=5 (same value)
│                                           ├── writes discount to cart_items
│                                           └── commits → usage still 5
├── finalizeOrder()                         │
│   └── incrementUsage()                   │
│       ├── lockForUpdate() → reads usage=5 │
│       └── increment → usage=6            │
│                                           ├── finalizeOrder()
│                                           │   └── incrementUsage()
│                                           │       ├── lockForUpdate() → reads usage=6
│                                           │       └── increment → usage=7
```

Both users pass eligibility (usage=5 < limiter=10). Both get the promotion. Both increment. Final usage = 7. **Total orders created with promotion: 2. Limit allows up to 10. Correct.**

**TOCTOU window**: Between `isValid()` (in `eligible()`) and `incrementUsage()` (in `finalizeOrder()`), the usage counter can change. The `lockForUpdate()` in `incrementUsage()` ensures the increment is atomic, but the eligibility was already evaluated without the lock.

**Is this a problem?** For most business cases, no. The promotion was valid when the customer selected it (during checkout). If another order completed in between, using up the remaining slots, the second customer would still get the promotion (they already went through checkout). The `lockForUpdate()` in `applyOutcome()` prevents concurrent modifications to the promotion row.

### 10.4 What If Order Creation Fails?

If `createOrder()` or `createOrderItems()` fails and the transaction rolls back:
- `incrementUsage()` is **NOT called** (because `finalizeOrder()` is after item creation in the same transaction)
- The promotion usage counter is **not incremented**
- The discount was already written to cart_items (in a previous transaction from `calcInvoicePrice()`)
- Cart items' `total_price` values remain discounted
- The user can retry checkout

**Correctness**: ✅ Usage is only incremented on successful order creation.

### 10.5 What If `finalizeOrder()` Fails After Order Creation?

If `incrementUsage()` throws an exception after `createOrderItems()` succeeded:
- In regular checkout: `addItemsInOrder()` catches it, calls `DB::rollBack()`. Order is **not persisted**.
- In fast checkout: `createFastOrder()` catches it, calls `DB::rollBack()`. Order is **not persisted**.

**Correctness**: ✅ The transaction ensures all-or-nothing.

### 10.6 Cancelled Orders

When an order is cancelled, `incrementUsage()` is **NOT decremented**. The promotion usage counter only goes up. A customer could:
1. Use a promotion
2. Create an order (usage incremented)
3. Cancel the order
4. The usage counter remains incremented

**Is this a problem?** Yes, if the limiter is tight. A cancelled order still counts against the promotion's usage limit. However, this is a business decision — some systems want this (promotion "slot" was used even if the order was later cancelled), others don't.

Current behaviour: **Usage is never decremented.** There is no `decrementUsage()` method.

### 10.7 Reliability Assessment

| Scenario | Correct? | Notes |
|----------|----------|-------|
| Normal single-user flow | ✅ | Usage incremented exactly once |
| Concurrent users, same promotion | ✅ | `lockForUpdate()` prevents race |
| Order creation fails | ✅ | Usage not incremented (transaction rollback) |
| Payment callback exceeds time | ✅ | Usage only incremented on successful order |
| Order cancelled | ⚠️ | Usage NOT decremented (business decision) |
| `finalizeOrder()` called twice | ✅ | `incrementUsage()` is idempotent (WHERE `usage < limiter` guards) |
| `checkout()` called without `calcInvoicePrice()` | ⚠️ | `getCheckoutTotalsFromCart()` reads persisted (possibly stale) data; if no items have `promotion_id`, promotion metadata is null |

---

## 11. Inventory Audit

### 11.1 Gift Item Reserve

Gift items are reserved through `CartInventoryService::reserveGiftItem()`:

```
reserveGiftItem($cart, $product, $promotion, $quantity, $productVariantId)
    │
    ├── DB::transaction
    ├── Lock cart row
    ├── Find or create gift CartItem matching (cart_id, product_id, promotion_id, is_gift=true)
    ├── Resolve variant (if variable product with variant)
    ├── Lock inventory row (product or variant)
    ├── reserveStock($stock, $delta) — increases reserved_quantity
    ├── Create/update CartItem with price=0, total_price=0, is_gift=true
    └── touchCartReservation() — updates expires_at
```

### 11.2 Gift Item Release

Gift items are released in two scenarios:

**During `applySelectedPromotion()`** (before applying a new promotion):
```php
$this->removeGiftItems($cart);
// Queries cart_items WHERE is_gift=true
// For each: inventoryService->releaseItem($item, true)
```

**During `releaseCart()`** (cart destroyed or expired):
```php
foreach ($cart->items as $item) {
    $this->releaseItem($item, $deleteItems);
}
```

`releaseItem()`:
```php
$item = CartItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
$stock = $this->lockInventoryRowByItem($item);
$this->releaseStock($stock, (int) $item->reserved_quantity);
// Decrease reserved_quantity on the product/variant
if ($deleteItem) { $item->delete(); }
```

### 11.3 Gift Item Finalize

Gift items are finalized through the same `finalizeItemsByShippingMethod()` path as normal items:

```php
$items = CartItem::where('cart_id', $cart->id)
    ->where('shipping_method', $shippingMethod)
    ->lockForUpdate()
    ->get();

foreach ($items as $item) {
    $stock = $this->lockInventoryRowByItem($item);
    $this->finalizeStock($stock, (int) $item->reserved_quantity);
    // Decrease stock_quantity AND reserved_quantity, increase sold_quantity
    $item->delete();
}
```

### 11.4 Is Inventory Consistent?

| Scenario | Reserve | Release | Finalize | Consistent? |
|----------|---------|---------|----------|-------------|
| Normal apply → order → complete | ✅ reserveGiftItem() | N/A | ✅ finalizeItemsByShippingMethod() | ✅ Yes |
| Apply → cart expired | ✅ reserveGiftItem() | ✅ expireCart() releases all items | N/A | ✅ Yes |
| Apply → user discards cart | ✅ reserveGiftItem() | ✅ releaseCart() | N/A | ✅ Yes |
| Apply → another promotion re-applied | 🚩 removeGiftItems() deletes old + reserveGiftItem() creates new | ✅ releaseItem() for old gifts before creating new | N/A | ✅ Yes |
| Concurrent apply same promotion | ✅ lockForUpdate() prevents double reservation | — | — | ✅ Yes |
| Gift out of stock between eligibility and apply | — | — | — | ✅ reserveGiftItem() throws `QUANTITY_EXCEEDS_STOCK` |

### 11.5 Bug: Missing `shipping_method` on Gift Items

As noted in Phase 7, `reserveGiftItem()` does not set `shipping_method`:

```php
$payload = [
    'product_id' => $product->id,
    ...
    // 'shipping_method' => ???
];
```

The column default on `cart_items.shipping_method` is likely `'SCHEDULED'` (based on the ShippingMethod enum having `SCHEDULED` as the first value). This means:

- Gift items always have `shipping_method = 'SCHEDULED'`
- In regular checkout (which processes scheduled items), gift items are finalized correctly
- In fast checkout (which processes fast items only), gift items are **NOT finalized** because they have the wrong shipping method

**But wait**: The regular checkout finalizes scheduled items after payment callback. The fast checkout finalizes fast items. If a gift promotion is used in a fast checkout, the gift items (with `shipping_method = 'SCHEDULED'`) would **not** be finalized during fast checkout finalization.

**Let me verify**: In the payment callback (`checkoutCallback()`):
```php
$shippingMethod = $order->shipping_method ?? ShippingMethod::SCHEDULED;
$this->cartInventoryService->finalizeItemsByShippingMethod($cart, $shippingMethod);
```

For a fast checkout order, `$shippingMethod` would be `ShippingMethod::FAST`. The gift items (with `shipping_method = 'SCHEDULED'`) would **not** be included in the `finalizeItemsByShippingMethod()` query because it filters by `where('shipping_method', $shippingMethod)`.

**This is a latent bug!** Gift items in fast checkout would never have their inventory finalized:
1. Gift item reserved with `shipping_method = 'SCHEDULED'` (default)
2. Fast checkout finalizes items with `shipping_method = 'FAST'`
3. Gift item is skipped → inventory stays reserved forever
4. `CartInventoryService::expireCarts()` would eventually expire the cart and release the items, but only when the cart TTL (3 days) passes

**Impact**: Low, because:
- The cart's remaining items (including gift) are deleted when the cart expires
- The gift is a free item, so the inventory loss is minor
- But it's still a correctness bug — the reserved inventory of gift products is not released on successful checkout

---

## 12. Coupon Integration Audit

### 12.1 Execution Order

```
calculateCheckoutTotals()
    │
    ├── 1. PromotionService::applySelectedPromotion()
    │       └── Returns CheckoutTotals with finalTotal = price after promotion
    │
    ├── 2. calculatePriceByCoupon($cart, $priceAfterPromotion)
    │       └── Reads $cart->coupon
    │       └── If coupon exists, applies to $priceAfterPromotion
    │       └── Returns ['finalPrice' => ..., 'discountType' => ...]
    │
    └── 3. Final total = max(0, couponResult['finalPrice'])
```

**Order**: Promotion first, then coupon on the remaining total. Correct.

### 12.2 Subtotal Used for Coupon

The coupon calculator receives `$priceAfterPromotion` (subtotal - promotionDiscount). The coupon discount is computed on this reduced amount, not the original subtotal. This is the standard e-commerce practice.

### 12.3 Discount Types

The coupon system (in `CouponCalculator`) supports different discount types:
- `fixed` — subtract fixed amount
- `percentage` — subtract percentage
- `free_shipping` — zero shipping cost

The free_shipping coupon is handled separately (in `OrderService::addItemsInOrder()` and `calcInvoicePrice()`), where `resolveFreeShippingByCoupon()` sets shipping to 0.

### 12.4 Potential Conflicts

| Conflict | Does it occur? | Resolution |
|----------|---------------|------------|
| Promotion + coupon on same subtotal | No, coupon is on price-after-promotion | ✅ Correct |
| Promotion + free_shipping coupon | Promotion reduces total, coupon removes shipping cost | ✅ They don't interact |
| Coupon applied but no promotion | Normal coupon flow | ✅ Works |
| Promotion applied but coupon invalid | Coupon validation in `addItemsInOrder()` clears invalid coupon | ✅ Works |

### 12.5 Double Discount Risk

**Could both the promotion and coupon apply the same discount amount?** No. The promotion operates on the matched subtotal, reducing item `total_price` values. The coupon then operates on the remaining `priceAfterPromotion`. They are applied sequentially on different base amounts. There is no double-discount issue.

### 12.6 Incorrect Totals Check

**Scenario**: Promotion gives 10% off, coupon gives 10% off.

- Subtotal: 1000
- After promotion: 1000 - 100 = 900
- After coupon (10% of 900): 900 - 90 = 810
- Total discount: 100 + 90 = 190

This is the correct stacked discount calculation. Promotion on original price, coupon on reduced price. ✅

### 12.7 Coupon Discount Calculation Inconsistency

`getCheckoutTotalsFromCart()` computes:
```php
$couponDiscount = round(max(0, $subtotal - $promotionDiscount - $finalTotal), 2);
```

`calculateCheckoutTotals()` computes:
```php
$couponDiscount = round(max(0, (float) $priceAfterPromotion - (float) $finalTotal), 2);
```

If `$priceAfterPromotion = $subtotal - $promotionDiscount`, these formulas are identical. But `$priceAfterPromotion` is from the `CheckoutTotals` DTO (produced by `applySelectedPromotion()`), while `$subtotal` and `$promotionDiscount` in `getCheckoutTotalsFromCart()` are recomputed from cart_items. Floating-point differences between the DTO values and the DB values could produce a 1-cent difference in coupon discount.

**Impact**: Extremely low (1 cent at most). But it's an inconsistency between the two methods.

---

## 13. Architecture Audit

### 13.1 Violation 1: Business Logic in Model

**File**: `packages/marvel/src/Database/models/Promotion.php`

**Method**: `discountAmount()` (line 202)

```php
public function discountAmount(float $price, int $qty = 1): float
{
    if ($this->isPercentagePromotion()) {
        $discount = $price * ($value / 100);
        if ($maxValue !== null) { $discount = min($discount, $maxValue); }
        return round(max(0.0, $discount), 2);
    }
    if ($this->isFixedRatePromotion()) {
        return round(max(0.0, min($price, $value)), 2);
    }
    // ...
}
```

**Violates**: Rule #4 (Model Purity) — Models must contain no pricing business logic.

**Severity**: Medium. The method is called by the strategies, so it's centralized. But it's still business logic in the model layer.

### 13.2 Violation 2: Resource Not Pure

**File**: `packages/marvel/src/Http/Resources/CartItemResource.php`

**Method**: `toArray()` (line 11)

```php
'thumbnail' => $this->product->getFirstMediaUrl('products'),
```

**Violates**: Rule #7 (No Hidden Work) — Hidden SQL/media queries inside a Resource.

**Severity**: Low. This is a pre-existing pattern used across many resources.

### 13.3 Violation 3: Controller Contains Business Logic

**File**: `packages/marvel/src/Http/Controllers/CartController.php`

**Methods**: `deleteItemFromCart()`, `destroy()`, `pluckItemsToCart()`

These methods directly orchestrate business operations (inventory release, cart updates) instead of delegating to a service.

**Violates**: Rule #5 (Controller Purity) — Controllers must only orchestrate requests and responses.

**Severity**: Low. The controller is "thin" but not "anorexic." Acceptable for a small application.

### 13.4 Violation 4: Parallel Pricing Flow

The promotion system operates entirely outside `ProductPricingService`. It has its own:
- `PromotionService` (parallel to `ProductPricingService`)
- `PromotionEligibilityResolver` (parallel pricing logic)
- Strategies with discount formulas

**Does this violate the frozen architecture?** ADR-001 states: "Any code path that computes a price, discount, flash sale amount, or final price must pass through this pipeline." But the promotion system is specifically excluded from product-level pricing — promotions are cart-level reductions, not product-level pricing.

**Verdict**: The frozen architecture's scope is product pricing (unit price, discount, flash sale). Promotions are cart-level and genuinely separate. However, `Promotion::discountAmount()` does compute discount amounts that could be seen as "pricing logic." This is a gray area.

### 13.5 Violation 5: Duplicated Subtotal Calculation

**Location 1**: `PromotionService::subtotal()` (line 180)
**Location 2**: `PromotionEligibilityResolver::matchedEligibility()` (line 105)
**Location 3**: `OrderService::getCheckoutTotalsFromCart()` (line 283)

All three compute `price * quantity` with `total_price` fallback, excluding gifts. This is a DRY violation.

**Severity**: Low. The formula is simple, and the contexts are different. But if the formula changes, all three must be updated.

### 13.6 Architecture Compliance Summary

| Rule | Requirement | Status | Evidence |
|------|-------------|--------|----------|
| #1 Single Pricing Authority | All pricing through `ProductPricingService` | ⚠️ Partial | Promotion pricing is separate (by design) |
| #2 Pre-Serialization Enrichment | Pricing set before Resource | ✅ Yes | CartInventoryService enriches before CartResource |
| #3 Resource Purity | Resources only serialize | ❌ Violated | `getFirstMediaUrl()` in CartItemResource |
| #4 Model Purity | No pricing business logic | ❌ Violated | `discountAmount()` in Promotion model |
| #5 Controller Purity | Controllers only orchestrate | ⚠️ Mostly | Some business logic leaks in CartController |
| #6 Zero Duplication | No duplicate pricing formulas | ⚠️ Mostly | Subtotal calculation duplicated in 3 places |
| #7 Lightweight Accessors | No computation in accessors | ✅ Yes | Model accessors are lightweight |
| #8 No Hidden Work | No hidden SQL | ❌ Violated | Media URL query in CartItemResource |
| #9 Extensibility | Extend ProductPricingService | ✅ Not applicable | Promotion is separate domain |

---

## 14. API Audit

### 14.1 Endpoints and Their Responses

#### `GET /api/v1/general/carts/{id}` — Cart Show

**Response includes**: id, user_id, coupon, coupon_code, status, reserved_at, expires_at, total_items, total_quantity, total_price, normal_items_count, fast_items_count, normal_items, fast_items.

**Items include**: id, product_id, product_variant_id, quantity, price, total_price, attributes, shipping_method, product (id, name, slug, thumbnail).

**Missing fields**: `promotion_id`, `discount_amount`, `is_gift` on items. `has_eligible_promotion` on cart.

**Consistency**: If a promotion was applied to the cart, `total_price` reflects the discounted total. But `discount_amount` and `promotion_id` are not visible. The frontend sees a reduced `total_price` with no explanation. This is **internally inconsistent** — the price changed but no promotion data is shown.

#### `GET /api/v1/general/checkout/promotions` — Eligible Promotions

**Response includes**: `eligible_promotions` array with `id`, `type`, `title`, `code`, `discount`, `gift_items`.

**Consistency**: ✅ Fully consistent with the eligibility logic.

#### `POST /api/v1/general/checkout` — Checkout

**Response**: Depends on payment method. For online payment, returns payment URL. For COD, returns order data.

**Order data includes** (via OrderResource): `discount`, `coupon`, `coupon_discount`, `promotion_discount`, `promotion` (id, type, code), items with `promotion_discount_amount`, `is_gift`, `promotion_id`.

**Consistency**: ✅ Order response fully exposes promotion data.

### 14.2 Missing Fields Summary

| Endpoint | Missing Field | DB Column Exists? | Should Be Added? |
|----------|--------------|-------------------|------------------|
| Cart Show/Index | `items[].promotion_id` | Yes | ✅ Yes (frontend needs to know if item has promotion) |
| Cart Show/Index | `items[].discount_amount` | Yes | ✅ Yes (frontend needs to see per-item discount) |
| Cart Show/Index | `items[].is_gift` | Yes | ✅ Yes (frontend needs to distinguish gift items) |
| Cart Show/Index | `has_eligible_promotion` | No (computed) | ⚠️ See Phase 15 |
| Cart Show/Index | `applied_promotion` | No (computed) | ⚠️ Could be derived from items[].promotion_id |
| Checkout Promotions | None | — | ✅ Complete |

### 14.3 Consistency Assessment

| Check | Status |
|-------|--------|
| Cart prices match order prices after promotion | ✅ Yes (both use same `total_price` from cart_items) |
| Promotion discount visible in order | ✅ Yes |
| Promotion discount visible in cart | ❌ No (not serialized) |
| Gift items visible in order | ✅ Yes (`is_gift` and `promotion_id` on order_items) |
| Gift items visible in cart | ❌ No (not serialized in CartItemResource) |
| Applied promotion visible in cart | ❌ No (not serialized) |

---

## 15. New Cart Indicator Evaluation

### 15.1 The Question

Should the cart response expose a field such as `has_eligible_promotion`?

### 15.2 Is Such a Field Actually Needed?

**Arguments for YES:**
- The frontend currently needs 2 API calls to show promotion-related UI
- UX improvement: cart could show "This cart qualifies for a promotion!" without a separate request
- The frontend could enable/disable promotion selection UI based on availability
- The cart endpoint is the natural place for cart-related metadata

**Arguments against:**
- The current 2-call pattern works (frontend calls `/checkout/promotions` when showing checkout)
- Promotion eligibility is a checkout concern, not a cart concern
- Adding eligibility to cart merges two concerns
- Performance impact (see Phase 16)

**Verdict**: **The field is not strictly needed** — the current architecture is functionally complete. But it would provide meaningful UX improvement. The decision depends on whether the product team wants to show promotion badges in the cart UI.

### 15.3 Would It Violate the Architecture?

The frozen architecture (ADR-001) governs **product pricing**, not promotions. Adding `has_eligible_promotion` to the cart response would:

1. Not touch `ProductPricingService` — ✅ No violation
2. Not put business logic in Resources — **if implemented as pre-serialization enrichment** ✅
3. Not put business logic in Controllers — **if implemented as a service call** ✅

**Potential violation**: Adding the field directly in `CartResource::toArray()` would require the resource to call `PromotionService::eligiblePromotions()`, which violates Resource Purity (Rule #3).

**Architecture-safe approach**: Pre-serialization enrichment in the controller (similar to `ProductPricingService::enrichProductWithPricing()`):

```php
// In CartController::show()
$cart = $this->repository->with([...])->findOrFail($id);
$cart->has_eligible_promotion = $this->promotionService->hasEligiblePromotions($cart);
return CartResource::make($cart);

// In CartResource::toArray()
'has_eligible_promotion' => $this->has_eligible_promotion ?? false,
```

This approach:
- Keeps Resource pure (serializes a pre-set attribute)
- Keeps Controller as orchestrator (calls service, sets attribute)
- Reuses existing `PromotionService`
- Follows the same pattern as product pricing enrichment

### 15.4 Can It Reuse Existing PromotionEligibilityResolver?

Yes. `PromotionService::eligiblePromotions()` already calls `PromotionEligibilityResolver::eligible()`. A new lightweight method could be added:

```php
// In PromotionService
public function hasEligiblePromotions(Cart $cart): bool
{
    $cart->load(['items.product', 'items.productVariant']);
    $subtotalCents = (int) round($this->subtotal($cart) * 100);

    return Promotion::valid()
        ->with(['products:id'])  // only need product IDs, not gift details
        ->get()
        ->contains(fn($promotion) => $this->resolver->resolve($cart, $promotion, $subtotalCents) !== null);
}
```

But this would still load ALL valid promotions and iterate through them. For a simple boolean, a more efficient approach would be:
- Load promotions with minimum product info
- Early-exit on first eligible match
- Skip gift product details (not needed until user selects a gift promotion)

### 15.5 Would It Duplicate Business Logic?

If reusing `PromotionEligibilityResolver` (the single source of truth), the eligibility logic is **not duplicated** — it's reused. The only new code would be:
1. The service method `hasEligiblePromotions()` — thin wrapper
2. The controller enrichment call — single line
3. The Resource field — single line

**No duplication**. ✅

### 15.6 Would It Introduce Unnecessary Queries?

Yes, promotion eligibility evaluation requires:
1. Querying all valid promotions (1 query)
2. For each promotion, checking product associations (in-memory, if eager loaded)
3. For specific_products promotions, loading product pivot (1 query)

A lightweight `hasEligiblePromotions()` implementation that early-exits would still need at least:
- 1 query for valid promotions (with products:id eager load)
- In-memory iteration until first match found

For a cart with 1 item and 50 active promotions, worst case: 50 iterations + 1 query. Best case: 1 iteration + 1 query.

### 15.7 Where Is the Correct Integration Point?

```
Controller (CartController::show/index)
    │
    ├── CartRepository → load cart with items, products, variants
    │
    ├── PromotionService::hasEligiblePromotions($cart)
    │       ├── Load valid promotions (lightweight, no gift details)
    │       └── PromotionEligibilityResolver (reuse existing)
    │
    └── Set attribute on Cart model
            │
            ▼
    CartResource → serialize has_eligible_promotion
```

### 15.8 What Alternatives Exist?

| Alternative | Pros | Cons |
|-------------|------|------|
| **Pre-serialization enrichment** (recommended) | Architecture-safe, reusable, testable | Requires controller changes |
| Add to CartResource directly | Minimal code change | Violates Resource Purity |
| New composite endpoint (cart + promotions) | Single request | Duplicates cart/promotion endpoints |
| Just-in-time evaluation on frontend | No backend changes | Increases frontend complexity, 2 calls |
| Always include in cart if previously computed | Cached eligibility | Stale data risk, cache invalidation complexity |

### 15.9 Recommendation

**Add `has_eligible_promotion` via pre-serialization enrichment** only if:
1. The product team requires promotion badges in the cart UI
2. Performance impact is acceptable (see Phase 16)
3. A lightweight `hasEligiblePromotions()` method is added to `PromotionService`

Do NOT add the field if:
1. The current 2-call pattern is acceptable for the frontend
2. The performance impact of evaluating all promotions on every cart load is unacceptable

**Architecture-safe implementation approach**: Pre-serialization enrichment in the controller, Resource only serializes a pre-set attribute, reuses existing `PromotionEligibilityResolver`. Zero duplication of business logic.

---

## 16. Performance Review

### 16.1 Current Cart Retrieval Cost

**Endpoint**: `GET /api/v1/carts/{id}`

**Current queries**:
1. `SELECT * FROM carts WHERE id = ?` — cart lookup
2. `SELECT * FROM cart_items WHERE cart_id = ?` — items
3. `SELECT * FROM products WHERE id IN (?, ?, ...)` — item products
4. `SELECT * FROM product_variants WHERE id IN (?, ?, ...)` — item variants
5. `SELECT * FROM attribute_products WHERE product_variant_id IN (?, ?, ...)` — variant attributes
6. `SELECT * FROM attribute_values WHERE id IN (?, ?, ...)` — attribute values
7. `SELECT * FROM attributes WHERE id IN (?, ?, ...)` — attributes
8. Media library calls for each product thumbnail (`getFirstMediaUrl()`)

**Estimated query count**: 5-10 queries + media lookups

### 16.2 Current Promotion Eligibility Cost

**Endpoint**: `GET /api/v1/general/checkout/promotions`

**Current queries**:
1. `SELECT * FROM carts WHERE user_id = ?` — cart lookup
2. `SELECT * FROM cart_items WHERE cart_id = ?` — items
3. `SELECT * FROM products WHERE id IN (?, ?, ...)` — products
4. `SELECT * FROM product_variants WHERE id IN (?, ?, ...)` — variants
5. `SELECT * FROM promotions WHERE status = true AND ...` — all valid promotions
6. `SELECT * FROM promotion_product WHERE promotion_id IN (?, ?, ...)` — product pivots
7. `SELECT * FROM promotion_gift_products WHERE promotion_id IN (?, ?, ...)` — gift products
8. `SELECT * FROM products WHERE id IN (?, ?, ...)` — gift product details
9. `SELECT * FROM product_variants WHERE product_id IN (?, ?, ...)` — gift variant details
10. `SELECT * FROM attribute_products WHERE product_variant_id IN (?, ?, ...)` — gift variant attributes
11. `SELECT * FROM attribute_values WHERE id IN (?, ?, ...)` — gift attribute values
12. `SELECT * FROM attributes WHERE id IN (?, ?, ...)` — gift attributes

**Estimated query count**: 10-15 queries + in-memory collection operations.

**Deep eager loading chain**:
```
giftProducts
    └── variations
            └── attributeProducts
                    └── attributeValue
                            └── attribute
```

This is 5 levels deep. For 50 promotions with 2 gift products each, each with 3 variations, each variation having 2 attribute products, that's potentially:
- 1 promotions query
- 1 promotion_product query
- 1 promotion_gift_products query
- 1 products query for gift products
- N product_variants queries (or 1 with WHERE IN)
- M attribute_products queries
- ... cascading further

### 16.3 Estimated Cost of Adding `has_eligible_promotion` to Cart

**Lightweight implementation** (early-exit, no gift details):

1. `SELECT * FROM promotions WHERE status = true AND ...` — all valid promotions
2. `SELECT * FROM promotion_product WHERE promotion_id IN (?, ?, ...)` — product associations (needed for specific_products check)

**In-memory**:
- Iterate promotions until first eligible match
- For each: check `isValid()` (in-memory, model attributes)
- For each: `matchedEligibility()` → iterate cart items (N items)
- First match → early exit

**Worst case** (no eligible promotion): iterate all promotions.
**Best case** (first promotion eligible): 1 iteration.

**Additional query cost**: 2 queries (promotions + pivot). For a large number of promotions (>100), this adds measurable query time.

### 16.4 N+1 Risks

| Risk | Current Status | Mitigation |
|------|---------------|------------|
| Gift stock check fallback | Variations eager loaded upstream | ✅ Mitigated |
| Promotion re-query in getCheckoutTotalsFromCart | Single query, not N+1 | ✅ Not an issue |
| attributeProducts chain | Always eager loaded | ✅ Mitigated |
| Media library in Resource | Called per item | ❌ This IS an N+1 (but pre-existing) |

The `getFirstMediaUrl()` call in `CartItemResource` is the only actual N+1 risk — called once per cart item. This is a pre-existing pattern.

### 16.5 Expensive Operations

| Operation | Cost | Notes |
|-----------|------|-------|
| Loading all valid promotions | `O(P)` queries | P = number of valid promotions |
| Gift product variations | `O(P × G × V)` eager load | G = gift products per promotion, V = variations |
| Proportional allocation | `O(N log N)` | N = matched items, sorting remainders |
| Double matchedEligibility in applySelectedPromotion | `O(N)` | Collection operations, no queries |

### 16.6 Summary

| Metric | Current (Cart Only) | With has_eligible_promotion |
|--------|---------------------|---------------------------|
| Additional queries | 0 | +2 (promotions + pivot) |
| Additional memory | 0 | + promotions + products data |
| Additional computation | 0 | + in-memory iteration over promotions |
| N+1 risk | N/A (media library) | Unchanged |
| Early exit possible | N/A | Yes — stop at first eligible match |

---

## 17. Edge Cases

### 17.1 Empty Cart

**Behaviour**: `CartController::show()` returns a valid CartResource with zero items. `CartResource` returns `total_items = 0`, `total_quantity = 0`, `total_price = 0`, empty `normal_items` and `fast_items` arrays.

**Promotion behaviour**: `PromotionService::eligiblePromotions()` would compute `subtotal = 0`, evaluate each promotion against the empty cart. `matchedEligibility()` returns empty matched items with 0 subtotal. `minimum_order_amount > 0` promotions would fail. Promotions with `minimum_order_amount = 0` would still fail because promotion type `price` with 0 subtotal would produce 0 discount.

**Verdict**: ✅ Correct behaviour. No promotions apply to an empty cart.

### 17.2 Expired Promotion

**Behaviour**: `Promotion::isValid()` checks `start_at <= today <= end_at`. If `today > end_at`, `isValid()` returns false. The `scopeValid()` query scope also filters these out.

**During eligibility listing**: Eligible promotions are loaded with `Promotion::valid()` scope. Expired promotions are excluded.

**During apply**: `PromotionService::applySelectedPromotion()` loads with `Promotion::valid()` scope. Expired promotion returns null → throws `InvalidArgumentException`.

**Verdict**: ✅ Correct. Expired promotions are excluded at every stage.

### 17.3 Usage Exceeded

**Behaviour**: `Promotion::isValid()` checks `is_null($this->limiter) || $this->usage < $this->limiter`. If `usage >= limiter`, the promotion is invalid.

**During eligibility listing**: `scopeValid()` includes the same condition. Excluded.

**During apply**: `Promotion::valid()` scope includes the condition. Promotion not found → exception.

**Race condition**: Two concurrent users could both pass eligibility before either increments. But `lockForUpdate()` in `incrementUsage()` ensures atomic increment.

**Verdict**: ✅ Correct. `lockForUpdate()` prevents race conditions.

### 17.4 Promotion Deleted

**Behaviour**: If an admin deletes a promotion:
- Cart items still have `promotion_id` pointing to the deleted row (no FK constraint on cart_items)
- `getCheckoutTotalsFromCart()` calls `Promotion::find($promotionId)` → null
- `$promotionData` becomes null
- Order is created with null promotion metadata
- But `$item->promotion_id` on cart_items (and later order_items) still holds the deleted ID

**Verdict**: ⚠️ Promotion metadata is lost but the discount amount is preserved.

### 17.5 Gift Out of Stock

**Behaviour**: 
1. During eligibility: `GiftPromotionStrategy::hasAvailableStock()` checks if gift product has stock.
2. If out of stock: gift filtered out; if all gifts out of stock, promotion becomes ineligible.
3. User adds promotion: eligibility passes (gift was in stock at that time).
4. Between eligibility and apply, stock runs out: `PromotionApplicator::applyOutcome()` calls `CartInventoryService::reserveGiftItem()` → `reserveStock()` throws `QUANTITY_EXCEEDS_STOCK`.

**Verdict**: ✅ Correct. Stock is re-checked at apply time with locks.

### 17.6 Promotion Changed After Cart Creation

**Behaviour**: If admin modifies a promotion (e.g., changes discount value, changes dates, changes status) after a user has added items to cart but before checkout:
- `Promotion::valid()` scope reflects current state
- Next eligibility check or apply sees the updated values
- If promotion was deactivated: not found by `Promotion::valid()` → ineligible

**Verdict**: ✅ Correct. Promotion validity is always checked fresh.

### 17.7 Cart Updated After Promotion Applied

**Behaviour**: If a user applies a promotion, then adds/removes items from the cart:
- `CartController::update()` or `store()` → `CartRepository::persistCart()` → `syncItems()`
- `syncItems()` calls `CartInventoryService::reserveItem()` which recalculates `total_price` for the new/updated item
- Cart total is recalculated as `SUM(items.total_price)`
- **But**: `promotion_id` and `discount_amount` on existing items are **NOT cleared**
- New items have `promotion_id = null`, `discount_amount = null`

**Result**: Cart has a mix of items with and without promotion data. The subtotal for future eligibility checks uses `price * quantity` (original prices), so promotion data on items doesn't directly affect eligibility. But the `total_price` includes discount amounts from the previous promotion, and the new items' total_price is added without discount.

**Verdict**: ⚠️ The totals are not invalid, but the state is confusing. For example:
- Item A: price=100, total_price=90 (previously discounted by 10%)
- Item B: price=50, total_price=50 (just added, no discount)
- Cart total_price = 140 (not 150)
- `getCheckoutTotalsFromCart()` would compute: subtotal=150, promotionDiscount=10, finalTotal=140
- The 10% discount on item A persists but was computed for a different cart composition

**Correctness**: The discount amount on item A is still valid (10% off its original 100). The cart total accurately reflects what the customer agreed to pay. The only concern is if the promotion was a fixed amount (e.g., 15 EGP off), the allocation was based on having both items A and B. With item B now added after the promotion, the 15 EGP discount was distributed only to item A (since it was the only item at apply time), but the promotion would now be re-evaluated at checkout.

### 17.8 Promotion Already Applied

**Behaviour**: If a user calls `calcInvoicePrice()` twice:
1. First call: applies promotion, discounts items
2. Second call: `removeGiftItems()` clears any gifts, then `applySelectedPromotion()` applies promotion again

The second call:
- `PromotionEligibilityResolver::resolve()` → `matchedEligibility()` uses `$item->price * quantity` (not `total_price`), so eligibility subtotal is correct
- `PromotionApplicator::applyOutcome()` re-evaluates using `$item->price * quantity` (original), computes same discount
- Overwrites `promotion_id`, `discount_amount`, `total_price` on items with same values

**Verdict**: ✅ Idempotent. Second call produces same result.

### 17.9 Multiple Requests (Concurrent Checkout)

**Behaviour**: Two concurrent requests:
1. Request A and B both call `calcInvoicePrice()` → `applySelectedPromotion()` → `applyOutcome()`
2. Request A acquires lock on cart + items first
3. Request B waits
4. Request A applies promotion, commits
5. Request B acquires lock, reads updated cart items
6. Request B re-evaluates using `$item->price * quantity` (original, unchanged), computes same discount
7. Request B overwrites the same values (idempotent)

**But**: If Request A calls `checkout()` (order creation) between these two calls, and Request B calls `calcInvoicePrice()` after:
1. Request A: calcInvoicePrice → discount applied to items
2. Request A: checkout → `getCheckoutTotalsFromCart()` reads discounted items → order created with discount
3. Request A: finalizeOrder → incrementUsage
4. Request B: calcInvoicePrice → removes gift items → re-applies same promotion → items discounted again

Both succeed. Orders are created with correct discount amounts. Usage counter incremented twice (correct).

**Verdict**: ✅ Safe due to `lockForUpdate()` and idempotent apply.

### 17.10 Promotion Already Applied (Frontend State Mismatch)

**Behaviour**: If a user:
1. Opens cart → sees items
2. Fetches eligible promotions → sees promotion X
3. Selects promotion X → calls `calcInvoicePrice()` → promotion applied
4. Navigates away without completing checkout
5. Returns to cart later → cart items still have promotion discount
6. Frontend shows reduced prices but may not know why (no promotion data in cart response)
7. User adds new item → cart total recalculated with mix of discounted and undiscounted items

**Verdict**: ⚠️ The frontend has no way to know from the cart response whether a promotion is already applied. The `total_price` is reduced but `promotion_id` and `discount_amount` are not serialized. This is an API consistency issue.

### 17.11 Fast Shipping + Gift Promotion

**Behaviour**: Gift items are created with `shipping_method = 'SCHEDULED'` (column default). Fast checkout finalizes items with `shipping_method = 'FAST'`. Gift items are skipped.

**Verdict**: 🐛 Potential bug — gift inventory not finalized in fast checkout.

---

## 18. Technical TODO

### Critical (P1)

| # | Issue | Current Behaviour | Expected Behaviour | Risk | Files |
|---|-------|-------------------|-------------------|------|-------|
| **1** | `addItemsInOrder()` reads persisted promotion data instead of recalculating | Order may be created without/different promotion discount if cart was modified between `calcInvoicePrice()` and `checkout()` | `addItemsInOrder()` should recalculate promotion from fresh data, not trust persisted `discount_amount` | Order total is wrong | `app/Services/General/OrderService.php:131` |
| **2** | Gift items have no explicit `shipping_method` | Gift items default to `SCHEDULED`; fast checkout does not finalize them | Gift items should inherit the shipping method from the checkout context | Inventory leak for gift items in fast checkout | `app/Services/General/CartInventoryService.php:137` |

### High (P2)

| # | Issue | Current Behaviour | Expected Behaviour | Risk | Files |
|---|-------|-------------------|-------------------|------|-------|
| **3** | Cart response has no promotion data | Frontend cannot determine promotion state from cart alone | Serialize `promotion_id`, `discount_amount`, `is_gift` on items | Frontend state mismatch | `packages/marvel/src/Http/Resources/CartItemResource.php` |
| **4** | `Promotion::discountAmount()` contains business logic in model | Discount formulas live in the Model layer | Strategies should own the calculation; model should be a data container | Model purity violation | `packages/marvel/src/Database/Models/Promotion.php:202` |
| **5** | `PromotionService::applySelectedPromotion()` calls `matchedEligibility()` redundantly | `resolve()` already calls `matchedEligibility()`, then called again | Remove the redundant second call | Double computation | `app/Services/General/PromotionService.php:91` |

### Medium (P3)

| # | Issue | Current Behaviour | Expected Behaviour | Risk | Files |
|---|-------|-------------------|-------------------|------|-------|
| **6** | `getCheckoutTotalsFromCart()` and `calculateCheckoutTotals()` compute coupon discount differently | One uses `subtotal - promotionDiscount - finalTotal`, the other uses `priceAfterPromotion - finalTotal` | Both should use the same formula | 1-cent discrepancy | `app/Services/General/OrderService.php:279-321` |
| **7** | All valid promotions loaded at once without pagination | `Promotion::valid()->get()` loads ALL promotions | Consider pagination or caching for large promotion catalogs | Performance | `app/Services/General/PromotionService.php:33` |
| **8** | Deep eager loading of gift attributes on every eligibility check | `giftProducts.variations.attributeProducts.attributeValue.attribute` loaded even when not needed | Load gift details only when evaluating gift promotions | Extra queries | `app/Services/General/PromotionService.php:37` |

### Low (P4)

| # | Issue | Current Behaviour | Expected Behaviour | Risk | Files |
|---|-------|-------------------|-------------------|------|-------|
| **9** | `has_eligible_promotion` not exposed in cart response | Frontend needs 2 API calls to determine eligibility | Pre-serialization enrichment in controller | UX not optimal | See Phase 15 analysis |
| **10** | Cart items' `price` field may be stale if product price changes | `price` is set at add-to-cart time, promotion uses it for eligibility | Should this be based on current price? (Business decision) | Stale eligibility base | `app/Services/General/CartInventoryService.php:43` |
| **11** | No `decrementUsage()` for cancelled orders | Usage counter only increments, never decrements | Should be a business decision | Potential limiter exhaustion | `app/Services/General/PromotionService.php` |
| **12** | `addItemsInOrder()` uses `whereIn('id', $itemIds)` to reload items after refresh | Items loaded twice (full refresh + filtered load) | Single load with correct state | Redundant query | `app/Services/General/PromotionService.php:101` |

---

## Document Metadata

- **Author**: AI Code Analysis
- **Date**: 2026-07-13
- **Purpose**: Complete Promotion System Lifecycle Audit
- **Scope**: Analysis only — no code changes were made
- **Architecture Compliance**: ADR-001 (Frozen) respected throughout
