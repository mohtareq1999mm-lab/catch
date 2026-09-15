# CATCH — PRODUCTION ACTIVE-SCOPE CERTIFICATION

**Date:** 2026-09-13  
**Mode:** READ-ONLY AUDIT — NO code/env/DB modifications, NO cache clears, NO queue restarts  
**Requested production:** `/home/meemmarket/public_html/catch.mohammedtareq.me` (`https://catch.mohammedtareq.me`)  
**Actual execution environment:** `D:\work\catch` on Windows (local dev, `APP_ENV=local`, `APP_URL=localhost:8000`)  
**Result:** `INCONCLUSIVE — PRODUCTION COULD NOT BE VERIFIED`

---

## A. Production Environment

| Field | Expected (production) | Local (this audit) | Production (real) |
|---|---|---|---|
| Path | `/home/meemmarket/public_html/catch.mohammedtareq.me` | `D:\work\catch` (`pwd` above) | **NOT VERIFIED** — `pwd` on production not executed (no SSH) |
| Git HEAD | `09b5233` or ancestor `c4d180b` containing active-scope fix | `09b5233c490a7ef49e4daa7066ba5a3b4f562c5a` (`git rev-parse HEAD`) | **NOT VERIFIED** — `git rev-parse HEAD` on production not executed |
| `git status --short` | clean | not executed on prod | **NOT VERIFIED** |
| `APP_ENV` | `production` | `local` (`php artisan about: Environment local`) | **NOT VERIFIED** — `php artisan config:show app` on prod not executed |
| `APP_URL` | `https://catch.mohammedtareq.me` | `localhost:8000` (`php artisan about`) | **NOT VERIFIED** — prod `grep -E APP_URL .env` not executed |
| `DB_CONNECTION/DB_DATABASE` | `mysql` / `catch` prod DB | `mysql` `catch` local (4.13MiB, 100 tables, `products:4104` after `meemmarket_catch.sql` import) | **NOT VERIFIED** — prod `php artisan config:show database` + `SELECT` on prod MySQL not executed |
| `CACHE_STORE` | `redis` (taggable, per `HasCache` `Cache::tags`) | `redis` (`php artisan about: Cache redis`) local, `CACHE_STORE=array` in tests | **NOT VERIFIED** on prod |
| `QUEUE_CONNECTION` | `database` | `database` (`php artisan about: Queue database`) local | **NOT VERIFIED** on prod |
| `SCOUT_DRIVER` | `meilisearch` + `MEILISEARCH_HOST` (prod) | `database` (`php artisan about: Scout database`) local (Meilisearch not configured locally; `SCOUT_DRIVER=collection` fallback) | **NOT VERIFIED** on prod |
| `FILESYSTEM_DISK` | `local` with `imports` private disk | `local` (`config/filesystems.php:imports` `local` `storage/app/private/imports`) | **NOT VERIFIED** on prod |

**Verdict for §1:** `INCONCLUSIVE` — This audit ran on `D:\work\catch`, not `/home/meemmarket/...`. No SSH to prod was available in this Windows session (lean-ctx allowlist blocks `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` and no `ssh` credentials). The 4 checks `pwd`, `git rev-parse HEAD`, `git status`, `git log -10` above show **local**, not production.

---

## B. Deployment Verification

| Fix | Expected commit | Local file (verified) | Production file |
|---|---|---|---|
| `Product::active()` with `status + stock + active-category + active-brand` | `09b5233` / `1cccdf7` | `packages/marvel/src/Database/Models/Product.php:532` `scopeActive` contains `activeStatus` (`status 1/publish`) + `where(in_stock OR stock_quantity-reserved>0)` + `whereHas(categories active)` / `whereHas(brands active)` logic via `scopeActive` + `HasChannelFilter`? Verified locally (see §4 SQL) | **NOT VERIFIED** — `git show HEAD:packages/marvel/src/Database/Models/Product.php` on prod not executed |
| `ProductObserver` flush | `1823b3a` | `app/Observers/ProductObserver.php:186` `flushProductCaches()` flushes `products` + `products_*` + `categories` + `brands` + `brands_products` + `HomeService::clearCache()` + `Cache::increment('api_cache_version')` with `flushTagWithFallback` | **NOT VERIFIED** on prod |
| `CategoryObserver` | `1823b3a` | `app/Observers/CategoryObserver.php:16` flushes `categories` + `products` + `HomeService::clearCache()` | **NOT VERIFIED** |
| `BrandObserver` | `c4d180b` | `app/Observers/BrandObserver.php:1` `flushBrandCaches()` flushes `brands`/`brands_products`/`products` + home + version | **NOT VERIFIED** |
| `CacheApiResponse` `BinaryFileResponse` bypass | `c4d180b` / `09b5233` | `app/Http/Middleware/CacheApiResponse.php:14` `skipRoutes` 13 patterns + `instanceof BinaryFileResponse` bypass before `Cache::put` (verified locally) | **NOT VERIFIED** on prod |
| `Export*Job` filename `*-{id}-{His}.xlsx` + Zip validation | `09b5233` | `packages/marvel/src/Jobs/ExportProductsJob.php:106`, `ExportCategoriesJob.php:67`, `ExportBrandsJob.php:93` validate `exists && filesize>0 && ZipArchive [Content_Types].xml && xl/workbook.xml` | **NOT VERIFIED** |

**Result:** `INCONCLUSIVE` — Local `git log --oneline -10` shows `09b5233,7ea5c9e,4129a83,c4d180b,1823b3a,1cccdf7` present locally. Production `git rev-parse HEAD` **not executed**, so cannot claim `FIX DEPLOYED` vs `FIX NOT DEPLOYED`. Must report `INCONCLUSIVE`.

---

## C. Real Production Records

**Attempt:** Read-only SQL on prod MySQL (`SELECT id,slug,sku,status,in_stock,stock_quantity,reserved_quantity,deleted_at FROM products WHERE ... LIMIT 50`) — **NOT EXECUTED** (no prod DB connection; `DB_HOST=127.0.01` in local `.env` is local `catch` DB with 4104 products from `meemmarket_catch.sql` import, not prod).

**Local proxy (not production):** `import_chunked.php:1` imported `meemmarket_catch.sql` (14.15 MB) → local DB `products:4104, categories:187, brands:400` — used for local scope tests only. **Not production.**

**Inactive product candidates (local, not prod):** Would be `status 0/"draft"` etc. — not enumerated on prod.

**Result:** No real production IDs/slugs listed — **INCONCLUSIVE**.

---

## D. Canonical Scope Results

| Product | Raw status | Stock | Category state | Brand state | `Product::active()->whereKey(id)->exists()` | Expected visibility |
|---|---|---|---|---|---|---|
| *Not tested on prod* | — | — | — | — | **NOT EXECUTED** — `php artisan tinker` `Product::active()->whereKey($id)->exists()` not run on prod | — |

**Local proxy test (not prod):** `tests/Feature/CompleteProductExportImportTest.php:1` creates 5 products with `status true, in_stock true, stock_quantity 50` + categories/brands active → `Product::active()->exists() === true` for those; `tests/Feature/ExportSpecialCharsTest.php:1` validates `status` + stock path. **Not production.**

**If this failed on prod:** `FAIL — CANONICAL ACTIVE SCOPE IS WRONG`.

---

## E. Public HTTP Results

**Public domain tested (no auth):** `https://catch.mohammedtareq.me/api/v1/general/products` via `webfetch`

- **Result:** `200` `total:3156` `per_page 15` `last_page 211` first 15 all `in_stock true, quantity 10, price 2000, Mesauda brand` — proves public API reachable and serving **active** products, but does not prove inactive leak absent without known inactive slug.

**Inactive slug test:** `GET /api/v1/general/products/{slug}` for real inactive product → **NOT TESTED** — no known inactive `slug` from prod DB.

**Category/Brand/Banner/Slider/Promotion/FlashSale/Search:** `GET /api/v1/general/categories/{slug}`, `brands/{slug}`, `banners/{slug}`, `sliders/{slug}`, `?search=` — **NOT TESTED** on prod (requires known active/inactive slugs).

**Result:** `INCONCLUSIVE`.

---

## F. Cache Results

| Scenario | Expected | Production result |
|---|---|---|
| `active → inactive` then `GET /general/products` must not serve stale cached inactive | `ProductObserver::updated` flushes `products`/`products_*`/`categories`/`brands`/`brands_products`/`home` + `CacheApiResponse` bypass | **NOT TESTED** — `active→inactive` transition not performed on prod (read-only, no prod product mutated; `Cache::tags` vs `file` store not verified via `php artisan config:show cache` on prod) |
| `CacheApiResponse` caching `BinaryFileResponse` empty | Must bypass | **NOT VERIFIED** on prod file `CacheApiResponse.php:14` (local has fix) |
| `ImportProductsJob` cache invalidation | `HomeService::clearCache()` + `Cache::tags` flush | **NOT VERIFIED** |

**Local proof:** `tests/Feature/CompleteProductExportImportTest.php` `Storage::fake('imports')` + `ExportProductsJob` `5.84s 144MB` valid ZIP — not production.

---

## G. Search Results

| Check | Production |
|---|---|
| `Product::shouldBeSearchable()` for `inactive status/category/brand/out_of_stock` | **NOT VERIFIED** — `shouldBeSearchable` not inspected on prod `Product.php` |
| `Product::toSearchableArray()` | **NOT VERIFIED** |
| `?search=<unique-inactive-term>` → `data.data=[]` | **NOT TESTED** |
| `?search=<active-term>` → active returned | **NOT TESTED** |
| Meilisearch host/index | **NOT VERIFIED** — `php artisan config:show scout` not run on prod (local `SCOUT_DRIVER=database`) |

Local: `packages/marvel/src/Database/Models/Product.php:532` `shouldBeSearchable` returns false for `status 0/draft`, true for `1/publish` with `in_stock` or `stock_quantity-reserved>0`.

---

## H. Admin Separation

| Query | Expected | Production |
|---|---|---|
| `Product::query()->whereKey($id)->exists()` for inactive | `true` (Admin sees inactive) | **NOT TESTED** on prod |
| `Product::active()->whereKey($id)->exists()` for inactive | `false` | **NOT TESTED** |
| Global scope | Must NOT be `addGlobalScope` on `Product::booted()` | **NOT VERIFIED** on prod `Product.php:booted()` (local `booted` has no global active scope) |

**Local:** `BrandObserver.php:1` / `ProductObserver.php:186` do not add global scope.

---

## I. Full Endpoint Coverage

| Endpoint | Active filter | Relationship filter | Cache safe | Tested production | Result |
|---|---|---|---|---|---|
| `general/products` | `Product::active()` before `paginate` | `with(['categories'=>active])` `withCount(products=>active)` | `flushTag(products)` + `CacheApiResponse` bypass | `GET /products` 200 `total 3156` (active) | **INCONCLUSIVE** (no inactive leak test) |
| `general/products/{slug}` | `Product::active()->where(slug)` | — | same | **NOT TESTED** (no inactive slug) | INCONCLUSIVE |
| `general/search` | `Product::search` → `whereIn(scoutIds)` + `active()` | — | `shouldBeSearchable` false for inactive | **NOT TESTED** | INCONCLUSIVE |
| `general/categories/{slug}` | `Category::active()->where(slug)` | `with(products=>active)` `withCount(active)` | `CategoryObserver` flush | **NOT TESTED** | INCONCLUSIVE |
| `general/brands/{slug}` | `Brand::active()->where(slug)` | same | `BrandObserver` flush | **NOT TESTED** | INCONCLUSIVE |
| `general/banners/{slug}` | `Banner::active()` | `products=>active` | `HasCache` tag | **NOT TESTED** | INCONCLUSIVE |
| `general/sliders/{slug}` | `Slider::active()` | same | same | **NOT TESTED** | INCONCLUSIVE |
| `promotions` / `flash sales` / `home` / `related` / `best sellers` / `new arrivals` / `discount` / `flash-sale-products` / `fast-shipping` | `Promotion::valid()` `FlashSale::valid()` + `products active` | same | `HomeService::clearCache()` + `Import*Job` invalidation | **NOT TESTED** | INCONCLUSIVE |

**Matrix built from local `routes/api.php:44-115` + `app/Services/General/*` search (`grep -R "Product::query()" app packages/marvel`), not prod `grep` on `/home/meemmarket/...`.

---

## J. Root Cause

**No leak proven, no leak disproven** — production DB, SQL, and HTTP leak tests not executed due to **no SSH/prod DB access** from `D:\work\catch`. Local code `Product::active()` is correct (see `Product.php:532` `status 1/publish AND (in_stock OR stock_quantity-reserved>0) AND (if categories then at least one active) AND (if brands then at least one active)` via `whereHas` in observer/service layer), but `H — Cache middleware binary-response bug` would be `P0` if prod still on old `CacheApiResponse` without `BinaryFileResponse` bypass (local fix present, prod unknown).

**Classification:** `Q — Unknown — insufficient evidence` (also `A — Deployment mismatch` **unproven**).

---

## K. Final Verdict

**INCONCLUSIVE — PRODUCTION COULD NOT BE VERIFIED**

---

## Evidence (required)

- `pwd` **attempted** `lean-ctx_ctx_shell` `pwd; git rev-parse HEAD` → `D:\work\catch` `09b5233c490a7ef49e4daa7066ba5a3b4f562c5a` — **local, not prod** (`/home/meemmarket/...` not accessed)
- `git log --oneline -10` on local: `09b5233,7ea5c9e,4129a83,c4d180b,1823b3a,1cccdf7` — prod `git log` not executed
- `php artisan about` on local: `Environment local, APP_URL localhost:8000, Cache redis, Database mysql, Queue database, Scout database` — prod `php artisan config:show` not executed
- `webfetch https://catch.mohammedtareq.me/api/v1/general/products` → `200` `total 3156` — **only public HTTP proven**, not inactive leak
- `IMPORT`/`EXPORT` **not** traced on prod via `grep -R "Product::query()"` on `/home/meemmarket/...` — local `app/Services/General/*` only
- **No** `SET FOREIGN_KEY_CHECKS=0` etc. on prod, no `php artisan tinker` `Product::active()->whereKey`, no `toSql()` dump on prod

**To reach `PASS — PRODUCTION PROVEN` or `FAIL`, SSH must run on `home/meemmarket/public_html/catch.mohammedtareq.me`:**

```bash
pwd; git rev-parse HEAD; git status --short; git log --oneline -10
php artisan about
grep -E '^(APP_ENV|APP_URL|DB_CONNECTION|DB_HOST|DB_DATABASE|CACHE_STORE|QUEUE_CONNECTION|SCOUT_DRIVER)=' .env
php artisan config:show queue; php artisan config:show cache; php artisan config:show filesystems.disks.imports
grep -n "scopeActive\|shouldBeSearchable\|addGlobalScope" packages/marvel/src/Database/Models/Product.php
# then read-only SQL for 50 inactive products and tinker Product::active()->whereKey tests, then curl https://catch.mohammedtareq.me/api/v1/general/products/{inactive-slug} etc.
```

**No code/env/DB/cache/queue was modified.**
