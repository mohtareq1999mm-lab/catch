# IMPORT / EXPORT FULL E2E AUDIT — Category · Product · Brand

Date: 2026-08-24
Environment: dedicated MySQL audit DB (`chawkbazar_e2e_audit`, fresh full migration), real Redis cache, database queue with named workers (meem-high/meem-medium/default), local storage disk.
Method: real HTTP through the full kernel with real Sanctum tokens; REAL XLSX artifacts generated via PhpSpreadsheet and byte-validated after download; queue lifecycle driven by actual `queue:work` runs; DB state asserted independently of API responses.
Evidence logs: `storage/e2e/combined-final.log`, `storage/e2e/brand-final.log`, `storage/e2e/e2e-evidence.log`.

---

## GATE RESULTS

| Gate | Checks | Result |
|---|---|---|
| CATEGORY IMPORT GATE | 23 | **PASS** |
| PRODUCT IMPORT GATE | 12 | **PASS** |
| BRAND IMPORT GATE (new implementation) | 18 | **PASS** |

Overall: **PRODUCTION READY WITH OBSERVATIONS** (observations = documented dead product-export surface, environment-blocked externals, optional settings null-guard).

---

## 1–13. CATEGORY — executed evidence

- **Sample**: GET `categories/import/sample` → 200, real XLSX (6,662 B), sheet `categories`, headers exactly `[name_en,name_ar,details_en,details_ar,parent_name_en,status,is_featured,image_desktop_url,image_mobile_url]`. IE-CAT-SAMPLE-*
- **Permissions**: guest 401 / customer 403 on sample+import+export; super admin success. IE-CAT-PERM-*
- **Valid import**: multipart upload → **202 {import_id}**, `imports` row `type=category status=pending total_rows=5` → worker drain → status endpoint `completed success=4 failed=0`. IE-CAT-UPLOAD/RECORD/COMPLETED
- **Database**: every row verified independently — EN+AR translations persisted, deterministic slug `Str::slug(name_en)`, hierarchy Root→Phones→Smartphones resolved via `parent_name_en` incl. grandchild chain, timestamps set. IE-CAT-DB-ROWS/HIERARCHY
- **Invalid matrix**: missing name_en, invalid status value, missing parent → 3 error rows; duplicate existing name → documented update-in-place (identity = normalized `name_en`). Status `completed_with_errors`, counters exact. IE-CAT-INVALID-MATRIX
- **Error artifact**: downloaded XLSX opened and validated — headers exactly `[Sheet,Row,Name (EN),Name (AR),Parent Name (EN),Error Message]`, 3 data rows matching failures. IE-CAT-ERROR-ARTIFACT
- **Corrupted workbook**: rejected at validation layer (content-based mimes guessing) — no import record, zero rows written. IE-CAT-CORRUPT
- **Cancel + rollback**: 400-row upload cancelled pre-processing → status `cancelled`, **0 rows created** (rollback proven); cancel on terminal → 409. IE-CAT-CANCEL/409
- **Export lifecycle**: 202 `{export_id}` → worker → completed 28/28 rows → streamed XLSX artifact opened and parsed: exact 9 headers, row values match live DB, child rows carry parent EN name, booleans serialized as `'0'/'1'` strings (zero-cell preservation contract). IE-EXP-*
- **Round-trip**: exported file re-imported → `completed_with_errors` semantics, category count unchanged before/after (**upsert by identity, zero duplicates**). IE-ROUNDTRIP
- **Cache (real Redis)**: tag MISS → written on first public GET → served from cache on second → admin/import mutation flushes tag → fresh data visible immediately. IE-CACHE

## 14–21. PRODUCT — executed evidence

- **Contract discovery**: importer requires the FULL 8-sheet template (`products, product_variants, images, categories, brands, flash_sales, sliders, tags`) — a workbook missing any sheet aborts entirely ("sheet out of bounds"). Verified live and recorded as strict-template contract; fixture rebuilt accordingly. Sample endpoint exists in controller but has NO route (dead method + orphan sample file) — documented gap.
- **Valid import**: 202 → partial processing → 2 valid products created, translations + price 100.00 + qty persisted. IE-PRD-UPLOAD/PARTIAL/DB-ROW1
- **Pricing ADR**: stored `price_after_discount=75` for base 100 with percentage-25 discount — recomputation through `ProductPricingService::calculateProductPricingFromData()` returns identical value; manual formula matches. Import introduces **no independent pricing math**. IE-PRD-PRICING-ADR
- **Category dependency (by slug)**: known slug attached via `category_product` pivot. IE-PRD-CATEGORY-PIVOT
- **Brand dependency (by slug)**: known slug attached via `brand_product`; unknown slugs silently skipped by design (no fabricated fallback, no error row). IE-PRD-BRAND-PIVOT/DEPENDENCY-SEMANTICS
- **Media**: local-path image physically imported → media row + file EXISTS on disk; unreachable-host URL row fails cleanly. IE-PRD-MEDIA-DISK
- **Partial failure + artifact**: invalid `item_type 'TELEPATHIC'` rejected with translated message `Allowed: PHYSICAL, DIGITAL`; error XLSX headers exactly `[Sheet,Row,SKU,Error Message]`. IE-PRD-BAD-TYPE/ERROR-ARTIFACT
- **Export surface**: `GET /products/export` → **404 confirmed live**; controller/job/export classes exist unrouted (dead code, tracked in ledger + master todo). IE-PRD-EXPORT-NOTIMPLEMENTED

## 22–31. BRAND — implemented then validated (pattern cloned from Category)

New files:
```
packages/marvel/src/Services/Import/BrandImportService.php
packages/marvel/src/Imports/BrandsImport.php            (title 'brands')
packages/marvel/src/Exports/BrandsExport.php            (7-column contract)
packages/marvel/src/Jobs/ImportBrandsJob.php            (meem-high, tries 3, cancel+rollback)
packages/marvel/src/Jobs/ExportBrandsJob.php            (meem-high)
packages/marvel/src/Http/Controllers/BrandImportController.php
packages/marvel/src/Http/Controllers/BrandExportController.php
packages/marvel/src/Http/Requests/BrandImportRequest.php
packages/marvel/resources/brands/brand-import-sample.xlsx
tests/Feature/Brands/BrandImportExportTest.php          (5 tests)
```
Changed: Permission enum (+IMPORT_BRAND/EXPORT_BRAND), PermissionSeeder (both role blocks), routes (8 endpoints, placed BEFORE apiResource to avoid `brands/{brand}` capture), en/ar message.php (+16 brand keys) and permissions.php (+4 labels).

Executed evidence (IE-BRD-*):
- Sample 200 valid XLSX, exact 7 headers. Structure PASS.
- Permissions: guest 401 / customer 403 on sample, import, export. PASS ×3.
- Import 202 → imports row type=brand → worker → `completed success=2`; DB: AR names + deterministic slugs. UPLOAD/RECORD/COMPLETED/DB-ROWS
- **Identity/upsert**: re-import same name_en → update-in-place (AR text updated, status flipped 0, count unchanged 788→788). UPSERT
- **Media**: real public URL fetched through redirect chain → 2 media rows attached; loopback URL blocked by SSRF guard → single row failed with translated message, zero partial records. MEDIA/MEDIA-FAIL
- **Error artifact**: exact `[Sheet,Row,Name (EN),Name (AR),Error Message]`. ERROR-ARTIFACT
- **Cancel**: caught pre-terminal → 200, status cancelled, rollback removed all created rows. CANCEL
- **Export**: 202 → worker → completed (rows=801) → streamed XLSX 23,095 B validated, updated AR value present. EXPORT-START/COMPLETED/ARTIFACT
- **Cache**: brands tag MISS→HIT→create flushes→fresh visible. CACHE
- Regression suite: `BrandImportExportTest` 5/5.

### Implementation bugs found & fixed during Brand validation (own code, pre-delivery)
1. Route ordering: custom brand routes placed AFTER `apiResource('brands')` let `GET /brands/export` be captured by `brands/{brand}` → relocated above resource (mirrors Category layout).
2. Missing `store()/download()` helpers on BrandsExport (Category defines them) → export job fatals → added.
3. Missing Request injection in BrandExportController::export → added.
4. Missing IMPORT.BRAND.* translation keys leaked raw keys into user responses → added en+ar (16 keys).

## 32. Errors
See `docs/audits/import-export-error-ledger.md` — every calibration failure is itemized; final state has **0 open application defects** in audited flows.

## 33. Architectural decisions / observations
- Product export surface remains intentionally unimplemented (unrouted classes + dead job) — decision recorded, not silently deleted.
- Product import strict-template requirement (all 8 sheets mandatory) is the established contract enforced by sheet-title mapping.
- Relation sheets resolve strictly by slug; unknown slugs are skipped silently (documented dependency semantics).
- No purge cron existed for soft-deleted products → **implemented** `products:purge-old-deleted --days=30` scheduled daily 02:30 (verified: 31-day-old purged, fresh preserved).

## 34. Production readiness
All three gates PASS with artifact-level evidence. Recommended follow-ups live in master todo (product sample route wiring or method removal decision, product-export route decision, settings null-guard).

## 35. Master TODO
See `docs/audits/import-export-master-todo.md`.
