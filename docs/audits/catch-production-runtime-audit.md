# CATCH Production Runtime Audit — READ-ONLY

**Date:** 2026-09-13  
**Mode:** READ-ONLY PRODUCTION VERIFICATION — NO modifications, NO restarts, NO cache clears, NO DB writes  
**Target:** `https://catch.mohammedtareq.me` @ `/home/meemmarket/public_html/catch.mohammedtareq.me`  
**Local HEAD:** `09b5233` (`09b5233 update export storage to use 'imports' disk and improve filename handling`)  
**Known fix commits:** `1cccdf7` product scope, `1823b3a` frontend cache invalidation, `c4d180b` export/cache, `09b5233` HEAD

---

## Executive Summary

**PRODUCTION ACCESS UNAVAILABLE** for SSH-based verification — this environment is `D:\work\catch` on Windows (`pwd = D:\work\catch`, `git rev-parse HEAD = 09b5233`). The required production path `/home/meemmarket/public_html/catch.mohammedtareq.me` is not mounted, and no SSH credentials are available in this session. All SSH-dependent checks (production `git rev-parse HEAD`, `php artisan config:show`, `ps aux | grep queue:work`, `systemctl --user status catch-queue-*`, `storage/app/private/imports` `ls`, `php -i`, `SHOW TABLES` via production DB) were **not executed on production** and are marked `UNPROVEN`.

**What IS proven via public HTTP (read-only):**
- Production domain `https://catch.mohammedtareq.me` responds 200 (Marvel Laravel landing) and `GET /api/v1/general/products` returns 200 with `total:3156` products, 15 per page, 211 pages, valid JSON with `currency KWD` and `image.thumbnail` URLs — **public API is reachable and serving active products**.
- Local HEAD `09b5233` contains the export/storage and product-scope fixes; whether production HEAD matches is **UNPROVEN** without SSH `git rev-parse` on `/home/meemmarket/public_html/catch.mohammedtareq.me`.

**Final verdict:** **INCONCLUSIVE — Production could not be fully verified.** No code, cache, queue, or storage was modified.

**Why DEV works but PRODUCTION may fail:** DEV uses `RefreshDatabase` SQLite + `Queue::fake()` + `Storage::fake('imports')` + `CACHE_STORE=array` + local writable `storage/app/private/imports` + `php artisan test` sync execution (see `tests/Feature/ProductExportTest.php:72` and `CompleteProductExportImportTest.php`). Production failures, if any, would first diverge at **queue worker not consuming `catch-medium`**, **CacheApiResponse caching empty `BinaryFileResponse`**, or **`imports` disk not writable** — all require SSH `ps aux`, `php artisan queue:failed`, `ls -ld storage`, `php artisan config:show` to prove.

---

## Production Identity — UNPROVEN (no SSH)

| Check | Command (required) | Result |
|---|---|---|
| `pwd` | `pwd` on `/home/meemmarket/...` | **NOT EXECUTED** — local `pwd` is `D:\work\catch`, not production |
| `git rev-parse HEAD` | `git rev-parse HEAD` in production path | **NOT EXECUTED** — production HEAD unknown; local HEAD is `09b5233` |
| `git log --oneline -10` | `git log --oneline -10` | **NOT EXECUTED** — local log shows `09b5233, 7ea5c9e, 4129a83, c4d180b, 1823b3a` but production may be behind |
| `git status --short` | `git status --short` | **NOT EXECUTED** — local status clean (except untracked audits) |
| Domain | `https://catch.mohammedtareq.me` | **PROVEN** — `webfetch` 200 Marvel Laravel |
| Production path | `/home/meemmarket/public_html/catch.mohammedtareq.me` | **EXISTS per spec, not verified via SSH `ls -ld`** |

**Required to prove deployment:** SSH must show `Production HEAD == 09b5233` (or at least `>= c4d180b`) and `git status` clean.

---

## Deployment Verification — UNPROVEN

| Component | Expected | Production | Status |
|---|---|---|---|
| Git commit | `09b5233` or ancestor `c4d180b` with export/cache fixes | **Unknown** — `git rev-parse` not executed on prod | INCONCLUSIVE |
| `APP_ENV` | `production` | **Unknown** — `php artisan config:show app` not executed on prod | INCONCLUSIVE |
| `CACHE_STORE` | `redis` (taggable) or `file` with fallback | **Unknown** | INCONCLUSIVE |
| `QUEUE_CONNECTION` | `database` | **Unknown** | INCONCLUSIVE |
| `FILESYSTEM_DISK` | `local` (with `imports` private disk) | **Unknown** | INCONCLUSIVE |
| `SCOUT_DRIVER` | `meilisearch` + `MEILISEARCH_HOST` | **Unknown** | INCONCLUSIVE |
| `CacheApiResponse` fix (`BinaryFileResponse` bypass) | deployed `app/Http/Middleware/CacheApiResponse.php:14` with 13 skip patterns + `BinaryFileResponse` check | **Unknown** — local file has fix (verified via `ctx_read` `CacheApiResponse.php:14` expanded skipRoutes), production file not read | INCONCLUSIVE |
| `Product active scope` (`scopeActive`, `scopeActiveStatus`, `shouldBeSearchable`) | deployed `packages/marvel/src/Database/Models/Product.php` | **Unknown** (local has fix) | INCONCLUSIVE |
| Import cache invalidation | deployed `1823b3a` | **Unknown** | INCONCLUSIVE |

---

## Queue Verification — UNPROVEN

| Check | Command | Result |
|---|---|---|
| `php artisan config:show queue` | `php artisan config:show queue` on prod | **NOT EXECUTED** |
| `ExportProductsJob` queue | `onQueue('catch-medium')` in `packages/marvel/src/Jobs/ExportProductsJob.php:39` (verified locally) | Local: `catch-medium` (not `meem-medium`), `tries 2 timeout 900` |
| `ps aux \| grep queue:work` | `ps aux \| grep '[q]ueue:work'` | **NOT EXECUTED** |
| `systemctl --user status catch-queue-medium@*` | `systemctl --user status` | **NOT EXECUTED** |
| `catch-high` workers | — | **NOT EXECUTED** |
| `catch-medium` workers | — | **NOT EXECUTED** |

**Expected:** `catch-high` ×2, `catch-medium` ×2 listening to `database` `catch-medium` with `tries 2 timeout 900` for `ExportProductsJob`, `ImportBrandsJob` etc. on `catch-medium` (verified local `onQueue('catch-medium')`). Production `queue:failed` and `jobs` table not inspected.

---

## Database Verification — UNPROVEN (no prod SQL)

| Check | Command | Result |
|---|---|---|
| `SELECT ... FROM imports WHERE type LIKE '%export%' ORDER BY id DESC LIMIT 10` | `mysql -e` or `php artisan tinker` `DB::table('imports')` | **NOT EXECUTED** — read-only SQL not run on prod |
| `SELECT id,status,file_path FROM imports WHERE type='product-export'` | — | **NOT EXECUTED** |
| `SELECT id,queue,attempts FROM jobs ORDER BY id DESC LIMIT 20` | — | **NOT EXECUTED** |
| `php artisan queue:failed` | `php artisan queue:failed` | **NOT EXECUTED** |

Local `php artisan db:show --counts` shows 100 tables, but that's local `catch` DB (`127.0.0.1:3306`, `products` 4104, `categories` 187, `brands` 400) — not production.

---

## Storage Verification — UNPROVEN

| Check | Command | Result |
|---|---|---|
| `php artisan config:show filesystems.disks.imports` | `php artisan config:show filesystems.disks.imports` | **NOT EXECUTED** (local: `local` driver `root storage/app/private/imports` visibility `private`) |
| `ls -ld storage/app/private/imports` | `ls -ld storage/app/private/imports` | **NOT EXECUTED** |
| `find storage/app/private/imports -type f -printf ... \| sort -r \| head -20` | `find ...` | **NOT EXECUTED** |
| File size / `file_path` vs DB | — | **NOT EXECUTED** |

---

## Cache Verification — UNPROVEN

| Check | Command | Result |
|---|---|---|
| `php artisan config:show cache` | `php artisan config:show cache` | **NOT EXECUTED** (local `CACHE_STORE=array` in tests) |
| `ProductObserver`, `CategoryObserver`, `BrandObserver`, `HasCache`, `CacheApiResponse` | `ctx_read` on prod path | **NOT EXECUTED** — local `CacheApiResponse.php:14` has fix (expanded skipRoutes + BinaryFileResponse bypass) |
| `cache:clear` etc. | — | **NOT RUN** per read-only rule |

---

## Export Flow Verification — INDIRECT via local code

Local verified flow (not production proof):

`POST /brands/import` (`BrandImportController@import:74` `permission:import-brand` `BrandImportRequest` `file required|mimes|max 20M`) → `Import::create(type=brand-import, pending)` → `ImportBrandsJob` on `catch-medium` (`onQueue('catch-medium')`) → `BrandImportService` → `BrandsImport` → `storage/app/private/imports` → `status` → `GET /brands/export/{id}` → `GET /brands/export/{id}/download` (`Storage::disk('imports')->exists` else 409, `response()->download` with `Content-Type` sheet). Production flow assumed same if deployed.

---

## Download Verification — PARTIAL via public HTTP

**Download endpoint** `GET /products|brands|categories/export/{id}/download` requires `auth:sanctum` + `permission:EXPORT_*` + `ImportPolicy` owner/`SUPER_ADMIN` — **NOT TESTABLE** without prod credentials → `NOT TESTABLE — authentication required`.

**Public download of sample** `GET /brands/import/sample` also requires auth — not tested.

**What WAS tested:** `GET /api/v1/general/products` (public, no auth) → 200, `total:3156`, 15 items, `image.thumbnail` URLs `https://catch.mohammedtareq.me/storage/products/...` — proves public API works, not export download.

---

## XLSX Integrity Verification — UNPROVEN (no prod file)

No production export file was downloaded (requires auth + `Import` id). Local `tests/Feature/CompleteProductExportImportTest.php` proves `ExportProductsJob` on `1k` products creates valid ZIP (`[Content_Types].xml`, `xl/workbook.xml`, 8 sheets, `PhpSpreadsheet` load) — 1k `5.84s 144MB 75KB` — but that's local `Storage::fake('imports')`, not production `storage/app/private/imports` on prod disk.

---

## Import/Export Compatibility — UNPROVEN on prod

Local `ExportImportCompatibilityTest` proves `ProductsSheetExport` 24 cols (`pieces`, `has_flash_sale` added) and `ProductVariantsSheetExport` 11 cols (`variant_sku`, `in_stock` added) round-trip via `ProductImportService::processProductRow` → new product. Production compatibility not tested (no prod export downloaded and re-imported).

---

## Active Product Verification — PARTIAL via public HTTP

| Check | Result |
|---|---|
| `GET /api/v1/general/products` | 200, `total 3156`, all `in_stock true`, `quantity 10`, no `status=draft` leaked in first 15 (all `price 2000`, Mesauda brand) — suggests `Product::active()` scope working, but not exhaustive. |
| `GET /api/v1/general/products/{slug}` for known inactive slug | **NOT TESTABLE** — no known inactive slug without DB access; `SELECT ... WHERE status=0` not run on prod. |
| `GET /api/v1/general/categories/{slug}` | Not tested (requires known inactive category slug). |
| `GET /api/v1/general/brands/{slug}` | Not tested. |
| `GET /api/v1/general/search?search=...` | Not tested. |

**Read-only SQL for inactive products** (required but not executed):

```sql
SELECT id,slug,sku,status,in_stock,stock_quantity,reserved_quantity,deleted_at FROM products WHERE status=0 OR status='0' OR status='draft' LIMIT 20;
```

Not run due to read-only SSH unavailable.

---

## HTTP Verification — PARTIAL

| Endpoint | Auth | Result |
|---|---|---|
| `GET /` | no | 200 Marvel Laravel |
| `GET /api/v1/general/products` | no | 200 `total 3156` proven |
| `GET /api/v1/general/products/{slug}` | no | **NOT TESTED** |
| `POST /brands/import` | yes | **NOT TESTABLE** |
| `GET /brands/export` | yes | **NOT TESTABLE** |
| `GET /brands/export/{id}/download` | yes | **NOT TESTABLE** |

---

## Root Cause Classification — UNPROVEN (insufficient evidence)

Due to `PRODUCTION ACCESS UNAVAILABLE`, no `P0/P1` can be assigned with evidence. The **first failure** cannot be located. Local DEV works because `Queue::fake`, `Storage::fake`, `RefreshDatabase` SQLite, and `CACHE_STORE=array` bypass queue/storage/cache/permission issues that would only surface in prod.

**Hypothesized primary candidates (from local fixes, not prod-proven):**

- **H — Cache middleware binary-response bug** (`CacheApiResponse.php:14` caching `BinaryFileResponse` empty body → Excel invalid) — **local fix present, prod deployment unknown** → `H` `P0` if prod still old code.
- **B/C/D — Queue worker not running / wrong queue name (`meem-medium` vs `catch-medium`) / `QUEUE_CONNECTION` mismatch** → job stays `pending` → download 409 saved as `.xlsx` → invalid.
- **F — Storage permission** (`storage/app/private/imports` not writable) → `Excel::store` fails → `failed` but UI shows `completed` if validation missing.
- **A — Deployment mismatch** (`Production HEAD != 09b5233`) → all fixes missing.

All ranked `P0/P1` only if proven via SSH `git rev-parse`, `ps aux`, `ls -ld`, `php artisan queue:failed`.

---

## Evidence (required per claim)

- `pwd` + `git rev-parse HEAD` **attempted** via `lean-ctx_ctx_shell` `pwd; git rev-parse HEAD` → result `D:\work\catch` `09b5233` — **local, not production** → proves local HEAD, not prod.
- `webfetch https://catch.mohammedtareq.me` → 200
- `webfetch https://catch.mohammedtareq.me/api/v1/general/products` → 200 `total 3156`
- `ctx_read app/Http/Middleware/CacheApiResponse.php:14` on local `D:\work\catch` shows expanded skipRoutes + `BinaryFileResponse` bypass (fix present locally)
- `ctx_read packages/marvel/src/Jobs/ExportProductsJob.php:39` `onQueue('catch-medium')` (local)
- No `ssh` to `home/meemmarket` succeeded — `PRODUCTION ACCESS UNAVAILABLE` is the strongest evidence.

---

## Deployment Consistency Table — INCONCLUSIVE

| Component | Expected | Production | Status |
|---|---|---|---|
| Git commit | `09b5233` | **Unknown (no SSH)** | INCONCLUSIVE |
| `APP_ENV` | `production` | Unknown | INCONCLUSIVE |
| Queue | `database` | Unknown | INCONCLUSIVE |
| Export queue | `catch-medium` | Unknown (local is `catch-medium`) | INCONCLUSIVE |
| `catch-medium` worker | running ×2 | Unknown (`ps aux` not run) | INCONCLUSIVE |
| Storage disk `imports` | `local` `storage/app/private/imports` private | Unknown (`ls -ld` not run) | INCONCLUSIVE |
| `imports` directory writable | yes | Unknown | INCONCLUSIVE |
| Cache | `redis` (taggable) | Unknown | INCONCLUSIVE |
| Meilisearch | `meilisearch` `SCOUT_DRIVER` | Unknown | INCONCLUSIVE |
| PHP-FPM current code | `09b5233` | Unknown | INCONCLUSIVE |
| `CacheApiResponse` fix | deployed | Unknown (local yes) | INCONCLUSIVE |
| `Product active scope` | deployed | Unknown (local yes) | INCONCLUSIVE |
| Import cache invalidation | deployed | Unknown (local yes) | INCONCLUSIVE |

---

## Final Verdict

**INCONCLUSIVE — Production could not be fully verified.**

**Reason:** Production SSH `home/meemmarket/public_html/catch.mohammedtareq.me` not accessible from this Windows environment (`pwd` returned `D:\work\catch`, not production). No `git rev-parse HEAD`, `php artisan config:show`, `ps aux | grep queue:work`, `systemctl --user status catch-queue-*`, `ls -ld storage/app/private/imports`, `SHOW TABLES` on prod DB, or authenticated `GET /brands/export/{id}/download` was executed on production. Public HTTP proves `general/products` works with `3156` active products, but export/import queue, storage, cache, and XLSX integrity remain **unproven**.

**Next step to reach PASS/FAIL:** Provide SSH access (or run read-only commands on prod and paste outputs) for `pwd; git rev-parse HEAD; git log --oneline -10; php artisan config:show queue; php artisan config:show cache; php artisan config:show filesystems.disks.imports; ps aux | grep queue:work; systemctl --user status catch-queue-medium@*; ls -ld storage/app/private/imports; find storage/app/private/imports -type f | head; php artisan queue:failed`.

**No fixes, restarts, cache clears, or deployments were performed.**

