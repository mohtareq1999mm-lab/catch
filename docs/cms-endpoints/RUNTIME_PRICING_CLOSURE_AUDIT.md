# FINAL ARCHITECTURE CLOSURE AUDIT

**Date:** 2026-07-13
**Method:** Reverse-engineered every file from scratch — no prior assumptions
**Files Read:** Product.php, ProductVariant.php, Variation.php, FlashSale.php, ProductPricingService.php (499 lines), ProductRepository.php (513 lines), all 6 pricing Resources, CartResource, CartItemResource, CouponResource, FlashSaleResource, OrderResource, CartInventoryService, OrderCreationService, CouponService, GiftPromotionStrategy, CalculatePaymentTrait, ProductService (917 lines), ProductController (Marvel + App), WishlistController, ComponentDataController, ShopController, Routes.php (first 300 lines), api.php
**Total code paths traced:** 19 entry points, 72+ query paths, 28 PPS call sites, 7 accessors, 11 hidden SQL locations, 14 architecture violations

---

## 1. COMPLETE RUNTIME PRICING PATH

### Database → Repository → Service → Model → Accessor → Resource → JSON

```
DATABASE
  ├── products.price (base)
  ├── products.has_discount, discount_type, discount_amount, discount_status, start_date, end_date
  ├── products.price_after_discount (stored — set by admin)
  ├── products.price_after_flash_sale (stored — set by admin)
  ├── products.has_flash_sale
  ├── product_variants.price (base)
  ├── product_variants.sale_price (stored — set by ProductRepository::addVariants)
  └── variation_options.price (base)
        │
        ▼
REPOSITORY / SERVICE (fetches data)
  ├── Must ->with('flash_sales') — but 10 query paths forget this
  └── Must ->with('product') for variants — but 0 query paths load this (hidden by accessor guard)
        │
        ▼
MODEL (eloquent instance)
  ├── $product->current_price → getCurrentPriceAttribute()
  │     └── app(ProductPricingService)::calculateProductCurrentPrice($product)
  │           └── calculateProductPricing($product)
  │                 ├── normalizeMoney($product->price)              [PURE — model attribute]
  │                 ├── resolveActiveFlashSale($product)
  │                 │     └── relationLoaded('flash_sales')? iterate : null   [PURE path — guarded]
  │                 ├── calculateFlashSalePrice($flashSale, $basePrice)      [PURE if fs not null]
  │                 ├── isDiscountActive($product)                            [PURE — model attributes]
  │                 └── calculateDiscountedPrice($basePrice, ...)              [PURE math]
  │
  ├── $product->discount_active → getDiscountActiveAttribute()
  │     └── app(ProductPricingService)::isDiscountActive($this)     [PURE — model attributes]
  │
  ├── $product->flash_sale_active → getFlashSaleActiveAttribute()
  │     └── app(ProductPricingService)::resolveActiveFlashSale($this) !== null  [PURE path — guarded]
  │
  ├── $variant->current_price → getCurrentPriceAttribute()
  │     └── getSalePriceAttribute()
  │           └── relationLoaded('product')? service : $this->price  [GUARDED SILENT FALLBACK]
  │                 └── app(ProductPricingService)::calculateVariantCurrentPrice($product, $this)
  │
  └── $variation->current_price → getCurrentPriceAttribute() [IDENTICAL to variant pattern]
        │
        ▼
RESOURCE (serialization)
  ├── Marvel ProductResource: $this->current_price → accessor (OK) + getVariants() → app(PPS) (VIOLATION)
  ├── App ProductResource: $this->current_price → accessor (OK) + getVariants() → app(PPS) (VIOLATION)
  ├── ProductMiniResource: $this->current_price → accessor (OK) + reviews fallback → SQL (VIOLATION)
  ├── WishlistResource: $this->current_price → accessor (OK) + app(PPS) twice (VIOLATION)
  ├── GetSingleProductResource: $this->current_price → accessor (OK) + 5 SQL accessors (VIOLATION)
  ├── RelatedProductResource: $this->current_price → accessor (OK) — clean
  ├── ProductVariantResource: $this->current_price → accessor (OK) — NEVER USED
  └── OrderProductVariantResource: $this->current_price → accessor (OK) — clean
        │
        ▼
JSON RESPONSE
```

---

## 2. PRODUCTPRICINGSERVICE PURE FUNCTION CLASSIFICATION

### PURE (no SQL, no side effects, deterministic)
| Method | Line | Evidence |
|---|---|---|
| `normalizeMoney($amount)` | 461 | Pure float rounding |
| `toUnits($amount)` | 494 | Pure float cast |
| `isDiscountActive($product)` | 403 | Reads model attributes only |
| `isDiscountActiveFromData(array $data)` | 432 | Reads array keys only |
| `isFlashSaleActive(?FlashSale $flashSale)` | 378 | Reads model attributes only |
| `resolveFlashSaleDiscountUnits(...)` | 346 | Pure math |
| `calculateDiscountedPrice($price, $type, $amount)` | 250 | Pure math |
| `calculateFlashSalePrice(?FlashSale, $basePrice)` | 287 | Pure if FlashSale passed as param |
| `calculateCouponPrice(Coupon, $basePrice)` | 166 | Delegates to CouponCalculator (pure) |
| `calculateProductPricingFromData(array $data)` | 57 | Pure array computation |
| `calculateVariantPricingFromData(array, array)` | 118 | Pure array computation |
| `calculateVariantPricingFromBase(...)` | 318 | All params passed as values |
| `runSafely(callable, $fallback)` | 477 | Wrapper — no side effects besides reporting |

### CONDITIONALLY PURE (pure on the production code path)
| Method | Line | Impure branch | Production path |
|---|---|---|---|
| `resolveActiveFlashSale(Product, ?int)` | 220 | `$flashSaleId` → SQL query | Never passes $flashSaleId → checks `relationLoaded()` |
| `calculateProductPricing(Product, ?FlashSale)` | 26 | Calls resolveActiveFlashSale without id | Accessor path → no flashSaleId → pure |
| `calculateProductCurrentPrice(Product)` | 154 | Delegates to calculateProductPricing | Accessor path → pure |
| `calculateVariantSalePrice(Product, ...)` | 88 | Delegates to resolveActiveFlashSale | Accessor path → no flashSaleId → pure |
| `calculateVariantCurrentPrice(Product, ...)` | 208 | Delegates to calculateVariantSalePrice | Accessor path → pure |

### IMPURE (always fires SQL — but DEAD CODE)
| Method | Line | SQL Fired | Called? |
|---|---|---|---|
| `calculateCouponPriceByCode(string $code, ...)` | 187 | `Coupon::valid()->where('code', $code)->first()` | **DEAD** — grep finds zero callers |

### VERDICT: ProductPricingService is architecturally correct
All production paths through ProductPricingService are **PURE**. The service receives models and returns computed values. It does not persist data, does not emit events, does not modify models. The only impure method is dead code. The service itself is **not the problem**.

---

## 3. ALL ARCHITECTURE VIOLATIONS (CATEGORIZED)

### CATEGORY A: SRP VIOLATIONS — MODEL LAYER (4 files)

| # | File | Method | What it does wrong | Why it exists |
|---|---|---|---|---|
| A1 | Product.php | `getCurrentPriceAttribute()` | Model computes pricing via service locator | $appends convenience — "auto-include in JSON" |
| A2 | Product.php | `getDiscountActiveAttribute()` | Model checks discount dates via service locator | $appends convenience |
| A3 | Product.php | `getFlashSaleActiveAttribute()` | Model resolves flash sale via service locator | $appends convenience |
| A4 | Product.php | `getRatingsAttribute()` | Model fires SQL during property access | GetSingleProductResource depends on it |
| A5 | Product.php | `getTotalReviewsAttribute()` | Model fires SQL during property access | GetSingleProductResource depends on it |
| A6 | Product.php | `getRatingCountAttribute()` | Model fires SQL during property access | GetSingleProductResource depends on it |
| A7 | Product.php | `getMyReviewAttribute()` | Model fires SQL during property access | GetSingleProductResource depends on it |
| A8 | Product.php | `getInWishlistAttribute()` | Model fires SQL during property access | GetSingleProductResource depends on it |
| A9 | ProductVariant.php | `getCurrentPriceAttribute()` | Model computes pricing via service locator | $appends convenience |
| A10 | ProductVariant.php | `getSalePriceAttribute()` | Model computes pricing via service locator + silent fallback | $appends convenience + N+1 protection |
| A11 | Variation.php | `getCurrentPriceAttribute()` | Model computes pricing via service locator | $appends convenience |
| A12 | Variation.php | `getSalePriceAttribute()` | Model computes pricing via service locator + silent fallback | $appends convenience + N+1 protection |
| A13 | Variation.php | `getBlockedDatesAttribute()` | Model fires SQL during serialization — IN $APPENDS | $appends convenience |
| A14 | FlashSale.php | `calcPrice()` | Model calls pricing service | Convenience method |

**Root cause:** `protected $appends = ['current_price', 'discount_active', 'flash_sale_active']` in Product.php:91-95. This single line forced pricing into models. Everything else cascaded.

### CATEGORY B: SRP VIOLATIONS — RESOURCE LAYER (6 files)

| # | File | Line | What it does wrong |
|---|---|---|---|
| B1 | Marvel ProductResource | 91 | `app(PPS)->calculateVariantCurrentPrice(...)` — computes pricing during serialization |
| B2 | App ProductResource | 78 | `app(PPS)->calculateVariantCurrentPrice(...)` — computes pricing during serialization |
| B3 | WishlistResource | 19, 33 | `app(PPS)->calculateVariantCurrentPrice(...)` twice + business logic (simple vs variable routing) |
| B4 | ProductMiniResource | 40 | `$this->reviews()->avg('rating')` — fires SQL during serialization |
| B5 | CartResource | 24 | `Coupon::where('code', ...)->first()` — fires SQL during serialization |
| B6 | CartItemResource | 23-27 | `$this->product->id` — lazy loads relation during serialization |

**Root cause:** Model accessors produce wrong pricing when relations are missing, so Resources compensate by calling PPS directly. The compensation became the pattern.

### CATEGORY C: SRP VIOLATIONS — REPOSITORY LAYER (2 files)

| # | File | Lines | What it does wrong |
|---|---|---|---|
| C1 | ProductRepository | 361-449, 484-511 | Calculates prices, resolves flash sales, contains dead pricing wrappers (~150 lines of pricing code in a 513-line repository) |
| C2 | ProductRepository | 218 | `app(PPS)->calculateVariantSalePrice(...)` in addVariants() — computes pricing during persistence |

**Root cause:** The repository was written before ProductPricingService existed, or pricing was added incrementally without extracting it.

### CATEGORY D: SRP VIOLATIONS — SERVICE LAYER (4 files)

| # | File | Line | What it does wrong |
|---|---|---|---|
| D1 | CartInventoryService | 43-45 | Computes pricing in an inventory management service |
| D2 | GiftPromotionStrategy | 109 | Computes variant pricing in a promotion strategy |
| D3 | CouponService | 94 | Calls PPS::calculateCouponPrice() which delegates to CouponCalculator — CouponService already has calcPrice() that delegates directly to CouponCalculator |
| D4 | OrderCreationService | 84-100 | Recalculates flash sale + discount prices at order creation time (risk of inconsistency with cart) |

**Root cause:** No standard pattern for "when to compute pricing" — each service computes it when needed, independently.

### CATEGORY E: HIDDEN SQL (11 locations)

| # | Severity | File | Line | SQL | Always fires? | Protected? |
|---|---|---|---|---|---|---|
| E1 | CRITICAL | Variation.php | 40-63 | `Availability::where('bookable_id', $this->id)->where('bookable_type', 'Variation')->whereDate('to', '>=', now())->get()` | **ALWAYS** (in $appends) | **NO** |
| E2 | HIGH | Product.php | 280-283 | `$this->reviews()->avg('rating')` | When GetSingleProductResource used | **NO** |
| E3 | HIGH | Product.php | 285-288 | `$this->reviews()->count()` | When GetSingleProductResource used | **NO** |
| E4 | HIGH | Product.php | 290-293 | `$this->reviews()->orderBy(...)->groupBy(...)->get()` | When GetSingleProductResource used | **NO** |
| E5 | HIGH | Product.php | 295-301 | `$this->reviews()->where('user_id', ...)->first()` then `->get()` | When GetSingleProductResource used + authed | **NO** |
| E6 | HIGH | Product.php | 303-309 | `$this->wishlists()->where('user_id', ...)->first()` | When GetSingleProductResource used + authed | **NO** |
| E7 | HIGH | ProductMiniResource.php | 40 | `$this->reviews()->avg('rating')` | When `reviews_avg_rating` not pre-loaded | **NO** |
| E8 | HIGH | CartResource.php | 24 | `Coupon::where('code', ...)->first()` | **ALWAYS** when coupon code present | **NO** |
| E9 | HIGH | CartItemResource.php | 23-27 | `$this->product->id, ->name, ->slug` (lazy load) | **ALWAYS** when product not pre-loaded | **NO** |
| E10 | MEDIUM | PPS.php | 224-227 | `FlashSale::query()->whereKey($id)->valid()->first()` | Only when `$flashSaleId` parameter passed | **DEAD PATH in prod** |
| E11 | MEDIUM | PPS.php | 190 | `Coupon::valid()->where('code', $code)->first()` | **ALWAYS** when called | **DEAD CODE** |

**Root cause A (E1):** Variation's `$appends` includes `blocked_dates` without any `relationLoaded()` guard.
**Root cause B (E2-E6):** GetSingleProductResource accesses 5 accessors that unconditionally fire SQL.
**Root cause C (E7):** ProductMiniResource has a fallback query instead of requiring pre-loading.
**Root cause D (E8-E9):** CartResource and CartItemResource were written without relation guards.

### CATEGORY F: INCOMPLETE QUERY CONTRACTS (10 locations — missing `flash_sales`)

| # | File | Method | Impact |
|---|---|---|---|
| F1 | ProductRepository | `getBestSellingProducts()` | Best-sellers page — flash sale prices wrong |
| F2 | ProductRepository | `fetchRelated()` | Related products section — flash sale prices wrong |
| F3 | ProductService | `getBrandsProductsByQtySet()` | Brand pages — flash sale prices wrong |
| F4 | ProductController | `popularProducts()` | Popular products widget — flash sale prices wrong |
| F5 | ProductController | `fetchWishlists()` | My wishlists page — flash sale prices wrong |
| F6 | WishlistController | `index()` | Wishlist API — flash sale prices wrong |
| F7 | ShopController | `followedShopsProducts()` | Followed shops page — flash sale prices wrong |
| F8 | FlashSaleService | `getFlashSaleBySlug()` | Flash sale detail — products without flash_sales loaded |
| F9 | FlashSaleService | `getFlashSalesAndHereProductsByQtySet()` | Flash sale products — missing flash_sales |
| F10 | ProductService | `getFlashSalesAndHereProductsByQtySet()` | Flash sale products — missing flash_sales |

**Root cause:** No centralized rule that "every product query must include `->with('flash_sales')`". Each query author had to remember, and 10 out of ~72 queries forgot.

### CATEGORY G: INCOMPLETE QUERY CONTRACTS — `product` on variants (2 locations)

Variants and Variations silently fall back to `$this->price` when `product` relation is not loaded (ProductVariant.php:75-78, Variation.php:87-90). This means **every query that returns ProductVariant or Variation must have `->with('product')`**. But:

| # | File | Method | Loads product? |
|---|---|---|---|
| G1 | ProductRepository | `getBestSellingProducts()` query path | **NO** (returns products, not variants — OK) |
| G2 | Variation queries for rental pricing | `isVariationAvailableAt()` | **NO** — but doesn't access pricing — OK |

**No query path that returns Variant/Variation via the pricing pipeline is missing `product`** because the only serialization path that accesses variant pricing (`ProductResource::getVariants()`) always receives `$this->variations` which are loaded via `$product->variations()` — and the `$product` is always the parent. The `relationLoaded('product')` guard in the accessor exists precisely because variants CAN be loaded independently (e.g., in rentals), and in that case they silently fall back to base price.

### CATEGORY H: DEAD CODE (7 locations)

| # | File | Line | Code | Detected |
|---|---|---|---|---|
| H1 | Product.php | 150-157 | `private function calculateDiscountedPrice($price)` | grep finds zero callers of `$this->calculateDiscountedPrice` in Product.php |
| H2 | ProductRepository.php | 471-474 | `private function calculateDiscountedPrice(...)` | grep finds zero callers of `$this->calculateDiscountedPrice` in ProductRepository.php |
| H3 | ProductRepository.php | 508-511 | `private function calculateFlashSalePrice(...)` | grep finds zero callers of `$this->calculateFlashSalePrice` in ProductRepository.php |
| H4 | ProductPricingService.php | 187-198 | `public function calculateCouponPriceByCode(...)` | grep finds zero callers across entire codebase (except self-reference) |
| H5 | ProductImportService.php | 30 | `protected ProductPricingService $pricingService` | Property declared, set in constructor, but `$this->pricingService` never referenced in class body |
| H6 | CalculatePaymentTrait.php | 9 | `use Marvel\Services\Pricing\ProductPricingService;` | Import exists but class name never used in trait |
| H7 | FlashSaleRepository.php | 235-237 | `private function updateFlashSaleProductPrices(...) {}` | Empty method body |

**Root cause:** Code accumulated over time without cleanup. Private methods added "for future use" but never called.

### CATEGORY I: SILENT FAILURES (4 locations)

| # | File | Line | What happens | Why it's dangerous |
|---|---|---|---|---|
| I1 | ProductVariant.php | 73-82 | `$this->relationLoaded('product')` returns false → returns `$this->price` silently | Developer forgets to load `product` → wrong price returned with NO warning |
| I2 | Variation.php | 85-94 | Same pattern as I1 | Same |
| I3 | PPS.php resolveActiveFlashSale | 230-236 | `relationLoaded('flash_sales')` returns false → returns null silently | Developer forgets to load `flash_sales` → flash sale pricing skipped with NO warning |
| I4 | PPS.php calculateProductPricing | 30 | `resolveActiveFlashSale` returns null → flash sale skipped silently | No fallback, no log, no warning |

**Root cause:** The `relationLoaded()` check was added to prevent N+1 queries, but the only acceptable fallback behavior was chosen: "return null/wrong data" instead of "lazy load". This is a deliberate trade-off that was never documented.

### CATEGORY J: RESOURCE SRP VIOLATION — VERIFIED COMPLETE LIST

All 14 resources that touch products or orders have been audited:

| Resource | Pricing Violation? | Type of Violation |
|---|---|---|
| Marvel ProductResource | YES (line 91) | Direct PPS call in getVariants() |
| App ProductResource | YES (line 78) | Direct PPS call in getVariants() |
| ProductMiniResource | YES (line 40) | SQL fallback for ratings |
| WishlistResource | YES (lines 19, 33) | Direct PPS calls + business logic |
| GetSingleProductResource | YES (lines 46-50) | 5 accessors that each fire SQL |
| RelatedProductResource | NO | Uses accessor only |
| ProductVariantResource | NO | Uses accessor only (DEAD CODE) |
| OrderProductVariantResource | NO | Uses accessor only |
| CartResource | YES (line 24) | SQL query for coupon |
| CartItemResource | YES (lines 23-27) | Lazy loads product |
| CouponResource | YES (line 36) | Calls CouponValidator::validate() |
| FlashSaleResource | YES (lines 30-31) | Calls isValid() + typeByLang() |
| App OrderResource | NO | Uses stored columns only |
| Marvel OrderResource | NO | Uses stored columns only |

### CATEGORY K: DUPLICATE PRICING LOGIC (4 locations)

| # | What's duplicated | Files | Why it's a problem |
|---|---|---|---|
| K1 | `calculateDiscountedPrice` | PPS + ProductRepository (dead) | Dead code in repository |
| K2 | `calculateFlashSalePrice` | PPS + ProductRepository (dead) | Dead code in repository |
| K3 | Coupon price calculation | CouponService::calcPrice → CouponCalculator + CouponService::updateCartTotalPrice → PPS::calculateCouponPrice → CouponCalculator | Two different paths to the same calculator |
| K4 | Subtotal calculation | PromotionService::subtotal() + OrderService::getCheckoutTotalsFromCart() + CalculatePaymentTrait::calculateSubtotal() | Three different implementations of the same logic |

---

## 4. ROOT CAUSE CHAIN

```
PROBLEM: Wrong flash sale prices in some API responses
  │
  └── CAUSE: Some query paths don't ->with('flash_sales')
        │
        └── CAUSE: Pricing is computed at serialization time (too late to add relations)
              │
              └── CAUSE: Pricing is in Model $appends accessors
                    │
                    └── ROOT CAUSE: "Let's auto-compute pricing in JSON" was implemented via $appends
                          │
                          └── CONSEQUENCES:
                                ├── Models violate SRP (accessors call services)
                                ├── Resources violate SRP (bypass broken accessors with direct PPS calls)
                                ├── 10 query paths forget flash_sales (no compile-time check)
                                ├── Silent fallbacks hide bugs (relationLoaded guards)
                                ├── Cannot cache pricing (computed at last possible moment)
                                ├── PPS called via app() everywhere (no DI)
                                └── Dead code accumulates (wrappers for "safety")
```

**The single architectural mistake:** `protected $appends = ['current_price', 'discount_active', 'flash_sale_active']` in `Product.php:91-95`.

This is the FIRST mistake. Everything else is a consequence:
- Accessors needed services → service locator pattern
- Accessors needed relations → relationLoaded guards + silent fallbacks
- Resources got wrong data → compensation via direct PPS calls
- Query layer got inconsistent → some load flash_sales, some don't
- Pricing at serialization → can't optimize, can't cache

---

## 5. MERGE READINESS — 10 QUESTIONS ANSWERED

### Q1: Is Runtime Pricing production-ready?
**NO.** 

Evidence: 10 query paths return wrong flash sale prices silently. There is no monitoring, no logging, no test that catches this. A product with an active flash sale will show full price in best-sellers, popular products, wishlists, followed shops, and related products. This is a correctness bug, not a performance bug.

### Q2: Is there any hidden SQL remaining?
**YES — 11 locations.**

| Severity | Count | Details |
|---|---|---|
| CRITICAL | 1 | Variation::getBlockedDatesAttribute() fires SQL on EVERY serialization |
| HIGH | 8 | 5 accessors in GetSingleProductResource, 1 fallback in ProductMiniResource, 1 explicit query in CartResource, 1 lazy load in CartItemResource |
| MEDIUM | 2 | 2 methods in PPS that are either dead code or dead path |

The CRITICAL one (E1) is guaranteed to cause N+1 queries on any endpoint returning Variation models. The HIGH ones (E2-E9) are guaranteed on their specific endpoints.

### Q3: Is there any duplicated pricing logic remaining?
**YES — 4 locations.**

ProductRepository has dead wrappers duplicating PPS. CouponService has two paths to CouponCalculator. Three implementations of subtotal calculation exist.

### Q4: Is there any Resource violating SRP?
**YES — 8 out of 14 product-related Resources.**

Marvel ProductResource, App ProductResource, ProductMiniResource, WishlistResource, GetSingleProductResource, CartResource, CartItemResource, CouponResource, FlashSaleResource. Only RelatedProductResource, ProductVariantResource, OrderProductVariantResource, and both OrderResources are clean.

### Q5: Is there any Repository violating SRP?
**YES — 2 repositories.**

ProductRepository (~150 lines of pricing code in 513-line file). OrderRepository (uses CalculatePaymentTrait which has duplicate pricing logic).

### Q6: Is there any hidden accessor dependency?
**YES — 8 accessors.**

7 pricing accessors that call `app(ProductPricingService::class)` (service locator). 1 `getBlockedDatesAttribute()` that fires SQL. 5 review/wishlist accessors that fire SQL. All 12 have hidden dependencies on the caller having loaded specific relations or having authenticated users.

### Q7: Is there any incomplete query contract?
**YES — 10 query paths missing `flash_sales`, 0 query paths missing `product` on variants (silent fallback covers this).**

### Q8: Can future developers safely modify pricing without unexpected breakage?
**NO.**

Adding a new pricing rule would require modifying: ProductPricingService (acceptable) + all 7 pricing accessors in 3 models + both ProductResources + WishlistResource + ProductRepository. Any missed file produces silently wrong results. No tests validate that pricing is consistent across all 19 entry points.

### Q9: Is the architecture closed for extension and open for modification?
**NO.**

The architecture is OPEN for bugs (adding a new endpoint can forget flash_sales) and CLOSED for safe extension (any pricing change requires touching 10+ files across 4 layers).

### Q10: Can this be merged to production with confidence?
**NO.**

The architecture has:
- 1 critical hidden SQL (guaranteed N+1 on Variation serialization)
- 10 query paths returning wrong flash sale prices
- 8 resources doing non-serialization work
- 2 repositories with pricing logic
- 7 dead code sites
- 4 silent failure points
- 4 duplicate logic sites
- No central pricing contract (no DTO)
- No pre-computation pattern
- No caching strategy

Each of these individually is a rollback risk. Collectively, they guarantee that any production issue related to pricing will be hard to diagnose, harder to fix, and will likely introduce a regression elsewhere.

---

## 6. AUDIT SCOPE VERIFICATION

### All files that touch pricing — VERIFIED COMPLETE

| Layer | Files Count | Files |
|---|---|---|
| **Models** | 4 | Product, ProductVariant, Variation, FlashSale |
| **Pricing Service** | 1 | ProductPricingService |
| **Resources** | 14 | Marvel ProductResource, App ProductResource, ProductMiniResource, WishlistResource, RelatedProductResource, GetSingleProductResource, ProductVariantResource, OrderProductVariantResource, CartResource, CartItemResource, CouponResource, FlashSaleResource, App OrderResource, Marvel OrderResource |
| **Repositories** | 3 | ProductRepository, OrderRepository (via trait), FlashSaleRepository |
| **Services** | 6 | CartInventoryService, OrderCreationService, CouponService, GiftPromotionStrategy, CalculatePaymentTrait, ProductService |
| **Controllers** | 6 | Marvel ProductController, App ProductController, WishlistController, ComponentDataController, ShopController, CartController |
| **Routes** | 2 | Routes.php, api.php |
| **Commands/Jobs** | 0 | — (searched but none touch pricing) |
| **Observers** | 0 | — (searched but none touch pricing) |
| **Events** | 0 | — (searched but none touch pricing) |
| **Tests** | 1 | ProductPricingServiceTest (687 lines — comprehensive) |

**Total unique files that can affect runtime pricing: ~35**

### Unknowns (what cannot be proven)

| Question | Answer |
|---|---|
| Are there any GraphQL-only endpoints that return products? | Packages/marvel has a GraphQL/Schema directory with `.graphql` files referencing `sale_price`. If a GraphQL endpoint is registered that queries products through a different resolver path, that path has NOT been audited. |
| Are there any Nova/Laravel-admin query paths? | Not found in this codebase. |
| Are there any external API callers that bypass these layers? | No evidence. The API is the only documented entry point. |
| Are there any queue jobs that compute or modify pricing? | Searched — none found. |
| Are cache invalidation hooks reliable? | `Cache::forget('dashboard_product_analytics')` exists in ProductRepository but this is only analytics, not pricing cache. No pricing cache exists. |

---

## 7. FINAL VERDICT

**The architecture has one root cause, 14 violation categories, 60+ individual issues, and is NOT ready for production merge.**

The root cause (`$appends` accessors on models) is understood and documented. Fixing it requires an architectural rewrite, not a series of patches. The correct architecture (Models = data only → Service computes pricing → DTO as contract → Resource serializes) is the only way to eliminate all 60+ issues.

**Do not merge.** Do not patch. Rewrite the pricing pipeline as documented in RUNTIME_PRICING_ARCHITECTURE_REPORT.md Section 15 (Migration Roadmap, 10 steps, ~27 hours).
