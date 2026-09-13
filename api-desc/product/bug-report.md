# Product Module — Bug Report

## BUG-PRD-001: `updateProduct()` method visibility inconsistency

**Severity:** Low
**Status:** ✅ Fixed
**File:** `packages/marvel/src/Http/Controllers/ProductController.php:324`

**Issue:** `updateProduct()` is `public` but it is an internal helper only called from `update()`. Should be `private` to match the pattern used in Brand (`brandUpdate`), Category (`categoryUpdate`), and Attribute (`updateAttribute`).

**Current:**
```php
private function updateProduct(ProductUpdateRequest $request): mixed
```

**Expected:**
```php
private function updateProduct(ProductUpdateRequest $request): mixed
```

**Fix applied:** ✅ Made `private`, type-hinted with `ProductUpdateRequest`.

---

## BUG-PRD-002: `ProductStore()` method visibility inconsistency

**Severity:** Low
**Status:** ✅ Fixed
**File:** `packages/marvel/src/Http/Controllers/ProductController.php:249`

**Issue:** `ProductStore()` is `public` but is an internal helper only called from `store()`. Should be `private`.

**Current:**
```php
private function ProductStore(ProductCreateRequest $request): mixed
```

**Expected:**
```php
private function ProductStore(ProductCreateRequest $request): mixed
```

**Fix applied:** ✅ Made `private`, type-hinted with `ProductCreateRequest`.

---

## BUG-PRD-003: `destroyProduct()` method visibility inconsistency

**Severity:** Low
**Status:** ✅ Fixed
**File:** `packages/marvel/src/Http/Controllers/ProductController.php:351`

**Issue:** `destroyProduct()` is `public` but is an internal helper only called from `destroy()`. Should be `private`.

**Current:**
```php
private function destroyProduct(Request $request): JsonResponse
```

**Expected:**
```php
private function destroyProduct(Request $request): JsonResponse
```

**Fix applied:** ✅ Made `private`.

---

## BUG-PRD-004: Missing DB transaction in `updateProduct()` repository method

**Severity:** High
**Status:** ⚠️ Reopened — confirmed `updateProduct()` lacks transaction (false-alarm note was incorrect)
**File:** `packages/marvel/src/Database/Repositories/ProductRepository.php:116`

**Issue:** `storeProduct()` wraps everything in `DB::beginTransaction`/`DB::commit`/`DB::rollBack`, but `updateProduct()` does not — it performs multiple writes (delete old `variations` + `AttributeProduct` pivots, `addVariants()`, pricing recalc, MediaLibrary image diff, `syncRelation` for categories/brands/banners/sliders/tags/flash_sales). Partial failure leaves half-deleted variants or half-synced relations. Also impacts storefront: `General\ProductController@index/getProductBySlug` reads the same tables and can surface wrong `filters` facets or stale Scout index.
**Fix required:** wrap `updateProduct` body in `DB::beginTransaction(); try { ... DB::commit(); } catch (\Throwable $th) { DB::rollBack(); throw $th; }` mirroring `storeProduct`.

---

## BUG-PRD-005: Product CRUD translation keys missing in English

**Severity:** Medium
**Status:** ✅ Fixed
**File:** `resources/lang/en/message.php`

**Issue:** The English translation file is missing the following product CRUD keys:
- `MESSAGE.CREATE_PRODUCT_SUCCESSFULLY`
- `MESSAGE.UPDATE_PRODUCT_SUCCESSFULLY`
- `MESSAGE.DELETE_PRODUCT_SUCCESSFULLY`
- `MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY`

These keys exist in Arabic but not English. When the app falls back to English, users see the key string instead of a readable message.

**Fix applied:** ✅ Added English translations:
```php
'MESSAGE.CREATE_PRODUCT_SUCCESSFULLY' => 'Product created successfully',
'MESSAGE.UPDATE_PRODUCT_SUCCESSFULLY' => 'Product updated successfully',
'MESSAGE.DELETE_PRODUCT_SUCCESSFULLY' => 'Product deleted successfully',
'MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY' => 'Products deleted successfully',
```

---

## BUG-PRD-006: `ProductStore()` and `updateProduct()` accept generic `Request` instead of typed FormRequest

**Severity:** Low
**Status:** ✅ Fixed
**File:** `packages/marvel/src/Http/Controllers/ProductController.php:249,324`

**Issue:** After validation passes in `store()`/`update()`, the helper methods `ProductStore()` and `updateProduct()` type-hint `Request` instead of their respective `ProductCreateRequest`/`ProductUpdateRequest`. This loses type safety.

**Current:**
```php
private function ProductStore(ProductCreateRequest $request): mixed
private function updateProduct(ProductUpdateRequest $request): mixed
```

**Expected:**
```php
private function ProductStore(ProductCreateRequest $request): mixed
private function updateProduct(ProductUpdateRequest $request): mixed
```

**Fix applied:** ✅ Both methods now type-hint their specific FormRequests.

---

## BUG-PRD-007: No `deleteAttribute` method for product (inconsistent with brand/attribute pattern)

**Severity:** Low
**Status:** Won't Fix (current inline approach works)

Product's `destroy()` directly calls `$this->repository->findOrFail($id)->delete()` inline. Brand and Attribute have separate helper methods. This is inconsistent but functional.

---

## BUG-PRD-008: Storefront `GET /general/products` + `GET /general/products/{slug}` undocumented (dual-surface drift)

**Severity:** Medium
**Status:** ✅ Fixed 2026-09-13
**File:** `api-desc/product/*.md` (api.md, backend.md, README.md, flow.md, frontend.md, investigation.md, qa.md, test-cases.md, changelog.md, database.md)
**Issue:** `Route::get('products', …index)` and `Route::get('products/{slug}', …getProductBySlug)` (`routes/api.php:82-83`, `App\Http\Controllers\Api\General\ProductController`) were public, cached, currency-aware endpoints but docs only described Admin `apiResource`. Storefront uses `ProductStrategyResolver` (10 `type` keys), Scout fallback, `ProductService::getDynamicFilters` facets, `HasChannelFilter`/`FastShippingScope`, and distinct resources (`ProductMiniResource` card vs `App\ProductResource` detail) — none documented.
**Fix:** split docs into Admin + Storefront; added `ProductIndexRequest` validation, `type` enumeration, `search` Scout/LIKE, dimension/rating filters, `currencyAwareCacheKey` caching, channel scope notes, and real response examples.

## BUG-PRD-009: `ProductIndexRequest` missing range validation for `limit` + unvalidated `type` passthrough (storefront `index`)

**Severity:** Low
**Status:** Open
**File:** `app/Http/Requests/ProductIndexRequest.php`, `app/Services/General/ProductService.php:826`
**Issue:** `ProductIndexRequest` validates only `type` (`Rule::in(supportedTypes)`) and `order` (`asc,desc`). `limit` is clamped in `ProductService::getLimit` (1..100) but not validated → no 422 on out-of-range input; callers cannot distinguish clamp from explicit limit. `search` bypasses strict validation and goes straight to Scout/LIKE — empty or overly long terms are silently handled.
**Recommendation:** add `limit: sometimes|integer|min:1|max:100` and `search: sometimes|nullable|string|max:100` to `ProductIndexRequest`, return 422 with field errors to match Admin `422` contract.

## BUG-PRD-010: Storefront detail merges `filters` only when `!routeIs('general-product-show')` but controller already injects facets (duplication)

**Severity:** Low
**Status:** Open
**File:** `app/Http/Resources/Product/ProductResource.php` + `app/Http/Controllers/Api/General/ProductController.php:getProductBySlug`
**Issue:** Resource does `mergeWhen(!request()->routeIs('general-product-show'), [filters=>getProductFilters])` while controller also enriches response with `getDynamicFilters(whereIn[id])`. On the canonical route `general-product-show` the resource hides `filters` but controller injects them — consumers see inconsistent `data.filters` depending on call site vs unit-tested resource. Could cause frontend to render empty faceted filters on detail page after cache refactor.
**Fix:** consolidate facet origin — either controller owns `filters` (remove resource merge) or resource owns it (controller stops injecting, add test asserting `data.filters` present on detail).
