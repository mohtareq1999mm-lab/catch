# Request Flows — Promotion Module

## Flow 1: List Promotions (Admin)

```
Client → GET /api/v1/promotions?search=summer&type_amount=percentage&page=1&limit=15
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-promotion] middleware → check Spatie permission
         ↓
    PromotionController@index(Request)
         ↓
    Apply filters:
      - search → where name/code/type LIKE '%summer%'
      - status → where('status', $bool)
      - type → where('type', $type)
      - type_amount → where('type_amount', $typeAmount)
      - order_by / sort → orderBy($orderBy, $sort)
         ↓
    PromotionRepository → paginate($limit)
         ↓
    PromotionResource::collection($promotions) → transform each promotion
         ↓
    Return: { status:200, message, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create Promotion (Admin)

```
Client → POST /api/v1/promotions (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:create-promotion]
         ↓
    PromotionRequest → validation rules (name, images, type, type_amount, products, discounts, etc.)
         ↓
    Fail? → 422 with field errors
         ↓
    PromotionController@store($request)
         ↓
    PromotionRepository::storePromotion($request)
         ↓
    1. Extract data: $request->only($this->dataArray)
    2. Generate slug via makeSlug($request)
    3. Normalize: if discount is set without value, copy; if value is set without discount, copy
    4. Promotion::create($data)
    5. Sync products ($promotion->products()->sync($product_ids))
    6. Sync gift products (validate variant belongs to product)
    7. Upload image-desktop → 'promotions-desktop' collection
    8. Upload image-mobile → 'promotions-mobile' collection
         ↓
    PromotionResource::make($promotion)
         ↓
    PromotionObserver@created() → dispatches LogActivityJob
         ↓
    Return: { status:201, message, success:true, data }
```

## Flow 3: Show Promotion (Admin)

```
Client → GET /api/v1/promotions/1
         ↓
    [auth:sanctum] → [permission:view-promotion]
         ↓
    PromotionController@show($id)
         ↓
    PromotionRepository::findOrFail($id)  [find by numeric ID only]
         ↓
    Found? → PromotionResource::make($promotion) → 200
    Not found? → Throwable(NOT_FOUND) → 404
```

## Flow 4: Update Promotion (Admin)

```
Client → PUT /api/v1/promotions/1 (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:update-promotion]
         ↓
    UpdatePromotionRequest → validation (all fields sometimes)
         ↓
    PromotionController@update($request, $id)
         ↓
    PromotionRepository::updatePromotion($id, $request)
         ↓
    1. Find promotion (findOrFail by ID)
    2. Extract data from request
    3. Normalize promotion data
    4. Regenerate slug via makeSlug() with update ID
    5. $promotion->update($data)
    6. Sync products if provided (replaces all)
    7. Sync gift products if provided (replaces all)
    8. If image-desktop → updateSingleImage() [clears + uploads]
    9. If image-mobile → updateSingleImage() [clears + uploads]
         ↓
    PromotionResource::make($promotion)
         ↓
    PromotionObserver@updated() → dispatches LogActivityJob
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 5: Delete Promotion (Admin)

```
Client → DELETE /api/v1/promotions/1
         ↓
    [auth:sanctum] → [permission:delete-promotion]
         ↓
    PromotionController@destroy($id)
         ↓
    PromotionRepository::findOrFail($id)
         ↓
    $promotion->delete()  → hard deletes (no soft delete trait)
         ↓
    Pivot records cascade-deleted (FK ON DELETE CASCADE)
         ↓
    PromotionObserver@deleted() → dispatches LogActivityJob
         ↓
    Return: { status:200, message, success:true }
```

## Flow 6: List Valid Promotions (Public)

```
Client → GET /api/v1/general/promotions?limit=10&promotionsId=1,2,3
         ↓
    PromotionController@index(Request)
         ↓
    If slug query param → delegate to getPromotionBySlug()
         ↓
    PromotionDataService::paginatePromotion($request)
         ↓
    Promotion::valid()
      ├─ where('status', true)
      ├─ where (usage < limiter OR limiter IS NULL)
      ├─ where (start_at IS NULL OR start_at <= today)
      └─ where (end_at IS NULL OR end_at >= today)
      ├─ Filter by start_date / end_date
      └─ Filter by promotionsId (comma-separated)
         ↓
    orderBy('id', $order) → limit($limit) → paginate()
         ↓
    PromotionResource::collection($promotions)
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 7: Get Promotion by Slug (Public)

```
Client → GET /api/v1/general/promotions/summer-special-20-off
         ↓
    PromotionController@getPromotionBySlug($slug)
         ↓
    PromotionDataService::getPromotionBySlug($slug)
         ↓
    Promotion::search('slug', $slug, locale)->first()
         ↓
    If found:
      ├─ Load products with channel filter, media
      └─ ProductService::enrichCollectionWithPricing()
         ↓
    PromotionResource::make($promotion) → 200
         ↓
    Not found → Return: { status:404, message, success:false }
```

## Flow 8: Get Eligible Promotions (Checkout)

```
Client → GET /api/v1/checkout/promotions
         ↓
    [auth:sanctum] middleware
         ↓
    OrderController@eligiblePromotions()
         ↓
    Find user's active cart
         ↓
    PromotionService::eligiblePromotionsPayload($cart)
         ↓
    PromotionService::eligiblePromotions($cart)
         ↓
    $cart->load(['items.product', 'items.productVariant'])
         ↓
    Compute subtotal in cents
         ↓
    Fetch valid promotions with products + gift products eager loaded
         ↓
    PromotionEligibilityResolver::eligible($cart, $promotions, $subtotalCents)
         ↓
    For each promotion:
      → resolve($cart, $promotion, $subtotalCents)
        → matchedEligibility() — scope cart items to promotion products
        → strategy->eligible() — check minimum amount, quantity
        → strategy->computeOutcome() — calculate discount or gift items
        → PromotionResult (promotion, discount, giftItems)
         ↓
    Filter out null results (ineligible promotions)
         ↓
    Map to array payload (eligible_promotions → id, type, title, code, discount, gift_items)
         ↓
    Return: { eligible_promotions: [...] }
```

## Flow 9: Apply Promotion to Cart (Checkout)

```
Client → Checkout flow (internal, via PromotionService)
         ↓
    PromotionService::applySelectedPromotion($cart, $promotionId, $selectedGiftProductId, $shippingMethod)
         ↓
    1. Remove existing gift items (release inventory)
    2. Load cart items with product + variant
    3. Compute subtotal in cents
         ↓
    If $promotionId is null:
      → clearPromotionFromCart($cart)
      → Return CheckoutTotals with zero discount
         ↓
    If $promotionId is set:
      → Fetch promotion with lockForUpdate()
      → If not valid → throw InvalidArgumentException
         ↓
      → resolver->resolve($cart, $promotion, $subtotalCents)
      → If not eligible → throw InvalidArgumentException
         ↓
      → If discount amount > 0:
        → DiscountOutcome
        → applicator->applyOutcome($cart, $promotion, $discountOutcome)
          → DB::transaction
            → Lock promotion + cart rows
            → Re-evaluate matched eligibility
            → Proportional allocation (largest remainder method)
            → Persist promotion_id, discount_amount, total_price on each item
            → Update cart total_price
         ↓
      → If gift items exist:
        → Resolve selected gift item
        → GiftOutcome
        → applicator->applyOutcome($cart, $promotion, $giftOutcome, $shippingMethod)
          → DB::transaction
            → Lock product row
            → reserveGiftItem() → inventory reservation
            → Gift priced at 0
            → Update cart total_price (excluding gifts)
         ↓
      → Return CheckoutTotals (subtotal, promotionDiscount, finalTotal, promotion, giftItems)
```

## Flow 10: Usage Increment/Decrement

```
Increment (on order placement):
    → OrderController or CheckoutService
    → PromotionService::incrementUsage($promotionId)
      → Promotion::lockForUpdate()
      → where('usage', '<', 'limiter') OR whereNull('limiter')
      → $promotion->increment('usage')

Decrement (on order cancellation):
    → OrderController::changeOrderStatus() [when new status = cancelled, previous != cancelled]
    → $order->promotion_id → PromotionService::decrementUsage($promotionId)
      → Promotion::lockForUpdate()
      → where('usage', '>', 0)
      → $promotion->decrement('usage')
```
