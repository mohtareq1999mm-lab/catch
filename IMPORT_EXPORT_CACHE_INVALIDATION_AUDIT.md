# Import/Export Cache Invalidation Audit

Date: 2026-09-14
Scope: All Import/Export jobs (Product/Category/Brand + others), cache architecture, invalidation timing/ordering.

## 1. Current Cache Architecture

**Cache stores:** `config/cache.php` default `redis` (`CACHE_DRIVER=redis`, `REDIS_CLIENT=predis`, prefix `meem_`), fallback `file`/`array` in tests/seeds where `TaggableStore` unavailable.

**Two caching layers:**

1. **Tagged business caches via `App\Traits\HasCache` (`app/Traits/HasCache.php:16`):**
   - `Cache::tags([$tag])->remember($key, $ttl, $callback)` with fallback `Cache::remember($tag.':'.$key)` on `BadMethodCallException`.
   - Readers: `BannerController` (`banners`), `BrandController` (`brands`, `brands_products`), `CategoryController` (`categories`), `ProductController` / `ProductService` / `ProductEngine` (`products`, `products_{type}` per `ProductStrategyResolver::supportedTypes()`), `SliderController` (`sliders`), `FlashSaleController` (`flash_sales`), etc. All via `FrontendResource` enum (`app/Enums/FrontendResource.php`).
   - Tags enum: `products`, `products_{type}`, `attributes`, `categories`, `brands`, `brands_products`, `flash_sales`, `promotions`, `settings`, `coupons`, `faqs`, `sliders`, `banners`, `tags`, `content_pages`, `static_pages`, `pickup_locations`, `fast_shipping_settings`, `sections`, `governorates`, `countries`, `cities`, `orders`, `dashboard`, `site_reviews`, `currencies`.

2. **Home page aggregate cache via `App\Services\General\HomeService` (`app/Services/General/HomeService.php:461`):**
   - `Cache::remember($channel . ':' . $key, 120, ...)` for keys `home-nav-bar`, `home-data`, `home-active-sliders`, `home-flash-sales`, `home-best-categories`, `home-discount-products-end-today`, `home-active-banners`, `home-brands`, `home-parent-categories`, `home-latest-coupons`, `home-flash-sale-products`, `home-weekly-parent-categories`, `home-weekly-products`, `home-all-discount-products`, `home-flash-sales-after-9` plus `home-nav-bar:level:{1..3}` and currency-aware variants (`:USD`, `:EGP` etc. via `activeCurrencyCodes()`). `HomeService::clearCache()` iterates `Channel::values()` and forgets each.

3. **API response envelope cache via `App\Http\Middleware\CacheApiResponse` (`app/Http/Middleware/CacheApiResponse.php:31`):**
   - `Cache::put($key, ['content','status','headers'], 3600)` where `$key = md5('api_cache|'.$version.'|'.$fullUrl.'|'.authId)`, `$version = Cache::get('api_cache_version',0)`. Invalidated via `Cache::increment('api_cache_version')` (all import jobs do this). Skips `api/*/brands/import*`, `api/*/categories/import*`, `api/*/products/import*`, `api/*/brands/export*`, etc., plus `BinaryFileResponse`/`StreamedResponse`.

**Observers that auto-invalidate on model events (per-entity, not bulk-import):**

- `ProductObserver` (`app/Observers/ProductObserver.php:90`): `created/updated/deleted/restored/forceDeleted` → `flushProductCaches()` → `flushTag(products)`, `flushTag(products_{type})`, `HomeService::clearCache()`, `flushTag(categories)`, `flushTag(brands)`, `flushTag(brands_products)`, `Cache::flush()` safety-net, `Cache::increment(api_cache_version)`. Uses `flushTagWithFallback` that also `Cache::flush()` on success (critical: ProductObserver double-flushes).
- `CategoryObserver` (`app/Observers/CategoryObserver.php:17`): `created/updated/deleted/restored` → `HomeService::clearCache()`, `flushTag(categories)`, `flushTag(products)`, `Cache::increment`.
- `BrandObserver` (`app/Observers/BrandObserver.php:17`): similarly `brands`, `brands_products`, `products`.

Bulk imports use `saveQuietly()` / `create()` without firing observers per row (to avoid N+1 cache flushes), so job-level invalidation is mandatory.

**Other caches:** `Cache::put('cached_settings_en')` via `Marvel\Database\Models\Settings`, `Cache::lock`/`Cache::has('idempotency:brand-export:{user}:{key}')` in import/export controllers, `Cache::store('file')->get('product-export:filters:{id}')` in `ExportProductsJob`.

## 2. All Import Jobs

| Entity | Job Class | Queue | Entry Point | Operation Model | Processing Flow | Final States | Current Invalidation |
|---|---|---|---|---|---|---|---|
| Product | `Marvel\Jobs\ImportProductsJob` (`packages/marvel/src/Jobs/ImportProductsJob.php:24`) | `catch-medium` (`onQueue(config('queue.queues.medium'))`, tries 3, timeout 1200, backoff [60,120,240]) | `ProductImportController@import` → `Import::create(type=product, status pending, created_by)` → `ImportProductsJob::dispatch(id)` | `Import` (`type=product-import`, `file_path`, `status` pending→processing→completed/completed_with_errors/failed/cancelled, counters, `errors`) | `resolveImportFilePath` → `ProductImportService::writeExplicitProgress(1,2)` → `countRows()` → `Excel::import(ProductsImport)` (null transaction handler, no global tx, per-row `DB::beginTransaction/commit` in `ProductImportService::processProductRow`) → `flushPendingSyncs/finalizeVariants` → `finalizeProgress` → `dispatchImageJobs` → atomic terminal `Import::whereIn(['pending','processing','cancelling'])->update(status, total/processed/success/failed, errors)` → **if successCount>0 \|\| variantSuccess>0 → `invalidateFrontendCaches()`** → `broadcastFileOperationTerminal` | `completed`, `completed_with_errors` (some failed), `failed` (success==0), `cancelled` (ImportCancelledException) | **Partial:** invalidates only on success path when `successCount>0`; failure/cancel paths do not invalidate even if partial commits exist |
| Category | `Marvel\Jobs\ImportCategoriesJob` (`packages/marvel/src/Jobs/ImportCategoriesJob.php:24`) | `catch-medium`, tries 3, timeout 1200, backoff [60,120,240] | `CategoryImportController@import` | Same `Import` with `type=category-import` | `CategoryImportService::writeExplicitProgress(1,2)` → `countRows` → `Excel::import(CategoriesImport)` (excel handler null) → `CategoryImportService::processRows` (prepare→upsert→assignParents→attachImages) → `writeExplicitProgress(99)` → `finalizeProgress` → atomic terminal → **if successCount>0 → `invalidateFrontendCaches()`** → `broadcastCategoryImportTerminal` | Same states | **Partial:** same gap on failed/cancelled with partial commits |
| Brand | `Marvel\Jobs\ImportBrandsJob` (`packages/marvel/src/Jobs/ImportBrandsJob.php:24`) | `catch-medium`, tries 3, timeout 1200, backoff [60,120,240] | `BrandImportController@import` (idempotency via `Cache::lock` + `brand-import:hash`) | `type=brand-import` | `BrandImportService::writeExplicitProgress(1,2)` → `countRows` → `Excel::import(BrandsImport)` (null handler) → `BrandImportService::processRows` → `writeExplicitProgress(99)` → atomic terminal → **if successCount>0 → `invalidateFrontendCaches()`** → `broadcastBrandImportTerminal` | Same states | **Partial:** same gap |

No other import jobs found (grep `Import.*Job` returns only these 3 plus `ImportProductImagesJob` (auxiliary image chunks, `packages/marvel/src/Jobs/ImportProductImagesJob.php`, medium, not a top-level operation, no cache logic).

## 3. All Export Jobs

| Entity | Job Class | Queue | Entry | Model | Flow | Final States | Current Invalidation |
|---|---|---|---|---|---|---|---|
| Product | `Marvel\Jobs\ExportProductsJob` (`packages/marvel/src/Jobs/ExportProductsJob.php:17`) | `catch-medium`, tries 2, timeout 900 | Dormant per ADR-002 G3: `GET /products/export` is synchronous `ProductsExport::download()`; `ExportProductsJob` exists, uses `Cache::store('file')->get('product-export:filters:{id}')` and `forget` after | Same `Import` generalized for exports (`type=product-export`) | `status processing` → `broadcast progress 5` → `export->collection()->count` → `store(filename,'imports')` → `broadcast progress 90` → file existence/ZipArchive checks → cancel check → atomic `status completed + file_path` → `broadcast completed` (no business cache logic) | `completed`, `failed`, `cancelled` | **None** for business data (correct: read-only, no product/category/brand mutation) |
| Category | `Marvel\Jobs\ExportCategoriesJob` (`packages/marvel/src/Jobs/ExportCategoriesJob.php:17`) | `catch-medium`, tries 2, timeout 900 | `CategoryExportController@export` → `Import::create(type=category-export)` → `ExportCategoriesJob::dispatch(id)` | Same | `processing` → `broadcast 5` → `CategoriesExport::collection()->count` → `store` → `broadcast 90` → validation → cancel check → `completed + file_path` → `broadcast completed` | Same | **None** |
| Brand | `Marvel\Jobs\ExportBrandsJob` (`packages/marvel/src/Jobs/ExportBrandsJob.php:18`) | `catch-medium`, tries 2, timeout 900 | `BrandExportController@export` similarly | Same | Same pattern (idempotency via `Cache::has('idempotency:brand-export')`) | Same | **None** |

No other export jobs (plus `BulkDeleteCategoriesJob` is not export; it deletes categories and already has no cache logic but should via observers on soft-delete per row — acceptable).

## 4. Cache Keys/Tags

- **Product-related:** `products`, `products_{type}` (dynamic per `ProductStrategyResolver::supportedTypes()`), `brands`, `brands_products`, `categories` (via ProductObserver), `home-*` (home cache), `api_cache_version` bump.
- **Category-related:** `categories`, `products`, `home-*`, `api_cache_version`.
- **Brand-related:** `brands`, `brands_products`, `products`, `home-*`, `api_cache_version`.
- **Export files:** `idempotency:brand-export:{user}:{key}` (24h), `product-export:filters:{id}` (file store), not business cache.
- **HomeService keys:** `home-nav-bar`, `home-data:parent:{id}:category-tree`, `home-active-sliders`, `home-flash-sales`, `home-best-categories`, `home-discount-products-end-today`, `home-active-banners`, `home-brands`, `home-parent-categories`, `home-latest-coupons`, `home-flash-sale-products`, `home-weekly-parent-categories`, `home-weekly-products`, `home-all-discount-products`, `home-flash-sales-after-9` (channel-prefixed, currency-aware for 6 keys).
- **Middleware key:** `md5(api_cache|<version>|<fullUrl>|<authId>)` for `GET` responses (3600 TTL).

## 5. Entity Dependencies

- **Product Import** changes: `products` (incl. translations, price/discount, quantity, status, `item_type`, `has_flash_sale`), `product_variants` + `attribute_product`, `media` (products), relations `category_product`, `brand_product`, `flash_sale_products`, `slider_product`, `tag` syncs, `categories/brands` aggregations, `home` product sections (`discountProductsEndToday`, `newArrivals`, `flashSaleProducts`, `weeklyProducts`, `allDiscountProducts`). Therefore product import must invalidate `products`, `products_{type}`, `categories`, `brands`, `brands_products`, `home-*`, `api_cache_version`.
- **Category Import** changes: `categories` (translations, `parent_id`, `is_featured`, `status`, hierarchy via `CategoryHierarchyService`), `media` (categories-desktop/mobile), `home` category tree/nav/bestCategories, and indirectly `products` (products_count via `withCount`). So invalidates `categories`, `products`, `home-*`, `api_cache_version` (plus `brands_products` currently in code as conservative extra).
- **Brand Import** changes: `brands` (translations, slug, status, media), `home-brands`, and `brands_products` aggregation. Invalidates `brands`, `brands_products`, `products`, `home-*`, `api_cache_version`.

## 6. Current Invalidation Behavior

- **Product/Category/Brand Import (success):** After atomic terminal update and **before** `broadcastFileOperationTerminal`, `invalidateFrontendCaches()` is called **only if** `successCount>0` (or variant success for product). This flushes tagged caches with `Cache::tags([$tag])->flush()` per tag, falling back to `Cache::flush()` if store not taggable, then `HomeService::clearCache()` and `Cache::increment('api_cache_version')` inside try/catch (swallows and reports). This satisfies "once after job finish, before final event" and is entity-aware (no `Cache::flush()` as first resort, though observers do flush heavily).
- **Product/Category/Brand Import (failed):** Job attempts `Import::update(status=failed)` and broadcasts `failed`, but **does not call** `invalidateFrontendCaches()`. If failure occurs after partial commits (e.g., 10 of 100 categories succeeded before exception), those commits remain in DB but cache still holds stale `products/categories/brands` → stale API responses until TTL or manual clear.
- **Product/Category/Brand Import (cancelled):** Similar gap — `ImportCancelledException` triggers `rollbackCreatedData()` (only deletes newly created `Category`/`Brand`/`Product` ids, not updates), updates `status=cancelled`, broadcasts `cancelled`, but **no cache invalidation**. Updates to existing entities persist, so stale cache again.
- **Export (all):** No `invalidateFrontendCaches()` and no `api_cache_version` bump (correct semantics). Only `ExportProductsJob` forgets its `product-export:filters:{id}` file cache. Business cache untouched — correct since export is read-only.

## 7. Missing Invalidation

- **Import failure with partial success:** After N rows committed via short transactions, exception → retries exhaust → `status=failed` but cache not invalidated → `GET /api/v1/general/products` still returns pre-import cached data; new rows exist in DB but not visible for up to `api_cache_version` TTL (home cache 120s + apiCache 3600s + tagged caches indefinite until flush).
- **Import cancelled with partial success:** Same — `Import::update(status=cancelled)` after `rollbackCreatedData()` leaves updated (non-created) rows committed; no invalidation → stale.
- **Exports:** No missing invalidation — verified correct to **not** invalidate business cache. If export modified operation metadata cached via `HasCache` under `imports` tag, no such tag exists; operation status is via DB, not cache.

## 8. Failure/Partial-Success Behavior

- **completed:** Full success → DB commits all rows → invalidate required → **currently invalidates iff success>0** (which is true here, so OK).
- **completed_with_errors:** Some rows failed (`$failedRows` non-empty, `$successCount>0`) → DB has committed successes → invalidate required → **currently invalidates** (success>0 guard passes, so OK).
- **failed:** Per specs, `failed` when `successCount==0` (no commits) → no invalidation needed (current no-op is correct). But edge: failure after some commits before exception (e.g., category `upsert` committed 5 categories then image download throws, but `try/catch (Throwable)` outside loop will catch and update `status=failed` without invalidating) → stale. Code audit shows `ImportCategoriesJob::handle()` wraps entire Excel import in try; `CategoryImportService::upsertCategories` commits per category via `Category::create/update` outside global tx, so partial commits survive failure → needs invalidation if `successCount>0` even though final status is `failed`.
- **cancelled:** `isCancelled()` checks `cancel_{id}.json` signal; if cancelled early (before any commit) → `successCount==0` → no invalidation needed; if late (after commits) → needs invalidation. Current code treats same as failed gap.

## 9. Transaction Analysis

- No global `DB::transaction` wrapping the entire import (explicitly disabled via `config(['excel.transactions.handler'=>'null'])` before `Excel::import` in all 3 import jobs — avoids holding transaction across HTTP image downloads). Each entity uses short per-row transactions (`DB::beginTransaction/commit` in `ProductImportService::processProductRow`, implicit single-statement inserts in `Category/BrandImportService`).
- Cache invalidation occurs **after** final atomic `Import::whereIn(['pending','processing','cancelling'])->update(status ...)` which commits the operation status, and **before** `broadcastFileOperationTerminal` (observable in code order: `invalidateFrontendCaches()` then `broadcastFileOperationTerminal`). This is correct: DB committed → cache cleared → Pusher (client reconciles via status endpoint).
- Export: `ExportXxxJob` does `status=processing` update → file `store` (filesystem, not DB) → validation → atomic `status=completed + file_path` → invalidate? (currently no) → broadcast. Correct to not invalidate business cache after DB commit.

## 10. Race Condition Analysis

- **Stale write-back race (T1 read old → T2 import commit → T3 invalidate → T4 T1 writes old into cache):** Exists for `CacheApiResponse` (non-tagged `md5` key with version) because readers compute key without locking. Mitigation: `api_cache_version` increment makes T1's key `api_cache|oldVersion|...` stale; T1's `Cache::put` writes under old version, but next reads use `newVersion` → miss, so stale not served. For `HasCache` tagged caches, `Cache::tags([$tag])->flush()` atomically invalidates tag namespace; T1's `remember` callback reading stale DB before flush will re-populate after flush — but `flush` after commit ensures next `remember` reads fresh DB. Remaining tiny window (read stale between commit and flush) is bounded to milliseconds; acceptable without distributed lock (consistent with existing `Cache::increment` strategy).
- **Concurrent imports on same entity:** `Imports` table uses atomic `whereIn(status)` transitions and `ImportCancelledException` cooperatively; cache invalidation is idempotent (`flush` + `increment`) so concurrent invalidations are safe (extra `Cache::flush()` is idempotent).

## 11. Scout/Meilisearch Interaction

- `config/scout.php`: driver `meilisearch`, `QUEUE= true`, prefix `meem_`, soft-delete enabled. Models (`Product`, `Category`, `Brand`) use `Searchable` trait via marvel? `toSearchableArray`/`searchable()` not called explicitly in import jobs; instead `ProductObserver` etc. are not fired per row due to `saveQuietly()`, so Scout auto-indexing is deferred. No explicit `searchable()`/`unsearchable()` in `Import*Job` or `*ImportService`. Current architecture relies on queueable Scout imports via `shouldBeSearchable` and model observers on normal updates, not bulk import. Therefore cache invalidation ordering is independent of Scout; no `search index → cache` coupling needed now. Do not redesign Scout.

## 12. Recommended Minimal Fix

- **Import jobs:** Ensure `invalidateFrontendCaches()` is called **once, after DB terminal update, before final broadcast**, for **any terminal where `successCount>0` OR database may have partial commits**, including `completed`, `completed_with_errors`, `failed` (when partial), and `cancelled` (when partial). Keep per-row unchanged. Implement shared helper `invalidateCacheIfDataChanged(int $successCount, int $failureCount = 0)` that checks `successCount>0` and handles tagging fallback, `HomeService::clearCache()`, `api_cache_version` increment, exception isolation (log+report, never rethrow to avoid marking operation as failed).
- **Export jobs:** Do **not** clear business caches (`products`/`categories`/`brands`). Keep only existing operation-scoped file cache cleanup (`Cache::store('file')->forget('product-export:filters:{id}')`). Add explicit no-op comment to document read-only semantics.
- **Ordering:** `DB status = terminal` → `invalidateFrontendCaches()` (try/catch) → `broadcastFileOperationTerminal()` (existing Pusher lifecycle preserved).
- **Entity-aware:** Keep existing tag sets per job (Product tags + home + api_version; Category tags; Brand tags) rather than global `Cache::flush()` as first choice; use `Cache::tags()->flush()` with `TaggableStore` check and `Cache::flush()` only as fallback for file/array stores (existing pattern). Do not flush `settings`, `currencies`, auth caches.

## 13. Exact Files to Change

- `packages/marvel/src/Jobs/ImportProductsJob.php` — add `invalidateFrontendCaches()` to failure (`failed`) and cancelled branches when `successCount>0` or `variantSuccess>0`; keep existing success invalidation before terminal broadcast.
- `packages/marvel/src/Jobs/ImportCategoriesJob.php` — same, plus ensure `failed()` hook (retry exhausted) invalidates if partial.
- `packages/marvel/src/Jobs/ImportBrandsJob.php` — same.
- `packages/marvel/src/Jobs/ExportCategoriesJob.php` — add explicit comment/nop for no business invalidation (no tag flush).
- `packages/marvel/src/Jobs/ExportBrandsJob.php` — same.
- `packages/marvel/src/Jobs/ExportProductsJob.php` — keep file-cache forget, add comment that business cache intentionally not invalidated.
- Optional shared trait extraction not needed; keep per-job `invalidateFrontendCaches()` as is (already entity-aware).

## 14. Test Plan

- **Product Import:** `test_product_import_success_invalidates_product_cache`, `test_product_import_completed_with_errors_invalidates`, `test_product_import_failed_with_partial_commits_invalidates`, `test_product_import_failed_zero_success_does_not_invalidate`, `test_product_import_cancelled_with_partial_invalidates`, `test_product_import_does_not_invalidate_per_row`.
- **Category Import:** Same matrix (use `CategoryImportService` stubs, `ImportCategoriesJob::handle()`).
- **Brand Import:** Same matrix.
- **Export:** `test_category_export_does_not_invalidate_business_cache`, `test_brand_export_does_not_invalidate`, `test_product_export_does_not_invalidate_business_cache` (assert `Cache::tags(products)->has` remains, `api_cache_version` unchanged, only `product-export:filters` forgotten).
- **Ordering:** Assert `Import::status` committed → `HomeService::clearCache` called → `FileOperationEvent::CATEGORY_IMPORT_COMPLETED` broadcast (via `RecordingPusher`), using `RefreshDatabase` + `FileOperationBroadcastTestCase` harness.

## 15. Production Verification Plan

1. Seed tagged caches: `Cache::tags(['products'])->put('probe','stale',3600)`, `Cache::tags(['categories'])->put('probe','stale')`, `Cache::tags(['brands'])->put('probe','stale')`, `Cache::put($channel.':home-nav-bar','stale',3600)`, `Cache::get('api_cache_version')`.
2. **Product Import** small file (5 rows mix valid/invalid → `completed_with_errors`): after `POST /api/v1/products/import` → `Job` → verify DB `success_rows=3, failed=2`, `Cache::tags(products)->has('probe')===false`, `home-nav-bar` forgotten, `api_cache_version` incremented, Pusher `product.import.completed` with `has_errors=true`, `GET /api/v1/general/products` returns new products.
3. **Category Import** `completed` → tagged `categories`/`products` probes gone, home cleared.
4. **Brand Import** `completed` → `brands`/`brands_products` probes gone.
5. **Failed Import** (file not found → `status=failed`, `success=0`) → probes **remain** (no invalidation, correct).
6. **Failed Import with partial** (simulate exception after 2 successes): run job with stubbed `CategoriesImport` throwing after 2 commits → `status=failed` but `success=2` → probes invalidated.
7. **Cancelled Import** with `cancel_{id}.json` mid-way → `status=cancelled`, `success>0` → invalidated; early cancel `success=0` → not.
8. **Exports:** Run Category/Brand/Product exports → verify `Cache::tags(products)->has('probe')===true` (not flushed), `api_cache_version` unchanged; only `product-export:filters:{id}` forgotten after product export; Pusher `*.export.completed` with `download_available=true` and download works.
9. Check `failed_jobs` empty for these ops; logs contain no `Broadcasting` swallowing.

