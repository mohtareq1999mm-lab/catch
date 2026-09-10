# RUNTIME PRICING ARCHITECTURE — FINAL AUDIT REPORT

> Audit Date: 2026-07-13
> Mode: Architecture Audit ONLY — No code modified
> Scope: Full runtime pricing flow for Product, ProductVariant, Variation

---

## TABLE OF CONTENTS

1. [Runtime Pricing Flow Diagram](#todo-1---runtime-pricing-flow-diagram)
2. [Class Responsibility Audit](#todo-2---class-responsibility-audit)
3. [Every Pricing Calculation Location](#todo-3---every-pricing-calculation-location)
4. [Every ProductPricingService Call Site](#todo-4---every-productpricingservice-call-site)
5. [Model Accessor Audit](#todo-5---model-accessor-audit)
6. [Resource Audit](#todo-6---resource-audit)
7. [Query Audit](#todo-7---query-audit)
8. [Repository Layer Audit](#todo-8---repository-layer-audit)
9. [Hidden Contracts](#todo-9---hidden-contracts)
10. [Fallback Audit](#todo-10---fallback-audit)
11. [Duplicate Pricing Implementations](#todo-11---duplicate-pricing-implementations)
12. [FINAL Ownership Table](#todo-12---final-ownership-table)
13. [FINAL Runtime Pricing Rules](#todo-13---final-runtime-pricing-rules)
14. [Final Project-Wide Violation Search](#todo-14---final-project-wide-violation-search)
15. [Implementation Plan](#todo-15---implementation-plan)

---

## TODO 1 — Runtime Pricing Flow Diagram

### FLOW A: HTTP Request → Product JSON (app/ layer — public API)

```
HTTP GET /api/general/products/{slug}
  │
  ▼
App\Http\Controllers\Api\General\ProductController::getProductBySlug()
  │  Calls: $this->productService->getProductBySlug($slug, $limit)
  │  Returns: ProductResource::make($product)  ← JSON
  ▼
App\Services\General\ProductService::getProductBySlug()
  │  Builds query with:
  │    ->with(['categories', 'variations', 'brands', 'banners', 'sliders',
  │            'flash_sales' => fn($q) => $q->valid()])
  │    ->withAvg(['reviews' ...], 'rating')
  │    ->withCount(['reviews' ...])
  │  Sets related_products relation
  │  Returns: Product model (loaded, not fresh)
  ▼
App\Http\Resources\Product\ProductResource::toArray()
  │  Reads: $this->price                         ← DB field (OK)
  │  Reads: $this->current_price                 ← ACCESSOR → ProductPricingService
  │  Reads: $this->discount_active               ← ACCESSOR → ProductPricingService
  │  Reads: $this->flash_sale_active             ← ACCESSOR → ProductPricingService
  │  For variants: app(ProductPricingService)->calculateVariantCurrentPrice()  ← CALCULATES
  │  Returns: array → JSON
  ▼
JSON Response
```

### FLOW B: HTTP Request → Product JSON (packages/marvel layer — admin/GraphQL)

```
HTTP Request (admin)
  │
  ▼
Marvel\Http\Controllers\ProductController
  │  Calls ProductRepository for fetch/store/update
  ▼
Marvel\Database\Repositories\ProductRepository
  │  storeProduct(): uses app(ProductPricingService) to calculate variant sale_price at creation
  │  updateProduct(): uses app(ProductPricingService) to calculate variant sale_price at update
  │  calculateProductPrice(): loads product with flash_sales, calls ProductPricingService
  │  calculateVariationPrice(): loads variation with product.flash_sales, calls ProductPricingService
  ▼
Marvel\Http\Resources\product\ProductResource::toArray()
  │  Reads: $this->current_price                 ← ACCESSOR → ProductPricingService
  │  Reads: $this->discount_active               ← ACCESSOR → ProductPricingService
  │  Reads: $this->flash_sale_active             ← ACCESSOR → ProductPricingService
  │  For variants: app(ProductPricingService)->calculateVariantCurrentPrice()  ← CALCULATES
  ▼
JSON Response
```

### FLOW C: Mini Product (listings, home page, search)

```
App\Http\Resources\Product\ProductMiniResource::toArray()
  │  Reads: $this->current_price                 ← ACCESSOR → ProductPricingService
  │  Reads: $this->discount_active               ← ACCESSOR → ProductPricingService
  │  Reads: $this->flash_sale_active             ← ACCESSOR → ProductPricingService
  │  Does NOT read variants
```

### FLOW D: Cart → Checkout (price capture at cart time)

```
CartInventoryService::reserveItem()
  │  Calculates price via:
  │    app(ProductPricingService)->calculateVariantCurrentPrice($product, $variant)
  │    app(ProductPricingService)->calculateProductCurrentPrice($product)
  │  Stores price in cart_items.price (snapshot at cart time)
  ▼
OrderCreationService::createOrderItems()
  │  Recalculates flash_sale_price and discount_price via ProductPricingService
  │  Stores in order_items.product_flash_sale_price, product_discount_price
  │  Uses already-calculated $item->price as the effective unit price
```

### FLOW E: Wishlist

```
Marvel\Http\Resources\WishlistResource::toArray()
  │  Reads: $this->current_price                 ← ACCESSOR
  │  Reads: $this->discount_active               ← ACCESSOR
  │  Reads: $this->flash_sale_active             ← ACCESSOR
  │  For variants: app(ProductPricingService)->calculateVariantCurrentPrice()
```

### KEY FILES IN THE FLOW

| Step | File (app layer) | File (package layer) |
|------|------------------|---------------------|
| Controller | `app/Http/Controllers/Api/General/ProductController.php` | `packages/marvel/src/Http/Controllers/ProductController.php` |
| Service | `app/Services/General/ProductService.php` | — |
| Repository | — | `packages/marvel/src/Database/Repositories/ProductRepository.php` |
| Model | — | `packages/marvel/src/Database/Models/Product.php` |
| Model | — | `packages/marvel/src/Database/Models/ProductVariant.php` |
| Model | — | `packages/marvel/src/Database/Models/Variation.php` |
| Pricing Service | — | `packages/marvel/src/Services/Pricing/ProductPricingService.php` |
| Resource | `app/Http/Resources/Product/ProductResource.php` | `packages/marvel/src/Http/Resources/product/ProductResource.php` |
| Resource | `app/Http/Resources/Product/ProductMiniResource.php` | — |
| Cart Service | `app/Services/General/CartInventoryService.php` | — |
| Order Creation | `app/Services/Checkout/OrderCreationService.php` | — |

---

## TODO 2 — Class Responsibility Audit

### ProductPricingService
**File:** `packages/marvel/src/Services/Pricing/ProductPricingService.php`

**Current responsibilities:**
- `calculateProductPricing(Product, ?FlashSale)` — full pricing for product
- `calculateProductPricingFromData(array, ?FlashSale)` — pricing from raw data
- `calculateVariantSalePrice(Product, ProductVariant|array, ?FlashSale)` — variant sale price
- `calculateVariantPricingFromData(array, array, ?FlashSale)` — variant pricing from raw data
- `calculateProductCurrentPrice(Product)` — convenience wrapper
- `calculateVariantCurrentPrice(Product, ProductVariant|array, ?FlashSale)` — convenience wrapper
- `calculateCouponPrice(Coupon, $basePrice)` — coupon discount application
- `calculateCouponPriceByCode(string, $basePrice)` — coupon by code
- `calculateDiscountedPrice($price, $discountType, $amount)` — pure discount math
- `calculateFlashSalePrice(?FlashSale, $basePrice)` — pure flash sale math
- `resolveActiveFlashSale(Product)` — LOADS DATA from DB (violation!)
- `isDiscountActive(Product)` — date validation
- `isDiscountActiveFromData(array)` — date validation from data
- `isFlashSaleActive(?FlashSale)` — date validation
- `runSafely()` — exception safety wrapper
- `normalizeMoney()` — money normalization
- `toUnits()` — cast to float

**Violation:** `resolveActiveFlashSale()` executes DB queries and calls `relationLoaded()`. The service should ONLY compute, never load data.

**Assessment:** Business orchestration + Pricing calculation. The data-loading part of `resolveActiveFlashSale` violates SRP.

### Product Model
**File:** `packages/marvel/src/Database/Models/Product.php`

**Current pricing responsibilities (should be ZERO):**
- `getCurrentPriceAttribute()` → calls `ProductPricingService::calculateProductCurrentPrice()` ← **violation**
- `getDiscountActiveAttribute()` → calls `ProductPricingService::isDiscountActive()` ← **violation**
- `getFlashSaleActiveAttribute()` → calls `ProductPricingService::resolveActiveFlashSale()` ← **violation**
- `getDiscountedPrice()` → calls `ProductPricingService::calculateProductPricing()` ← **violation**
- `calculateDiscountedPrice()` → calls `ProductPricingService::calculateDiscountedPrice()` ← **violation**
- Appends: `current_price`, `discount_active`, `flash_sale_active`

**Assessment:** Accessors should NOT call services. The model should be a passive data holder.

### ProductVariant Model
**File:** `packages/marvel/src/Database/Models/ProductVariant.php`

**Current pricing responsibilities:**
- `getCurrentPriceAttribute()` → delegates to `getSalePriceAttribute()` ← **violation**
- `getSalePriceAttribute()` → checks `relationLoaded('product')`, falls back to `$this->price`, calls `ProductPricingService::calculateVariantCurrentPrice()` ← **violation**
- Appends: `current_price`, `sale_price`

**Assessment:** Contains HIDDEN FALLBACK: if product relation not loaded, returns raw price silently.

### Variation Model
**File:** `packages/marvel/src/Database/Models/Variation.php`

**Current pricing responsibilities:**
- `getCurrentPriceAttribute()` → delegates to `getSalePriceAttribute()` ← **violation**
- `getSalePriceAttribute()` → checks `relationLoaded('product')`, falls back to `$this->price`, calls `ProductPricingService::calculateVariantCurrentPrice()` ← **violation**
- Appends: `current_price`, `sale_price`

**Assessment:** Same pattern as ProductVariant — duplicate accessor logic.

### FlashSale Model
**File:** `packages/marvel/src/Database/Models/FlashSale.php`

**Current pricing responsibilities:**
- `calcPrice($price)` → calls `ProductPricingService::calculateFlashSalePrice()` ← **violation**

**Assessment:** Model should not calculate pricing.

### ProductRepository
**File:** `packages/marvel/src/Database/Repositories/ProductRepository.php`

**Current pricing responsibilities:**
- `addVariants()` → calls `ProductPricingService::calculateVariantSalePrice()` ← **violation**
- `calculateProductPrice()` → loads product with flash_sales, calls `ProductPricingService::calculateProductCurrentPrice()` ← **violation**
- `calculateVariationPrice()` → loads variation with product.flash_sales, calls `ProductPricingService::calculateVariantCurrentPrice()` ← **violation**
- `calculateDiscountedPrice()` → delegates to `ProductPricingService::calculateDiscountedPrice()` ← **violation**
- `resolveFlashSale()` → manually queries flash sales ← **violation**
- `calculateFlashSalePrice()` → delegates to `ProductPricingService::calculateFlashSalePrice()` ← **violation**
- `calculatePrice()` → complex booking pricing (this IS appropriate for repository)
- `calculateResourcePrice()`, `calculateLocationPrice()` → booking pricing (appropriate)

**Assessment:** Repository should ONLY retrieve data. Pricing helpers must be removed.

### Resources (app layer)

**App\Http\Resources\Product\ProductResource:**
- `$this->current_price` → accesses model accessor (indirect violation)
- `$this->discount_active` → accesses model accessor (indirect violation)
- `$this->flash_sale_active` → accesses model accessor (indirect violation)
- `getVariants()` → calls `app(ProductPricingService)->calculateVariantCurrentPrice()` ← **DIRECT VIOLATION**

**App\Http\Resources\Product\ProductMiniResource:**
- `$this->current_price` → accesses model accessor (indirect violation)
- `$this->discount_active` → accesses model accessor (indirect violation)
- `$this->flash_sale_active` → accesses model accessor (indirect violation)

### Resources (package layer)

**Marvel\Http\Resources\product\ProductResource:**
- Same pattern as app layer — accessor access + direct service call in `getVariants()`

**Marvel\Http\Resources\WishlistResource:**
- Directly calls `app(ProductPricingService)->calculateVariantCurrentPrice()` ← **DIRECT VIOLATION**

### CalculatePaymentTrait
**File:** `packages/marvel/src/Traits/CalculatePaymentTrait.php`

- `calculateSubtotal()` → loads products with flash_sales, reads `$item->current_price` (triggers accessor)
- `calculateEachItemTotal()` → uses `$item->current_price` (accessor)

### CartInventoryService
**File:** `app/Services/General/CartInventoryService.php`

- `reserveItem()` → calls `ProductPricingService::calculateVariantCurrentPrice()` and `calculateProductCurrentPrice()`

**Assessment:** CORRECT. Service needs to know the price to store in cart at reservation time.

### OrderCreationService
**File:** `app/Services/Checkout/OrderCreationService.php`

- `createOrderItems()` → calls `ProductPricingService::calculateFlashSalePrice()` and `calculateDiscountedPrice()`

**Assessment:** VIOLATION. Prices are already calculated at cart time. This recalculates unnecessarily.

### CouponService
**File:** `app/Services/General/CouponService.php`

- `updateCartTotalPrice()` → calls `ProductPricingService::calculateCouponPrice()`

**Assessment:** This is a valid use — CouponService needs coupon price calculation. However, the `calculateCouponPrice` method on ProductPricingService is questionable since coupons are not products.

### GiftPromotionStrategy
**File:** `app/Services/General/PromotionEngine/Strategies/GiftPromotionStrategy.php`

- `variantPayload()` → calls `ProductPricingService::calculateVariantCurrentPrice()`

**Assessment:** VIOLATION. Strategy should not calculate pricing.

---

## TODO 3 — Every Pricing Calculation Location

| # | Location | Method | Classification | Why |
|---|----------|--------|---------------|-----|
| 1 | `Product::getCurrentPriceAttribute()` | `app(ProductPricingService)->calculateProductCurrentPrice($this)` | **REMOVE** | Accessor should not calculate |
| 2 | `Product::getDiscountActiveAttribute()` | `app(ProductPricingService)->isDiscountActive($this)` | **REMOVE** | Accessor should not validate |
| 3 | `Product::getFlashSaleActiveAttribute()` | `app(ProductPricingService)->resolveActiveFlashSale($this) !== null` | **REMOVE** | Accessor should not load data |
| 4 | `Product::getDiscountedPrice()` | `app(ProductPricingService)->calculateProductPricing($this)` | **REMOVE** | Model method should not exist |
| 5 | `Product::calculateDiscountedPrice($price)` | `app(ProductPricingService)->calculateDiscountedPrice(...)` | **REMOVE** | Model method should not exist |
| 6 | `ProductVariant::getCurrentPriceAttribute()` | Delegates to `getSalePriceAttribute()` | **REMOVE** | Accessor should not calculate |
| 7 | `ProductVariant::getSalePriceAttribute()` | `app(ProductPricingService)->calculateVariantCurrentPrice(...)` | **REMOVE** | Accessor should not calculate |
| 8 | `Variation::getCurrentPriceAttribute()` | Delegates to `getSalePriceAttribute()` | **REMOVE** | Accessor should not calculate |
| 9 | `Variation::getSalePriceAttribute()` | `app(ProductPricingService)->calculateVariantCurrentPrice(...)` | **REMOVE** | Accessor should not calculate |
| 10 | `FlashSale::calcPrice($price)` | `app(ProductPricingService)->calculateFlashSalePrice(...)` | **REMOVE** | Model should not calculate |
| 11 | `ProductRepository::addVariants()` | `app(ProductPricingService)->calculateVariantSalePrice(...)` | **REMOVE** | Repository should not calculate |
| 12 | `ProductRepository::calculateProductPrice()` | `app(ProductPricingService)->calculateProductCurrentPrice(...)` | **REMOVE** | Repository should not calculate |
| 13 | `ProductRepository::calculateVariationPrice()` | `app(ProductPricingService)->calculateVariantCurrentPrice(...)` | **REMOVE** | Repository should not calculate |
| 14 | `ProductRepository::calculateDiscountedPrice()` | `app(ProductPricingService)->calculateDiscountedPrice(...)` | **REMOVE** | Repository should not calculate |
| 15 | `ProductRepository::resolveFlashSale()` | Raw flash sale query | **REMOVE** | Repository should not resolve flash sales |
| 16 | `ProductRepository::calculateFlashSalePrice()` | `app(ProductPricingService)->calculateFlashSalePrice(...)` | **REMOVE** | Repository should not calculate |
| 17 | `app/ProductResource::getVariants()` | `app(ProductPricingService)->calculateVariantCurrentPrice(...)` | **REMOVE** | Resource should not calculate |
| 18 | `package/ProductResource::getVariants()` | `app(ProductPricingService)->calculateVariantCurrentPrice(...)` | **REMOVE** | Resource should not calculate |
| 19 | `WishlistResource::price` | Ternary with `calculateVariantCurrentPrice()` | **REMOVE** | Resource should not calculate |
| 20 | `WishlistResource::getVariants()` | `app(ProductPricingService)->calculateVariantCurrentPrice(...)` | **REMOVE** | Resource should not calculate |
| 21 | `CartInventoryService::reserveItem()` | Both `calculateVariantCurrentPrice` and `calculateProductCurrentPrice` | **CORRECT** | Service needs price for cart snapshot |
| 22 | `OrderCreationService::createOrderItems()` | `calculateFlashSalePrice()` and `calculateDiscountedPrice()` | **REMOVE** | Prices already calculated at cart time |
| 23 | `CouponService::updateCartTotalPrice()` | `ProductPricingService::calculateCouponPrice()` | **CORRECT** | Coupon pricing is valid use |
| 24 | `GiftPromotionStrategy::variantPayload()` | `calculateVariantCurrentPrice()` | **REMOVE** | Strategy should not calculate pricing |
| 25 | `ProductPricingService::calculateProductPricing()` | Self | **CORRECT** | Core responsibility |
| 26 | `ProductPricingService::calculateProductPricingFromData()` | Self | **CORRECT** | Core responsibility |
| 27 | `ProductPricingService::calculateVariantSalePrice()` | Self | **CORRECT** | Core responsibility |
| 28 | `ProductPricingService::calculateVariantPricingFromData()` | Self | **CORRECT** | Core responsibility |
| 29 | `ProductPricingService::calculateProductCurrentPrice()` | Self | **CORRECT** | Core responsibility |
| 30 | `ProductPricingService::calculateVariantCurrentPrice()` | Self | **CORRECT** | Core responsibility |
| 31 | `ProductPricingService::calculateFlashSalePrice()` | Self | **CORRECT** | Core responsibility |
| 32 | `ProductPricingService::calculateDiscountedPrice()` | Self | **CORRECT** | Core responsibility |
| 33 | `ProductPricingService::resolveActiveFlashSale()` | Self | **REMOVE (from service)** | Should not load data |
| 34 | `ProductPricingService::isDiscountActive()` | Self | **KEEP** | Validation logic |
| 35 | `ProductPricingService::isFlashSaleActive()` | Self | **KEEP** | Validation logic |
| 36 | `CalculatePaymentTrait::calculateEachItemTotal()` | `$item->current_price` (accessor) | **REMOVE** | Uses accessor |

**Total: 36 locations. 4 CORRECT, 28 to REMOVE, 2 to KEEP (but relocate), 2 to KEEP.**

---

## TODO 4 — Every ProductPricingService Call Site (Table)

| Call Site | File | Line(s) | Why this layer? | Should know pricing? | Should calc earlier? | Should calc later? | Verdict |
|-----------|------|---------|-----------------|---------------------|---------------------|-------------------|---------|
| `isDiscountActive($this)` | `Product.php` | 115 | Accessor | NO | Yes, in service | No | **REMOVE** |
| `resolveActiveFlashSale($this)` | `Product.php` | 120 | Accessor | NO | Yes, in service | No | **REMOVE** |
| `calculateProductCurrentPrice($this)` | `Product.php` | 130 | Accessor | NO | Yes, in service | No | **REMOVE** |
| `calculateProductPricing($this)` | `Product.php` | 140 | Model method | NO | Yes, in service | No | **REMOVE** |
| `calculateDiscountedPrice(...)` | `Product.php` | 152 | Model method | NO | Yes, in service | No | **REMOVE** |
| `calculateVariantCurrentPrice($product, $this)` | `ProductVariant.php` | 81 | Accessor | NO | Yes, in service | No | **REMOVE** |
| `calculateVariantCurrentPrice($product, $this)` | `Variation.php` | 93 | Accessor | NO | Yes, in service | No | **REMOVE** |
| `calculateFlashSalePrice($this, $price)` | `FlashSale.php` | 85 | Model method | NO | Yes, in service | No | **REMOVE** |
| `calculateVariantSalePrice(...)` | `ProductRepository.php` | 218 | Repository CRUD | NO | Yes, before persist | No | **REMOVE** |
| `calculateProductCurrentPrice($product)` | `ProductRepository.php` | 411 | Repository pricing helper | NO | Yes, in service | No | **REMOVE** |
| `calculateVariantCurrentPrice(...)` | `ProductRepository.php` | 422 | Repository pricing helper | NO | Yes, in service | No | **REMOVE** |
| `calculateDiscountedPrice(...)` | `ProductRepository.php` | 473 | Repository pricing helper | NO | Yes, in service | No | **REMOVE** |
| `calculateFlashSalePrice(...)` | `ProductRepository.php` | 510 | Repository pricing helper | NO | Yes, in service | No | **REMOVE** |
| `calculateVariantCurrentPrice(...)` | `app/ProductResource.php` | 78 | Resource serialization | NO | Yes, in service | No | **REMOVE** |
| `calculateVariantCurrentPrice(...)` | `package/ProductResource.php` | 91 | Resource serialization | NO | Yes, in service | No | **REMOVE** |
| `calculateVariantCurrentPrice(...)` | `WishlistResource.php` | 19,33 | Resource serialization | NO | Yes, in service | No | **REMOVE** |
| `calculateVariantCurrentPrice($product, $variant)` | `CartInventoryService.php` | 44 | Cart pricing snapshot | YES | At cart time | No | **KEEP** |
| `calculateProductCurrentPrice($product)` | `CartInventoryService.php` | 45 | Cart pricing snapshot | YES | At cart time | No | **KEEP** |
| `resolveActiveFlashSale($product)` + `calculateFlashSalePrice(...)` | `OrderCreationService.php` | 86,95 | Order item creation | NO | Already at cart time | No | **REMOVE** |
| `calculateDiscountedPrice(...)` | `OrderCreationService.php` | 95 | Order item creation | NO | Already at cart time | No | **REMOVE** |
| `calculateCouponPrice($coupon, $cart->total_price)` | `CouponService.php` | 94 | Coupon calculation | YES | At coupon apply time | No | **KEEP** (but move to CouponCalculator directly) |
| `calculateVariantCurrentPrice($product, $variant)` | `GiftPromotionStrategy.php` | 109 | Promotion strategy | NO | Not needed (gifts are free) | No | **REMOVE** |

---

## TODO 5 — Model Accessor Audit

### Product

| Accessor | SQL Exec? | Requires Relations? | Silent Incorrect? | SRP Violation? | Should Exist? |
|----------|-----------|-------------------|-------------------|----------------|--------------|
| `getCurrentPriceAttribute()` | Indirect (via PPS → flash_sales) | `flash_sales` | No (fallback to price) | YES | NO |
| `getDiscountActiveAttribute()` | No | No | No | YES | NO |
| `getFlashSaleActiveAttribute()` | YES (via PPS) | `flash_sales` | No (returns false if not loaded) | YES | NO |
| `getDiscountedPrice()` | Indirect | `flash_sales` | No | YES | NO |
| `calculateDiscountedPrice($price)` | No | No | No | YES | NO |

### ProductVariant

| Accessor | SQL Exec? | Requires Relations? | Silent Incorrect? | SRP Violation? | Should Exist? |
|----------|-----------|-------------------|-------------------|----------------|--------------|
| `getCurrentPriceAttribute()` | Indirect | `product` | YES — falls back to raw price | YES | NO |
| `getSalePriceAttribute()` | Indirect | `product` | YES — falls back to raw price | YES | NO |

**CRITICAL BUG:** If `product` relation is NOT loaded, `getSalePriceAttribute()` silently returns `$this->price` (the variant's base price) WITHOUT applying any discounts. The frontend would see an incorrect price.

### Variation

| Accessor | SQL Exec? | Requires Relations? | Silent Incorrect? | SRP Violation? | Should Exist? |
|----------|-----------|-------------------|-------------------|----------------|--------------|
| `getCurrentPriceAttribute()` | Indirect | `product` | YES — falls back to raw price | YES | NO |
| `getSalePriceAttribute()` | Indirect | `product` | YES — falls back to raw price | YES | NO |

**CRITICAL BUG:** Same silent fallback as ProductVariant. If `product` not eager-loaded, raw undiscounted price is returned.

---

## TODO 6 — Resource Audit

### App\Http\Resources\Product\ProductResource
| Question | Answer |
|----------|--------|
| Does it calculate? | YES — `getVariants()` calls `ProductPricingService::calculateVariantCurrentPrice()` |
| Does it call ProductPricingService? | YES — line 78 |
| Does it trigger accessors? | YES — `$this->current_price`, `$this->discount_active`, `$this->flash_sale_active` |
| Does it depend on hidden relations? | YES — `variations` must be loaded for variant pricing |
| SRP Violation? | YES — serialization + pricing calculation |

### App\Http\Resources\Product\ProductMiniResource
| Question | Answer |
|----------|--------|
| Does it calculate? | NO (only reads accessors) |
| Does it call ProductPricingService? | NO |
| Does it trigger accessors? | YES — `$this->current_price`, `$this->discount_active`, `$this->flash_sale_active` |
| Does it depend on hidden relations? | YES — relies on accessors to work |
| SRP Violation? | Indirect — via accessor chain |

### Marvel\Http\Resources\product\ProductResource (package)
| Question | Answer |
|----------|--------|
| Does it calculate? | YES — `getVariants()` calls `ProductPricingService::calculateVariantCurrentPrice()` |
| Does it call ProductPricingService? | YES — line 91 |
| Does it trigger accessors? | YES |
| SRP Violation? | YES |

### Marvel\Http\Resources\WishlistResource
| Question | Answer |
|----------|--------|
| Does it calculate? | YES — price field uses ternary with `calculateVariantCurrentPrice()` |
| Does it call ProductPricingService? | YES — lines 19, 33 |
| Does it trigger accessors? | YES |
| SRP Violation? | YES |

### Marvel\Http\Resources\product\RelatedProductResource
| Question | Answer |
|----------|--------|
| Does it calculate? | NO (only reads accessor `$this->current_price`) |
| Does it call ProductPricingService? | NO |
| SRP Violation? | Indirect via accessor |

### Marvel\Http\Resources\product\GetSingleProductResource
| Question | Answer |
|----------|--------|
| Does it calculate? | NO (only reads accessor `$this->current_price`, `$this->flash_sale_active`) |
| Does it call ProductPricingService? | NO |
| SRP Violation? | Indirect via accessor |

### App\Http\Resources\Order\OrderProductVariantResource
| Question | Answer |
|----------|--------|
| Does it calculate? | NO (reads accessor `$this->current_price`) |
| Does it call ProductPricingService? | NO |
| SRP Violation? | Indirect via accessor |

---

## TODO 7 — Query Audit

### ProductService::buildFilteredBaseQuery()
```php
Product::query()->active()
    ->with(['categories', 'variations', 'brands', 'flash_sales' => fn($q) => $q->valid()])
    ->withAvg(['reviews' ...], 'rating')
    ->withCount(['reviews' ...]);
```
| Question | Answer |
|----------|--------|
| Returns enough data for pricing? | YES — loads `flash_sales` for flash sale calculation |
| Eagerly loads required relations? | YES — `flash_sales` with valid scope |
| Intentionally omits data? | NO |
| Relies on accessors fixing missing data? | NO — data is properly loaded |
| **Verdict** | **ADEQUATE** |

### ProductService::getProductBySlug()
```php
Product::query()->active()
    ->search('slug', $slug, ...)
    ->with(['categories', 'variations', 'brands', 'banners', 'sliders',
            'flash_sales' => fn($q) => $q->valid(),
            'reviews' => fn($builder) => $builder->approved()->with('user')])
    ->withAvg(...)
    ->withCount(...);
```
| Question | Answer |
|----------|--------|
| Returns enough data for pricing? | YES — `flash_sales` loaded |
| Eagerly loads required relations? | YES |
| **Verdict** | **ADEQUATE** |

### ProductService::paginateFlashSales()
Same pattern as buildFilteredBaseQuery. **ADEQUATE**.

### FlashSaleService::getFlashSaleProductsEndingThisWeek()
```php
Product::query()
    ->select(['id', 'name', 'slug', 'price', 'quantity', ...])
    ->with(['flash_sales' => fn($q) => $q->valid()])
```
| Question | Answer |
|----------|--------|
| Returns enough data for pricing? | YES — `flash_sales` and `price` loaded |
| **Verdict** | **ADEQUATE** (though limited select) |

### ProductRepository::fetchRelated()
```php
$this->whereHas('categories', ...)
    ->where('id', '!=', $id)
    ->limit($limit)->get();
```
| Question | Answer |
|----------|--------|
| Does NOT load flash_sales | **ISSUE** — accessors will use fallback or trigger N+1 |
| **Verdict** | **INADEQUATE** — missing eager load |

### ProductRepository::getBestSellingProducts()
```php
Product::leftJoin(...)
    ->with(['type', 'shop'])
    ->selectRaw(...)
```
| Question | Answer |
|----------|--------|
| Does NOT load flash_sales | **ISSUE** — accessors trigger N+1 |
| **Verdict** | **INADEQUATE** — missing eager load |

### ProductController (package) — index()
The package ProductController likely uses the repository's standard fetch which may or may not load flash_sales. **NEEDS VERIFICATION.**

### HomeService::getFlashSaleProductsEndingThisWeek()
```php
Product::query()
    ->select([...])
    ->with(['flash_sales' => fn($q) => $q->valid()])
```
**ADEQUATE**.

---

## TODO 8 — Repository Layer Audit

### ProductRepository pricing helpers

| Method | Lines | Type | Verdict |
|--------|-------|------|---------|
| `addVariants()` → `calculateVariantSalePrice()` | 218 | Pricing calculation | **DELETE** |
| `calculateProductPrice()` | 411 | Pricing calculation | **DELETE** |
| `calculateVariationPrice()` | 422 | Pricing calculation | **DELETE** |
| `calculateDiscountedPrice()` | 473 | Pricing calculation helper | **DELETE** |
| `resolveFlashSale()` | 484 | Flash sale data resolver | **DELETE** |
| `calculateFlashSalePrice()` | 508 | Pricing calculation | **DELETE** |
| `calculatePrice()` | 361 | Booking price aggregation | **KEEP** (booking-specific, not product pricing) |
| `calculateProductPrice()` | 403 | Used by booking | **KEEP** (but relocate to booking service) |
| `calculateVariationPrice()` | 414 | Used by booking | **KEEP** (but relocate to booking service) |
| `calculateResourcePrice()` | 435 | Booking resources | **KEEP** |
| `calculateLocationPrice()` | 425 | Booking locations | **KEEP** |

**The booking-related `calculatePrice()` and its sub-methods are a separate domain (booking/rental) and should be moved to a BookingService.**

---

## TODO 9 — Hidden Contracts

| # | Hidden Contract | File | Risk |
|---|----------------|------|------|
| 1 | `ProductVariant::getSalePriceAttribute()` assumes `product` relation is loaded | `ProductVariant.php:75` | HIGH — Silent fallback to raw price |
| 2 | `Variation::getSalePriceAttribute()` assumes `product` relation is loaded | `Variation.php:87` | HIGH — Silent fallback to raw price |
| 3 | `Product::getFlashSaleActiveAttribute()` assumes `flash_sales` relation is loaded | `Product.php:118-121` | MEDIUM — returns false if not loaded |
| 4 | `Product::getCurrentPriceAttribute()` assumes `flash_sales` relation is loaded when there's a flash sale | `Product.php:128-131` | MEDIUM — falls back to base price |
| 5 | `ProductResource::getVariants()` assumes `variations` relation is loaded | `ProductResource.php:72-74` | MEDIUM — uses `whenLoaded` guard but then calls service |
| 6 | `ProductPricingService::resolveActiveFlashSale()` silently returns null if `flash_sales` not loaded | `ProductPricingService.php:230-240` | MEDIUM — product appears to have no flash sale |
| 7 | `Product::appends = ['current_price', 'discount_active', 'flash_sale_active']` always triggers service calls on serialization | `Product.php:91-95` | HIGH — Every model serialization triggers pricing service |
| 8 | `ProductVariant::$appends = ['current_price', 'sale_price']` always triggers service calls | `ProductVariant.php:17` | HIGH — Every serialization triggers pricing |
| 9 | `Variation::$appends = ['current_price', 'sale_price']` always triggers service calls | `Variation.php:20` | HIGH — Every serialization triggers pricing |

**VERDICT: 9 hidden contracts identified. The most dangerous is #1 and #2 — silent incorrect pricing.**

---

## TODO 10 — Fallback Audit

| # | Fallback | File | Line(s) | Classification |
|---|----------|------|---------|---------------|
| 1 | `relationLoaded('product') ? $this->product : null` | `ProductVariant.php` | 75 | **DANGEROUS** — silent price fallback |
| 2 | `if (!$product) return $this->price` | `ProductVariant.php` | 77-78 | **DANGEROUS** — returns undiscounted price silently |
| 3 | `relationLoaded('product') ? $this->product : null` | `Variation.php` | 87 | **DANGEROUS** — same issue |
| 4 | `if (!$product) return $this->price` | `Variation.php` | 89-90 | **DANGEROUS** — returns undiscounted price silently |
| 5 | `relationLoaded('flash_sales')` check in `resolveActiveFlashSale()` | `ProductPricingService.php` | 230 | **Safe** — returns null, no incorrect data |
| 6 | `runSafely()` fallback to base price | `ProductPricingService.php` | 42-47, 72-77 | **Safe** — error resilience |
| 7 | `Product::getFlashSaleActiveAttribute()` — returns false if not loaded | `Product.php` | 118-121 | **Safe** — conservative default |
| 8 | `Product::getCurrentPriceAttribute()` — falls back to base price | `Product.php` | 133-136 | **Safe** — at least returns base price |
| 9 | `ProductResource::getVariants()` uses `whenLoaded('variations')` | Both ProductResources | 53, 50 | **Safe** — skips variants if not loaded |

**CRITICAL FALLBACKS: #1, #2, #3, #4 — must be removed.**

---

## TODO 11 — Duplicate Pricing Implementations

| # | What | Where | Count | Action |
|---|------|-------|-------|--------|
| 1 | `current_price` computation | Product model accessor + ProductPricingService | 2 | Remove accessor, keep service |
| 2 | `sale_price` computation | ProductVariant accessor + Variation accessor + ProductPricingService | 3 | Remove all accessors |
| 3 | `discount_active` computation | Product accessor + ProductPricingService | 2 | Remove accessor |
| 4 | `flash_sale_active` computation | Product accessor + ProductPricingService | 2 | Remove accessor |
| 5 | Variant current_price in Resources | app/ProductResource + package/ProductResource + WishlistResource + GiftPromotionStrategy | 4 | Remove all — service is single source |
| 6 | `resolveActiveFlashSale()` | ProductPricingService + ProductRepository | 2 | Remove from both? Consolidate to one |
| 7 | `isDiscountActive()` | ProductPricingService (public) + Product model (via accessor) | 2 | Keep in service, remove from model |
| 8 | `calculateDiscountedPrice()` | Product model + ProductRepository + ProductPricingService | 3 | Keep only in ProductPricingService |
| 9 | `calculateFlashSalePrice()` | ProductRepository + ProductPricingService + FlashSale model | 3 | Keep only in ProductPricingService |
| 10 | `calculateProductCurrentPrice()` | ProductRepository + ProductPricingService | 2 | Keep only in ProductPricingService |

**TOTAL: 10 groups of duplication. Single source of truth only in ProductPricingService.**

---

## TODO 12 — FINAL Ownership Table

| Responsibility | Current Owner | Correct Owner | Status |
|---------------|--------------|---------------|--------|
| Loading Product from DB | Query Builder / Repository | Query Layer | ✅ CORRECT |
| Loading flash_sales relation | Query Builder (with() calls) | Query Layer | ✅ CORRECT |
| Loading product relation on variants | Query Builder | Query Layer | ❌ NOT GUARANTEED (hidden contract) |
| Base price from DB column | Product model | Product model | ✅ CORRECT |
| Discount date validation | ProductPricingService + Product accessor | ProductPricingService ONLY | ❌ DUPLICATE |
| Flash sale date validation | ProductPricingService + FlashSale model | ProductPricingService ONLY | ❌ DUPLICATE |
| Flash sale resolution (which FS applies) | ProductPricingService + ProductRepository | ProductPricingService ONLY | ❌ DUPLICATE |
| Discount calculation (percentage/fixed) | ProductPricingService | ProductPricingService | ✅ CORRECT |
| Flash sale price calculation | ProductPricingService | ProductPricingService | ✅ CORRECT |
| Variant pricing (base + parent discount) | ProductPricingService | ProductPricingService | ✅ CORRECT |
| Coupon price calculation | ProductPricingService / CouponCalculator | CouponCalculator | ❌ DIVIDED |
| `current_price` on model | Product accessor | NONE (remove from model) | ❌ MUST REMOVE |
| `sale_price` on model | ProductVariant + Variation accessors | NONE (remove from model) | ❌ MUST REMOVE |
| `discount_active` on model | Product accessor | NONE (remove from model) | ❌ MUST REMOVE |
| `flash_sale_active` on model | Product accessor | NONE (remove from model) | ❌ MUST REMOVE |
| Variant pricing in Resources | app/ProductResource + package/ProductResource + WishlistResource | NONE (remove — consumers should call service) | ❌ MUST REMOVE |
| Cart price snapshot | CartInventoryService | CartInventoryService | ✅ CORRECT |
| Order item pricing | OrderCreationService | NONE (already in cart snapshot) | ❌ MUST REMOVE |
| Serialization to JSON | Resources | Resources | ✅ CORRECT |

### PROPOSED OWNERSHIP (After Refactor)

| Responsibility | Owner | Layer |
|---------------|-------|-------|
| Loading Product with flash_sales | ProductService/Repository | Query |
| Loading variants with product relation | ProductService/Repository | Query |
| Discount active check | ProductPricingService::isDiscountActive() | Service |
| Flash sale resolution | ProductPricingService::resolveActiveFlashSale() | Service |
| Flash sale price calculation | ProductPricingService::calculateFlashSalePrice() | Service |
| Discounted price calculation | ProductPricingService::calculateDiscountedPrice() | Service |
| Product current price | ProductPricingService::calculateProductCurrentPrice() | Service |
| Variant current price | ProductPricingService::calculateVariantCurrentPrice() | Service |
| Coupon price calculation | CouponCalculator (existing) | Service |
| Cart item pricing snapshot | CartInventoryService (calls PPS) | Service |
| Serialization | Resources (read prepared data only) | Resource |
| JSON response | Controller | Controller |

---

## TODO 13 — FINAL Runtime Pricing Rules

### Rule 1 — Resources NEVER calculate

Resources must NEVER call `ProductPricingService`, `app(...)` or any calculation. They serialize only. Variant pricing must be pre-computed before the resource is called.

### Rule 2 — Accessors NEVER execute business logic

Model accessors must NEVER call services, trigger queries, or calculate. They may only format/transform already-loaded data (e.g., `roundMoney()`). The `$appends` array for `current_price`, `sale_price`, `discount_active`, `flash_sale_active` must be REMOVED.

### Rule 3 — Repositories NEVER calculate pricing

Repositories query and persist data only. All pricing calculation must be removed from `ProductRepository`. The booking `calculatePrice()` methods should be moved to a dedicated `BookingService`.

### Rule 4 — Services NEVER serialize

Services return plain data or models. They never format JSON.

### Rule 5 — Queries MUST prepare complete models

Every query that returns products for display MUST eager-load `flash_sales` (with `valid()` scope) and `variations.product`. The `ProductRepository::fetchRelated()` and `getBestSellingProducts()` lack this and must be fixed.

### Rule 6 — ProductPricingService ONLY computes (never loads)

`resolveActiveFlashSale()` currently loads data from the database. This must be removed. The flash sale must be resolved BEFORE calling the service and passed as a parameter. The service must receive `?FlashSale $flashSale` as a parameter, never load it.

### Rule 7 — Variants MUST have parent product loaded

Any code that accesses variant pricing MUST ensure `product` relation is loaded. The silent fallback in `ProductVariant::getSalePriceAttribute()` and `Variation::getSalePriceAttribute()` is a bug. The relation must be eagerly loaded or pricing must be pre-computed.

### Rule 8 — Prices must be computed ONCE

The pricing flow is: query loads data → service computes price → data flows through resources. Prices should never be recomputed at different layers. `OrderCreationService::createOrderItems()` must NOT recalculate prices that were already set at cart creation time.

### Rule 9 — Models are data holders only

Models hold data and define relationships. They do not compute pricing, validate dates, or load data. All `app(ProductPricingService::class)` calls in models must be eliminated.

### Rule 10 — Promotions do not compute product pricing

`GiftPromotionStrategy` should not calculate variant pricing. Gift items have price = 0. The strategy should not call `ProductPricingService`.

### Rule 11 — Coupon pricing stays in CouponCalculator

`ProductPricingService::calculateCouponPrice` duplicates `CouponCalculator::calculate`. Coupon price calculation belongs in the `CouponCalculator`, not in `ProductPricingService`. Remove coupon methods from `ProductPricingService`.

### Rule 12 — FlashSale model does not calculate

`FlashSale::calcPrice()` must be removed. The model is a data holder.

### Rule 13 — One source of truth for `resolveActiveFlashSale`

There are two implementations: `ProductPricingService::resolveActiveFlashSale()` and `ProductRepository::resolveFlashSale()`. These must be consolidated. Since Rule 6 says the service should never load data, the Repository version (or a dedicated method) should be the single loader.

### Rule 14 — The pricing flow is unidirectional

Query → Service (compute) → Resource (serialize) → JSON. No layer calls back to a previous layer.

---

## TODO 14 — Final Project-Wide Violation Search

### Search: `app\(ProductPricingService`

Found in:
- `Product.php` — must remove (4 instances)
- `ProductVariant.php` — must remove (1 instance)
- `Variation.php` — must remove (1 instance)
- `FlashSale.php` — must remove (1 instance)
- `ProductRepository.php` — must remove (5 instances)
- `app/ProductResource.php` — must remove (1 instance)
- `package/ProductResource.php` — must remove (1 instance)
- `WishlistResource.php` — must remove (2 instances)
- `CartInventoryService.php` — KEEP (2 instances, correct use)
- `OrderCreationService.php` — must remove (1 instance)
- `CouponService.php` — KEEP but replace with CouponCalculator (1 instance)
- `GiftPromotionStrategy.php` — must remove (1 instance)
- Seeders (ProductVariantSeeder, ProductSeeder) — KEEP (seeders need pricing)
- `ProductImportService.php` — KEEP (import needs pricing)
- `CalculatePaymentTrait.php` — uses accessors indirectly, not direct call

### Search: `current_price`

Found in accessors (3 files), resources (6 files), services (3 files), tests (4 files).

All resource usages of `current_price` go through accessors. When accessors are removed, resources must fetch pre-computed prices.

### Search: `sale_price`

Found in: `ProductVariant.php`, `Variation.php` (accessors), `ProductRepository.php` (stored in DB), migrations, seeders, exports.

`sale_price` is BOTH a DB column on `product_variants` (stored at create/update time) AND a computed accessor. This is a naming collision — the DB column stores the calculated price at time of creation, while the accessor recalculates at read time.

### Search: `relationLoaded`

Found in:
- `ProductVariant.php:75` — DANGEROUS fallback
- `Variation.php:87` — DANGEROUS fallback
- `ProductPricingService.php` — safe (returns null)
- Multiple resources — safe (conditional serialization)

### Search: `flash_sale`

Found in 62 files. The pervasive requirement for flash sale data to be loaded before pricing calculations is the root cause of many issues.

### Search: `load(`, `loadMissing`

- `CartInventoryService.php:48` — `$variant->load('attributeProducts...')` — OK (attribute data, not pricing)
- `FlashSaleService.php:45` — `load('products')` — OK
- `ProductController.php:294,1140` — check context needed
- Various other services — general eager loading

### Search: `fresh()`, `refresh()`

- `CartInventoryService.php:66,153,301` — `refresh()` — OK (after mutation)
- `ProductService.php:448` — `fresh()` — context needed
- Tests — OK

---

## TODO 15 — Implementation Plan

### Phase 1: Foundation (remove violations from Models)

**Priority: HIGH | Risk: HIGH | Impact: ALL PRICING**

1. Remove `current_price`, `discount_active`, `flash_sale_active` from `Product::$appends`
2. Remove `current_price`, `sale_price` from `ProductVariant::$appends`
3. Remove `current_price`, `sale_price` from `Variation::$appends`
4. Delete accessor methods from Product, ProductVariant, Variation
5. Delete `Product::getDiscountedPrice()`, `Product::calculateDiscountedPrice()`

### Phase 2: Fix Queries (ensure data completeness)

**Priority: HIGH | Risk: MEDIUM | Impact: ALL PRODUCT QUERIES**

1. Add `flash_sales` eager loading to `ProductRepository::fetchRelated()`
2. Add `flash_sales` eager loading to `ProductRepository::getBestSellingProducts()`
3. Audit all other product queries for missing `flash_sales` or `variations.product`

### Phase 3: Purge Repository Pricing

**Priority: HIGH | Risk: LOW | Impact: REPOSITORY**

1. Delete `ProductRepository::calculateDiscountedPrice()`
2. Delete `ProductRepository::calculateFlashSalePrice()`
3. Delete `ProductRepository::resolveFlashSale()` (consolidate to service)
4. Delete `ProductRepository::calculateProductPrice()`
5. Delete `ProductRepository::calculateVariationPrice()`
6. Move booking `calculatePrice()` to dedicated `BookingService`

### Phase 4: Purge Resource Pricing

**Priority: HIGH | Risk: MEDIUM | Impact: RESOURCES**

1. Remove `ProductPricingService` calls from `app/ProductResource::getVariants()`
2. Remove `ProductPricingService` calls from `package/ProductResource::getVariants()`
3. Remove `ProductPricingService` calls from `WishlistResource`
4. Replace with pre-computed data or remove variant pricing from resources

### Phase 5: Clean ProductPricingService

**Priority: MEDIUM | Risk: MEDIUM | Impact: SERVICE**

1. Remove `resolveActiveFlashSale()` from service (move data loading to caller)
2. Make all public methods accept `?FlashSale` as explicit parameter (no internal resolution)
3. Remove `calculateCouponPrice()` and `calculateCouponPriceByCode()` — delegate to CouponCalculator
4. Remove `calculateProductPricingFromData()` and `calculateVariantPricingFromData()` — only needed in seeders/imports

### Phase 6: Fix Order Creation

**Priority: MEDIUM | Risk: LOW | Impact: ORDER**

1. Remove `ProductPricingService` calls from `OrderCreationService::createOrderItems()`
2. Use prices already stored in `cart_items.price` instead of recalculating

### Phase 7: Fix GiftPromotionStrategy

**Priority: LOW | Risk: LOW | Impact: PROMOTION**

1. Remove `ProductPricingService::calculateVariantCurrentPrice()` from `variantPayload()`
2. Gift items have price = 0, variant price is not needed

### Phase 8: Remove FlashSale::calcPrice()

**Priority: LOW | Risk: LOW | Impact: FLASH SALE**

1. Remove `FlashSale::calcPrice()` method

### Phase 9: Coupon Service Refactor

**Priority: LOW | Risk: LOW | Impact: COUPON**

1. Replace `ProductPricingService::calculateCouponPrice()` call with direct `CouponCalculator::calculate()` call

### Phase 10: Final Verification

**Priority: HIGH | Risk: NONE | Impact: ALL**

1. Run full test suite
2. Run all pricing-specific tests
3. Manual verification of product, variant, and order pricing in API responses
4. Verify no remaining `app(ProductPricingService::class)` calls in Models, Repositories, Resources

---

## Summary of Findings

| Category | Count |
|----------|-------|
| Pricing calculation locations | 36 |
| Correct locations | 4 |
| Must remove | 28 |
| Must keep/relocate | 4 |
| Hidden contracts | 9 |
| Dangerous fallbacks | 4 |
| Duplicate implementations | 10 groups |
| Files containing violations | 22 source files |
| Direct `app(ProductPricingService)` calls | 22 (excluding docs/seeders/tests) |

### Risk Summary

The most critical issue is the **silent fallback in ProductVariant and Variation accessors** — if the `product` relation is not loaded, the variant returns its raw undiscounted price. This is a hard-to-detect bug that could cause incorrect pricing in production.

The second most critical issue is **pricing being computed in 36 different locations** across 6 architectural layers, making it impossible to reason about correctness, performance, or consistency.

### Architecture Verdict

**The current architecture has NO single owner for runtime pricing.** Pricing knowledge is fragmented across Models (6 accessors), Resources (5 direct calls), Repository (5 helper methods), Services (1 service + 3 consumer services), Traits (1 trait), and Controllers (indirect). The `ProductPricingService` is the correct single owner, but its authority is undermined by every layer bypassing it through accessors and duplicate implementations.

**The architecture CAN be locked** by following the 14 rules in TODO 13 and the 10-phase implementation plan in TODO 15.
