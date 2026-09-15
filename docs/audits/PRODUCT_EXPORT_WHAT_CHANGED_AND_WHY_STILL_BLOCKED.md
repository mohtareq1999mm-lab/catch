# PRODUCT EXPORT — WHAT CHANGED AND WHY IT STILL FAILS (IF IT DOES)

**Date:** 2026-09-12
**Last commit:** `61b87bd update export storage to use 'imports' disk and improve filename handling` (8 files 235+/130-)
**Baseline:** `5f1247f` (before any export fix, 6 pivots `FromCollection`)
**This file answers:** *What was changed, what was fixed to pass 10K in this sandbox, and what still blocks production if you still see failure.*

---

## 1. TL;DR — What changed

```
BEFORE (5f1247f, P0 OOM):
 Packages/marvel/src/Exports/Sheets/*SheetExport.php  (6 pivots)
  FromCollection -> Product::with(relation)->lazy(1000)->flatMap -> collection()->all()
  => Sheet.php:474 appendRows(collection()->all()) materializes N×relations into array,
     Writer holds all 8 sheets in one Spreadsheet => 1K 142MB, 10K ~1.4GB OOM

AFTER (61b87bd, Strategy B):
  FromQuery -> DB::table(pivot)->join(products)->join(related) select sku,slug
  => Sheet::fromQuery:464 query()->chunk(1000) bounded, never materializes
  Images: DB::table(media)->join(products on model_id) instead of Product::with(media)+getMedia()->getUrl()
  Timeout 900 -> 1200

Result in this sandbox (sqlite :memory: bulk 10K):
 10K realistic 61.49s peak 388MB/512M 1.35MB 8 sheets valid, filter parity, no duplicates, 24/11/2 contract
```

---

## 2. Exact diff (git since baseline)

```bash
git diff 5f1247f..61b87bd --stat
 .phpunit.cache/test-results                        |  2 +-
 .../src/Exports/Sheets/BrandsSheetExport.php       | 54 ++++++++------
 .../src/Exports/Sheets/CategoriesSheetExport.php   | 53 ++++++++------
 .../src/Exports/Sheets/FlashSalesSheetExport.php   | 57 +++++++++------
 .../src/Exports/Sheets/ImagesSheetExport.php       | 83 ++++++++++++++++------
 .../src/Exports/Sheets/SlidersSheetExport.php      | 57 +++++++++------
 .../marvel/src/Exports/Sheets/TagsSheetExport.php  | 57 +++++++++------
 packages/marvel/src/Jobs/ExportProductsJob.php     |  2 +-
 8 files changed, 235 insertions(+), 130 deletions(-)
```

Per-file:

| File | Before | After | Line ref |
|------|--------|-------|----------|
| `BrandsSheetExport.php:11` | `class BrandsSheetExport implements FromCollection, WithTitle, WithHeadings` <br> `collection() Product::with('brands')->lazy(1000)->flatMap` | `class BrandsSheetExport implements FromQuery, WithTitle, WithHeadings, WithMapping` <br> `query() DB::table('brand_product')->join(products)->join(brands) select sku,slug orderBy` <br> `map(row)->[sku,slug]` | `BrandsSheetExport.php:6,11,25` |
| `CategoriesSheetExport.php` | same `FromCollection category_product` | `FromQuery DB::table('category_product')->join(products)->join(categories)` | `CategoriesSheetExport.php:6,11,25` |
| `TagsSheetExport.php` | `product_tag` | `DB::table('product_tag')` | `TagsSheetExport.php:6,11,25` |
| `FlashSalesSheetExport.php` | `flash_sale_products` | `DB::table('flash_sale_products')->join(flash_sales)` | `FlashSalesSheetExport.php:6,11,25` |
| `SlidersSheetExport.php` | `slider_product` | `DB::table('slider_product')` | `SlidersSheetExport.php:6,11,25` |
| `ImagesSheetExport.php:12` | `FromCollection Product::with('media')->lazy->flatMap getMedia('products')->map getUrl()` + `Media::find` N+1 | `FromQuery DB::table('media')->join(products on model_id where model_type='Marvel\Database\Models\Product' and collection_name='products') select sku,media_id,file_name,disk` + `map Storage::url fallback (no Media::find)` | `ImagesSheetExport.php:7,12,26,67` |
| `ExportProductsJob.php:25` | `public int $timeout = 900` | `public int $timeout = 1200` | `ExportProductsJob.php:25` |

`config/excel.php` NOT changed (`chunk_size 1000`, `cache driver memory`), `config/queue.php` NOT changed (`catch-medium retry_after 1800`).

Check yourself:
```bash
grep -R "FromCollection" packages/marvel/src/Exports/Sheets/  # should be 0
grep -R "FromQuery" packages/marvel/src/Exports/Sheets/     # should be 8
grep timeout packages/marvel/src/Jobs/ExportProductsJob.php  # should be 1200
```

---

## 3. What the fix solved (measured)

| Test | Before | After | Evidence |
|------|--------|-------|----------|
| `1K simple` ExportLargeDatasetTest | OOM risk (6 scans) | `28.76s peak 144MB 75KB` PASS | `php artisan test --filter=ExportLargeDatasetTest` |
| `1K realistic` (213 variants+1333 media+3616 pivots) | ~142MB + materialize | `1.23s peak 116MB 184KB` 8 sheets valid | `large_verify.php` |
| `10K realistic` 10000 products 1999 variants 12989 media 35503 pivots | **OOM ~1.4GB** | **`61.49s peak 388MB/512M 1.35MB` PASS** filtered `publish 8041 43.7s 1.09MB` all `match true` duplicates `0` `ZipArchive` valid 24/11/2 headings | `bulk_large_test.php` 10K (sqlite) |
| Filter parity `status/product_type/item_type/category_id/brand_id` | pivot sheets returned all products (extra SKUs) | `36/36 subset checks PASS` every pivot SKU subset of Products SKU | `filter_parity_verify.php` |

---

## 4. WHY IT CAN STILL FAIL IN PRODUCTION (if you still see failure)

If you deployed `61b87bd` and still see `500` / `failed` / `pending forever`, the remaining blockers are **not** the 6 pivots (they are fixed). Check these in order:

### A. You are not on `61b87bd` (most common)
```bash
git rev-parse HEAD              # must be 61b87bd or later
git log -1 --oneline --stat     # must list 8 files above
grep timeout packages/marvel/src/Jobs/ExportProductsJob.php  # must be 1200, not 900
grep FromCollection packages/marvel/src/Exports/Sheets/*.php # must be 0
```
If `HEAD` is `5f1247f` or `60a8b24`, you are on old code → `pull` + `composer install -o` + `php artisan config:clear` + `systemctl reload php-fpm` + `queue:restart`.

### B. MySQL not reachable / queue not running
This sandbox: `DB_CONNECTION=mysql 127.0.0.1:3306 TcpTestSucceeded False SQLSTATE[HY000][2002] refused` + `Predis tcp://127.0.0.1:6379 refused`. `bulk_large_test` had to `Config::set('cache.default','array')` and `sqlite :memory:` to pass. If prod `MySQL` or `Redis` down, `ExportProductsJob` fails at `Cache::get(product-export:filters)` / `DB::table` before export. Check:
```bash
php artisan tinker --execute "DB::connection()->getPdo(); echo 'mysql ok';"
redis-cli ping  # or Cache::get
php artisan queue:failed --queue=catch-medium  # failed_jobs
```

### C. `cache.driver = memory` at `25K+` (next OOM)
Even after fix, `PhpSpreadsheet` holds all 8 sheets' cells in one `Spreadsheet` object (`config/excel.php cache.driver memory`). Measured `10K 150k cells 388MB` → `25K ~970MB >512M` will OOM **without any materialization**. This is not fixed by the 6 pivots.

Check:
```bash
php -i | grep memory_limit        # CLI 512M
php -f check.php                  # check.php: <?php echo ini_get('memory_limit');
grep -A2 "'cache'" config/excel.php  # driver => 'memory'
```
Fix **only if** `25K` or prod `10K` with more variants/media peaks `>512M`:
- Option 1 (no code): `config/excel.php 'cache' ['driver'=>'batch','batch'=>['memory_limit'=>60000]]` (spills to cache store, slower but bounded).
- Option 2 (code): `ProductsExport implements ShouldQueue` + `QueuedWriter` (`ShouldQueue` per chunk `AppendQueryToSheet`) — true streaming.

### D. Indexes missing on MySQL (slow, not OOM)
`PRAGMA index_list` (sqlite test tables) shows only `category_product` has unique; `brand_product/product_tag/flash_sale_products/slider_product` have **no index** in test harness. If prod `SHOW INDEX FROM brand_product` also empty, `10K` joins do full scans → timeout `1200`. Run `EXPLAIN` on MySQL replica before adding indexes.

### E. Queue worker timeout mismatch
`config/queue.php database retry_after 1800` must be `>` `worker --timeout 1300` `>` `job timeout 1200`. If worker `timeout 300` (default), `10K 61s` is ok but `retry_after 1800` < `job 1200` would re-dispatch. Check `systemctl cat laravel-queue-catch-medium` or `ps aux | grep queue:work`.

---

## 5. What to do next (do not guess)

1. **Confirm deployment** (A): paste `git rev-parse HEAD` + `grep` outputs above.
2. **Reproduce with measurement**: run `php bulk_large_test.php` (creates 10K bulk, 61.49s, logs `peak 388MB`) or `RUN_LARGE_EXPORT=1 php artisan test --filter=test_export_10000` on staging MySQL replica, not sqlite.
3. **If still `failed`**: paste `storage/logs/laravel.log` `PRODUCT_EXPORT_FAILED` + `failed_jobs` `exception` + `queue worker stderr`.
4. **If `388MB` at `10K` passes but `25K` OOM**: apply `cache batch` or `ShouldQueue` (Strategy E) — only then.

No API/DB contract was changed (still `24` product cols, `11` variant, `2` pivot, `status publish/draft` admin all-products unless filtered, `scopeActive` NOT introduced).

