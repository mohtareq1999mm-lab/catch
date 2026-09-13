# Product Module — Backend JIRA Tasks

## B-001: Make internal helper methods private

**Priority:** Low
**Story Points:** 1
**Labels:** refactoring, consistency
**File:** `ProductController.php`

**Description:** `ProductStore()`, `updateProduct()`, and `destroyProduct()` are all `public` but are internal helpers only called from controller methods. Make them `private` to match the Brand/Category/Attribute pattern.

**Acceptance Criteria:**
- `ProductStore()` → `private`
- `updateProduct()` → `private`
- `destroyProduct()` → `private`

---

## B-002: Wrap `updateProduct()` repository method in DB transaction

**Priority:** High
**Story Points:** 2
**Labels:** bug, data-integrity
**File:** `ProductRepository.php`

**Description:** `updateProduct()` does not use a DB transaction, while `storeProduct()` does. Multiple writes happen (delete variants, create variants, update images, sync relations). A partial failure can corrupt data.

**Acceptance Criteria:**
- `DB::beginTransaction()` at start
- `DB::commit()` after all operations
- `DB::rollBack()` in catch block
- All operations inside try/catch

---

## B-003: Add missing English translation keys

**Priority:** Medium
**Story Points:** 1
**Labels:** bug, i18n
**File:** `resources/lang/en/message.php`

**Description:** Four product CRUD translation keys exist in Arabic but are missing in English. When the app falls back to English, users see the key string instead of a readable message.

**Acceptance Criteria:**
- `MESSAGE.CREATE_PRODUCT_SUCCESSFULLY` → "Product created successfully"
- `MESSAGE.UPDATE_PRODUCT_SUCCESSFULLY` → "Product updated successfully"
- `MESSAGE.DELETE_PRODUCT_SUCCESSFULLY` → "Product deleted successfully"
- `MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY` → "Products deleted successfully"

---

## B-004: Type-hint helper methods with specific FormRequests

**Priority:** Low
**Story Points:** 1
**Labels:** refactoring, type-safety
**File:** `ProductController.php`

**Description:** `ProductStore()` and `updateProduct()` type-hint generic `Request`. Change to `ProductCreateRequest` and `ProductUpdateRequest` for type safety and IDE support.

**Acceptance Criteria:**
- `ProductStore(ProductCreateRequest $request)`
- `updateProduct(ProductUpdateRequest $request)`

---

## B-005: Add /products test suite

**Status:** ✅ **Done** — `tests/Feature/ProductCrudTest.php` (62 tests, all passing)

Covers: Products CRUD + bulk-delete + destroy-all + import routes + reviews CRUD + toggle-approve

## B-006: Add review & import route tests

**Priority:** High
**Story Points:** 3
**Labels:** testing, coverage
**Files:** `tests/Feature/ProductCrudTest.php`

**Description:** The following routes are covered in the existing 62-test file:

- `POST /products/bulk-delete` (auth, success, validation)
- `DELETE /products/all` (auth, success)
- `POST /products/import` (guest auth)
- `GET /products/import/{id}` (auth, status, nonexistent)
- `POST /products/import/{id}/cancel` (auth, cancel, nonexistent)
- `GET /products/import/{id}/download-errors` (auth, nonexistent)
- `GET /reviews` (auth, list, validation)
- `POST /reviews` (guest auth)
- `GET /reviews/{id}` (auth, show, nonexistent)
- `PUT /reviews/{id}` (guest auth)
- `DELETE /reviews/{id}` (auth, delete, nonexistent)
- `PATCH /reviews/{id}/toggle-approve` (auth, success, nonexistent)

**Remaining gaps:**
- Review create validation (missing rating, comment)
- Review update via API
- Has_discount true but missing discount_type → 422
- Has_flash_sale true but missing flash_sale_id → 422

---

## B-007: Document tags in product CRUD

**Priority:** Low
**Story Points:** 1
**Labels:** documentation
**Files:** `api-desc/product/*.md`

**Description:** All product documentation files have been updated to reflect tags support:
- `api.md`: Added `tags` field to POST request body, PUT notes
- `frontend.md`: Added Tags section in Key Considerations
- `backend.md`: Updated ProductFilter, repository, permissions for tags
- `README.md`: Added TagController/TagRepository/TagResource to Key Files, tag permissions, tag routes, tags filter param
- `flow.md`: Added tags to sync relations in create/update flows
- `changelog.md`: Full tags support entry
- `test-cases.md`: Added tag-related test cases
- `qa.md`: Added tag coverage items
- `bug-report.md`: Updated all BUG-PRD statuses to ✅ Fixed
- `database.md`: Added tags table documentation

---

## B-008: Document dual-surface Product API (Admin `apiResource` + Storefront `general/products`)

**Priority:** High
**Story Points:** 5
**Labels:** documentation, product, storefront
**Files:** `api-desc/product/api.md`, `backend.md`, `README.md`, `flow.md`, `frontend.md`, `database.md`, `investigation.md`, `qa.md`, `test-cases.md`, `changelog.md`, `bug-report.md`

**Description:** `Route::get('products', index)` + `Route::get('products/{slug}', getProductBySlug)` (`routes/api.php:82-83`, `App\Http\Controllers\Api\General\ProductController`) are public storefront endpoints but docs only covered Admin `Route::apiResource('products', ProductController::class)` (`packages/marvel/src/Rest/Routes.php:236`). Bring all `api-desc/product` docs into dual-surface accuracy.

**Acceptance Criteria:**
- `api.md` split Admin vs Storefront with real response examples (`ProductMiniResource` listing + facets + `links`, `App\ProductResource` detail with `currency+variants`, Scout vs LIKE `search`, dimension/rating filters, `item_type:PHYSICAL|DIGITAL`, `supportsTypes` 10 keys, `ProductIndexRequest` validation table)
- `backend.md` documents `General\ProductController` (`buildStrategyResponse`/`buildFallbackResponse`/`shouldCache`/`currencyAwareCacheKey`/`getCollectionCategories`), `ProductService` (`buildScoutSearchQuery`, `buildFilteredBaseQuery`, `applyProductFilters`, `getDynamicFilters`, `getLimit`), `ProductStrategyResolver` 10 strategies, `ProductMiniResource` vs `App\ProductResource` vs `Marvel\ProductResource`
- `README.md` dual-surface overview + key-files + permissions + routes + query-param tables + response shape reference
- `flow.md` added storefront flows 8 (listing) + 9 (slug detail) with strategy/Scout/cache/currency + `HasChannelFilter`
- `frontend.md` dual-surface endpoint table, storefront `filters/categories/links` + `image{thumbnail,original}` + currency/scout/channel states
- `database.md` adds `item_type/tax_enabled/tax_rate/currency` columns + `FastShippingScope`/`HasChannelFilter`/`Scout` scopes
- `investigation.md` expanded to 7 sections covering all three routes together
- `qa.md` 8 sections with storefront cache/Scout/currency/channel + `item_type` immutability
- `changelog` v1.3.0 documents all changes

**Status:** ✅ Done 2026-09-13

## B-009: Fix `ProductRepository::updateProduct` not wrapped in `DB::transaction`

**Priority:** High
**Story Points:** 2
**Labels:** bug, data-integrity
**File:** `packages/marvel/src/Database/Repositories/ProductRepository.php:116`

**Description:** `storeProduct()` is `DB::transaction`-wrapped but `updateProduct()` is not. Admin updates can leave half-deleted variants or half-synced relations on partial failure; storefront listing (`filters` facets, Scout index) then surfaces stale data.

**Acceptance Criteria:**
- `DB::beginTransaction()` at start of `updateProduct`, `DB::commit()` on success, `DB::rollBack()` + throw on `Throwable`
- Regression test: simulate failure mid-`updateProduct` (e.g., invalid `attribute_values` after variant delete) → assert no variant rows deleted, no relation rows partially synced

## B-010: Add `ProductIndexRequest` range validation (`limit`, `search`) for storefront listing

**Priority:** Medium
**Story Points:** 1
**Labels:** validation
**File:** `app/Http/Requests/ProductIndexRequest.php`

**Description:** Only `type` + `order` are validated. `limit` is silently clamped in `ProductService::getLimit` and `search` is free-form — callers cannot get `422` guidance.

**Acceptance Criteria:**
- `limit: sometimes|integer|min:1|max:100`
- `search: sometimes|nullable|string|max:100`
- Returns standard `422` error contract with field keys (consistent with Admin `422`)

## B-011: Consolidate `filters` facet origin between `General\ProductController:getProductBySlug` and `App\ProductResource`

**Priority:** Low
**Story Points:** 1
**Labels:** refactoring, consistency
**Files:** `app/Http/Controllers/Api/General/ProductController.php:getProductBySlug`, `app/Http/Resources/Product/ProductResource.php`

**Description:** Both controller and resource inject `filters` (resource via `mergeWhen(!routeIs general-product-show)` + controller via `getDynamicFilters(whereIn[id])`). Detail response can vary by call site.

**Acceptance Criteria:**
- Single owner (prefer controller owns `filters`; remove resource `mergeWhen` and add controller-level `filters` injection for listing+detail, or reverse and drop controller injection)
- Add test asserting `GET /general/products/{slug}` → `data.filters` present and stable
