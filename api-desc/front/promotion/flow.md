# Data Flow - Promotion Feature

## Flow 1: Public Promotion Listing

```
Client
  |
  GET /api/v1/general/promotions
  |
  v
General\PromotionController@index(Request $request)
  |
  v
PromotionDataService::paginatePromotion($request)
  |
  +-- Promotions filtered by active status and date validity
  +-- Supports with_product flag to load products relation
  |
  v
Public PromotionResource collection
  |--- Maps: id, name (translated), slug, status, image {desktop, mobile}
  |--- Products included when loaded (w/ products relation)
  |
  v
JSON Response
```

## Flow 2: Checkout Eligibility Resolution

```
Cart Page (Client)
  |
  GET /api/v1/general/checkout/promotions
  Authorization: Bearer <token>
  |
  v
OrderController@eligiblePromotions(Cart $cart)
  |
  v
PromotionService::eligiblePromotions($cart)
  |
  +-- $subtotalCents = $cart->items->sum(fn($item) => $item->price * $item->quantity)
  |
  +-- $validPromotions = Promotion::valid()->get()
  |     Scope: status=1, usage<limiter, start_at<=now, end_at>=now
  |
  v
PromotionEligibilityResolver::eligible($cart, $validPromotions, $subtotalCents)
  |
  For each $promotion in $validPromotions:
    |
    +-- PromotionEligibilityResolver::resolve($cart, $promotion, $subtotalCents)
         |
         +-- matchedEligibility(): Check cart items against promotion conditions
         |     - If apply_to=specific_products: filter cart items by promotion.products
         |     - If type=quantity: check required_quantity_type
         |     - Calculate matchedSubtotalCents, matchedQuantity
         |
         +-- Resolve strategy based on $promotion->type_amount:
         |     percentage → PercentagePromotionStrategy
         |     fixed_rate → FixedPromotionStrategy
         |     gift       → GiftPromotionStrategy
         |
         +-- Strategy::eligible($promotion, $cart, $subtotalCents, $evaluation)
         |     - Percentage: check minimum_order_amount
         |     - Fixed: check minimum_order_amount
         |     - Gift: check stock availability for gift products
         |
         +-- If eligible: Strategy::computeOutcome(...)
         |     - Percentage: DiscountOutcome(amountCents = matchedSubtotal * discount%)
         |     - Fixed: DiscountOutcome(amountCents = min(discount, matchedSubtotal))
         |     - Gift: GiftOutcome(giftItems = available gift products)
         |
         +-- Return PromotionResult(promotion, discount, giftItems, matchedSubtotalCents)
    |
  v
Collection<PromotionResult> (eligible only)
  |
  v
JSON Response with eligible promotions data
```

## Flow 3: Apply Selected Promotion at Checkout

```
Checkout Submit (Client)
  |
  POST /api/v1/checkout
  Body: { ..., "selected_promotion_id": 1, "selected_gift_product_id": 5, ... }
  |
  v
OrderCreationService / OrderService
  |
  v
PromotionService::applySelectedPromotion(
    $cart, $promotionId, $selectedGiftProductId, $shippingMethod
  )
  |
  +-- If $promotionId is null:
  |     → clearPromotionFromCart($cart)
  |     → Return CheckoutTotals (no promo)
  |
  +-- Find and validate promotion:
  |     $promotion = Promotion::findOrFail($promotionId)
  |     Verify promotion is valid (status, dates, usage)
  |
  +-- Resolve eligibility:
  |     PromotionEligibilityResolver::resolve($cart, $promotion, $subtotalCents)
  |     → PromotionResult
  |
  +-- If Gift promotion:
  |     CartInventoryService::reserveGiftItem($cart, $promotion, $selectedGiftProductId)
  |     → Creates cart item with is_gift=true, price=0, promotion_id set
  |
  +-- If Discount promotion:
  |     PromotionApplicator::applyOutcome($cart, $promotion, $outcome, $shippingMethod)
  |     → Sets promotion_id and discount_amount on cart items
  |
  +-- Calculate totals with promotion discount
  |
  v
CheckoutTotals { subtotal, promotionDiscount, couponDiscount, finalTotal, promotion, giftItems }
  |
  v
Total includes: subtotal - promotion_discount - coupon_discount + shipping = final_total
```

## Flow 4: Order Creation with Promotion Snapshot

```
Order Confirmation
  |
  v
OrderCreationService::createOrder($cart, $request)
  |
  +-- Save order-level promotion data:
  |     $order->promotion_id = $promotion->id
  |     $order->promotion_code = $promotion->code
  |     $order->promotion_type = $promotion->type_amount
  |     $order->promotion_discount = $promotionDiscount
  |
  +-- Save line-item promotion data:
  |     $orderProduct->promotion_id = $promotion->id
  |     $orderProduct->promotion_discount_amount = $item->discount_amount
  |
  +-- PromotionService::incrementUsage($promotion->id)
  |     → UPDATE promotions SET usage = usage + 1 WHERE id = ?
  |     (locked, respects limiter)
  |
  v
Order created with promotion snapshot
```

## Flow 5: Admin Promotion Creation

```
Client
  |
  POST /api/v1/promotions
  Authorization: Bearer <token>
  Content-Type: multipart/form-data
  Body: name[en]=..., type=price, type_amount=percentage, discount=20, ...
  |
  v
Permission middleware: create-promotion
  |
  v
PromotionController@store(PromotionRequest $request)
  |
  +-- Request validation:
  |     |-- Complex conditional rules based on type/type_amount
  |     |-- product_ids required_if:apply_to=specific
  |     |-- gift_products required_if:type_amount=gift
  |     |-- max_discount_amount required_if:type_amount=percentage
  |
  v
PromotionRepository::storePromotion($request)
  |
  +-- DB::beginTransaction()
  |
  +-- Normalize data: sync discount and value fields
  |
  +-- Create Promotion model:
  |     |-- name (translatable), type, type_amount, discount, value
  |     |-- code auto-generated (if empty)
  |     |-- slug auto-generated by Sluggable trait
  |     |-- Additional fields: status, limiter, dates, etc.
  |
  +-- Upload images:
  |     |-- image-desktop → media collection 'promotions-desktop'
  |     |-- image-mobile → media collection 'promotions-mobile'
  |
  +-- Sync product associations:
  |     |-- Sync product_ids → promotion_product pivot
  |     |-- Sync gift_products data → promotion_gift_products pivot
  |     |     (with product_variant_id and quantity)
  |
  +-- DB::commit()
  |
  v
PromotionObserver::created()
  |--- LogActivityJob::dispatch('activity.promotion_created')
  |
  v
Admin PromotionResource response (201)
```

## Flow 6: Promotion Engine Strategy Resolution

```
PromotionEligibilityResolver::resolve(cart, promotion, subtotalCents)
  |
  +-- matchedEligibility(cart, promotion, subtotalCents)
  |     → PromotionEvaluation { matchedItems, matchedSubtotalCents, matchedQuantity }
  |
  +-- Switch on promotion->type_amount:
       |
       |--- "percentage" → PercentagePromotionStrategy
       |    |-- eligible(): check minimum_order_amount
       |    |-- computeOutcome():
       |    |     discountCents = matchedSubtotalCents * discount / 100
       |    |     if max_discount_amount: cap discountCents
       |    |     → DiscountOutcome(amountCents, baseAmountCents)
       |
       |--- "fixed_rate" → FixedPromotionStrategy
       |    |-- eligible(): check minimum_order_amount
       |    |-- computeOutcome():
       |    |     discountCents = min(discountCents, matchedSubtotalCents)  // floor at 0
       |    |     → DiscountOutcome(amountCents, baseAmountCents)
       |
       |--- "gift" → GiftPromotionStrategy
            |-- eligible(): check minimum_order_amount
            |    check stock for all gift products
            |-- computeOutcome():
                 → GiftOutcome(giftItems = [GiftItem, ...])
                   Each GiftItem:
                     - productId, productVariantId, productName
                     - quantity (from pivot), priceCents = 0, isGift = true
```
