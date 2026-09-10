# RUNTIME PRICING ARCHITECTURE — COMPLETE AUDIT REPORT

**Date:** 2026-07-13
**Scope:** 76 Resource files, 52+ Repository files, 20+ Service files, 3 Models, 3 Controllers, all route files, all tests
**Status:** Read-only audit completed — NO code changed

---

## TABLE OF CONTENTS

1. [Runtime Execution Graph](#1-runtime-execution-graph)
2. [Dependency Graph](#2-dependency-graph)
3. [Ownership Graph](#3-ownership-graph)
4. [Responsibility Matrix](#4-responsibility-matrix)
5. [Hidden SQL Report](#5-hidden-sql-report)
6. [Hidden Dependency Report](#6-hidden-dependency-report)
7. [Pricing Lifecycle](#7-pricing-lifecycle)
8. [Resource Audit](#8-resource-audit)
9. [Repository Audit](#9-repository-audit)
10. [Service Audit](#10-service-audit)
11. [Model Audit](#11-model-audit)
12. [Architecture Violations](#12-architecture-violations)
13. [Root Cause Analysis](#13-root-cause-analysis)
14. [Correct Target Architecture](#14-correct-target-architecture)
15. [Migration Roadmap](#15-migration-roadmap)

---

## 1. RUNTIME EXECUTION GRAPH

### Generic Flow Pattern

```
HTTP Request
    │
    ▼
Route (routes/api.php or packages/marvel/src/Rest/Routes.php)
    │
    ▼
Controller
    │  ┌─ Marvel Controllers: calls Repository directly
    │  └─ App Controllers: calls Service, which calls Repository
    ▼
Repository / Service
    │  ┌─ Queries Models with ->with() eager loading
    │  └─ Returns Eloquent Collection / LengthAwarePaginator
    ▼
Resource::collection() / Resource::make()
    │  ┌─ Calls toArray() on each model
    │  └─ Model $appends accessors fire during JSON serialization
    ▼
ProductPricingService (called BY accessors OR directly BY Resources)
    │
    ▼
JSON Response
```

### All Entry Points Trace

#### Entry Point A: GET /api/v1/products (Product List — Marvel)
```
Route: packages/marvel/src/Rest/Routes.php:169
Controller: Marvel ProductController@index (packages/marvel/src/Http/Controllers/ProductController.php:126)
  ├── fetchProducts($request) → returns query builder
  │   └── with(['variations', 'categories', 'flash_sales' => fn($q) => $q->valid()])
  │       → OWNER: Query Layer (eager loads flash_sales ✓)
  │
  ├── ProductCollection::make(...) (packages/marvel/src/Http/Resources/product/ProductCollection.php)
  │   └── Each item → Marvel ProductResource::toArray()
  │       ├── $this->current_price → Product::getCurrentPriceAttribute()
  │       │   └── app(ProductPricingService)->calculateProductCurrentPrice($this)
  │       │       → OWNER: Model (SERVICE LOCATOR — violates SRP)
  │       │
  │       ├── $this->discount_active → Product::getDiscountActiveAttribute()
  │       │   └── app(ProductPricingService)->isDiscountActive($this)
  │       │       → OWNER: Model (SERVICE LOCATOR — violates SRP)
  │       │
  │       ├── $this->flash_sale_active → Product::getFlashSaleActiveAttribute()
  │       │   └── app(ProductPricingService)->resolveActiveFlashSale($this)
  │       │       → OWNER: Model (SERVICE LOCATOR — violates SRP)
  │       │
  │       └── getVariants():
  │           → app(ProductPricingService)->calculateVariantCurrentPrice($this->resource, $variant)
  │           → OWNER: Resource (PRESENTATION LEAK — violates SRP)
  │
  └── JSON Response
```

#### Entry Point B: GET /general/products (Product List — App)
```
Route: routes/api.php:38
Controller: App ProductController@index (app/Http/Controllers/Api/General/ProductController.php:65)
  ├── ProductService::paginate() (app/Services/General/ProductService.php:96)
  │   └── buildFilteredBaseQuery() with flash_sales eager loaded ✓
  │       → OWNER: Service Layer
  │
  ├── ProductCollectionMini::make(...) (packages/marvel/src/Http/Resources/product/ProductCollectionMini.php)
  │   └── Each item → ProductMiniResource::toArray()
  │       ├── $this->current_price → accessor (Service Locator)
  │       ├── $this->discount_active → accessor (Service Locator)
  │       ├── $this->flash_sale_active → accessor (Service Locator)
  │       └── ratings fallback: $this->reviews()->avg('rating') ← HIDDEN SQL!
  │
  └── JSON Response
```

#### Entry Point C: GET /general/products/{slug} (Single Product — App)
```
Route: routes/api.php:37
Controller: App ProductController@getProductBySlug (app/Http/Controllers/Api/General/ProductController.php:113)
  ├── ProductService::getProductBySlug() (app/Services/General/ProductService.php:133)
  │   └── with(['categories', 'variations', ..., 'flash_sales' => fn($q) => $q->valid()]) ✓
  │
  ├── App ProductResource::make($product)
  │   ├── $this->current_price → accessor (Service Locator)
  │   ├── $this->discount_active → accessor (Service Locator)
  │   ├── $this->flash_sale_active → accessor (Service Locator)
  │   └── getVariants():
  │       → app(ProductPricingService)->calculateVariantCurrentPrice() ← PRESENTATION LEAK
  │
  └── JSON Response
```

#### Entry Point D: GET /api/v1/products/{id} (Single Product — Marvel)
```
Route: packages/marvel/src/Rest/Routes.php:169
Controller: Marvel ProductController@show (actually fetchSingleProduct at line 290)
  ├── ->firstOrFail() then ->load(['flash_sales' => fn($q) => $q->valid()]) ✓
  │
  ├── Marvel ProductResource::make($product)
  │   └── SAME PATTERN as Entry Point C
  │
  └── JSON Response
```

#### Entry Point E: GET /api/v1/wishlists
```
Route: packages/marvel/src/Rest/Routes.php:441
Controller: WishlistController@index (packages/marvel/src/Http/Controllers/WishlistController.php:49)
  ├── Product::whereIn('id', $productIds) — MISSING flash_sales! ✗ CRITICAL
  │
  ├── WishlistResource::collection(...)
  │   ├── $this->current_price → accessor (returns base price, flash_sales MISSING!)
  │   ├── app(ProductPricingService)->calculateVariantCurrentPrice() ← PRESENTATION LEAK
  │   └── app(ProductPricingService)->calculateVariantCurrentPrice() again in variants loop
  │
  └── JSON Response with WRONG prices if product has flash sale
```

#### Entry Point F: GET /api/v1/component-data/flash-sale-products
```
Route: packages/marvel/src/Rest/Routes.php:549
Controller: ComponentDataController@flashSaleProducts
  └── ComponentDataService::getFlashSaleProducts()
      └── with(['flash_sales']) ✓
```

#### Entry Point G: GET /api/v1/component-data/popular-products
```
Route: packages/marvel/src/Rest/Routes.php:550
Controller: ComponentDataController@popularProducts
  └── ProductController::popularProducts()
      └── with(['type', 'shop']) — MISSING flash_sales! ✗ CRITICAL
```

#### Entry Point H: Cart flows
```
Route: packages/marvel/src/Rest/Routes.php:407-437
Controller: CartController
  ├── CartInventoryService::reserveItem()
  │   └── app(ProductPricingService)->calculateVariantCurrentPrice / calculateProductCurrentPrice
  │       → OWNER: Service (legitimate, should use DI)
  │
  └── CartResource / CartItemResource
      ├── CartResource: Coupon::where('code', ...)->first() ← HIDDEN SQL!
      └── CartItemResource: $this->product->id (lazy load) ← HIDDEN SQL!
```

#### Entry Point I: Checkout / Order Creation
```
Route: packages/marvel/src/Rest/Routes.php:311-367
Controller: OrderController / CheckoutController
  ├── OrderService::getCheckoutTotalsFromCart()
  │   └── Cart::with(['items.product.flash_sales', 'items.productVariant']) ✓
  │
  ├── OrderCreationService::createOrderItems()
  │   └── app(ProductPricingService)->calculateFlashSalePrice (recalculation — data integrity risk)
  │
  └── OrderResource / OrderItemResource
      └── $this->product_flash_sale_price (from stored column — CORRECT)
```

---

## 2. DEPENDENCY GRAPH

### ProductPricingService Dependency Chain

```
                     ┌──────────────────────────────────────────┐
                     │           ProductPricingService          │
                     │  (packages/marvel/Services/Pricing/)     │
                     └──────┬──────────────┬──────────┬─────────┘
                            │              │          │
              ┌─────────────┘              │          └─────────────┐
              ▼                            ▼                        ▼
     ┌────────────────┐         ┌──────────────────┐     ┌──────────────────┐
     │  FlashSale     │         │   CouponCalculator│     │  normalizeMoney  │
     │  (Model)       │         │   (Pure Math)     │     │  (helper)        │
     └────────────────┘         └──────────────────┘     └──────────────────┘
              ▲
              │
     ┌────────┴────────┐
     │  Product        │
     │  (Model)        │
     │  $appends:      │
     │  current_price  │──→ app(ProductPricingService) ← SERVICE LOCATOR
     │  discount_active│──→ app(ProductPricingService) ← SERVICE LOCATOR
     │  flash_sale_act │──→ app(ProductPricingService) ← SERVICE LOCATOR
     └─────────────────┘
              ▲
              │
     ┌────────┴────────────────┐
     │  ProductVariant        │
     │  $appends:             │
     │  current_price → sale_price → app(ProductPricingService) ← SERVICE LOCATOR
     │  sale_price → app(ProductPricingService) ← SERVICE LOCATOR
     └─────────────────────────┘
              ▲
              │
     ┌────────┴────────────────┐
     │  Variation             │
     │  $appends:             │
     │  current_price → sale_price → app(ProductPricingService) ← SERVICE LOCATOR
     │  sale_price → app(ProductPricingService) ← SERVICE LOCATOR
     │  blocked_dates → SQL query ← HIDDEN SQL CRITICAL
     └─────────────────────────┘
              ▲
              │
     ┌────────┴────────────────┐
     │  FlashSale             │
     │  calcPrice() → app(PPS)← SERVICE LOCATOR
     └─────────────────────────┘

     LAYER 4: RESOURCES (call ProductPricingService DIRECTLY)
     ┌───────────────────────────────────────────────────────────────┐
     │  Marvel ProductResource::getVariants() → app(PPS)            │
     │  App ProductResource::getVariants() → app(PPS)               │
     │  WishlistResource → app(PPS) (TWICE)                         │
     │  CouponResource → CouponValidator::validate()                │
     └───────────────────────────────────────────────────────────────┘

     LAYER 3: SERVICES (call ProductPricingService with app())
     ┌───────────────────────────────────────────────────────────────┐
     │  CartInventoryService → app(PPS)                             │
     │  OrderCreationService → app(PPS)                             │
     │  CouponService → app(PPS) (redundant — goes through          │
     │                   CouponCalculator already)                   │
     │  GiftPromotionStrategy → app(PPS)                            │
     └───────────────────────────────────────────────────────────────┘

     LAYER 2: REPOSITORY (calls ProductPricingService with app())
     ┌───────────────────────────────────────────────────────────────┐
     │  ProductRepository (5 call sites + 2 dead private wrappers)  │
     └───────────────────────────────────────────────────────────────┘

     LAYER 1: MODELS (SERVICE LOCATOR PATTERN)
     ┌───────────────────────────────────────────────────────────────┐
     │  Product (5 call sites, 1 dead code)                         │
     │  ProductVariant (1 call site)                                │
     │  Variation (1 call site)                                    │
     │  FlashSale (1 call site)                                    │
     └───────────────────────────────────────────────────────────────┘
```

---

## 3. OWNERSHIP GRAPH

### Current (Broken) Ownership

| Artifact | Product Pricing | Variant Pricing | Flash Sale Resolution | Discount Resolution | Coupon Resolution | Serialization | Query Prep |
|----------|---------------|----------------|----------------------|-------------------|------------------|---------------|------------|
| Product Model | ✓ (accessor) | — | ✓ (accessor) | ✓ (accessor) | — | — | — |
| ProductVariant Model | — | ✓ (accessor) | — | — | — | — | — |
| Variation Model | — | ✓ (accessor) | — | — | — | — | — |
| FlashSale Model | — | — | — | — | — | — | — |
| ProductPricingService | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| ProductRepository | ✓ (dead) | ✓ | ✓ (dead) | ✓ (dead) | — | — | ✓ |
| ProductService (App) | — | — | — | — | — | — | ✓ |
| CartInventoryService | ✓ | ✓ | — | — | — | — | — |
| OrderCreationService | — | — | ✓ | ✓ | — | — | — |
| CouponService | — | — | — | — | ✓ | — | — |
| GiftPromotionStrategy | ✓ | ✓ | — | — | — | — | — |
| ProductResource (Marvel) | ✓ | ✓ | ✓ | ✓ | — | ✓ | — |
| ProductResource (App) | ✓ | ✓ | ✓ | ✓ | — | ✓ | — |
| ProductMiniResource | ✓ | — | ✓ | ✓ | — | ✓ | — |
| WishlistResource | ✓ | ✓ | ✓ | ✓ | — | ✓ | — |
| GetSingleProductResource | ✓ | — | ✓ | ✓ | — | ✓ | — |
| RelatedProductResource | ✓ | — | — | — | — | ✓ | — |
| OrderProductVariantResource | ✓ | — | — | — | — | ✓ | — |
| CalculatePaymentTrait | ✓ | ✓ | — | ✓ | ✓ | — | — |

**Key:** ✓ = owns this responsibility properly | ✓(italics) = owns but should not | — = not involved

### Current Problems Clearly Visible

1. **Pricing has 14+ owners** — every layer thinks it owns pricing
2. **ProductPricingService** — the only correct owner — is bypassed by accessors
3. **Resources** — own pricing AND serialization (two responsibilities)
4. **ProductRepository** — owns pricing (violation) AND CRUD (violation)
5. **Multiple actors calculate the same thing differently** — `OrderCreationService` recalculates flash sale prices at order creation, while `CartInventoryService` already calculated them at cart reservation

---

## 4. RESPONSIBILITY MATRIX

### Correct Ownership (Target)

| Responsibility | Owner | Layer | Why |
|---|---|---|---|
| **Store base prices** | Product.price, ProductVariant.price, Variation.price | Data (Model) | Database columns — passive data |
| **Define discount rules** | Product.discount_type, Product.discount_amount | Data (Model) | Product attributes |
| **Define flash sale rules** | FlashSale table | Data (Model) | Flash sale definition |
| **Compute effective price** | ProductPricingService | Service (Domain) | Pure computation — no side effects |
| **Compute flash sale price** | ProductPricingService | Service (Domain) | Pure computation |
| **Compute discount price** | ProductPricingService | Service (Domain) | Pure computation |
| **Resolve active flash sale** | ProductPricingService | Service (Domain) | Business rule: "which flash sale is active now?" |
| **Check if discount active** | ProductPricingService | Service (Domain) | Business rule: date checking |
| **Compute coupon price** | CouponCalculator | Service (Domain) | Pure math — separate concern |
| **Eager load required relations** | Query Layer (Repository/Service) | Data (Query) | Must load flash_sales, product before passing to Service |
| **Serialize pricing** | Resources | Presentation | Receive pre-computed data, format for JSON |
| **Orchestrate checkout pricing** | OrderService + OrderCreationService | Service (Application) | Coordinate pricing snapshot at order creation |

---

## 5. HIDDEN SQL REPORT

### SQL Fires During JSON Serialization

| # | Severity | File | Line | SQL Triggered | Guarded? |
|---|---|---|---|---|---|
| 1 | **CRITICAL** | `Variation.php` | 40-63 | `getBlockedDatesAttribute()` fires `Availability::where(...)->get()` on EVERY serialization. `blocked_dates` is in `$appends`. | **NO** |
| 2 | **HIGH** | `ProductMiniResource.php` | 40 | `$this->reviews()->avg('rating')` — aggregate query when `reviews_avg_rating` not pre-loaded | **NO** |
| 3 | **HIGH** | `CartResource.php` | 24 | `Coupon::where('code', $this->coupon)->first()` — explicit query every serialization | **NO** |
| 4 | **HIGH** | `CartItemResource.php` | 23-27 | `$this->product->id` etc. triggers lazy loading of `product` relation | **NO** |
| 5 | **HIGH** | `GetSingleProductResource.php` | 46-50 | 5 accessors: `ratings`, `total_reviews`, `rating_count`, `my_review`, `in_wishlist` — each fires SQL | **NO** |
| 6 | **MEDIUM** | `SectionResource.php` | 22-28 | `SectionType::where(...)->first()` + settings queries | **NO** |
| 7 | **MEDIUM** | `ProductPricingService.php` | 224-227 | `FlashSale::query()->where(...)->first()` (only when explicit flashSaleId passed) | N/A (by design) |
| 8 | **MEDIUM** | `ProductPricingService.php` | 190 | `Coupon::valid()->where('code', $code)->first()` in `calculateCouponPriceByCode()` | N/A (dead code) |

### Key Finding

**CRITICAL: `Variation::getBlockedDatesAttribute()`** is the most dangerous hidden SQL. It is in `$appends` so it fires on EVERY variation serialization. Unlike the pricing accessors which are guarded by `relationLoaded()`, this one has NO guard. Any endpoint that returns Variation models (e.g., product detail with variations) will fire an N+1 avalanche of `Availability` queries.

---

## 6. HIDDEN DEPENDENCY REPORT

### `relationLoaded()` Guarded Dependencies (Safe, but indicate design smell)

| File | Line | Guard | Fallback |
|---|---|---|---|
| `ProductVariant.php` | 75 | `$this->relationLoaded('product')` | Returns `$this->price` silently |
| `Variation.php` | 87 | `$this->relationLoaded('product')` | Returns `$this->price` silently |
| `ProductPricingService.php` | 230 | `$product->relationLoaded('flash_sales')` | Returns null (flash sale skipped) |

### The Silent Fallback Problem

Every guarded dependency has a **silent fallback** — if the relation is not loaded, a default value is returned instead of the correct value. This means:
- Wrong prices are returned without errors or warnings
- Developer must remember to eager-load relations at EVERY query site
- Adding a new query path that returns products can introduce silently wrong pricing
- No test will catch this unless it specifically checks flash sale pricing

### Dead Code Dependencies

| File | Line | Code | Status |
|---|---|---|---|
| `Product.php` | 150-157 | `private function calculateDiscountedPrice()` | **DEAD** — never called |
| `ProductRepository.php` | 471-474 | `private function calculateDiscountedPrice()` | **DEAD** — never called |
| `ProductRepository.php` | 508-511 | `private function calculateFlashSalePrice()` | **DEAD** — never called |
| `ProductPricingService.php` | 187-198 | `calculateCouponPriceByCode()` | **DEAD** — never called |
| `ProductImportService.php` | 30-60 | `$this->pricingService` property | **DEAD** — declared but never used |
| `CalculatePaymentTrait.php` | 9 | `use ProductPricingService` | **DEAD** — unused import |
| `FlashSaleRepository.php` | 235-237 | `private function updateFlashSaleProductPrices()` | **DEAD** — empty method body |

---

## 7. PRICING LIFECYCLE

### Phase 1: Definition (Admin)
```
Admin creates/updates Product
  │
  ├── Sets price, discount_type, discount_amount, has_discount, discount_status, start_date, end_date
  │
  ├── ProductRepository::storeProduct() / updateProduct()
  │   ├── Stores variant with base price
  │   └── Calls ProductPricingService to compute sale_price for each variant
  │
  └── Data stored in DB columns (price, sale_price, discount fields)
```

### Phase 2: Query (API Request)
```
API Request for products
  │
  ├── Controller → Repository/Service
  │   ├── Queries Product model (may or may not ->with('flash_sales'))
  │   └── Returns Collection/Paginator
  │
  └── SILENT FAILURE POINT: If caller forgot ->with('flash_sales'), pricing will be WRONG
```

### Phase 3: Serialization (JSON Response)
```
Resource::toArray()
  │
  ├── Accesses $this->current_price
  │   └── Product::getCurrentPriceAttribute()
  │       └── app(ProductPricingService)::calculateProductCurrentPrice($this)
  │           ├── normalizeMoney($this->price)  ← base price from DB
  │           ├── resolveActiveFlashSale($this)
  │           │   └── checks relationLoaded('flash_sales') ← guarded
  │           ├── isDiscountActive($this)  ← checks model attributes
  │           ├── calculateFlashSalePrice()  ← if flash sale active
  │           └── calculateDiscountedPrice()  ← if discount active
  │           └── returns final_price (lowest of all)
  │
  ├── Accesses $this->discount_active
  │   └── Simple check on model attributes
  │
  ├── Accesses $this->flash_sale_active
  │   └── app(ProductPricingService)::resolveActiveFlashSale($this) !== null
  │
  └── getVariants() [in ProductResource]
      └── app(ProductPricingService)::calculateVariantCurrentPrice() ← BYPASSES accessor
```

### Phase 4: Cart Reservation
```
CartInventoryService::reserveItem()
  │
  ├── app(ProductPricingService)::calculateVariantCurrentPrice() or calculateProductCurrentPrice()
  │
  ├── Stores price in cart_items.price and cart_items.total_price
  │
  └── Price is now SNAPSHOTTED in cart (correct for order creation)
```

### Phase 5: Order Creation
```
OrderCreationService::createOrderItems()
  │
  ├── RECALCULATES flash sale price (Data Integrity Risk!)
  │   └── app(ProductPricingService)::calculateFlashSalePrice()
  │       → Potential inconsistency with what was in cart
  │
  ├── RECALCULATES discount price
  │   └── app(ProductPricingService)::calculateDiscountedPrice()
  │
  └── Stores product_flash_sale_price in order_products (snapshot for historical record)
```

### The Core Flaw

**Pricing is computed at serialization time (Phase 3), not at query time (Phase 2).** This means:
- Every API response does N pricing calculations (one per product × one per variant)
- The correctness depends on the caller having eager-loaded the right relations
- Resources must bypass accessors with direct service calls when accessors don't return correct data
- No caching is possible because pricing happens at the last possible moment

---

## 8. RESOURCE AUDIT

### 76 Resources Examined — 5 With Pricing Violations

| Resource | File | Violation | Severity |
|---|---|---|---|
| **Marvel ProductResource** | `packages/marvel/Http/Resources/product/ProductResource.php` | Line 91: `app(PPS)->calculateVariantCurrentPrice()` — direct service call during serialization | HIGH |
| **App ProductResource** | `app/Http/Resources/Product/ProductResource.php` | Line 78: `app(PPS)->calculateVariantCurrentPrice()` — direct service call during serialization | HIGH |
| **WishlistResource** | `packages/marvel/Http/Resources/WishlistResource.php` | Lines 19, 33: `app(PPS)->calculateVariantCurrentPrice()` twice + business logic for simple vs variable product pricing | HIGH |
| **ProductMiniResource** | `app/Http/Resources/Product/ProductMiniResource.php` | Line 40: `$this->reviews()->avg('rating')` — N+1 SQL fallback | HIGH |
| **CouponResource** | `packages/marvel/Http/Resources/CouponResource.php` | Line 36: `CouponValidator::validate($this)` — business validation during serialization | MEDIUM |

### Resources With Clean Pricing (use accessors only — not ideal, but consistent)
- `RelatedProductResource` — uses `$this->current_price` via accessor
- `GetSingleProductResource` — uses accessor + N+1 through non-pricing accessors
- `ProductVariantResource` — uses `$this->current_price` via accessor (DEAD CODE — never used)
- `OrderProductVariantResource` — uses `$this->current_price` via accessor
- `OrderItemResource` — reads stored `product_flash_sale_price` column (CORRECT)

### 68 Resources — Clean (no pricing code at all)

---

## 9. REPOSITORY AUDIT

### 52+ Repositories Examined — 3 With Violations

#### ProductRepository (`packages/marvel/Database/Repositories/ProductRepository.php` — 513 lines)
| Issue | Lines | Detail |
|---|---|---|
| Calculates prices | 361-449 | `calculatePrice()`, `calculateProductPrice()`, `calculateVariationPrice()`, `calculateLocationPrice()`, `calculateResourcePrice()` — ~90 lines of pricing |
| Resolves flash sales | 484-499 | `resolveFlashSale()` — duplicates logic from ProductPricingService |
| Knows ProductPricingService | 28, 218, 411, 422, 473, 510 | Import + 5 call sites + 2 dead wrappers |
| Dead pricing code | 471-511 | `calculateDiscountedPrice()` and `calculateFlashSalePrice()` private wrappers never called |
| SRP Violations | Entire file | CRUD + pricing + flash sale resolution + slug generation + rental pricing |

#### OrderRepository (`packages/marvel/Database/Repositories/OrderRepository.php` — 771 lines)
| Issue | Lines | Detail |
|---|---|---|
| Uses CalculatePaymentTrait | Uses trait for subtotal/discount/item total calculation |
| Duplicates pricing logic | Trait calculates subtotals independently of ProductPricingService |
| SRP Violations | Entire file | CRUD + payment + stock + wallet + coupons + child orders + digital files + rentals + invoices + income + pricing |

#### FlashSaleRepository (`packages/marvel/Database/Repositories/FlashSaleRepository.php`)
| Issue | Lines | Detail |
|---|---|---|
| Empty dead method | 235-237 | `private function updateFlashSaleProductPrices(FlashSale $flashSale): void {}` |
| Data integrity | Modifies product `has_flash_sale` flag |

#### All other repositories — Clean (no pricing code)

---

## 10. SERVICE AUDIT

### 20+ Services Examined — 6 With Violations

| Service | Violation | Severity |
|---|---|---|
| **CartInventoryService** | Calculates pricing (calls PPS) — inventory service should not own pricing | MEDIUM |
| **OrderCreationService** | Recalculates flash sale price at order creation — data integrity risk (inconsistent with cart) | HIGH |
| **CouponService** | Calls PPS::calculateCouponPrice() which delegates to CouponCalculator — CouponService already has `calcPrice()` that delegates directly to CouponCalculator | MEDIUM |
| **GiftPromotionStrategy** | Calculates variant pricing — promotion strategy should only compute eligibility/discount, not product prices | MEDIUM |
| **OrderService** | `getCheckoutTotalsFromCart()` duplicates subtotal logic from `PromotionService::subtotal()` | LOW |
| **PromotionService** | `subtotal()` and `removeGiftItems()` are side effects mixed with eligibility flow | LOW |

### Clean Services (exemplary)
| Service | Reason |
|---|---|
| **ProductPricingService** | Pure pricing computation — single responsibility, no side effects |
| **CouponCalculator** | Pure math — no dependencies, testable |
| **CouponValidator** | Pure validation — no side effects |
| **PromotionEligibilityResolver** | Pure matching logic — no side effects |
| **ProductFilter** | Pure query building — no pricing |

---

## 11. MODEL AUDIT

### Models With Pricing Accessors

#### Product Model
| Accessor | Lines | Calls Service? | Uses `app()`? | Has Guard? | In `$appends`? |
|---|---|---|---|---|---|
| `getCurrentPriceAttribute` | 133-136 | YES | YES | Indirect (PPS checks flash_sales) | YES |
| `getDiscountActiveAttribute` | 113-116 | YES | YES | N/A (no SQL) | YES |
| `getFlashSaleActiveAttribute` | 118-121 | YES | YES | YES (via PPS) | YES |
| `getCurrentPrice()` (non-accessor) | 128-131 | YES | YES | Indirect | N/A |
| `getDiscountedPrice()` | 138-141 | YES | YES | Indirect | NO |
| `calculateDiscountedPrice()` (private) | 150-157 | YES | YES | N/A | **DEAD CODE** |

#### ProductVariant Model
| Accessor | Lines | Calls Service? | Uses `app()`? | Has Guard? | In `$appends`? |
|---|---|---|---|---|---|
| `getCurrentPriceAttribute` | 63-66 | YES (delegates) | Delegates | YES | YES |
| `getSalePriceAttribute` | 73-82 | YES | YES | `relationLoaded('product')` | YES |

#### Variation Model
| Accessor | Lines | Calls Service? | Uses `app()`? | Has Guard? | In `$appends`? |
|---|---|---|---|---|---|
| `getCurrentPriceAttribute` | 80-83 | YES (delegates) | Delegates | YES | YES |
| `getSalePriceAttribute` | 85-94 | YES | YES | `relationLoaded('product')` | YES |
| `getBlockedDatesAttribute` | 40-43 | **SQL query** | N/A | **NO** | **YES — CRITICAL** |

#### FlashSale Model
| Method | Lines | Calls Service? | Uses `app()`? |
|---|---|---|---|
| `calcPrice()` | 83-86 | YES | YES |

---

## 12. ARCHITECTURE VIOLATIONS

### SRP (Single Responsibility Principle)

| Violation | File | What it Does | What it Should Do |
|---|---|---|---|
| **Model computes pricing** | Product.php, ProductVariant.php, Variation.php | Data access + pricing computation | Data access ONLY |
| **Resource computes pricing** | ProductResource (both), WishlistResource | Serialization + pricing calculation | Serialization ONLY |
| **Repository computes pricing** | ProductRepository | Data access + pricing + flash sale resolution | Data access ONLY |
| **Repository does everything** | OrderRepository | CRUD + payment + stock + wallet + coupons + invoices + pricing | CRUD only |
| **Inventory service computes pricing** | CartInventoryService | Inventory management + pricing | Inventory management only |
| **Promotion strategy computes pricing** | GiftPromotionStrategy | Promotion eligibility + pricing | Promotion eligibility + gift payload only |
| **Checkout service recalculates pricing** | OrderCreationService | Order creation + pricing recalculation | Order creation using pre-computed prices |

### OCP (Open-Closed Principle)

- Adding a new pricing rule requires modifying: `ProductPricingService` (acceptable), PLUS potentially 3 models, 3 resources, 1 repository, 2 services
- The `$appends` approach makes it impossible to add pricing variants without changing the model

### DIP (Dependency Inversion Principle)

- Models use `app(ProductPricingService::class)` — this is a concrete service locator, violating DIP
- Resources use `app(ProductPricingService::class)` — same violation
- NO constructor injection is used for ProductPricingService anywhere

### DRY (Don't Repeat Yourself)

- `Product::getCurrentPriceAttribute()` calls `getCurrentPrice()` which calls service — three layers to do one thing
- `ProductRepository::calculateDiscountedPrice()` and `ProductPricingService::calculateDiscountedPrice()` — same logic, two places
- `ProductRepository::calculateFlashSalePrice()` and `ProductPricingService::calculateFlashSalePrice()` — same logic, two places
- `CartInventoryService::reserveItem()` and `OrderCreationService::createOrderItems()` both calculate the same prices
- `CalculatePaymentTrait::calculateSubtotal()` and `PromotionService::subtotal()` — same logic
- `roundMoney()` duplicated in 9+ files

### Law of Demeter

- `CartItemResource::toArray()` calls `$this->product->getFirstMediaUrl('products')` — reaching through product to media
- Resources access `$this->product->something` — violating Law of Demeter

### Tell Don't Ask

- `ProductVariant::getSalePriceAttribute()` asks `$this->relationLoaded('product')` then decides behavior
- `ProductPricingService::resolveActiveFlashSale()` asks `$product->relationLoaded('flash_sales')` then decides
- Resources ask about product type, variation loading state before deciding pricing

### Repository Pattern

- `ProductRepository` is 513 lines with 30% dedicated to pricing — violates "Repository = data access only"
- `OrderRepository` is 771 lines — should be split into focused repositories

### Serialization Side Effects

- Variation `$appends` includes `blocked_dates` which fires SQL on every serialization
- ProductMiniResource fires `reviews()->avg()` on fallback
- CartResource fires `Coupon::where('code', ...)` on every serialization
- GetSingleProductResource fires 5+ SQL queries through accessors
- CouponResource fires `CouponValidator::validate()` on every serialization

---

## 13. ROOT CAUSE ANALYSIS

### The FIRST Architectural Mistake

**Follow the chain backwards:**

```
Wrong price in API response
  │
  └── Wrong accessor return value (silent fallback to base price)
      │
      └── Missing relation (flash_sales not loaded)
          │
          └── Query layer forgot ->with('flash_sales')
              │
              └── Pricing computed at serialization time (too late to add relations)
                  │
                  └── Pricing in $appends accessors (serialization happens last)
                      │
                      └── "Let's put computed prices in $appends so they auto-appear in JSON"
                          │
                          └── FIRST ARCHITECTURAL MISTAKE
```

### The Primary Root Cause

**Putting pricing computation inside Eloquent `$appends` accessors on Models.**

This single decision caused ALL of these consequences:

| Consequence | Mechanism |
|---|---|
| **Service Locator in Models** | Accessors call `app(PPS::class)` because models can't receive injected dependencies |
| **Hidden relation dependency** | Accessors depend on `flash_sales` / `product` being loaded — hidden contract |
| **Silent fallback** | `relationLoaded()` guards prevent N+1 but return wrong data silently |
| **Resources bypass accessors** | When accessors fail, Resources call PPS directly (SRP violation) |
| **Inconsistent pricing** | Some endpoints get flash sale prices (load flash_sales), some don't |
| **Cannot cache** | Pricing computed at serialization — last possible moment, no caching hooks |
| **Duplicate code** | Multiple layers implement pricing because accessors can't be trusted |
| **N+1 risk** | Non-pricing accessors (blocked_dates, ratings) fire SQL without guards |
| **Testing difficulty** | Models require booted container to test pricing |
| **Dead code proliferation** | Private wrapper methods accumulate as developers add safer alternatives |

### Secondary Root Causes

| # | Mistake | Evidence |
|---|---|---|
| 2 | Resources calling services directly | `ProductResource::getVariants()` → `app(PPS)` |
| 3 | Repository with pricing logic | `ProductRepository::calculatePrice()` (90+ lines of pricing) |
| 4 | No pricing DTO/ViewModel | Pricing data passed as raw arrays — no type safety |
| 5 | No eager loading standard | 10 query paths miss `flash_sales`, 2 query paths miss `product` |
| 6 | Two active pricing pipelines | `CalculatePaymentTrait` vs `ProductPricingService` |

---

## 14. CORRECT TARGET ARCHITECTURE

### Design Principles

1. **Models are data containers** — NO pricing logic, NO accessors, NO service calls, NO SQL
2. **ProductPricingService is the SOLE pricing authority** — called by Service layer only
3. **Resources receive pre-computed data** — NO pricing calls, NO business logic
4. **ProductPricingDTO is the pricing contract** — immutable, typed, testable
5. **Query layer is responsible for loading all required relations** — enforced by convention or contract
6. **Repository is data access only** — NO pricing, NO business rules

### New Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CONTROLLER LAYER                             │
│  Receives Request → Calls Service → Returns Resource                │
│  NO pricing logic                                                   │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        SERVICE LAYER                                │
│  ProductService / CartService / CheckoutService                     │
│                                                                     │
│  1. Calls Repository to get data                                     │
│  2. Calls ProductPricingService to compute pricing                   │
│  3. Assembles ProductPricingDTO with computed prices                 │
│  4. Passes DTO to Resource (via constructor or dedicated method)     │
│                                                                     │
│  SOLE layer that knows about pricing                                 │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    REPOSITORY LAYER                                  │
│  ProductRepository / CartRepository / OrderRepository                │
│                                                                     │
│  SOLE responsibility: Query data with all required relations         │
│  NO pricing logic at all                                             │
│  Returns Eloquent models or collections                              │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        MODEL LAYER                                   │
│  Product / ProductVariant / Variation / FlashSale                    │
│                                                                     │
│  SOLE responsibility: Data representation + relationships            │
│  NO $appends for computed fields                                     │
│  NO accessors that call services                                     │
│  NO accessors that fire SQL                                          │
│  NO relationLoaded() checks                                          │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      DOMAIN SERVICE LAYER                            │
│  ProductPricingService                                               │
│                                                                     │
│  SOLE responsibility: Compute pricing                                │
│  Receives Models as input → returns DTO                              │
│  Pure computation — no side effects, no SQL                          │
│  Injectable (constructor injection)                                  │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        DTO LAYER                                     │
│  ProductPricingDTO (immutable)                                       │
│                                                                     │
│  Properties:                                                         │
│  - base_price: float                                                 │
│  - final_price: float (lowest applicable)                            │
│  - flash_sale_price: ?float                                          │
│  - discount_price: ?float                                            │
│  - is_discount_active: bool                                          │
│  - is_flash_sale_active: bool                                        │
│  - active_flash_sale: ?FlashSaleDTO                                  │
│  - applied_discount: ?DiscountDTO                                    │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      RESOURCE LAYER                                  │
│  ProductResource / ProductMiniResource / WishlistResource            │
│                                                                     │
│  SOLE responsibility: Transform data to JSON                         │
│  Receives pre-computed pricing via DTO                               │
│  NO service calls                                                    │
│  NO SQL queries                                                      │
│  NO business logic                                                   │
└─────────────────────────────────────────────────────────────────────┘
```

### Resource Receives Pricing Data

```php
// AFTER: Resource receives pre-computed data
class ProductResource extends JsonResource
{
    public function __construct($resource, ?ProductPricingDTO $pricing = null)
    {
        parent::__construct($resource);
        $this->pricing = $pricing;
    }

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'current_price' => $this->pricing?->final_price ?? $this->price,
            'discount_active' => $this->pricing?->is_discount_active ?? false,
            'flash_sale_active' => $this->pricing?->is_flash_sale_active ?? false,
            'variants' => $this->whenLoaded('variations', fn() => ...),
        ];
    }
}
```

### Service Computes Pricing Before Serialization

```php
// AFTER: Service computes pricing and passes to Resource
class ProductService
{
    public function __construct(
        private ProductRepository $repository,
        private ProductPricingService $pricingService,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $products = $this->repository->paginateWithRelations($filters);

        // Pre-compute pricing for every product
        $products->each(fn(Product $product) =>
            $product->pricing = $this->pricingService->calculateProductPricingDTO($product)
        );

        return ProductCollection::make($products);
    }
}
```

---

## 15. MIGRATION ROADMAP

### Step 1: Create ProductPricingDTO (Foundation)

| Property | Value |
|---|---|
| **Objective** | Create immutable DTO for pricing contract |
| **Files affected** | NEW: `app/DTOs/ProductPricingDTO.php` |
| **Risk** | None (new file, not referenced yet) |
| **Rollback** | Delete file |
| **Complexity** | Low |
| **Benefit** | Establishes the type-safe contract for all pricing data |

### Step 2: Add calculateProductPricingDTO to ProductPricingService

| Property | Value |
|---|---|
| **Objective** | New method that returns DTO instead of raw array |
| **Files affected** | `packages/marvel/Services/Pricing/ProductPricingService.php` (+ new method) |
| **Risk** | Low (old methods still work, new method is additive) |
| **Rollback** | Remove method |
| **Complexity** | Low |
| **Benefit** | Gradual migration path — old code uses array methods, new code uses DTO |

### Step 3: Remove pricing from Model $appends

| Property | Value |
|---|---|
| **Objective** | Remove `current_price`, `discount_active`, `flash_sale_active` from `$appends` and delete accessors |
| **Files affected** | `Product.php`, `ProductVariant.php`, `Variation.php` |
| **Risk** | **HIGH** — will break anything that accesses these properties |
| **Rollback** | Restore accessors |
| **Complexity** | Medium |
| **Benefit** | Eliminates root cause — models no longer compute pricing |

### Step 4: Update Resources to receive pricing externally

| Property | Value |
|---|---|
| **Objective** | Resources accept `ProductPricingDTO` instead of relying on accessors |
| **Files affected** | `ProductResource` (both), `ProductMiniResource`, `WishlistResource`, `RelatedProductResource`, `GetSingleProductResource`, `ProductVariantResource` |
| **Risk** | **HIGH** — changes constructor signatures, affects all callers |
| **Rollback** | Restore old constructor |
| **Complexity** | High |
| **Benefit** | Resources become pure serialization — SRP restored |

### Step 5: Update Service layer to compute pricing before serialization

| Property | Value |
|---|---|
| **Objective** | ProductService, OrderService, CartInventoryService compute pricing and pass DTOs to Resources |
| **Files affected** | `ProductService.php`, `OrderService.php`, `CartInventoryService.php`, `OrderCreationService.php`, all controllers |
| **Risk** | **HIGH** — changes the entire data flow pattern |
| **Rollback** | Temporarily revert to old flow |
| **Complexity** | Very High |
| **Benefit** | Pricing computed once at service level — consistent, cacheable, testable |

### Step 6: Fix all query paths to load required relations

| Property | Value |
|---|---|
| **Objective** | Every query path that returns products loads `flash_sales` (and `product` on variant queries) |
| **Files affected** | `ProductRepository.php` (2 queries), `ProductService.php` (1 query), `ProductController.php` (2 queries), `WishlistController.php` (1 query), `ShopController.php` (1 query), `FlashSaleService.php` (2 queries) |
| **Risk** | Low — additive `->with()` calls |
| **Rollback** | Remove added `->with()` |
| **Complexity** | Low |
| **Benefit** | Eliminates 10 silent pricing failure points |

### Step 7: Fix Variation::getBlockedDatesAttribute (Hidden SQL)

| Property | Value |
|---|---|
| **Objective** | Guard `blocked_dates` accessor with `relationLoaded('availabilities')` |
| **Files affected** | `Variation.php` |
| **Risk** | Medium — changes behavior when relation not loaded |
| **Rollback** | Remove guard |
| **Complexity** | Low |
| **Benefit** | Eliminates guaranteed N+1 on variation serialization |

### Step 8: Remove dead code

| Property | Value |
|---|---|
| **Objective** | Remove all dead pricing methods, wrappers, and unused imports |
| **Files affected** | `Product.php`, `ProductRepository.php`, `ProductPricingService.php`, `ProductImportService.php`, `CalculatePaymentTrait.php`, `FlashSaleRepository.php` |
| **Risk** | Low (all are dead code — no callers) |
| **Rollback** | Restore deleted code |
| **Complexity** | Low |
| **Benefit** | Cleaner codebase, removes maintenance burden |

### Step 9: Replace app() with constructor injection

| Property | Value |
|---|---|
| **Objective** | All service callers use DI instead of service locator |
| **Files affected** | `CartInventoryService.php`, `OrderCreationService.php`, `CouponService.php`, `GiftPromotionStrategy.php`, `ProductRepository.php` |
| **Risk** | Medium — requires updating all instantiation sites |
| **Rollback** | Revert to app() calls |
| **Complexity** | Medium |
| **Benefit** | Testability, explicit dependencies, DIP compliance |

### Step 10: Fix remaining hidden SQL in Resources

| Property | Value |
|---|---|
| **Objective** | Eliminate all SQL during serialization |
| **Files affected** | `ProductMiniResource.php`, `CartResource.php`, `CartItemResource.php`, `CouponResource.php`, `GetSingleProductResource.php` |
| **Risk** | Medium — may change behavior if relations not pre-loaded |
| **Rollback** | Restore fallback queries |
| **Complexity** | Medium |
| **Benefit** | Eliminates N+1 risks, predictable performance |

### Migration Priority Summary

| Priority | Step | Benefit | Risk | Effort |
|---|---|---|---|---|
| **P0** | 7 — Fix Variation blocked_dates | Eliminates CRITICAL N+1 | Low | 1h |
| **P0** | 6 — Fix query paths | Fixes 10 silent pricing bugs | Low | 2h |
| **P1** | 1 — Create ProductPricingDTO | Foundation for all other steps | None | 1h |
| **P1** | 2 — Add DTO method to PPS | Enables gradual migration | Low | 1h |
| **P2** | 3 — Remove $appends accessors | Eliminates root cause | HIGH | 4h |
| **P2** | 4 — Update Resources | SRP restoration | HIGH | 4h |
| **P2** | 5 — Update Service layer | Pricing centralized | HIGH | 8h |
| **P3** | 8 — Remove dead code | Cleanup | Low | 1h |
| **P3** | 9 — Replace app() with DI | DIP compliance | Medium | 2h |
| **P3** | 10 — Fix hidden SQL | Performance | Medium | 3h |

**Total estimated effort:** ~27 hours for complete migration

---

## EXECUTIVE SUMMARY

### What's Wrong

The **single architectural mistake** — putting pricing computation inside Eloquent `$appends` accessors on Models — has cascaded into 14+ layers all owning pricing, 17 files calling ProductPricingService via service locator, 10 query paths returning wrong flash sale prices, 6+ hidden SQL triggers during serialization, and 6 dead code sites.

### What To Do

**Stop patching. Rewrite the architecture.**

The correct architecture:
1. Models = data only (no pricing logic, no accessors, no service calls)
2. ProductPricingService = sole pricing authority (returns immutable DTOs)
3. Service layer = orchestrates: query → compute pricing → pass DTOs to Resources
4. Resources = pure serialization (no business logic, no service calls)

### What Not To Do

- Do NOT add more `->with('flash_sales')` calls (treats symptom, not cause)
- Do NOT add more guards to accessors (treats symptom)
- Do NOT add more direct service calls in Resources (makes the problem worse)
- Do NOT try to "fix" ProductPricingService (it's the only correctly designed piece)

### 10-Second Answer

**Delete all pricing from `$appends` → Move pricing computation to Service layer (before serialization) → Pass ProductPricingDTO to Resources → Resources serialize only.**
