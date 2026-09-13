# Product Module — Changelog

> Tracks Admin `apiResource` + Storefront `GET /products` / `GET /products/{slug}` dual-surface.

## v1.3.0 — 2026-09-13 — Dual-surface docs & Storefront GA

### Docs (requested: `Route::apiResource('products', ProductController::class)` + `GET products` + `GET products/{slug}`)

- **api.md** — split into Admin (`packages/marvel/src/Rest/Routes.php:236`, 5 `apiResource` routes + bespoke `bulk-delete/all/import/export/digital-assets` before guard) and Storefront (`routes/api.php:82-83`, public `throttle:public-api`, cached per `currencyAwareCacheKey`, channel-scoped). Updated every endpoint with current request tables (`ProductCreateRequest`/`ProductUpdateRequest` 30+ fields, `item_type:PHYSICAL|DIGITAL`, conditional `discount_*`/`flash_sale_id`, `ProductIndexRequest` `type\in supportedTypes` + `order\in asc,desc`), Scout vs LIKE `search`, dimension/rating facets, currency conversion, and real response examples (`Marvel\ProductResource` 40+ fields + `tax/price_including_tax`, `ProductMiniResource` card + `filters/categories/links`, `App\ProductResource` detail with `currency+variants+related_products`).
- **backend.md** — new storefront controller `General\ProductController` (strategy vs fallback paths), `ProductService` (`buildScoutSearchQuery`, `buildFilteredBaseQuery`, `applyProductFilters`, `getDynamicFilters`, `getLimit 1..100`, `FastShippingScope`/`HasChannelFilter`), `ProductStrategyResolver` (10 `type` keys), `ProductMiniResource` vs `App\ProductResource` vs `Marvel\ProductResource`, `ProductIndexRequest`.
- **README.md** — overview now dual-surface, key-files table updated (adds `General\ProductController`, `ProductService`, `ProductStrategyResolver`, `ProductMiniResource`, `ConvertsProductPrice`), permissions table adds storefront `none` + `import-product/export-product`, routes split Admin/Storefront, query-param tables duplicated per surface, response shape reference.
- **flow.md** — added storefront flows 8 (`GET /general/products` strategy/fallback/Scout/cache/currency) and 9 (`GET /general/products/{slug}` slug→channel→pricing→cache) + legacy admin flows 1-7 retained.
- **frontend.md** — endpoint table adds Admin/Storefront surface column, list/detail response examples now show storefront `filters/categories/links` + `image{thumbnail,original}` + `currency/ratings`, query-param tables per surface (including `type` enumeration, `order_price`, `productsId`, dimension/rating ranges), key considerations add item_type vs product_type, currency/tax, cache/scout, channel scope.
- **database.md** — added `item_type` column, `tax_enabled/tax_rate/currency` columns, `Scout` + `FastShippingScope`/`HasChannelFilter` scopes, `item_type` index, Scout index note.
- **investigation.md** — expanded from Admin-only to 7-section dual-surface investigation (three routes together: `apiResource:236` + general `index` + `getProductBySlug`).
- **qa.md** — new 8-section plan covering storefront `type` enumeration, Scout/LIKE search, `currencyAwareCacheKey`, `FastShippingScope`, `item_type` immutability, facet correctness.
- **test-cases.md** — TC-PRD-001/002 split antistorefront public 200 vs admin 401, added 8 storefront cases (041-048: `type` enumeration, `type=all` ≡ fallback, `order_price`, dimension/rating, Scout fallback, cache/currency, immutability, bulk throttle).

### Confirming no behavior change

- No code change in this changelog entry — docs now match implemented `Marvel\ProductController` (`__construct` 108-111 permission map, `storeProduct/updateProduct` with `ProductPricingService`) and `General\ProductController` (`index` with `shouldCache/currencyAwareCacheKey/buildStrategyResponse/buildFallbackResponse`, `getProductBySlug` with `enrichProductWithPricing` + `HasChannelFilter`). Route ordering guard (`bulk-delete/all/import/sample/export` before `apiResource`) reconfirmed via `routes/api.php` + `Rest/Routes.php:223-236`.

## v1.0.0 (Current — prior)

### Endpoints
- 5 CRUD `apiResource`: list, create, show, update, delete (admin `auth:sanctum`)
- Additional: bulk-delete, destroy-all
- 4 import endpoints (import, status, cancel, download-errors)
- 6 review endpoints (CRUD + toggle-approve)
- Storefront read-only `v1/general/products` + `v1/general/products/{slug}` (public)

### Architecture
- Controller → Repository → Model + Storefront `General\ProductController` → `ProductService` → `ProductStrategyResolver` → Strategy → `ProductCollectionMini`
- Separate FormRequests for create/update + `ProductIndexRequest` for storefront
- `Marvel\ProductResource` admin + `ProductMiniResource` listing + `App\ProductResource` detail
- `ProductPricingService` centralized + `ProductTaxPresenter` + `ConvertsProductPrice`
- `ProductFilter` + `applyProductFilters` + `applyDimensionFilters`
- Soft deletes + `active()` scope + `FastShippingScope` global + `HasChannelFilter`

### Bug Fixes
- **BUG-PRD-001/002/003** — Product helpers made private (fixed)
- **BUG-PRD-004** — `updateProduct()` repository transaction guard flagged as missing (see bug-report)
- **BUG-PRD-005** — Missing English translation keys added (fixed)
- **BUG-PRD-006** — FormRequest type-hints for `ProductStore()` and `updateProduct()` (fixed)

### Tags Support
- `ProductCreateRequest` `tags: nullable|array|exists:tags,id`, `fetchSingleProduct` eager-loads `tags`, `ProductMiniResource` `TagResource::collection`, `ProductFilter` `tags` slug or numeric ID AND logic, full `TagController apiResource` (see `database.md` `product_tag` pivot)

### Test Coverage
- `tests/Feature/ProductCrudTest.php` — 62 tests
- + `ProductAdminTest` (17) + `ProductProductionHardenTest` (28) + `AttributesProductionHardenTest` (32) = 139 total
- + `tests/Feature/General/ProductsEndpointTest.php` storefront + `ImportExport/*` + `ProductPricingServiceTest`

### Known Limitations
- No restore endpoint for soft-deleted products
- No DTOs or Action classes (admin still Repository-heavy)
- `ProductStore()` PascalCase naming inconsistency
- `updateProduct` not yet wrapped in `DB::transaction` (B-002)
- `destroyAll` hard delete lacks `super_admin` policy (operational guard is `throttle:admin` only)
