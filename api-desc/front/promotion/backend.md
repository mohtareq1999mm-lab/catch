# Backend - Promotion Feature

## Overview

The Promotion feature spans two layers with a sophisticated promotion engine:

1. **App Layer (`app/`)**: Public API + Promotion Engine (Strategy Pattern) + Checkout Integration
2. **Package Layer (`packages/marvel/`)**: Admin CRUD + Model + Repository + Form Requests

## Key Files

### 1. Model - `packages/marvel/src/Database/Models/Promotion.php`

**Table:** `promotions`

**Traits:** `HasTranslations` (Spatie), `InteractsWithMedia` (Spatie), `Sluggable` (cviebrock)

**Translatable:** `['name']`

**Fillable:**
- `name`, `slug`, `type`, `type_amount`, `value`, `discount`, `max_discount_amount`, `code`
- `required_quantity_type`, `minimum_order_amount`, `apply_to`, `limiter`, `usage`
- `start_at`, `end_at`, `status`

**Casts:**
- `start_at` → `date`, `end_at` → `date`
- `status` → `boolean`, `usage` → `integer`, `limiter` → `integer`
- `value` → `float`, `discount` → `float`
- `minimum_order_amount` → `float`, `max_discount_amount` → `float`

**Relationships:**

| Method | Type | Related | Pivot |
|--------|------|---------|-------|
| `products()` | `BelongsToMany` | `Product` | `promotion_product` |
| `giftProducts()` | `BelongsToMany` | `Product` | `promotion_gift_products` (with pivot: `quantity`, `product_variant_id`) |

**Scopes:** `active()`, `valid()` (status + limiter + date range), `search()`

**Boot Events:**
- `creating`: Auto-generates `code` if empty
- `saving`: Syncs `discount` and `value` fields

**Helper Methods:**
- `appliesToAllProducts()`, `isValid()`, `isGiftPromotion()`, `isPercentagePromotion()`, `isFixedRatePromotion()`
- `discountAmount($price, $qty)`, `calcPrice($price, $qty)`, `applyGift($qty)`

### 2. Repository - `packages/marvel/src/Database/Repositories/PromotionRepository.php`

**Extends:** `BaseRepository`

| Method | Description |
|--------|-------------|
| `model()` | Returns `Promotion::class` |
| `boot()` | Pushes `RequestCriteria` |
| `storePromotion(Request)` | Creates in transaction (model + images + products + gift products) |
| `updatePromotion($id, Request)` | Updates in transaction |
| `normalizePromotionData(array)` | Syncs discount/value fields |
| `syncPromotionProducts(Promotion, Request)` | Syncs product_ids, gift_product_ids, gift_products |

### 3. Controller (Admin) - `packages/marvel/src/Http/Controllers/PromotionController.php`

**Extends:** `CoreController`

**Permissions:**

| Method | Permission |
|--------|-----------|
| `index` | `view-promotion` |
| `store` | `create-promotion` |
| `show` | `view-promotion` |
| `update` | `update-promotion` |
| `destroy` | `delete-promotion` |

### 4. Controller (Public) - `app/Http/Controllers/Api/General/PromotionController.php`

| Method | Description |
|--------|-------------|
| `index(Request)` | Lists all or by slug. Supports `with_product` flag. Returns `PromotionResource` collection. |
| `getPromotionBySlug($slug)` | Single promotion with products. Returns `PromotionResource`. |

### 5. Promotion Engine (Strategy Pattern)

The promotion engine lives in `app/Services/General/PromotionEngine/`:

```
PromotionEligibilityResolver
  - eligible(Cart, Collection<Promotion>, subtotalCents): Collection<PromotionResult>
  - resolve(Cart, Promotion, subtotalCents): ?PromotionResult
  - matchedEligibility(Cart, Promotion, subtotalCents): PromotionEvaluation

PromotionApplicator
  - applyOutcome(Cart, Promotion, PromotionOutcome, ?shippingMethod): array

Contracts/PromotionStrategy (Interface)
  - eligible(): bool
  - computeOutcome(): PromotionOutcome

Strategies/
  - PercentagePromotionStrategy  (type_amount: percentage)
  - FixedPromotionStrategy       (type_amount: fixed_rate)
  - GiftPromotionStrategy        (type_amount: gift)
  - AbstractPromotionStrategy    (base class)

Outcome/
  - DiscountOutcome  (amountCents, baseAmountCents)
  - GiftOutcome      (GiftItem[])

DTOs/
  - PromotionResult   (promotion, discount, giftItems, matchedSubtotalCents)
  - PromotionEvaluation (matchedItems, matchedSubtotalCents, matchedQuantity)
  - GiftItem          (productId, productVariantId, quantity, priceCents, isGift, ...)
```

### 6. Checkout Integration Services

| Service | Key Methods | Purpose |
|---------|-------------|---------|
| `PromotionService` | `eligiblePromotions()`, `applySelectedPromotion()`, `clearPromotionFromCart()`, `incrementUsage()`, `decrementUsage()`, `hasEligiblePromotion()` | Core checkout promotion operations |
| `CartInventoryService` | `reserveGiftItem()` | Creates gift cart items |
| `OrderService` | `eligiblePromotionsForUser()`, `calculateCheckoutTotals()` | Order-level promotion integration |
| `OrderCreationService` | Creates order with promotion snapshot | Saves promotion data on order |
| `InvoiceSnapshotService` | Captures promotion discount per line item | Invoice audit trail |

### 7. Form Requests

**PromotionRequest** (Create):
- `name` (required, array)
- `image-desktop` (required, image, mimes, max:2MB)
- `image-mobile` (required, image, mimes, max:2MB)
- `type` (required, in: PromotionType values)
- `type_amount` (required, in: PromotionMountType values)
- `product_ids` (required_if:apply_to=specific, prohibited_if:all_products)
- `gift_products` (required_if:type_amount=gift)
- `gift_products.*.product_id` (required_with, exists)
- `gift_products.*.product_variant_id` (nullable, exists)
- `gift_products.*.quantity` (sometimes, integer, min:1)
- `discount` (required_if: not quantity+gift, numeric, min:0)
- `max_discount_amount` (required_if:type_amount=percentage)
- `minimum_order_amount` (required_if:type≠quantity, numeric, min:0)
- `apply_to` (required, in: all_products, specific_products)
- `limiter` (sometimes, integer, min:1)
- `start_at` (sometimes, date)
- `end_at` (after_or_equal:start_at)
- `status` (sometimes, in:0,1)

**UpdatePromotionRequest**: Same rules but all fields `sometimes`.

### 8. API Resources

| Resource | Route | Fields |
|----------|-------|--------|
| `PromotionResource` (Admin) | Admin CRUD | id, name, slug, type, discount_type, value, discount, code, minimum_order_amount, required_quantity, apply_to, products, gift_products, image {desktop, mobile}, start_at, end_at, status, is_valid, created_at |
| `PromotionResource` (Public) | General routes | id, name, slug, status, image {desktop, mobile}, products (when loaded) |

### 9. Enums

| Enum | Values |
|------|--------|
| `PromotionType` | `price`, `quantity` |
| `PromotionMountType` | `fixed_rate`, `percentage`, `gift` |
| `Permission` | `view-promotion`, `create-promotion`, `update-promotion`, `delete-promotion` |

### 10. Observer - `app/Observers/PromotionObserver.php`

| Event | Action |
|-------|--------|
| `created` | Dispatches `LogActivityJob('promotion_created')` |
| `updated` | Dispatches `LogActivityJob` with field-level change tracking, detects activated/deactivated |
| `deleted` | Dispatches `LogActivityJob('promotion_deleted')` |

Tracked fields: `name, slug, type, type_amount, value, discount, max_discount_amount, minimum_order_amount, apply_to, required_quantity_type, limiter, usage, start_at, end_at, status`

### 11. Media Collections

| Collection | Used In |
|-----------|---------|
| `promotions-desktop` | Desktop banner image |
| `promotions-mobile` | Mobile banner image |

## Data Flow (Checkout Promotion Eligibility)

```
Cart Page (Client)
  |
  GET /api/v1/general/checkout/promotions
  |
  v
OrderController@eligiblePromotions()
  |
  v
PromotionService::eligiblePromotions($cart)
  |
  v
PromotionEligibilityResolver::eligible($cart, $promotions, $subtotalCents)
  |
  For each promotion:
    |
    v
    PromotionEligibilityResolver::resolve($cart, $promotion, $subtotalCents)
      |
      +-- Check: isValid() (status, date range, limiter)
      +-- Resolve strategy based on type_amount:
      |     percentage → PercentagePromotionStrategy
      |     fixed_rate → FixedPromotionStrategy
      |     gift       → GiftPromotionStrategy
      |
      +-- Strategy::eligible($promotion, $cart, $subtotalCents, $evaluation)
      |     - Check matched items/quantity/subtotal
      |     - Check minimum_order_amount
      |
      +-- If eligible: Strategy::computeOutcome()
      |     → DiscountOutcome or GiftOutcome
      |
      +-- Return PromotionResult(promotion, discount, giftItems, matchedSubtotal)
  |
  v
Collection<PromotionResult>
```
