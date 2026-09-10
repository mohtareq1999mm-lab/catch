# Promotion System Analysis

> **Task**: Reverse engineering and documentation only.
> **No code changes were made.**

---

## 1. Promotion System Overview

The promotion system provides rules-based discount and gift logic at the cart/checkout level. It is entirely separate from product-level pricing (discounts, flash sales) which is handled by `ProductPricingService`. Promotions are **cart-level** reductions selected by the customer during checkout.

### Key Design Characteristics

- **Strategy Pattern** — Each promotion type (percentage, fixed, gift) has its own strategy class implementing `PromotionStrategy`.
- **Pipeline Architecture** — `Resolve → Evaluate → Compute → Apply`.
- **Cents-Based Math** — All monetary calculations use integer cents internally to avoid float rounding errors.
- **Database-Backed** — Promotion definitions, product associations, gift products, and usage tracking are stored in the database.
- **Transactional Apply** — Discounting and gift reservation happen inside `DB::transaction()` with `lockForUpdate()` row locking.
- **No Coupon Overlap** — Promotion is a separate concept from coupons. Promotion discount and coupon discount are computed independently and both applied.
- **Admin CRUD** — Marvel package provides full admin CRUD.
- **Customer Facing** — App layer provides read-only listing and selection endpoints.

---

## 2. Component Map

### 2.1 App Layer (`app/`) — Runtime

| Component | Path | Responsibility |
|-----------|------|----------------|
| `PromotionController` | `app/Http/Controllers/Api/General/PromotionController.php` | Public read-only endpoints: list promotions, show by slug |
| `PromotionResource` | `app/Http/Resources/Promotion/PromotionResource.php` | Serializes promotion data including loaded products |
| `PromotionService` | `app/Services/General/PromotionService.php` | Orchestrates eligibility check, promotion selection, apply, usage increment |
| `PromotionDataService` | `app/Services/General/PromotionDataService.php` | Data query service for promotion listing/by-slug (with product enrichment) |
| `PromotionEligibilityResolver` | `app/Services/General/PromotionEngine/PromotionEligibilityResolver.php` | Resolves which promotions are eligible for a given cart; produces `PromotionResult` |
| `PromotionApplicator` | `app/Services/General/PromotionEngine/PromotionApplicator.php` | Applies a computed outcome to cart items and cart totals in a transaction |
| `PromotionEvaluation` | `app/Services/General/PromotionEngine/PromotionEvaluation.php` | Immutable DTO: matched items, matched subtotal in cents, matched quantity |
| `PromotionResult` | `app/Services/General/PromotionEngine/PromotionResult.php` | Immutable DTO: promotion, discount amount, gift items |
| `PromotionStrategy` (interface) | `app/Services/General/PromotionEngine/Contracts/PromotionStrategy.php` | Contract: `eligible()` + `computeOutcome()` |
| `AbstractPromotionStrategy` | `app/Services/General/PromotionEngine/Strategies/AbstractPromotionStrategy.php` | Shared eligibility logic (validity, minimum order, required quantity) |
| `PercentagePromotionStrategy` | `app/Services/General/PromotionEngine/Strategies/PercentagePromotionStrategy.php` | Percentage discount calculation |
| `FixedPromotionStrategy` | `app/Services/General/PromotionEngine/Strategies/FixedPromotionStrategy.php` | Fixed rate discount calculation |
| `GiftPromotionStrategy` | `app/Services/General/PromotionEngine/Strategies/GiftPromotionStrategy.php` | Gift product resolution |
| `PromotionOutcome` (abstract) | `app/Services/General/PromotionEngine/Outcome/PromotionOutcome.php` | Base class for outcomes |
| `DiscountOutcome` | `app/Services/General/PromotionEngine/Outcome/DiscountOutcome.php` | Amount in cents + base amount in cents |
| `GiftOutcome` | `app/Services/General/PromotionEngine/Outcome/GiftOutcome.php` | Array of GiftItem DTOs |
| `GiftItem` | `app/Services/General/PromotionEngine/DTOs/GiftItem.php` | Immutable DTO: product_id, variant_id, variant payload, name, sku, image, quantity, price_cents, is_gift |
| `PromotionObserver` | `app/Observers/PromotionObserver.php` | Logs activity on promotion create/update/delete |

### 2.2 Marvel Package (`packages/marvel/`) — Admin CRUD

| Component | Path | Responsibility |
|-----------|------|----------------|
| `PromotionController` | `packages/marvel/src/Http/Controllers/PromotionController.php` | Admin CRUD: index, store, show, update, destroy |
| `Promotion` (Model) | `packages/marvel/src/Database/models/Promotion.php` | Eloquent model with scopes, relationships, and discount calculation helper |
| `promotionShop` (Model) | `packages/marvel/src/Database/models/promotionShop.php` | Shop relationship model |
| `PromotionRepository` | `packages/marvel/src/Database/Repositories/PromotionRepository.php` | Repository pattern for store/update with image upload and product sync |
| `PromotionRequest` | `packages/marvel/src/Http/Requests/PromotionRequest.php` | Validation for create |
| `UpdatePromotionRequest` | `packages/marvel/src/Http/Requests/UpdatePromotionRequest.php` | Validation for update |
| `PromotionResource` (Marvel) | `packages/marvel/src/Http/Resources/PromotionResource.php` | Admin-side serializer |
| `PromotionMountType` (Enum) | `packages/marvel/src/Enums/PromotionMountType.php` | `FIXED_RATE`, `PERCENTAGE`, `GIFT` |
| `PromotionType` (Enum) | `packages/marvel/src/Enums/PromotionType.php` | `PRICE`, `QTY` (quantity-based) |

### 2.3 Routes

| Method | Path | Layer | Handler | Purpose |
|--------|------|-------|---------|---------|
| GET | `/api/v1/general/promotions` | App | `PromotionController@index` | List promotions (supports `?slug=` filter) |
| GET | `/api/v1/general/promotions/{slug}` | App | `PromotionController@getPromotionBySlug` | Show single promotion with products |
| GET | `/api/v1/general/checkout/promotions` | App | `OrderController@eligiblePromotions` | List eligible promotions for current user's cart |
| GET | `/api/v1/promotions` | Marvel | `PromotionController@index` | Admin paginated list |
| POST | `/api/v1/promotions` | Marvel | `PromotionController@store` | Admin create |
| GET | `/api/v1/promotions/{id}` | Marvel | `PromotionController@show` | Admin show |
| PUT | `/api/v1/promotions/{id}` | Marvel | `PromotionController@update` | Admin update |
| DELETE | `/api/v1/promotions/{id}` | Marvel | `PromotionController@destroy` | Admin delete |

### 2.4 Database Migrations

| Migration | Purpose |
|-----------|---------|
| `2020_04_29_000001_create_promotions_table.php` | Creates `promotions` table with all base columns |
| `2026_05_03_111116_create_promotion_product_table.php` | Creates `promotion_product` pivot table |
| `2026_05_17_000001_add_selected_promotion_checkout_fields.php` | Adds columns to `promotions`, creates `promotion_gift_products` table, adds promotion/gift columns to `cart_items`, `order_products`, `orders` |

### 2.5 Other Consumers

| File | How It Uses Promotions |
|------|----------------------|
| `app/Services/General/OrderService.php` | Calls `PromotionService::eligiblePromotionsPayload()`, `PromotionService::applySelectedPromotion()`, reads promotion data from cart items |
| `app/Services/Checkout/OrderCreationService.php` | Writes `promotion_id`, `promotion_code`, `promotion_type`, `promotion_discount` to order; writes `promotion_id` and `promotion_discount_amount` to order items; calls `PromotionService::incrementUsage()` |
| `app/Services/General/CartInventoryService.php` | Reserves gift items via `reserveGiftItem()` |
| `app/Http/Resources/Order/OrderResource.php` | Serializes promotion metadata on orders |

---

## 3. Execution Flow

### 3.1 End-to-End Checkout Flow with Promotion

```
Customer checks out
       │
       ▼
OrderController::checkout()
       │
       ▼
OrderService::calcInvoicePrice($request)
       │
       ▼
OrderService::calculateCheckoutTotals($cart, $selectedPromotionId, $selectedGiftProductId)
       │
       ├── Promotion 
       │       │
       │       ▼
       │   PromotionService::applySelectedPromotion($cart, $promotionId, $giftProductId)
       │       │
       │       ├── PromotionService::removeGiftItems($cart)          — clear previous gifts
       │       ├── Load promotion with products + giftProducts + giftProducts.variations
       │       │
       │       ├── PromotionEligibilityResolver::resolve($cart, $promotion, $subtotalCents)
       │       │       │
       │       │       ├── Select strategy by $promotion->type_amount
       │       │       ├── PromotionEligibilityResolver::matchedEligibility($cart, $promotion)
       │       │       │       ├── Filter cart items: exclude gifts, match product_ids
       │       │       │       ├── Compute matched subtotal (cents)    ← from item->price * quantity
       │       │       │       ├── If appliesToAllProducts(), use full subtotal
       │       │       │       └── Return PromotionEvaluation(matchedItems, matchedSubtotalCents, matchedQty)
       │       │       │
       │       │       ├── Strategy::eligible($promotion, $cart, $subtotalCents, $evaluation)
       │       │       │       ├── Promotion::isValid()               ← status + dates + limiter
       │       │       │       ├── Check minimum_order_amount          ← against matchedSubtotal
       │       │       │       ├── Check required_quantity_type        ← against matchedQuantity
       │       │       │       └── (Gift only) check giftProducts is not empty
       │       │       │
       │       │       ├── Strategy::computeOutcome($promotion, $cart, $subtotalCents, $evaluation)
       │       │       │       ├── Percentage: $matchedSubtotal × ($discount% / 100) → DiscountOutcome
       │       │       │       ├── Fixed: min($matchedSubtotal, $discount) → DiscountOutcome
       │       │       │       ├── Gift: resolve gift products with stock → GiftOutcome
       │       │       │       └── Return Outcome
       │       │       │
       │       │       └── Wrap outcome → PromotionResult(promotion, discount, giftItems)
       │       │
       │       └── (if amountCents > 0) PromotionApplicator::applyOutcome($cart, $promotion, $outcome)
       │               │
       │               ├── DB::transaction
       │               ├── Lock promotion row (lockForUpdate)
       │               ├── Lock cart + items (lockForUpdate)
       │               ├── Re-evaluate matched eligibility
       │               ├── For DiscountOutcome:
       │               │       ├── Proportional allocation across matched lines (largest remainder method)
       │               │       ├── Update each cart item: promotion_id, discount_amount, total_price
       │               │       └── Update cart total_price
       │               └── For GiftOutcome:
       │                       ├── Reserve each gift via CartInventoryService::reserveGiftItem()
       │                       └── Update cart total_price
       │
       ├── Coupon (after promotion)
       │       └── OrderService::calculatePriceByCoupon($cart, $priceAfterPromotion)
       │
       └── Return CheckoutTotals(subtotal, promotionDiscount, couponDiscount, finalTotal, promotion, giftItems)
       │
       ▼
OrderService::addItemsInOrder($request)
       │
       ├── OrderCreationService::createOrder()         — writes promotion_id/code/type/discount to order
       ├── OrderCreationService::createOrderItems()     — writes promotion_id, promotion_discount_amount per item
       └── PromotionService::incrementUsage(promotionId)  — increments usage counter
```

### 3.2 Eligible Promotions Listing Flow

```
Customer visits checkout page
        │
        ▼
OrderController::eligiblePromotions()
        │
        ▼
OrderService::eligiblePromotionsForUser()
        │
        ▼
PromotionService::eligiblePromotionsPayload($cart)
        │
        ├── Load cart with items.product, items.productVariant
        ├── Compute subtotal (cents)
        ├── Promotion::valid() scope — status=true, usage<limiter, start_at≤today≤end_at
        │       └── Eager load: products:id, giftProducts + variations + attributes
        │
        ├── PromotionEligibilityResolver::eligible($cart, $promotions, $subtotalCents)
        │       └── For each promotion: resolve() → filter out null results
        │
        └── Map PromotionResult[] → array with discount/gift_items
```

### 3.3 Promotion Browse Flow (App)

```
GET /api/v1/general/promotions
        │
        ▼
PromotionController::index($request)
        │
        ├── If ?slug= → PromotionDataService::getPromotionBySlug()
        │       ├── Promotion::search('slug', ...)
        │       ├── load(['products' => channelHomeFilter])
        │       ├── enrichCollectionWithPricing(products)
        │       └── PromotionResource::make()
        │
        └── else → PromotionDataService::paginatePromotion()
                ├── Promotion::valid()->...->paginate()
                └── PromotionResource::collection()
```

---

## 4. Promotion Types

### 4.1 `PromotionMountType` Enum (the actual type discriminator)

| Value | Strategy Class | Outcome Type | Description |
|-------|---------------|--------------|-------------|
| `fixed_rate` | `FixedPromotionStrategy` | `DiscountOutcome` | Subtract a fixed amount from the matched subtotal |
| `percentage` | `PercentagePromotionStrategy` | `DiscountOutcome` | Subtract a percentage of the matched subtotal (with optional `max_discount_amount` cap) |
| `gift` | `GiftPromotionStrategy` | `GiftOutcome` | Add gift product(s) at zero price instead of a monetary discount |

### 4.2 `PromotionType` Enum (discriminators within `GIFT`)

| Value | Meaning | Usage |
|-------|---------|-------|
| `price` | Price-based promotion | Used by percentage and fixed_rate promotions |
| `quantity` | Quantity-based promotion | Used by gift promotions (e.g., "buy X get Y free") |

### 4.3 Discount Amount Computation

**Percentage Promotion** (in `Promotion::discountAmount()`):
```
discount = matchedSubtotal × (discount_value / 100)
if max_discount_amount is set:
    discount = min(discount, max_discount_amount)
result = round(max(0, discount), 2)
```

**Fixed Rate Promotion** (in `Promotion::discountAmount()`):
```
discount = min(matchedSubtotal, discount_value)
result = round(max(0, discount), 2)
```

**Gift Promotion**:
```
discount = 0.0  (no monetary discount; gift products are added at price_cents=0)
```

### 4.4 Calculation Examples

#### Example 1: Percentage Promotion
```
Cart subtotal: 1,000 EGP
Promotion: 10% off, max_discount = 50 EGP
discount = 1,000 × (10/100) = 100
capped = min(100, 50) = 50
Final price after promotion: 1,000 - 50 = 950 EGP
```

#### Example 2: Fixed Rate Promotion
```
Cart subtotal: 1,000 EGP
Promotion: 75 EGP off
discount = min(1,000, 75) = 75
Final price after promotion: 1,000 - 75 = 925 EGP
```

#### Example 3: Gift Promotion
```
Cart subtotal: 500 EGP
Promotion: Gift product valued at 0
discount = 0
Final price after promotion: 500 EGP (gift item added with price_cents = 0)
```

---

## 5. Eligibility Rules

All rules are enforced in the **strategy layer** — `AbstractPromotionStrategy::eligible()` and strategy-specific overrides.

### 5.1 Base Eligibility (all types)

Rule enforced in `AbstractPromotionStrategy::eligible()`:

| Rule | Code | Source |
|------|------|--------|
| Promotion must be active | `$promotion->isValid()` | `Promotion::isValid()` — checks `status=true`, `start_at≤today`, `end_at≥today`, `usage < limiter` |
| Minimum order amount | `$evaluation->matchedSubtotalCents >= minimum_order_amount * 100` | `$promotion->minimum_order_amount` field |
| Minimum quantity | `$promotion->isRequiredQuantityTrue($evaluation->matchedQuantity)` | `$promotion->required_quantity_type` field |

### 5.2 Product Eligibility

| Rule | Code | Location |
|------|------|----------|
| Applies to all products | `$promotion->appliesToAllProducts()` → checks `apply_to === 'all_products'` | `Promotion::appliesToAllProducts()` |
| Applies to specific products | `in_array($item->product_id, $promotion->products->pluck('id'))` | `PromotionEligibilityResolver::matchedEligibility()` |
| Gift items excluded from eligibility | `(bool) ($item->is_gift ?? false)` → filtered out | `PromotionEligibilityResolver::matchedEligibility()` |

### 5.3 Gift-Specific Eligibility (additional check)

| Rule | Code | Location |
|------|------|----------|
| Gift products must exist | `$promotion->giftProducts->isNotEmpty()` | `GiftPromotionStrategy::eligible()` |
| Gift product must have available stock | `available_stock > 0` (or variant with stock) | `GiftPromotionStrategy::hasAvailableStock()` |

### 5.4 Eligibility Summary

| Condition | Where Enforced |
|-----------|---------------|
| `status = true` | `Promotion::isValid()` (called from `AbstractPromotionStrategy`) |
| `start_at <= today` | `Promotion::isValid()` |
| `end_at >= today` | `Promotion::isValid()` |
| `usage < limiter` (if limiter is set) | `Promotion::isValid()` |
| Cart matched subtotal ≥ `minimum_order_amount` | `AbstractPromotionStrategy::eligible()` |
| Cart matched quantity ≥ `required_quantity_type` | `AbstractPromotionStrategy::eligible()` via `Promotion::isRequiredQuantityTrue()` |
| Product is in `products` relation (unless `apply_to = all_products`) | `PromotionEligibilityResolver::matchedEligibility()` |
| Gift products have stock (gift type only) | `GiftPromotionStrategy::eligible()` |

---

## 6. Database Structure

### 6.1 `promotions` Table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | Auto-increment |
| `name` | string | Translatable (Spatie) |
| `slug` | string | Sluggable |
| `code` | string, unique | Auto-generated: `ALLxxxx` or `PROxxxx` |
| `type` | enum (`price`, `quantity`) | `PromotionType` |
| `type_amount` | enum (`fixed_rate`, `percentage`, `gift`) | `PromotionMountType` — the actual discount type discriminator |
| `value` | decimal(10,2) | Discount value (synced with `discount` via model events) |
| `discount` | decimal(10,2), nullable | Discount value (synced with `value`) |
| `max_discount_amount` | decimal(10,2), nullable | Cap for percentage promotions |
| `required_quantity_type` | integer, nullable | Minimum quantity required |
| `minimum_order_amount` | decimal(10,2), default 0 | Minimum subtotal |
| `apply_to` | string, default `specific_products` | `all_products` or `specific_products` |
| `limiter` | integer, nullable | Max total uses |
| `usage` | integer, default 0 | Current usage count |
| `start_at` | date, nullable | Start date |
| `end_at` | date, nullable | End date |
| `status` | boolean, default true | Active/inactive |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Indexes:**
- `promotions_validity_index` on (`status`, `start_at`, `end_at`)
- `promotions_usage_limiter_index` on (`usage`, `limiter`)

### 6.2 `promotion_product` (Pivot) Table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `promotion_id` | bigint, FK → promotions.id | Cascade on delete |
| `product_id` | bigint, FK → products.id | Cascade on delete |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Unique:** (`promotion_id`, `product_id`)

### 6.3 `promotion_gift_products` Table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `promotion_id` | bigint, FK → promotions.id | Cascade on delete |
| `product_id` | bigint, FK → products.id | Cascade on delete |
| `product_variant_id` | bigint, FK → product_variants.id | Cascade on delete |
| `quantity` | unsigned int, default 1 | |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Unique:** (`promotion_id`, `product_id`)
**Indexes:** `product_id`, `product_variant_id`

### 6.4 Entity Relationships

```
Promotion
    ├── belongsToMany → Product (via promotion_product pivot)
    ├── belongsToMany → Product (via promotion_gift_products pivot)  ← gift products
    │       └── withPivot: quantity, product_variant_id
    └── promotionShop (unused in runtime)

CartItem
    ├── promotion_id (nullable FK → promotions)
    ├── is_gift (boolean, default false)
    └── discount_amount (decimal, stored per item)

Order (orders table)
    ├── promotion_id (nullable FK → promotions)
    ├── promotion_code (string, nullable)
    ├── promotion_type (string, nullable)
    └── promotion_discount (decimal, default 0)

OrderProduct (order_products table)
    ├── promotion_id (nullable FK → promotions)
    ├── is_gift (boolean, default false)
    └── promotion_discount_amount (decimal)
```

---

## 7. Runtime Integration Points

### 7.1 Where Promotion Is Calculated

| Stage | What Happens | Layer |
|-------|-------------|-------|
| **Product listing/detail** | No promotion calculation. Promotions apply at cart level only. | N/A |
| **Cart** | No automatic calculation. Promotions are resolved on-demand when user requests eligible list or selects one. | N/A |
| **Checkout — eligible listing** | `GET /api/v1/general/checkout/promotions` | Service layer (`PromotionService::eligiblePromotions()`) |
| **Checkout — price calculation** | `OrderService::calcInvoicePrice()` → `calculateCheckoutTotals()` → applies selected promotion → applies coupon | Service layer |
| **Checkout — order creation** | `OrderService::addItemsInOrder()` → `OrderCreationService::createOrder()` writes promotion data to order | Service layer |
| **Post-checkout** | `PromotionService::incrementUsage()` increments the usage counter | Service layer |

### 7.2 Where Promotion Discount Is Stored

| Entity | Field | When Set |
|--------|-------|----------|
| `cart_items` | `promotion_id`, `discount_amount`, `total_price`, `is_gift` | During `PromotionApplicator::applyOutcome()` |
| `orders` | `promotion_id`, `promotion_code`, `promotion_type`, `promotion_discount` | During `OrderCreationService::createOrder()` |
| `order_products` | `promotion_id`, `is_gift`, `promotion_discount_amount` | During `OrderCreationService::createOrderItems()` |
| `promotions` | `usage` (incremented) | During `PromotionService::incrementUsage()` |

### 7.3 Promotion vs Product Pricing

| Concern | Component | When Applied |
|---------|-----------|-------------|
| Product base price | Database `products.price` | Always |
| Product discount (has_discount) | `ProductPricingService` | Pre-serialization enrichment |
| Product flash sale | `ProductPricingService` | Pre-serialization enrichment |
| Promotion discount | `PromotionEngine` (entirely separate) | Only at checkout when user selects a promotion |
| Coupon discount | `CouponCalculator` | Only at checkout after promotion is applied |

Promotions do NOT interact with `ProductPricingService`. They operate at the **cart total level**, not at the product unit price level.

---

## 8. Discount Allocation Algorithm

When a discount is applied, `PromotionApplicator::applyOutcome()` uses **proportional allocation with largest remainder** to distribute the discount across matched cart items:

```
For each matched item:
    exact_share = (item_line_total_cents × total_discount_cents) / matched_subtotal_cents
    floor_share = floor(exact_share)
    allocation  = min(floor_share, line_total_cents)  ← capped to line total
    remainder   = exact_share - floor_share

Remaining cents are distributed one-by-one to items with the largest remainders
(respecting line total caps).

Each item's total_price is reduced by its allocation.
Cart total_price is recomputed as sum of all non-gift items' total_price.
```

This ensures the exact discount amount is distributed without losing pennies to rounding.

---

## 9. Current Business Rules (Extracted from Code)

1. **Promotions are optional.** A customer may choose zero, one, or select a specific promotion at checkout.
2. **Only one promotion per order.** The system does not support stacking multiple promotions. `selected_promotion_id` is a single integer.
3. **Promotion is applied before coupon.** The promotion discount is computed first, then coupon discount is applied to the remaining total.
4. **Gift items are priced at zero.** They do not contribute to the subtotal or total.
5. **Gift items are reserved from inventory** via `CartInventoryService::reserveGiftItem()`.
6. **Existing gift items are cleared** before applying a new promotion selection.
7. **Usage is tracked.** Each promotion has a `usage` counter and optional `limiter` cap.
8. **Promotions are time-bound.** `start_at` and `end_at` dates control availability.
9. **Apply scope.** A promotion can apply to all products (`apply_to = 'all_products'`) or only specific products (via `products` pivot).
10. **Quantity threshold.** `required_quantity_type` sets a minimum quantity in the cart for the promotion to apply.
11. **Minimum order.** `minimum_order_amount` sets a subtotal threshold.
12. **Discount capping.** For percentage promotions, `max_discount_amount` caps the absolute discount value.
13. **Promotion + coupon are independent.** They do not conflict; both discounts are subtracted sequentially.
14. **Discount allocation is proportional.** The discount amount is distributed across matching items proportionally to their line totals.
15. **Admins create/update/delete promotions** via Marvel CRUD endpoints (permission-gated).
16. **Customers view and select promotions** via App endpoints (checkout flow).
17. **Promotion changes are logged** via `PromotionObserver` → `LogActivityJob`.

---

## 10. Observations

### 10.1 Duplicated Logic

- `Promotion::discountAmount()` (model) contains the actual discount formula (percentage, fixed rate calculations). This is business logic inside the model layer. The strategies call this method (`$promotion->discountAmount(...)`) rather than containing the math themselves. This is a design choice but violates strict model purity.
- `CalculatePaymentTrait::calculateDiscount()` previously duplicated coupon discount formulas (already fixed separately).

### 10.2 Hidden Dependencies

- `GiftPromotionStrategy::hasAvailableStock()` falls back to a database query (`$product->variations()->whereRaw(...)->exists()`) if the `variations` relation is not already loaded. This could cause an N+1 query if not properly eager loaded upstream.
- `PromotionService::eligiblePromotions()` loads `giftProducts.variations.attributeProducts.attributeValue.attribute` — deep nested eager loading that affects performance.
- `PromotionEligibilityResolver::matchedEligibility()` reads `$item->price` from the cart item. This price was set when the item was added to the cart, before any promotion or pricing enrichment. If the cart item's `price` field does not reflect the current `current_price` (e.g., if the product's price changed after adding to cart), the matched subtotal may use a stale base price.

### 10.3 Tight Coupling

- `PromotionApplicator` depends directly on `CartInventoryService` for gift reservation. The inventory service must implement a `reserveGiftItem()` method that the applicator expects.
- `PromotionService` orchestrates resolver + applicator but also contains subtotal logic that duplicates `PromotionEligibilityResolver::matchedEligibility()`'s approach.
- The resolver produces both a `PromotionEvaluation` (internal DTO) and a `PromotionResult` (external DTO), then the service re-calls the resolver for evaluation and converts the result to outcomes — some redundancy in the flow.

### 10.4 Performance Concerns

- `PromotionService::eligiblePromotions()` loads ALL valid promotions at once (no pagination). With many promotions and deep eager loading (giftProducts → variations → attributeProducts → attributeValue → attribute), this could be slow.
- `PromotionApplicator::applyOutcome()` re-evaluates `matchedEligibility()` inside a transaction after locking rows. The re-evaluation re-queries cart items from the database.
- `GiftPromotionStrategy::computeOutcome()` iterates gift products and for each variant, loads `attributeProducts.attributeValue.attribute`. This could be slow if there are many gift products, though in practice gift products are typically few (1-2 per promotion).

### 10.5 Observation Summary

| Observation | Severity | Location |
|-------------|----------|----------|
| Business logic in model (`discountAmount()`) | Medium | `Promotion::discountAmount()` |
| Fallback DB query in gift stock check | Medium | `GiftPromotionStrategy::hasAvailableStock()` |
| All valid promotions loaded at once | Medium | `PromotionService::eligiblePromotions()` |
| Stale cart item price for subtotal | Low | `PromotionEligibilityResolver::matchedEligibility()` |
| Promotion → inventory service coupling | Low | `PromotionApplicator` constructor |
| Redundant evaluation in apply flow | Low | `PromotionService::applySelectedPromotion()` |
| Deep eager loading chain | Medium | `PromotionService::eligiblePromotions()` → `giftProducts.variations.attributeProducts.attributeValue.attribute` |

---

## Document Metadata

- **Author**: AI Code Analysis
- **Date**: 2026-07-13
- **Purpose**: Reverse engineering and documentation of the existing Promotion System
- **Scope**: No code changes were made. This is analysis only.
