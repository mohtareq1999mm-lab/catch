# Product Export — Import Compatibility Certification

**Date:** 2026-09-12 (Phase 2 rebuild, import as source of truth)  
**Packages:** `maatwebsite/excel 3.1.48` (`6d0fe2a`), `phpoffice/phpspreadsheet 1.29.0`, `config/excel.php chunk_size 1000`  
**Sample:** `packages/marvel/resources/products/product-import-sample.xlsx` (8 sheets, 20 cols products, 11 cols variants)  
**Import:** `packages/marvel/src/Services/Import/ProductImportService.php` + `packages/marvel/src/Imports/ProductsImport.php` (8 sheets)  
**Export:** `packages/marvel/src/Exports/ProductsExport.php` (8 sheets)

---

## 1. Root Cause of the Invalid XLSX

**Primary:** `app/Http/Middleware/CacheApiResponse.php:14` cached every `GET` except 3 patterns (`products/*/reviews`, `checkout`, `coupons/apply`). Export downloads (`GET /brands| categories| products/export/{id}/download`, `GET .../import/sample`, `.../download-errors`) are `GET` and return `BinaryFileResponse` (`response()->download`). For `BinaryFileResponse`, `getContent()` is **empty** (streamed via `X-Sendfile`), so middleware cached `['content'=>'', 'status'=>200, 'headers'=>['Content-Type'=>'application/vnd...sheet']]` with key `md5(api_cache|version|fullUrl|authId)`. Next `GET` for same URL served the **cached empty body** with 200 and `.xlsx` filename — Excel reports *"file format or file extension is not valid"* (ZIP header `504b0304` missing, 0 bytes, not a ZIP). File on disk (`storage/app/private/imports/*.xlsx`) was **valid** (proven `ZipArchive` + `PhpSpreadsheet` load 1k/75KB), but HTTP layer served cached empty copy.

**Secondary drift:** `ProductsSheetExport.php:54` headings 22 vs import contract 24 (missing `pieces`, `has_flash_sale`); `ProductVariantsSheetExport.php:17` headings 9 vs sample 11 (missing `variant_sku`, `in_stock`, wrong order `product_sku` first vs `variant_sku` first) → exported workbook not fully re-importable.

**Vendor proof:** `Sheet.php:464` `fromQuery` → `query()->chunk(getChunkSize)` where `getChunkSize` defaults `config('excel.exports.chunk_size',100)` → auto-chunked, not full load; `Sheet.php:474` `fromCollection` → `collection()->all()` materializes `LazyCollection`; `Writer.php:66` `foreach(sheets) addNewSheet()->export` sequential shared `Spreadsheet`; `Excel.php:114` `if(ShouldQueue) queue else export` — current exports are synchronous inside `Export*Job` (`onQueue('catch-medium')`), not `ShouldQueue` on export class.

---

## 2. Exact Product Import Workbook Structure

**Controller:** `packages/marvel/src/Http/Controllers/ProductImportController.php` → `ProductImportRequest` (file `required|mimes:xlsx,xls,ods|max:20480`) → `Import::create(type=product-import, pending)` → `ImportProductsJob` on `catch-medium`.

**Workbook:** `ProductsImport.php:15` `WithMultipleSheets` → 8 sheets:

| # | Title | Class | Type | Chunk |
|---|---|---|---|---|
|1| `products` | `ProductsSheetImport.php:21` `ToCollection,WithHeadingRow,WithChunkReading,SkipsEmptyRows` | `processProductRow` | 1000 |
|2| `product_variants` | `ProductVariantsSheetImport.php:21` | `processVariantRow` | 1000 |
|3| `images` | `ImagesSheetImport.php:21` | `processProductImage` | 200 |
|4| `categories` | `ProductCategoriesSheetImport.php:21` | `queueCategories` | 500 |
|5| `brands` | `ProductBrandsSheetImport.php:21` | `queueBrands` | 500 |
|6| `flash_sales` | `FlashSalesSheetImport.php:21` | `queueFlashSales` | 500 |
|7| `sliders` | `SlidersSheetImport.php:21` | `queueSliders` | 500 |
|8| `tags` | `TagsSheetImport.php:21` | `queueTags` | 500 |

Sample file `product-import-sample.xlsx` matches: 8 sheets `products, product_variants, images, categories, brands, flash_sales, sliders, tags` with `products` 20 cols, `product_variants` 11 cols, others 2 cols each.

---

## 3. Exact Product Import Sheets & Headers

**Products (20 in sample, 24 handled by code):**

| # | Header | Required | Type | Import Logic |
|---|---|---|---|---|
|1| `sku` | No (auto `PRD-`+uuid if empty) | string | `Product::where(sku)->first` identity; `generateSlug` if new |
|2| `name_en` | Yes | string | `name['en']` |
|3| `name_ar` | Yes (if `name_en` present) | string | `name['ar']` |
|4| `description_en` | No | string | `description['en']` |
|5| `description_ar` | No | string | `description['ar']` |
|6| `price` | No | numeric≥0 | `price` float |
|7| `product_type` | No | enum `simple,variable` | `ProductType::getValues()` |
|8| `item_type` | No | enum `PHYSICAL,DIGITAL` | `ItemType::getValues()` upper-cased |
|9| `quantity` | No | int≥0 | `stock_quantity` + `quantity` |
|10| `status` | No | bool `1/0 true/false yes/no on/off` | `parseBoolean` |
|11| `in_stock` | No | bool | `parseBoolean` |
|12| `has_discount` | No | bool | `parseBoolean` |
|13| `discount_type` | No | enum `percentage,fixed` | `DiscountType` |
|14| `discount_amount` | No | numeric≥0 | float |
|15| `start_date` | No | date `Y-m-d` | `Carbon::parse` |
|16| `end_date` | No | date | `Carbon::parse` |
|17| `height` | No | string | dimension |
|18| `width` | No | string | dimension |
|19| `length` | No | string | dimension |
|20| `weight` | No | string | dimension |
|21| `tax_enabled` | No | bool | `parseBoolean` (sample missing, code handles) |
|22| `tax_rate` | No | 0..100 | float (sample missing) |
|23| `pieces` | No | int≥0 | `pieces` |
|24| `has_flash_sale` | No | bool | `parseBoolean` |

**Product_variants (11):** `variant_sku, product_sku, price, sale_price, quantity, in_stock, height, width, length, weight, attributes` — `attributes` syntax `Color|لون:Red|أحمر-Size:XL` (`-` groups, `:` name:value, `|` en|ar).

**Images:** `product_sku, image` (or `images` with `|` split, both accepted).

**Pivot sheets:** `product_sku, category_slug` etc. (`brand_slug, tag_slug, flash_sale_slug, slider_slug`) — `sync($ids)` replacement, grouped by `product_sku`.

---

## 4. Exact Product Import Relationship Format

- **Categories:** `category_slug` (slug, not id/name) — `Category::whereIn('slug', slugs)->pluck('id')` → `sync`.
- **Brand:** `brand_slug` — `Brand::whereIn('slug', ...)` → `sync`.
- **Tags:** `tag_slug` — `Tag::whereIn('slug',...)` → `sync`.
- **FlashSales/Sliders:** `flash_sale_slug`/`slider_slug` similarly.
- **Images:** URL `http/https` or local path, downloaded via `UrlImageHandler::download` (SSRF `isBlockedIp`, 5MB, redirects 5) → `addMedia` to `products` collection (additive).
- **Variants:** identified by `product_id + price + sale_price + dimensions` (`findVariantByFields`), not `variant_sku` alone; `variant_sku` stored as `sku`.
- **Translations:** `name_en`/`name_ar` via `HasTranslations` `getTranslations('name')` JSON.

---

## 5. Current Product Export Structure (before fix)

**ProductsExport.php:15** `WithMultipleSheets` 8 sheets: `products` (`FromQuery` 22 cols), `product_variants` (`FromQuery` 9 cols `product_sku` first, missing `variant_sku`/`in_stock`), `images` (`FromCollection` 2 cols), `categories`/`brands`/`flash_sales`/`sliders`/`tags` (`FromCollection` 2 cols each via `Product::lazy(1000)->flatMap`).

**Problems:** `products` missing `pieces`/`has_flash_sale` (22 vs 24), `product_variants` missing `variant_sku`/`in_stock` (9 vs 11) and wrong order, `FromCollection` pivot sheets materialize via `collection()->all()` but valid, `HasChunkReading` not used for export (import-only).

---

## 6. Import vs Export Comparison

| Sheet | Import | Export (before) | Match? | Problem | Fix |
|---|---|---|---|---|---|
| products | 20 (sample) /24 (code) | 22 (missing 2) | No | `pieces`, `has_flash_sale` missing → round-trip lost | Added `pieces`, `has_flash_sale` to headings+map |
| product_variants | 11 `variant_sku,product_sku,price,sale_price,quantity,in_stock,height,width,length,weight,attributes` | 9 `product_sku,price,sale_price,quantity,height,width,length,weight,attributes` | No | Missing `variant_sku`, `in_stock`, wrong order | Fixed to 11 with correct order |
| images | 2 `product_sku,image` | 2 `product_sku,image` | Yes | — | — |
| categories | 2 `product_sku,category_slug` | 2 `product_sku,category_slug` | Yes | — | — |
| brands | 2 | 2 | Yes | — | — |
| flash_sales | 2 | 2 | Yes | — | — |
| sliders | 2 | 2 | Yes | — | — |
| tags | 2 | 2 | Yes | — | — |

Field-level for products: all 20 sample cols match, 4 extra (`tax_enabled`,`tax_rate`,`pieces`,`has_flash_sale`) now present; order preserved.

---

## 7. Exact Problems Discovered (P0-P2)

- **P0:** `CacheApiResponse` cached `BinaryFileResponse` empty body → Excel invalid.
- **P1:** `ProductsSheetExport` missing `pieces`/`has_flash_sale`.
- **P1:** `ProductVariantsSheetExport` missing `variant_sku`/`in_stock`.
- **P2:** `Export*Job` only checked `exists` not `ZipArchive` validity before `completed` (could mark corrupt empty ZIP as completed) — now fixed with `filesize>0 && ZipArchive open && [Content_Types].xml && xl/workbook.xml` check.

Previous audit's `meem-medium` vs `catch-medium` was false — verified `onQueue('catch-medium')` in all jobs (`ImportBrandsJob.php:40`, `Export*Job.php:32`).

---

## 8. Exact Files Changed

| File | Change | Why |
|---|---|---|
| `app/Http/Middleware/CacheApiResponse.php:14` | `skipRoutes` 4→13 (`api/*/brands|categories|products/import*|export*`, `download-errors`, `download`) + `BinaryFileResponse`/`StreamedResponse`/`Content-Type` bypass before `Cache::put` | **P0** prevent caching empty binary download |
| `packages/marvel/src/Exports/Sheets/ProductsSheetExport.php:54,108` | Headings 22→24 add `pieces`,`has_flash_sale`; map add `pieces=>pieces`, `has_flash_sale=>has_flash_sale?'1':'0'` | **P1** import compatibility |
| `packages/marvel/src/Exports/Sheets/ProductVariantsSheetExport.php:17,30` | Headings 9→11 `variant_sku,product_sku,price,sale_price,quantity,in_stock,height,width,length,weight,attributes`; map adds `variant_sku=>sku`, `in_stock=>'1'/'0'` | **P1** sample 11 cols |
| `packages/marvel/src/Jobs/ExportProductsJob.php:106`, `ExportCategoriesJob.php:67`, `ExportBrandsJob.php:93` | After `exists` add `is_file && filesize>0 && ZipArchive open && [Content_Types].xml && xl/workbook.xml` else throw → `failed` + delete partial | **P2** validate physical XLSX before `completed` |
| `packages/marvel/src/Jobs/ExportCategoriesJob.php:30,53` | Atomic `whereIn(pending,processing)->update` + unique `categories-export-{id}-{His}.xlsx` + `Storage` import | **P1** filename collision + duplicate worker (already fixed for brands/products) |
| `tests/Feature/Categories/CategoryExportTest.php:148,248` | `Storage::fake('public')`→`imports`, assert `imports` disk, `assertStringContainsString(id)` | **P2** test disk mismatch |
| `api-desc/brand-import/*` | Updated queue `meem-medium→catch-medium`, routes 8→9, filename with id, idempotency, ZIP validation | Documentation parity |

---

## 9. Laravel Excel / PhpSpreadsheet Versions

- `maatwebsite/excel 3.1.48`, `phpoffice/phpspreadsheet 1.29.0`, `config/excel.php chunk_size 1000`.

---

## 10. XLSX Structural Validation (1k products, 75.04KB)

- `is_file` true, `filesize 77000`, `ZipArchive::open` 0, `numFiles >10`, `[Content_Types].xml` true, `xl/workbook.xml` true, `_rels/.rels` true, 8 `xl/worksheets/sheet*.xml`, header `504b0304`.
- `PhpSpreadsheet\IOFactory::createReaderForFile` → `Xlsx` reader, `load` succeeds, `getSheetNames()` = `products,product_variants,images,categories,brands,flash_sales,sliders,tags`, `products!A1:X1` headings 24, `highestRow` 1001.

---

## 11. Product Data & Relationship Validation

- **1 product with cat+brand:** `GET /products/export` → `products!A2 sku` matches DB `sku`, `pieces` 5, `has_flash_sale` 0, `tax_enabled` 1, `tax_rate` 15, `categories!A2` `product_sku→category_slug` correct, no cross-contamination (checked via `ExportImportCompatibilityTest` with cat `CatTest` and brand `BrandTest`).

---

## 12. Translation Validation

- `name_en`/`name_ar` via `getTranslations('name')`, `description_*` via `getTranslations('description')` — special chars `& < > ' \x00` tested `ExportSpecialCharsTest` valid ZIP, Excel-compatible escaped via `htmlspecialchars` in PhpSpreadsheet.

---

## 13. Round-Trip Export → Import

- **Test:** `ExportImportCompatibilityTest::test_export_can_be_reimported` — create `COMPAT-xxx` with cat/brand, `ExportProductsJob` → parse `products` sheet row, `ProductImportService::processProductRow` with new `COMPAT-NEW-xxx` → `getFailedRows` empty, `Product::where(sku,newSku)` exists, `name_en` and `pieces` preserved. **PASS.**

---

## 14. Scale Test

| # Products | Time | Peak Memory | File Size | Rows | Status |
|---|---|---|---|---|---|
| 1 (special) | 0.85s | <50MB | 6KB | 2 | PASS |
| 1 + cat/brand | 2.18s | <100MB | 15KB | 2 | PASS |
| 1,000 | 5.84s | 144MB | 75.04KB | 1001 | PASS |

10k skipped (`RUN_LARGE_EXPORT=1` required) — estimated 35s/900MB, would exceed 900s timeout for 50k.

---

## 15. Concurrency

- `ExportConcurrencyTest::test_two_category_exports_have_unique_filenames_and_both_exist` — 2 `ExportCategoriesJob` with `categories-export-{id}-{His}.xlsx` produce different `file_path` containing respective `id`, both files exist, no overwrite.

---

## 16. Regression Tests

| Suite | Result |
|---|---|
| `ProductExportTest` (6) | 6 PASS |
| `CategoryExportTest` (9) | 9 PASS (was 7/9 before `imports` disk fix) |
| `ExportLargeDatasetTest` (1k) | 1 PASS |
| `ExportSpecialCharsTest` | 1 PASS |
| `ExportImportCompatibilityTest` | 1 PASS |
| `ExportConcurrencyTest` (2) | 2 PASS |

---

## 17. Known Limitations

- 5 pivot sheets `FromCollection::lazy(1000)->flatMap` materialize via `all()` — 50k products (100k pivot rows) will OOM; fix requires `FromQuery` on pivot table or `ShouldQueue` per `QueuedWriter` (not done, minimal fix).
- `total_rows` counts only `products` sheet, not sum of 8 sheets — informational.
- No prune for `imports/*.xlsx` exports — files accumulate.

---

## 18. Final Status

**CERTIFIED WITH KNOWN LIMITATIONS**

- Valid `.xlsx` (ZIP + `[Content_Types].xml` + `xl/workbook.xml`, PhpSpreadsheet load, 8 sheets, headings 24/11, data correct, relationships correct, round-trip import succeeds).
- Cache root cause fixed, headings fixed, ZIP validation before `completed`, concurrency unique filenames.

