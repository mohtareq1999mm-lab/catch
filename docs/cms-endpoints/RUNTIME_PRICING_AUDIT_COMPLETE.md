# COMPLETE RUNTIME PRICING ARCHITECTURE AUDIT

---

## TABLE OF CONTENTS

1. Complete Dependency Graph
2. Runtime Execution Graph
3. Ownership Graph
4. Responsibility Matrix
5. Architectural Violations
6. Hidden Contracts
7. Hidden Dependencies
8. Circular Dependencies
9. Serialization Flow
10. Query Flow
11. Hydration Flow
12. Pricing Lifecycle
13. Root Cause Analysis
14. Ideal Architecture
15. Migration Roadmap
16. Final Verdict

---

## 1. COMPLETE DEPENDENCY GRAPH

```
                        ┌─────────────────────────────────────┐
                        │        ProductPricingService        │
                        │  (packages/marvel/src/Services/     │
                        │   Pricing/ProductPricingService.php)│
                        │                                     │
                        │  PURE CALCULATION ENGINE            │
                        │  (in production paths)              │
                        └─────────────────────────────────────┘
                                      ▲
                                      │
                    ┌─────────────────┼──────────────────┐
                    │                 │                   │
              ┌─────┴─────┐    ┌──────┴──────┐    ┌──────┴──────┐
              │  Product   │    │ProductVariant│    │  Variation  │
              │  Model     │    │  Model       │    │  Model      │
              │ (accessors)│    │ (accessors)  │    │ (accessors) │
              └────────────┘    └──────────────┘    └─────────────┘
                    │                 │                   │
                    │         ┌───────┼───────────────────┘
                    │         │       │
              ┌─────┴─────┐   │  ┌────┴────┐
              │ FlashSale │   │  │ Coupon   │
              │ Model     │   │  │ Model    │
              └───────────┘   │  └─────────┘
                              │
              Called by 4 LAYERS:
              ┌────────────────────────────────────────────────┐
              │                                                │
     ┌────────┴────────┐  ┌──────────────┐  ┌───────────────┐  │
     │  MODEL LAYER    │  │RESOURCE LAYER│  │REPOSITORY LAY │  │
     │  (4 models)     │  │ (3 resources)│  │ (5 methods)   │  │
     └─────────────────┘  └──────────────┘  └───────────────┘  │
                                                │               │
     ┌────────────────┐   ┌────────────────┐    │               │
     │SERVICE LAYER   │   │CONTROLLER LAYER│    │               │
     │(5 services)    │   │(0 controllers) │    │               │
     └────────────────┘   └────────────────┘    │               │
                              │                 │               │
     ┌────────────────┐      │                 │               │
     │  TRAITS        │──────┘                 │               │
     │  (1 trait)     │                        │               │
     └────────────────┘                        │               │
                                                │               │
     ┌────────────────┐                        │               │
     │  SEEDERS       │────────────────────────┘               │
     │  (2 seeders)   │                                        │
     └────────────────┘                                        │
                                                               │
     ┌────────────────┐                                        │
     │  TESTS         │────────────────────────────────────────┘
     │  (1 test file) │
     └────────────────┘
```

### Dependency Count

| Caller Type | Count | Examples |
|------------|-------|---------|
| Models (via accessors) | 4 | Product, ProductVariant, Variation, FlashSale |
| Resources (via direct call) | 3 | App\ProductResource, Marvel\ProductResource, WishlistResource |
| Repository (via direct call) | 1 | ProductRepository (5 methods) |
| Services (via direct call) | 5 | CartInventoryService, CouponService, OrderCreationService, GiftPromotionStrategy, ProductImportService |
| Traits | 1 | CalculatePaymentTrait (uses accessor, not direct) |
| Seeders | 2 | ProductSeeder, ProductVariantSeeder |
| Tests | 1 | ProductPricingServiceTest |

---

## 2. RUNTIME EXECUTION GRAPH

### 2.1 How `current_price` Reaches JSON (5 Different Paths)

```
PATH A: ProductMiniResource → Product Accessor (app index, product lists)
┌─────────────────────────────────────────────────────────────────────┐
│ Controller → ProductService → Query (with flash_sales)              │
│   → ProductMiniResource::toArray()                                  │
│     → $this->current_price                                          │
│       → Product::getCurrentPriceAttribute()                         │
│         → ProductPricingService::calculateProductCurrentPrice($this)│
│           → calculateProductPricing($product)                       │
│             → resolveActiveFlashSale($product)                      │
│               → relationLoaded('flash_sales')? YES → correct        │
│               → relationLoaded('flash_sales')? NO → null (WRONG)    │
│             → isDiscountActive($product) → attributes only → OK     │
│             → final = flashPrice ?? discountPrice ?? basePrice      │
│           ← returns float                                           │
│     → $this->roundMoney(float)                                      │
│   ← JSON: "current_price": 90.00                                   │
└─────────────────────────────────────────────────────────────────────┘

PATH B: App\ProductResource::getVariants() → Direct Service Call (product detail)
┌─────────────────────────────────────────────────────────────────────┐
│ Controller → ProductService → Query (with variations, flash_sales)  │
│   → ProductResource::toArray()                                      │
│     → $this->whenLoaded('variations', fn() => $this->getVariants())│
│       → getVariants()                                               │
│         → foreach variant:                                          │
│           → ProductPricingService::calculateVariantCurrentPrice(    │
│               $this->resource,   ← Product (EXPLICITLY PASSED)      │
│               $variant           ← ProductVariant (no product loaded)│
│             )                                                        │
│           → calculateVariantSalePrice($product, $variant)           │
│             → calculateVariantPricingFromBase(...)                  │
│               → resolveActiveFlashSale($product)                    │
│                 → relationLoaded('flash_sales')? YES                │
│               → isDiscountActive($product)?                         │
│               → final = flashPrice ?? discountPrice ?? basePrice    │
│   ← JSON: "variants": [{ "current_price": 85.00 }]                  │
└─────────────────────────────────────────────────────────────────────┘

PATH C: Product Accessor on Simple Product (wishlist)
┌─────────────────────────────────────────────────────────────────────┐
│ WishlistResource::toArray()                                         │
│   → $this->product_type === 'simple'?                               │
│     → YES: $this->current_price  → Product accessor                 │
│     → NO:  ProductPricingService::calculateVariantCurrentPrice(     │
│               $this->resource, $this->variations->first())          │
└─────────────────────────────────────────────────────────────────────┘

PATH D: ProductVariantResource Accessor (NEVER USED IN PRODUCTION)
┌─────────────────────────────────────────────────────────────────────┐
│ ProductVariantResource::toArray()                                   │
│   → $this->current_price                                            │
│     → ProductVariant::getCurrentPriceAttribute()                    │
│       → getSalePriceAttribute()                                     │
│         → relationLoaded('product')?                                │
│           → YES: calculate → correct                                │
│           → NO:  return $this->price → WRONG (silent)               │
└─────────────────────────────────────────────────────────────────────┘

PATH E: Variation Accessor (NEVER ACCESSED BY ANY RESOURCE)
┌─────────────────────────────────────────────────────────────────────┐
│ GetSingleProductResource uses raw helper, NOT accessor               │
│ No Resource accesses Variation::current_price or sale_price         │
│ If accessed: same silent fallback as ProductVariant                 │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.2 How `discount_active` Reaches JSON

```
All Resources:
  → $this->discount_active
    → Product::getDiscountActiveAttribute()
      → ProductPricingService::isDiscountActive($this)
        → checks: $this->has_discount (column)
        → checks: $this->discount_status (column)
        → checks: $this->start_date (column)
        → checks: $this->end_date (column)
      ← bool
  ← JSON: "discount_active": true
```

**This accessor always works correctly.** It only checks columns, never relations.

### 2.3 How `flash_sale_active` Reaches JSON

```
All Resources:
  → $this->flash_sale_active
    → Product::getFlashSaleActiveAttribute()
      → ProductPricingService::resolveActiveFlashSale($this) !== null
        → relationLoaded('flash_sales')?
          → YES: filter in-memory → return FlashSale or null
          → NO:  return null
      ← bool
  ← JSON: "flash_sale_active": true/false
```

**This accessor is WRONG when `flash_sales` is not loaded.** It returns `false` even if a flash sale exists.

---

## 3. OWNERSHIP GRAPH

### Current Ownership (Who Actually Owns What)

```
CONCEPT               OWNER                                   LAYER VIOLATION
───────────────────── ─────────────────────────────────────── ─────────────────
Current Price         3 owners: Model accessor, Resource,     TRIPLE OWNERSHIP
                      Repository

Discount Active       ProductPricingService::isDiscountActive  Model accessor
                      called from Product accessor             (acceptable)

Flash Sale Active     ProductPricingService::resolveActiveFS() Model accessor
                      called from Product accessor             (acceptable)

Flash Sale Price      ProductPricingService::calculateFlash    Single owner
                      SalePrice()                              (correct)

Discount Price        ProductPricingService::calculateDiscountedPrice() (correct)

Variant Price         3 owners: Model accessor, Resource,     TRIPLE OWNERSHIP
                      Repository

Price Composition     ProductPricingService::calculateProduct  Single owner
                      Pricing()                                (correct)

Serialization         Resources (format data)                  SRP OK, but
                                                                Resources also
                                                                COMPUTE prices
                                                                SRP VIOLATION

Query Preparation     Controllers + Services + Repository      SPREAD ACROSS
                                                               3 layers

Hydration             Eloquent ORM                             Correct

Business Rules        ProductPricingService + scattered         SCATTERED
                      across accessors, Resources, Repository

Persistence           Repository + Model                        Correct
```

### Ideal Ownership (Who Should Own What)

```
CONCEPT               OWNER
───────────────────── ─────────────────────────────────────
Current Price          ProductPricingService ONLY
                      (called from Service layer, never from
                       Model accessor or Resource)

Discount Active        ProductPricingService::isDiscountActive()
                      (called from Service layer)

Flash Sale Active      ProductPricingService::resolveActiveFlashSale()
                      (called from Service layer)

Flash Sale Price       ProductPricingService

Discount Price         ProductPricingService

Variant Price          ProductPricingService
                      (receives both Product and Variant as params)

Price Composition      ProductPricingService

Serialization          Resources ONLY (format, don't compute)

Query Preparation      Service Layer ONLY
                      (Repository fetches what Service specifies)

Business Rules         ProductPricingService ONLY

Persistence            Repository ONLY

Hydration              Eloquent ORM + Repository
```

---

## 4. RESPONSIBILITY MATRIX

### Current vs Ideal

| Concern | Current Owner(s) | Current Layer Count | Ideal Owner | Gap |
|---------|-----------------|-------------------|-------------|-----|
| Product base price | Product model (column) | 1 | Product model (column) | ✅ |
| Product discount fields | Product model (columns) | 1 | Product model (columns) | ✅ |
| Discount active check | ProductPricingService (called by Product accessor) | 2 | ProductPricingService (called by Service) | 🔶 Minor |
| Flash sale resolution | ProductPricingService (called by Product accessor) | 2 | ProductPricingService (called by Service) | 🔶 Minor |
| Flash sale price calc | ProductPricingService | 1 | ProductPricingService | ✅ |
| Discount price calc | ProductPricingService | 1 | ProductPricingService | ✅ |
| **Product `current_price`** | **Product accessor → PricingService** | **2** | **Service → PricingService** | ❌ MAJOR |
| **Variant `current_price`** | **3 places: accessor, 3 Resources, Repository** | **4** | **Service → PricingService** | ❌ CRITICAL |
| **Variation `current_price`** | **Variation accessor (never triggered)** | **1** | **Service → PricingService** | ❌ |
| Loading flash_sales | Query Layer (14 of 18 non-admin queries) | 1 | Query Layer (all queries) | ❌ |
| Loading product on variant | Query Layer (never) | 1 | Query layer (always) or remove need | ❌ |
| Variant attribute mapping | Resources (2x duplicated) | 2 | Shared component or Service | 🔶 |
| Rental price calc | ProductRepository | 1 | Dedicated RentalPricingService | ❌ |
| Coupon price calc | ProductPricingService → CouponCalculator | 2 | CouponService → CouponCalculator | ✅ |
| Order item pricing | OrderCreationService | 1 | OrderCreationService | ✅ |
| Cart item pricing | CartInventoryService | 1 | CartInventoryService | ✅ |
| **Serialization** | **Resources + price computation** | **2** | **Resources ONLY** | ❌ SRP |
| **Business rules** | **Accessors, Resources, Repository, Service** | **4** | **ProductPricingService** | ❌ SCATTERED |

### Critical Violations

```
CRITICAL: 4 layers compute variant current_price
  1. Model accessor (ProductVariant::getSalePriceAttribute)
  2. App\ProductResource::getVariants()
  3. Marvel\ProductResource::getVariants()
  4. WishlistResource::toArray()
  5. ProductRepository::calculateVariationPrice()

CRITICAL: Resources compute prices (SRP violation)
  - App\ProductResource::getVariants() calls ProductPricingService
  - Marvel\ProductResource::getVariants() calls ProductPricingService
  - WishlistResource::toArray() calls ProductPricingService

CRITICAL: Repository computes prices (SRP violation)
  - ProductRepository::calculateProductPrice()
  - ProductRepository::calculateVariationPrice()
  - ProductRepository::calculateDiscountedPrice()
  - ProductRepository::calculateFlashSalePrice()
  - ProductRepository::resolveFlashSale()
  - ProductRepository::calculatePrice() (rental)

CRITICAL: Model accessors depend on external service AND external data
  - Product::getCurrentPriceAttribute() requires ProductPricingService + flash_sales relation
  - ProductVariant::getSalePriceAttribute() requires ProductPricingService + product relation
  - Variation::getSalePriceAttribute() requires ProductPricingService + product relation
```

---

## 5. ARCHITECTURAL VIOLATIONS

### 5.1 Single Responsibility Principle (SRP)

| # | File | Line | Violation | Severity |
|---|------|------|-----------|----------|
| 1 | `App\Http\Resources\Product\ProductResource.php` | 73-80 | Resource computes variant prices instead of serializing | HIGH |
| 2 | `Marvel\Http\Resources\product\ProductResource.php` | 85-105 | Resource computes variant prices instead of serializing | HIGH |
| 3 | `Marvel\Http\Resources\WishlistResource.php` | 16-20 | Resource has business logic (simple vs variable routing) | HIGH |
| 4 | `ProductRepository.php` | 403-511 | Repository has 6 pricing methods (should only fetch) | HIGH |
| 5 | `ProductRepository.php` | 361-401 | Repository calculates rental prices | HIGH |
| 6 | `ProductRepository.php` | 484-499 | Repository resolves flash sale business rules | HIGH |
| 7 | `ProductModel.php` | 112-120 | Model has discount/flash business logic in accessors | MEDIUM |
| 8 | `ProductModel.php` | 126-131 | Model has pricing logic in accessor | MEDIUM |

**Root Cause of All SRP Violations:**

The decision to put `current_price` computation in a model accessor created a cascade of compensatory SRP violations:
1. Accessor needed relations → not always loaded
2. Resources compensated by calling service directly → SRP violation
3. Repository "helped" by adding pricing methods → SRP violation
4. Repository flash sale resolution duplicates ProductPricingService → DRY violation

### 5.2 Open/Closed Principle (OCP)

| # | File | Line | Violation | Severity |
|---|------|------|-----------|----------|
| 1 | `ProductVariant.php` | 61-69 | Silent fallback `relationLoaded()` hardcodes behavior. Cannot extend without modifying model. | MEDIUM |
| 2 | `Variation.php` | 93-101 | Same pattern | MEDIUM |
| 3 | `ProductPricingService.php` | 165-175 | `resolveActiveFlashSale` hardcodes 2 resolution paths. Adding a 3rd requires modifying method. | LOW |

### 5.3 Dependency Inversion Principle (DIP)

| # | File | Line | Violation | Severity |
|---|------|------|-----------|----------|
| 1 | `Product.php` | 115, 120, 130, 140 | Model calls `app(ProductPricingService::class)` directly | HIGH |
| 2 | `ProductVariant.php` | 69 | Same | HIGH |
| 3 | `Variation.php` | 99 | Same | HIGH |
| 4 | `FlashSale.php` | 85 | Same | MEDIUM |
| 5 | Every `app(ProductPricingService::class)` call | Various | Service Locator pattern | MEDIUM |

**Impact:** These models are tightly coupled to the concrete `ProductPricingService` class and the Laravel service container. They cannot be instantiated or tested without booting the container.

### 5.4 DRY Violations

| # | Code Duplicated | Locations | Count |
|---|----------------|-----------|-------|
| 1 | Variant `current_price` mapping (id, price, current_price, attributes) | App\ProductResource, Marvel\ProductResource, WishlistResource | 3 |
| 2 | `resolveActiveFlashSale` logic | ProductPricingService + ProductRepository::resolveFlashSale | 2 |
| 3 | `isDiscountActive` logic | ProductPricingService + isDiscountActiveFromData | 2 (private) |
| 4 | Variant pricing computation | Model accessor + 3 Resources + Repository | 5 |
| 5 | Coupon-by-code query | ProductPricingService::calculateCouponPriceByCode + CouponService::calcPriceByCode | 2 |

### 5.5 Law of Demeter Violations

| # | File | Line | Violation |
|---|------|------|-----------|
| 1 | `WishlistResource.php:19` | `$this->resource->variations->first()` | Accessing grandchild |
| 2 | `CartInventoryService.php:57` | `$variant->attributeProducts->map(fn($ap) => $ap->attributeValue?->attribute?->name)` | Chained access through 4 objects |
| 3 | Various Resources accessing `$this->variations->map(fn($v) => $v->attributeProducts...)` | Same pattern |

### 5.6 Tell Don't Ask Violations

| # | File | Line | Violation |
|---|------|------|-----------|
| 1 | `ProductVariant.php:63` | Accessor asks "is product loaded?" instead of being told | The accessor checks state instead of receiving data |
| 2 | `WishlistResource.php:17` | Resource asks "is product simple?" instead of telling price calculation "give me the right price" | Business logic leak |

### 5.7 Domain-Driven Design Boundary Violations

| # | Violation | Description |
|---|-----------|-------------|
| 1 | **Anemic Domain Model** | Product model has no domain methods. All pricing behavior is in accessors, service, repository, resources. |
| 2 | **Infrastructure Leakage** | Model calls `app()` (service locator), which is infrastructure concern. |
| 3 | **Serialization Side Effects** | Accessing `$product->current_price` triggers service calls. Serialization should be side-effect-free. |
| 4 | **Persistence mixed with Calculation** | ProductRepository does both data access and price calculation. |

### 5.8 Hidden Circular Dependency

```
ProductPricingService
  ↑ depends on (via method params)
Product model
  ↑ depends on (via accessor calls)
ProductPricingService
```

This is not a code-level circular dependency (no import cycles), but a **behavioral circular dependency**: the service expects Product data, and Product expects the service to compute. In a clean architecture, both should depend on abstractions (interfaces/DTOs).

---

## 6. HIDDEN CONTRACTS

### Contract 1: `Product::current_price` requires `product.flash_sales` relation

| Aspect | Detail |
|--------|--------|
| **Where defined** | `ProductPricingService::resolveActiveFlashSale()` line 171 |
| **Enforcement** | None. Silent fallback: `return null` |
| **Violated by** | `getBestSellingProducts()`, `popularProducts()`, `fetchRelated()` (Marvel), `getBrandsProductsByQtySet()` |
| **Should exist?** | **NO** — An accessor that requires external data should guarantee that data or not be an accessor |

### Contract 2: `ProductVariant::sale_price` requires `variant.product` relation

| Aspect | Detail |
|--------|--------|
| **Where defined** | `ProductVariant::getSalePriceAttribute()` line 63 |
| **Enforcement** | None. Silent fallback: `return $this->price` |
| **Violated by** | Every query path (relationship `variations()` never loads `product`) |
| **Should exist?** | **NO** — The variant's `product` should either be always loaded, or the accessor should not exist |

### Contract 3: Resources must call ProductPricingService because accessors fail

| Aspect | Detail |
|--------|--------|
| **Where defined** | `App\ProductResource::getVariants()`, `Marvel\ProductResource::getVariants()`, `WishlistResource::toArray()` |
| **Why it exists** | Compensating for Contract 2 being violated |
| **Should exist?** | **NO** — Resources should serialize, not compute |

### Contract 4: `discount_active` accessor is safe (only columns)

| Aspect | Detail |
|--------|--------|
| **Where defined** | `Product::getDiscountActiveAttribute()` → `isDiscountActive()` |
| **Why it works** | Only checks scalar columns (`has_discount`, `discount_status`, `start_date`, `end_date`) |
| **Should exist?** | **DEBATABLE** — It works without relations, but puts business logic in a model accessor. Acceptable for simple delegation, but violates DIP. |

### Contract 5: `flash_sale_active` accessor requires `flash_sales` relation

| Aspect | Detail |
|--------|--------|
| **Where defined** | `Product::getFlashSaleActiveAttribute()` → `resolveActiveFlashSale()` |
| **Enforcement** | None. Returns `false` when relation missing |
| **Should exist?** | **NO** — Same issue as Contract 1 |

---

## 7. HIDDEN DEPENDENCIES

### 7.1 Dependencies Hidden by the Accessor Pattern

```
$product->current_price       ← LOOKS LIKE: property access
                                ACTUALLY: 5 method calls deep,
                                           requires service container,
                                           requires loaded relation
```

The accessor pattern hides the following dependencies:

| Hidden Dependency | Where | Why It's Hidden |
|------------------|-------|-----------------|
| `ProductPricingService` | `Product.php:130` | `app()` call hidden behind accessor |
| `Product.flash_sales` | `ProductPricingService.php:171` | `relationLoaded()` guard hidden behind accessor |
| Service Container | `Product.php:130` | `app(ProductPricingService::class)` |
| `Carbon` (date parsing) | `ProductPricingService.php:248-256` | `isFlashSaleActive()` parses dates |
| `CouponCalculator` | `ProductPricingService.php:143` | `calculateCouponPrice` delegates |
| `DiscountType` / `FlashSaleType` enums | `ProductPricingService.php:194-225` | Enum values used in calculations |

### 7.2 Why These Dependencies Are Hidden

The accessor pattern in Eloquent (`$appends` + `getXAttribute()`) makes a method call look like a property read. This is intentional Eloquent design, but it creates the illusion that the property is cheap and independent when it's actually expensive and coupled.

### 7.3 Who Depends on Pricing Without Knowing It

| Consumer | Accesses | Doesn't Know |
|----------|----------|-------------|
| `ProductMiniResource` | `$this->current_price` | Triggers service + requires relation |
| `RelatedProductResource` | `$this->current_price` | Same |
| `GetSingleProductResource` | `$this->current_price` | Same |
| `CalculatePaymentTrait` | `$item->current_price` | Same (but item might be Product or Variation) |
| `ProductVariantResource` | `$this->current_price` | Same + requires product relation |

---

## 8. CIRCULAR DEPENDENCIES

### 8.1 Behavioral Circular Dependency

```
ProductPricingService::calculateProductCurrentPrice(Product $product)
                          │
                          │ calls
                          ▼
                Product::getCurrentPriceAttribute()
                          │
                          │ calls app()
                          ▼
                ProductPricingService::calculateProductCurrentPrice($this)
                          │
                          │ recursively? NO (not infinite, same call)
                          │ but architecturally: Model → Service → Model
                          ▼
                Chain continues...
```

**This is NOT infinite recursion**, but it IS a circular behavioral dependency:
- `ProductPricingService` needs `Product` data
- `Product` needs `ProductPricingService` to compute its price

In a hexagonal architecture:
- `ProductPricingService` → Domain Service
- `Product` → Domain Entity
- Domain Entities should NOT depend on Domain Services (that's the **Anemic Domain Model** anti-pattern)

### 8.2 Layer Circularity

```
Controller → Service → Repository → Model (accessor) → Service → Resource
                                                          │
                                                          └── calls Service again
```

The flow is: **Controller → Service → Repository → Model (accessor) → Service → Resource**

The model's accessor reaches back up to the service layer, creating a layer-skipping circularity. Resources also reach back up to the service layer. This means data flows through the service layer THREE times:
1. Service calls Repository to fetch
2. Accessor calls Service to compute (during serialization)
3. Resource calls Service to compute (compensation)

---

## 9. SERIALIZATION FLOW

### 9.1 The Serialization Side-Effect

When Laravel serializes a model to JSON (or array), it:
1. Gets all model attributes
2. Gets all loaded relations
3. Evaluates all `$appends` accessors

For pricing, step 3 triggers:

```
JSON serialization
  → evaluate $appends = ['current_price', 'discount_active', 'flash_sale_active']
    → Product::getCurrentPriceAttribute()
      → app(ProductPricingService::class)->calculateProductCurrentPrice($this)
        → normalizeMoney, resolveActiveFlashSale, calculateFlashSalePrice, isDiscountActive, calculateDiscountedPrice
          → 5-10 internal method calls
          → possible database call (dead path: $flashSaleId provided)
    → Product::getDiscountActiveAttribute()
      → app(ProductPricingService::class)->isDiscountActive($this)
        → 4 conditional checks
    → Product::getFlashSaleActiveAttribute()
      → app(ProductPricingService::class)->resolveActiveFlashSale($this)
        → relation load check → in-memory filter or null
```

**This means serialization ALWAYS triggers pricing computation, even when the frontend doesn't need it.**

### 9.2 Impact of Serialization Side-Effects

| Impact | Detail |
|--------|--------|
| Performance | Every product serialized triggers pricing computation |
| Coupling | JSON output depends on ProductPricingService correctness |
| Testability | Testing serialization requires booting service container |
| Reliability | Missing relation → silently wrong price in JSON |
| Debugging | `dd($product)` triggers pricing computation (side effect) |

---

## 10. QUERY FLOW

### 10.1 Complete Query Flow

```
REQUEST
  │
  ▼
CONTROLLER
  │  Receives Request
  │  Calls Service/Repository
  ▼
SERVICE / REPOSITORY
  │  Builds Query
  │  Applies filters (where, whereHas, scope)
  │  Adds eager loads (with)
  │  Orders, limits, paginates
  ▼
DATABASE (MySQL/Postgres)
  │  Executes SQL
  ▼
ELOQUENT HYDRATION
  │  Creates Model instances
  │  Sets attributes
  │  Sets loaded relations
  │  Sets $appends (triggers accessors)
  ▼
RESOURCE SERIALIZATION
  │  Calls toArray()
  │  Accesses $this->attribute (triggers accessors again)
  │  Maps to JSON structure
  ▼
JSON RESPONSE
```

### 10.2 Where Queries Are Built

There are **5+ different places** where Product queries are built:

| Builder | Files | Role |
|---------|-------|------|
| `ProductService` | `app/Services/General/ProductService.php` | Main service for app endpoints |
| `ProductRepository` | `packages/marvel/src/Database/Repositories/ProductRepository.php` | Main repository for Marvel endpoints |
| `Marvel\ProductController` | `packages/marvel/src/Http/Controllers/ProductController.php` | Direct queries in some methods |
| `DashboardService` | `app/Services/Dashboard/DashboardService.php` | Dashboard analytics |
| `ComponentDataService` | `packages/marvel/src/Services/ComponentDataService.php` | Component data |

**No centralized query builder.** Each layer builds its own queries with inconsistent relation loading.

---

## 11. HYDRATION FLOW

### 11.1 Model Hydration Sequence

```
SQL Result Row
  │
  ▼
new Product()
  │  → sets $attributes from DB columns
  │  → sets $relations (eager-loaded)
  │  → sets $appends = ['current_price', 'discount_active', 'flash_sale_active']
  │
  ▼
Product::boot() (if creating)
  │  → SKU generation (static::creating)
  │
  ▼
Accessor evaluation (on first access):
  │  → getCurrentPriceAttribute()
  │  → getDiscountActiveAttribute()
  │  → getFlashSaleActiveAttribute()
  │
  ▼
Fully constructed Product instance
```

### 11.2 The Hydration Problem

The `$appends` array causes accessors to be evaluated during serialization, NOT during hydration. But this means:

1. During hydration: model has all columns + relations loaded
2. During accessor evaluation: model uses loaded relations + service
3. If relations not loaded: service returns null → wrong data

The hydration phase is when the data contract must be satisfied. If `flash_sales` is loaded during hydration, pricing is correct. If not, it's wrong.

**The query phase (hydration prep) determines whether pricing is correct.** The accessor phase merely uses whatever was loaded.

---

## 12. PRICING LIFECYCLE

### 12.1 Complete Pricing Lifecycle Per Request

```
1. QUERY PHASE
   Controller calls Service/Repository
   Query is built with/without relations
   SQL executes
   Models hydrated with/without flash_sales, variations
   
2. PRICING PHASE (during serialization)
   Accessors fire
   ProductPricingService called
   Relations checked (relationLoaded)
   Prices computed or silently skipped
   
3. RESOURCE PHASE
   Resource toArray() called
   If simple product: uses accessor (trusts)
   If variable product: calls service directly (compensates)
   JSON structure built
   
4. RESPONSE PHASE
   JSON sent to client
   
   NOTE: If query phase missed flash_sales:
     → Pricing phase silently skips flash sale
     → Client gets wrong price
     → NO ERROR, NO WARNING
```

### 12.2 Where the Lifecycle Breaks

```
Query Phase
  │
  ├── getBestSellingProducts         ← flash_sales NOT loaded  ← BREAKS HERE
  ├── popularProducts                ← flash_sales NOT loaded  ← BREAKS HERE
  ├── fetchRelated (Marvel)          ← flash_sales NOT loaded  ← BREAKS HERE
  ├── getBrandsProductsByQtySet      ← flash_sales NOT loaded  ← BREAKS HERE
  │
  └── ALL other queries             ← flash_sales loaded      ← WORKS
```

---

## 13. ROOT CAUSE ANALYSIS

### 13.1 The "Five Whys"

```
PROBLEM: Wrong prices appear in some API responses.

WHY?
Because flash_sale pricing is not applied to products when
the flash_sales relation is not loaded.

WHY?
Because ProductPricingService::resolveActiveFlashSale() returns null
when $product->relationLoaded('flash_sales') is false.

WHY?
Because the accessor pattern ($appends) evaluates pricing during
serialization, and it has a defensive guard for missing relations.

WHY?
Because the original architect chose to compute pricing as
model accessors, which created a hidden dependency on loaded relations.

WHY?
Because the architect chose the convenience of $appends
(automatic serialization of computed fields) over architectural purity
(explicit computation in the service layer).

WHY IS THIS THE FIRST MISTAKE?
Because the accessor pattern in Eloquent is designed for
SIMPLE derived attributes (e.g., full_name = first_name + ' ' + last_name),
NOT for COMPLEX COMPUTATIONS that require:
  - External service calls
  - Loaded relations
  - Business rule evaluation
  - Conditional fallbacks
```

### 13.2 The First Architectural Mistake

**The decision to use Eloquent `$appends` accessors for runtime pricing computation.**

This decision violated a fundamental principle of Eloquent accessor design: **accessors should be self-contained**. An accessor should compute its value from:
- Other attributes on the same model (columns)
- Simple string/number transformations

An accessor should NOT:
- Call external services
- Require loaded relations
- Have conditional fallbacks that return different data types
- Create side effects during serialization

When the architect chose to put `current_price`, `discount_active`, and `flash_sale_active` in `$appends`, they inadvertently:
1. Made JSON serialization depend on service layer availability
2. Made correct output depend on query completeness
3. Created a hidden contract between query and serialization
4. Forced every layer to compensate (Resources, Repository)
5. Made the wrong-price bug invisible (silent fallback)

### 13.3 The Cascade of Consequences

```
FIRST MISTAKE
│
│  current_price as $appends accessor
│
├──→ Need to call ProductPricingService from Model
│     └──→ DIP violation (Model → Service)
│     └──→ Hidden dependency on service container
│
├──→ Accessor needs relations (flash_sales, product)
│     └──→ relationLoaded() guard added (defensive, not corrective)
│     └──→ Silent fallback when relation missing
│     └──→ Hidden contract: "load this or get wrong data"
│
├──→ Query Layer inconsistent
│     └──→ Some queries load relations, some don't
│     └──→ No documented contract about what to load
│
├──→ Resources compensate
│     └──→ Call ProductPricingService directly
│     └──→ SRP violation (Resources compute)
│     └──→ Duplicated code (3 Resources, same pattern)
│
├──→ Repository duplicates
│     └──→ Adds pricing methods "for convenience"
│     └──→ SRP violation (Repository computes)
│     └──→ More duplicated code
│
├──→ ProductVariant accessor has same issue
│     └──→ Silent fallback when product relation missing
│     └──→ Never triggered in production (Resources compensate)
│     └──→ Latent bug waiting to manifest
│
├──→ Variation model copies same pattern
│     └──→ Same silent fallback
│     └──→ Same latent bug
│
└──→ Dead code accumulates
      └──→ calculateCouponPriceByCode (never called)
      └──→ $flashSaleId branch (never called)
      └──→ ProductVariantResource (imported, never used)
```

### 13.4 Root Cause Statement

**The architectural root cause is the decision to compute runtime pricing inside Eloquent model accessors (`$appends`) rather than in a dedicated computation layer that runs before serialization.**

This decision violated:
- **Single Responsibility Principle** — Models should represent data, not compute business-dependent prices
- **Dependency Inversion Principle** — Models should not depend on services
- **Open/Closed Principle** — Accessor behavior cannot be extended without modifying the model
- **DRY** — Same computation duplicated in accessors, Resources, and Repository
- **Law of Demeter** — Serialization triggers 5-level-deep service calls
- **Tell Don't Ask** — Accessors check state instead of being given data

All other issues (wrong prices, Resource compensation, Repository duplication, hidden contracts, latent bugs) are **consequences** of this single architectural mistake.

---

## 14. IDEAL ARCHITECTURE

### 14.1 Architectural Decision: Pricing as a Value Object / DTO

**Decision:** Pricing should be a **Value Object** (immutable, self-contained, no identity) computed by a **Domain Service** (ProductPricingService) **before serialization**.

### 14.2 Architecture Overview

```
┌──────────────────────────────────────────────────────────────┐
│                     PRESENTATION LAYER                       │
│                                                              │
│  Resources (ProductResource, ProductMiniResource, etc.)      │
│  ─── ONLY serialize data                                    │
│  ─── NEVER compute prices                                   │
│  ─── Receive pre-computed ProductPricingDTO                 │
└──────────────────────────┬───────────────────────────────────┘
                           │
┌──────────────────────────▼───────────────────────────────────┐
│                     APPLICATION LAYER                        │
│                                                              │
│  Controllers                                                  │
│  ─── Validate request                                        │
│  ─── Call Service                                            │
│  ─── Return Resource(pre-computed data)                      │
│                                                              │
│  Services (ProductService, OrderCreationService, etc.)       │
│  ─── Orchestrate business logic                              │
│  ─── Call Repository for data                                │
│  ─── Call PricingService for computation                     │
│  ─── Combine results into DTOs                               │
└──────────────────────────┬───────────────────────────────────┘
                           │
┌──────────────────────────▼───────────────────────────────────┐
│                     DOMAIN LAYER                             │
│                                                              │
│  ProductPricingService                                        │
│  ─── PURE function: input → output                          │
│  ─── Receives data (not models)                              │
│  ─── Returns ProductPricingDTO (immutable Value Object)      │
│  ─── No DB access                                            │
│  ─── No side effects                                         │
│  ─── No dependencies on other layers                         │
│                                                              │
│  ProductPricingDTO                                           │
│  ─── finalPrice, basePrice, discountPrice, flashSalePrice    │
│  ─── discountActive, flashSaleActive                         │
│  ─── IMMUTABLE                                               │
│                                                              │
│  CouponCalculator (already pure)                              │
│                                                              │
│  Models (Product, ProductVariant, Variation)                  │
│  ─── NO pricing accessors                                    │
│  ─── NO $appends for pricing                                 │
│  ─── Only data + relationships                               │
│  ─── Domain methods for domain behavior only                 │
└──────────────────────────┬───────────────────────────────────┘
                           │
┌──────────────────────────▼───────────────────────────────────┐
│                     INFRASTRUCTURE LAYER                     │
│                                                              │
│  Repository (ProductRepository)                              │
│  ─── ONLY data access (CRUD)                                │
│  ─── NEVER pricing logic                                    │
│  ─── Returns Eloquent models or Collections                  │
│                                                              │
│  Query Layer                                                 │
│  ─── Loads exactly what Service requests                     │
│  ─── No assumptions about what pricing needs                 │
└──────────────────────────────────────────────────────────────┘
```

### 14.3 Data Flow (Ideal)

```
Service Layer
│
├── 1. Call Repository::findProducts(criteria) → Collection<Product>
│      Repository loads: categories, variations, flash_sales, brands
│      (Repository is told what to load by Service)
│
├── 2. For each Product, call:
│      PricingService::calculateProductPricing(
│          price: product.price,
│          hasDiscount: product.has_discount,
│          discountType: product.discount_type,
│          discountAmount: product.discount_amount,
│          discountStatus: product.discount_status,
│          startDate: product.start_date,
│          endDate: product.end_date,
│          flashSales: product.flash_sales → Collection<FlashSale>
│      ) → ProductPricingDTO (immutable)
│
├── 3. For each ProductVariant, call:
│      PricingService::calculateVariantPricing(
│          basePrice: variant.price,
│          parentDiscount: same as above,
│          flashSales: same as above,
│      ) → VariantPricingDTO
│
├── 4. Attach DTOs to models:
│      $product->setAttribute('pricing', $dto)
│      or: new ProductWithPricing($product, $dto)
│
└── 5. Return to Controller

Controller
│
├── 6. Pass to Resource:
│      return ProductResource::make($productWithPricing)
│
└── Resource
      ├── $this->pricing->finalPrice  (pre-computed, no accessor)
      ├── $this->pricing->discountActive
      └── $this->pricing->flashSaleActive
```

### 14.4 What Changes

| Current | Ideal |
|---------|-------|
| `Product::$appends = ['current_price', 'discount_active', 'flash_sale_active']` | NO pricing in `$appends` |
| `ProductVariant::$appends = ['current_price', 'sale_price']` | NO pricing in `$appends` |
| `Variation::$appends = ['current_price', 'sale_price']` | NO pricing in `$appends` |
| `ProductPricingService` takes `Product` model | `ProductPricingService` takes scalar values (or DTO) |
| `ProductPricingService::resolveActiveFlashSale` checks `relationLoaded()` | Flash sales are always passed explicitly |
| Resources call `app(ProductPricingService::class)` | Resources receive pre-computed data |
| ProductRepository has pricing methods | ProductRepository is pure data access |
| `app(ProductPricingService::class)` in 10+ places | Constructor injection in 1-2 services |
| `$product->current_price` triggers accessor | `$product->pricing->finalPrice` (set explicitly) |
| `$variant->current_price` triggers accessor | `$variant->pricing->finalPrice` (set explicitly) |

---

## 15. MIGRATION ROADMAP

### Step 1: Remove `current_price`, `sale_price` from `$appends` on ProductVariant and Variation

| Aspect | Detail |
|--------|--------|
| **Why** | These accessors never work correctly (product relation never loaded). They silently return wrong data. |
| **Risk** | LOW — Resources already bypass them by calling ProductPricingService directly |
| **Impact** | ProductVariantResource would stop working (but it's never used anyway) |
| **Rollback** | Restore `$appends` array |
| **Files** | `ProductVariant.php`, `Variation.php` |
| **Complexity** | 1/5 |

### Step 2: Remove `current_price`, `discount_active`, `flash_sale_active` from `$appends` on Product

| Aspect | Detail |
|--------|--------|
| **Why** | These accessors are the root cause. They create hidden contracts. |
| **Risk** | HIGH — Many Resources depend on these. Must be done AFTER Step 3. |
| **Impact** | Every Resource that accesses `$this->current_price` would crash |
| **Rollback** | Restore `$appends` |
| **Files** | `Product.php` |
| **Complexity** | 2/5 (depends on later steps) |

### Step 3: Create `ProductPricingDTO`

| Aspect | Detail |
|--------|--------|
| **Why** | Provide a typed, immutable container for pricing data |
| **Risk** | LOW — New file, no changes to existing code |
| **Impact** | None until used |
| **Files** | New file: `app/DTOs/ProductPricingDTO.php` |
| **Complexity** | 1/5 |

### Step 4: Add `pricing` relationship/method to Service that returns ProductPricingDTO

| Aspect | Detail |
|--------|--------|
| **Why** | Centralize pricing computation in Service layer |
| **Risk** | LOW — New method, doesn't change existing flow |
| **Impact** | None until Resources use it |
| **Files** | `ProductService.php`, `ProductPricingService.php` |
| **Complexity** | 2/5 |

### Step 5: Update Resources to read from `pricing` instead of accessors

| Aspect | Detail |
|--------|--------|
| **Why** | This is the main migration. Resources stop computing and start reading. |
| **Risk** | HIGH — Must coordinate with Step 2 (removing $appends) |
| **Impact** | Every product endpoint changes |
| **Rollback** | Revert Resource changes, restore $appends |
| **Files** | All 6 product-related Resources |
| **Complexity** | 3/5 |

### Step 6: Remove pricing methods from ProductRepository

| Aspect | Detail |
|--------|--------|
| **Why** | Repository should not compute prices |
| **Risk** | MEDIUM — Must ensure no callers depend on these methods |
| **Impact** | `calculateProductPrice`, `calculateVariationPrice`, `calculateDiscountedPrice`, `calculateFlashSalePrice`, `resolveFlashSale`, `calculatePrice` |
| **Files** | `ProductRepository.php` |
| **Complexity** | 2/5 |

### Step 7: Remove `app(ProductPricingService::class)` from all Resources

| Aspect | Detail |
|--------|--------|
| **Why** | Resources should not call services |
| **Risk** | LOW — After Step 5, Resources no longer need it |
| **Impact** | Cleaner Resources |
| **Files** | `App\ProductResource`, `Marvel\ProductResource`, `WishlistResource` |
| **Complexity** | 1/5 |

### Step 8: Standardize Query Loading

| Aspect | Detail |
|--------|--------|
| **Why** | Ensure consistent relation loading across all query paths |
| **Risk** | MEDIUM — May affect performance if relations are loaded unnecessarily |
| **Impact** | Fixed pricing for all endpoints |
| **Files** | `ProductService.php`, `ProductRepository.php`, `Marvel\ProductController.php`, `ComponentDataService.php`, `DashboardService.php` |
| **Complexity** | 3/5 |

### Step 9: Clean up dead code

| Aspect | Detail |
|--------|--------|
| **Why** | Remove unused code that creates confusion |
| **Risk** | LOW |
| **Impact** | `calculateCouponPriceByCode` removed, `ProductVariantResource` removed (or made functional) |
| **Files** | `ProductPricingService.php`, `ProductVariantResource.php`, `CartItemResource.php` |
| **Complexity** | 1/5 |

### Step 10: Clean up N+1 accessors

| Aspect | Detail |
|--------|--------|
| **Why** | `ratings`, `total_reviews`, `rating_count`, `my_review`, `in_wishlist` fire SQL every access |
| **Risk** | MEDIUM — Changes behavior if callers depend on these |
| **Impact** | Performance improvement |
| **Files** | `Product.php`, `ProductMiniResource.php` |
| **Complexity** | 2/5 |

### Migration Order

```
Step 1 ─── ProductVariant/Variation $appends removed
    │
Step 3 ─── ProductPricingDTO created
    │
Step 4 ─── Service returns DTO
    │
Step 5 ─── Resources use DTO
    │
Step 2 ─── Product $appends removed
    │
Step 6 ─── Repository pricing methods removed
    │
Step 7 ─── Service locator removed from Resources
    │
Step 8 ─── Query loading standardized
    │
Step 9 ─── Dead code removed
    │
Step 10 ── N+1 accessors cleaned
```

---

## 16. FINAL VERDICT

### The Runtime Pricing Architecture Is Fundamentally Wrong

**The architecture should be redesigned from scratch, not patched.**

The current design has:
- **4 layers** computing the same thing (Model, Resource, Repository, Service)
- **3 hidden contracts** that silently produce wrong data
- **6 SRP violations** (Resources compute, Repository computes, Models access services)
- **4 DIP violations** (Models depend on concrete service)
- **5 latent bugs** (silent fallbacks that are never triggered but could be)
- **4 missing relation paths** that produce wrong flash sale pricing
- **6 N+1 accessors** that fire SQL on every serialization

### The specific changes needed:

1. **REMOVE** `current_price`, `discount_active`, `flash_sale_active` from `Product::$appends`
2. **REMOVE** `current_price`, `sale_price` from `ProductVariant::$appends`
3. **REMOVE** `current_price`, `sale_price` from `Variation::$appends`
4. **MOVE** all pricing computation to `ProductPricingService` called from Service layer
5. **CREATE** immutable `ProductPricingDTO` or scalar return values
6. **STOP** Resources from calling `ProductPricingService`
7. **STOP** Repository from computing prices
8. **STANDARDIZE** query loading (but this is cosmetic without fixing the source)

### Summary in One Sentence

The accessor-based pricing architecture is the root cause; it turns a simple data lookup into a multi-layer computation with hidden contracts, silent failures, and compensatory code spread across the entire application — and the only correct fix is to remove pricing from accessors entirely and compute it explicitly in the service layer before serialization.
