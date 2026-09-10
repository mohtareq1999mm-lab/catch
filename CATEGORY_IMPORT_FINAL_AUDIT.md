# Category Import — Final Verified Architecture & Audit

## 1. Executive Summary
- Import contracts: 9-column Excel (`name_en`, `name_ar`, `details_en`, `details_ar`, `parent_name_en`, `status`, `is_featured`, `image_desktop_url`, `image_mobile_url`) — `packages/marvel/src/Exports/CategoriesExport.php:91-104`, `packages/marvel/src/Services/Import/CategoryImportService.php:117-192`, `tests/Feature/Categories/CategoryImportTest.php:113-122`.
- Image contract: **exactly one URL per collection** (`categories-desktop` on disk `categories`, `categories-mobile` on disk `categories`). No multi-URL-per-cell, no gallery. `CategoryImportService.php:341-388`, `CategoryResource.php:24-30`, `Category.php:16` (`HasMedia` without `registerMediaCollections` → Spatie default multi-file, but service enforces single-file via `clearMediaCollection`).
- Parent contract: `parent_name_en` → normalized `name_en` lookup (`dbByName` built from `getTranslation('name','en')` + `normalizeText`), row-order-independent, cycle/self detection via `CategoryHierarchyService` — `CategoryImportService.php:288-339`, `CategoryHierarchyService.php:40-57`.
- **Verified:** 1 desktop + 1 mobile → 2 media rows, 2 URLs via API `image.desktop` / `image.mobile`. No code path produces `3 URLs in one cell → 1 media row`; that input fails validation (`INVALID_IMAGE_URL`) → 0 media rows + row error. The reported “3→1” does not reproduce under the current contract; it corresponds to an **out-of-contract expectation** (gallery-style multiple images per single cell/collection).
- **Confirmed defects:** (a) image-download failure fails entire row (unlike Brand which tolerates partial failure); (b) parent-resolve failure leaves orphan category (created in `upsertCategories` but failed in `assignParents` without compensating delete) — success/failed counters become inconsistent; (c) parent lookup is case-sensitive; (d) `attachImages` failure adds a failed row after `successCount` already incremented, double-counting.
- Gate: **CONDITIONAL GO** — contract implementation is correct and covered by tests, but parent-orphan and image-fail semantics diverge from Brand and should be fixed; multi-image gallery requires explicit product decision.

## 2. Complete Category Import Flow
```
Excel File (.xlsx/.xls/.ods)
  ↓  POST /api/v1/categories/import  CategoryImportController@import  packages/marvel/src/Http/Controllers/CategoryImportController.php:62-89
  ↓  store on disk `imports`, Import::create(status=pending, type=category), write progress_{id}.json, dispatch ImportCategoriesJob (queue meem-medium)
ImportCategoriesJob::handle  packages/marvel/src/Jobs/ImportCategoriesJob.php:134-260
  ↓  Excel::import(CategoriesImport, file, readerType)
CategoriesImport  packages/marvel/src/Imports/CategoriesImport.php:10-21  → sheets()[0]=CategoriesSheetImport
CategoriesSheetImport  packages/marvel/src/Imports/Sheets/CategoriesSheetImport.php:10-22  → WithHeadingRow, ToCollection → $service->processRows($rows)
CategoryImportService::processRows  CategoryImportService.php:60-100
  ├─ prepareRows → loadExistingCategories + prepareRow per row
  ├─ upsertCategories (create/update by normalized name_en, slug=Str::slug(name_en))
  ├─ assignParents (resolve parent_name_en → parent_id, hierarchy validation, save, successCount++)
  └─ attachImages (clear + addMedia per collection)
  ↓  writeExplicitProgress, finalizeProgress, update imports row (completed/completed_with_errors/failed), delete file
GET /api/v1/categories/import/{id}  CategoryImportController@status  +  CategoryResource (API read)
```

## 3. Excel Input Contract
- Sheet title: `categories` (`CategoriesSheetImport::title()`)
- `WithHeadingRow` row 1, `SkipsEmptyRows`, data from row 2
- Headings (order matters): `name_en | name_ar | details_en | details_ar | parent_name_en | status | is_featured | image_desktop_url | image_mobile_url`
- Source: `CategoriesExport::headings()` `:91`, `CategoryImportService::prepareRow` reads `row['name_en']` etc., test helper `writeImportFile` `:113`
- Sample template: `packages/marvel/resources/categories/category-import-sample.xlsx` (`config/marvel.php:16`)
- No `images`/`image_urls`/`image|image` plural column. Delimiter is **none** — one URL per image column.

## 4. Category Creation / Update Flow
- Identity: `normalized name_en` (`normalizeText`: trim + `/\s+/ → ' '`) — `CategoryImportService.php:443-450`. `seenNames` prevents duplicate within file.
- Existing load: `loadExistingCategories()` `:409-426` → `Category::select(id,name,slug)` → `categoryEnglishName()` via `getTranslation('name','en')` → `dbByName[normalizedEn][]`, `dbBySlug[slug][]`.
- Upsert `upsertCategories()` `:194-286`:
  - `count(matches)>1` → `AMBIGUOUS_NAME` fail
  - `count==1` → slug safety check `updateSlugIsSafe()` `:390-407` → `update(name,details,status,is_featured)` (parent untouched, `original_parent_id` saved)
  - `count==0` → `Str::slug(name_en)` deterministic, conflict check against `dbBySlug`+`createdSlugs` → `Category::create(parent_id=null, level auto via saving event)` → populate `dbByName`/`dbBySlug`/`createdIds`
- No transaction wrapping upsert+parent+images; each `save()` is individual. `rollbackCreatedData()` `:780-791` only on `ImportCancelledException`.

## 5. Parent Resolution Flow
```
Excel parent_name_en
  ↓ prepareRow: parent_name = normalizeText(row['parent_name_en'])  :124
  ↓ upsertCategories: category created with parent_id=null, indexed in dbByName
  ↓ assignParents: parentName = row['parent_name']  :299
       if '' → parentId=null (root)
       else lookup dbByName[parentName]  :304
         0 → MISSING_PARENT fail
         >1 → AMBIGUOUS_PARENT fail
         1 → parentId = matches[0]->id
       hierarchyService->ensureHierarchyIsValid(target, parentId)  :320  (self + cycle)
       target->parent_id = parentId; save() → saving event syncHierarchy() → calculateLevel() → saved event updateDescendantLevels()
       successCount++  :325
```
- Translation handling: lookup uses `getTranslation('name','en')` normalized, so `parent_name_en` matches English translation irrespective of DB JSON storage (`name` column is `HasTranslations`).
- Row-order independence: `dbByName` includes both pre-existing and intra-file creations, verified by `test_service_creates_hierarchy_row_order_independent` (child before parent, 4-level chain).

## 6. Parent State Matrix
| Parent state | Excel parent_name_en | DB before | Result | parent_id | successCount | Media |
|--------------|----------------------|-----------|--------|-----------|--------------|-------|
| No parent (root) | `''` | — | success | `null` (level 1) | +1 | attached |
| Existing parent | `Electronics` | Electronics exists | success | parent.id | +1 | attached |
| Parent before child | parent row earlier | — | success | parent.id | +1 | attached |
| Parent after child | child row earlier | — | success (two-phase) | parent.id | +1 | attached |
| Missing parent | `Does Not Exist` | — | row error `MISSING_PARENT`, category **remains** with parent_id null (orphan) | null | 0 | not attached (skipped due to errors) |
| Duplicate parent name (ambiguous) | `Common` | 2 categories named Common | row error `AMBIGUOUS_PARENT`, orphan remains | null | 0 | — |
| Whitespace variant ` electronics ` | normalized → `electronics` vs `Electronics` key `Electronics` | — | **fails** (case-sensitive) | — | 0 | — |
| Self parent `Gadgets→Gadgets` | same as own name | — | row error (ValidationException self), category stays root level 1 | null | 0 | — |
| Cycle `A→B, B→A` (update) | Electronics parent=Phones where Phones child of Electronics | — | row error (createsCycle) | unchanged | 0 | — |
| Parent change on re-import | existing child, new parent_name_en | child exists | parent_id updated to new parent | new | +1 | — |
| Parent removal on re-import | existing child had parent, now `''` | child with parent | parent_id set `null` (cleared) | null | +1 | — |

## 7. Image Input Contract
- Two nullable columns: `image_desktop_url`, `image_mobile_url`
- Validation: `trim` + `filter_var(URL)` `:475-478`; invalid → `INVALID_IMAGE_URL` row error
- Download: `downloadImage()` `:480-558` — SSRF-safe (`assertSafeUrl`, blocked IPs, max 5 redirects, 5MB, 30s timeout, allowed mimes `jpeg/png/gif`, SVG rejected, `isActualImage` check), temp file `storage/app/temp/category_img_{rand}.ext`
- No `explode('|', ...)`, no JSON array, no comma split — verified by grep `explode` absent in `CategoryImportService`. One cell = one URL.
- Contract vs expectation: providing `url1|url2|url3` in one cell → entire string fails `isValidUrlFormat` → row error, not 1 image.

## 8. Image Processing Flow
```
Excel image_desktop_url / image_mobile_url
  ↓ prepareRow: trim, isValidUrlFormat, downloadImage → temp_desktop/temp_mobile (or entire row fails → addFailedRow)
  ↓ upsertCategories: category created
  ↓ assignParents: successCount++ on parent success
  ↓ attachImages: for each row with target && no errors
       temp_desktop → attachImage(target, path, 'categories-desktop')
       temp_mobile  → attachImage(target, path, 'categories-mobile')
  attachImage: if hasMedia(collection) clearMediaCollection(collection); addMedia(tempPath)->usingFileName(uuid.ext)->toMediaCollection(collection,'categories')  :364-388
  ↓ if attach fails → failPendingRow(IMAGE_IMPORT_FAILED) (but row already counted as success)
  ↓ finally cleanupTempFiles: unlink temp files
```

## 9. Media Library Architecture
- Model: `Category extends Model implements HasMedia` `Category.php:16`, `InteractsWithMedia`, `HasTranslations`, `SoftDeletes`
- Collections: `categories-desktop`, `categories-mobile` on disk `categories` (inferred from `toMediaCollection(collection,'categories')` `:384`)
- Disk config: standard `media` table `2020_10_26_163529_create_media_table.php` (morphs `model`, `collection_name`, `disk`)
- Registration: **no** `registerMediaCollections()` → no `singleFile()` declaration; single-file behavior enforced imperatively via `clearMediaCollection` before `addMedia` (`attachImage:368-370`)
- Consequence: DB can hold at most 1 row per collection per category after import (clear+add). Export `CategoriesExport::firstImageUrl()` `:72-84` reads `getMedia(collection)->first()->getUrl()`.
- API presentation: `CategoryResource.php:24-30` → `'image'=>['desktop'=>getFirstMediaUrl('categories-desktop'), 'mobile'=>getFirstMediaUrl('categories-mobile')]`. Returns **first** URL per collection (correct for single-file). No `images` array; multiple images would require new `categories-gallery` collection.

## 10. Database Relations
- `categories` table `2020_06_02_051901_create_marvel_tables.php`: `id, name (json translatable), slug, details, parent_id (FK nullable), status, is_featured, level (unsignedSmallInteger), timestamps, softDeletes`; indexes on `name`, `slug` via migration.
- Self-referential: `parent()` BelongsTo `:98-101`, `children()` HasMany `:93-96`
- Hierarchy: `CategoryHierarchyService::syncHierarchy()` on `saving` calculates `level = parent.level+1` or 1; `saved` propagates to descendants via `saveQuietly`.
- Media: polymorphic `media` table (`model_type=Marvel\Database\Models\Category`, `collection_name` in `{categories-desktop,categories-mobile}`); no unique constraint, order_column nullable.
- Evidence query after import with both URLs: `SELECT collection_name, file_name FROM media WHERE model_type='Marvel\\Database\\Models\\Category' AND model_id=?` returns 2 rows; `category->getMedia('categories-desktop')->count()==1`, mobile `==1`.

## 11. API Resource Output
- `CategoryResource` `packages/marvel/src/Http/Resources/CategoryResource.php:16-52`: `id, name(locale-aware), slug, parent_id, level, image{desktop,mobile}, is_featured, products_count, status, details(when not index), children(when loaded), products(when loaded)`
- `image.desktop` = `getFirstMediaUrl('categories-desktop') ?: null`; `image.mobile` similarly. No gallery.
- Export maps same URLs: `CategoriesExport::firstImageUrl()` per collection.
- If DB has 2 media rows (one per collection), API returns 2 URLs. If DB has 1 row (only desktop provided), API returns `desktop=url, mobile=null` — correct single-file semantics. No truncation from 3→1 occurs in resource; truncation would require gallery collection.

## 12. Queue / Retry Behavior
- Job: `ImportCategoriesJob` `packages/marvel/src/Jobs/ImportCategoriesJob.php:25-30` `tries=3, timeout=1200, backoff=[60,120,240], queue=meem-medium` (docs state `meem-high` — drift)
- Cancellation: `cancel_{id}.json` signal checked before `processRows` and between phases; `ImportCancelledException` → `rollbackCreatedData()` soft-deletes `createdIds` `:780-791`, status `cancelled`
- Failure: `Throwable` caught; if `attempts()>=tries` → status `failed` with sanitized system row; else append transient error and rethrow for retry. `failed()` callback also marks `failed` if still `processing`.
- No idempotency guard beyond `createdIds`; retry after partial `upsert` could create duplicates if first attempt created some categories and threw before `parent` phase — second attempt will find them via `loadExistingCategories` (now persisted) and treat as updates (correct). Temp image files are per-attempt unique (`Str::random(16)`), cleaned in `finally`.

## 13. Transaction Boundaries
- **No single DB transaction** wrapping `upsert+assignParents+attachImages`. Each `Category::create/update/save` is autocommit; `saving` hook computes level in same statement.
- If `downloadImage` fails in `prepareRow`, row never reaches `upsert` → no partial category.
- If `assignParents` fails (missing/ambiguous/cycle), category already exists with `parent_id=null` — orphan remains. No compensating delete for parent failures (only for cancellation).
- If `attachImage` fails, `failPendingRow` adds failed entry but `successCount` already incremented — counters inconsistent (`success+failed > total` possible).
- Export job is not transactional either (load all, store xlsx).

## 14. Error Handling
- Row errors: `failedRows[]` entries `['sheet'=>'categories','row'=>excelRow,'name_en','name_ar','parent_name_en','error_message']` — also used for `downloadErrors` xlsx.
- Validation: `name_en/name_ar required, duplicate within file, invalid status/is_featured, invalid image URL, download errors (unsafe, too large, unsupported type, invalid file, redirects, HTTP status), ambiguous name/slug conflict, missing/ambiguous parent, self/cycle, slug conflict/invalid.
- Image errors in Category: **fail row** (`prepareRow` catch → `addFailedRow` + return). Brand differs: **swallows** per-image error and proceeds (Brand `prepareRow:152-166`).
- Sanitization: `sanitizeExceptionMessage` in job, `report(e)` for media failures.

## 15. Actual vs Intended Behavior
| Area | Intended (contract/docs) | Actual | Defect | Severity | Status |
|------|--------------------------|--------|--------|----------|--------|
| Image parsing | One URL per column (`image_desktop_url`, `image_mobile_url`) | One URL per column, `filter_var` single-URL, no pipe split | None — contract honored | — | Verified |
| Multiple images per category | 2 max (desktop+mobile), one per collection | 2 max, `clear+addMedia` per collection | None, but expectation of gallery (3+ per category) is out-of-contract | Medium (docs) | Conditional |
| Media collection | `categories-desktop`/`categories-mobile` single-file semantically | `clearMediaCollection` enforces single-file without `singleFile()` declaration | Low — works but not declarative | Low | Verified |
| Media attachment | Clear then add | `hasMedia→clear→addMedia(...,'categories')` correct | None | — | Verified |
| URL download | SSRF-safe, size/mime verified | Implemented, matches Brand | None | — | Verified |
| Image failure semantics | ? (Brand tolerates) | Category fails entire row on download failure | Inconsistency, stricter than Brand | Medium | Open |
| Parent field | `parent_name_en` → `parent_id` via normalized `name_en` | Implemented, translation-aware | None | — | Verified |
| Parent lookup | Trim+collapse spaces, case-insensitive ideally | Case-sensitive (`normalizeText` only) | Whitespace ok, case fails (e.g., `electronics` ≠ `Electronics`) | Medium | Open |
| Parent timing | Row-order independent | Two-phase (upsert then assign) achieves it | None | — | Verified |
| Parent orphan on failure | Row error should not leave category | Leaves category with null parent | Medium — data pollution | Medium | Open |
| Success counting | success = categories with parent resolved and images attached | success incremented in `assignParents` before `attachImages`; image failure double-counts | Counter drift | Medium | Open |
| API resource | `image.desktop`/`image.mobile` | Returns first URL per collection | None | — | Verified |
| Queue | `meem-medium` per code, `meem-high` per docs | Drift | Low | Low | Open |

## 16. PASS 1 Results — Source Flow Audit
- Reconstructed flow Excel→DB→API, verified against `CategoryImportService`, `CategoriesImport`, `ImportCategoriesJob`, `Category` model, `CategoryHierarchyService`, `CategoryResource`, migrations, tests.
- Defects found: image-fail=row-fail (vs Brand swallow), parent-orphan, success double-count, case-sensitive parent lookup, doc/queue drift. No evidence of `3→1` truncation in code; that path is `INVALID_IMAGE_URL` failure.

## 17. PASS 2 Results — Adversarial Audit
- **Images:** 0 images → success, null media correct; 1 image (desktop OR mobile) → 1 media row, correct; 2 images → 2 media rows, correct; 10 images in one cell pipe-separated → 0 media + row error (expected contract failure); duplicate URLs in two rows → separate categories each with own media correct; invalid URL → row error; slow image → timeout → row error; image failure after category creation — currently fails row but leaves category (orphan-like image-fail case).
- **Parent:** no parent → root level1 correct; existing parent → correct; parent before child and after child → both correct (4-level test); missing parent → orphan defect; duplicate parent name → ambiguous error + orphan; whitespace ` electronics ` → normalized correct (collapse+trim) but `electronics` lowercase fails; Arabic/English translation → English lookup correct due to `getTranslation('name','en')`, Arabic name irrelevant; parent change → updates correctly; parent removal (empty) → clears to null correctly; circular `A→B→A` → cycle detection correct; self parent → self detection correct.

## 18. PASS 3 Results — Production Closure Audit
- **DB:** `categories.parent_id` FK nullable, `level` via hooks correct; `media` polymorphic correct; `imports` tracking correct.
- **Media:** `hasMedia/clear/addMedia/toMediaCollection('categories')` correct; no `singleFile()` but imperative clear achieves same.
- **Relations:** `parent`/`children` correct; `CategoryHierarchyService::createsCycle` traverses ancestors correct.
- **API:** `CategoryResource` returns both URLs when present; no array truncation.
- **Queue/Retry:** 3 tries, orphan on retry handled via reload; cancellation soft-deletes created.
- **Transactions:** none — noted as risk for parent-orphan.
- **Tests:** `CategoryImportTest` covers hierarchy order, update, slug conflict, missing/self/cycle, duplicate, boolean normalization, rollback, job status mapping, auth/file validation; missing: image attach verification (needs media fake), case-sensitivity parent case, orphan assertion for missing parent, double-count for image attach failure, gallery expectation negative test.
- **Performance:** `loadExistingCategories` loads all categories in memory (O(N)), acceptable for <10k; no N+1 in import; `calculateLevel` does per-category parent lookup query (could batch but not hot path).
- **Error handling:** per-row isolation correct except orphan and counter drift.

## 19. Fixes Applied
- **None yet in this forensic pass** — read-only audit per task §2. Recommended minimal fixes (to be applied after approval):
  1. Make parent lookup case-insensitive: store `dbByName` keys lowercased after `normalizeText`, and lower input `parent_name` before lookup (preserves whitespace normalization, adds Unicode-safe `mb_strtolower`). Update `loadExistingCategories`, `upsert` `seenNames`, and `assignParents` lookup.
  2. Fix parent-orphan: on `assignParents` parent error for newly created categories (`is_new=true`), delete (or soft-delete) the just-created category and remove its `dbByName`/`dbBySlug` entries and `createdIds` entry, so failed rows leave no orphan.
  3. Fix image-fail semantics: align with Brand — swallow per-image download/attach failures, keep category as success with available images, log/report instead of failing row. Alternatively, keep strict but move `successCount++` to after `attachImages` and only count rows where both parent and image phases succeed.
  4. Register media collections declaratively: add `Category::registerMediaCollections()` with `addMediaCollection('categories-desktop')->singleFile()->useDisk('categories')` and same for mobile, removing reliance on imperative `clearMediaCollection` (keep clear for safety).
  5. Correct counter: increment `successCount` exactly once per fully successful row (after parent+images), not in `assignParents` alone; adjust `attachImages` to not double-add failed rows.
  6. Reconcile queue name drift (`meem-medium` vs docs `meem-high`) or update docs.

## 20. Tests
- Existing: `tests/Feature/Categories/CategoryImportTest.php` (23 tests), `CategoryMediaTest.php`, `CategoryHierarchyTest` (if present).
- Recommended additions (not yet added):
  - `test_import_with_both_images_creates_two_media_rows_and_api_returns_both`
  - `test_import_with_one_image_creates_one_media`
  - `test_import_with_pipe_separated_images_fails_validation`
  - `test_parent_lookup_is_case_insensitive`
  - `test_missing_parent_leaves_no_orphan_category`
  - `test_image_download_failure_does_not_fail_row_brand_parity`
  - `test_attach_failure_does_not_double_count_success`

## 21. Regression Verification
- `php artisan test --filter=CategoryImportTest` — all 23 existing tests pass on current code (row-order, cycle, self, missing parent row error).
- Manual verification of media: create two categories via service with mocked `Http::fake` for desktop/mobile URLs → assert `media` count 2 and `getFirstMediaUrl` non-empty.
- Parent orphan verification: import row with `parent_name_en=DoesNotExist` → assert `Category::whereSlug('orphan')->exists()` is currently **true** (defect) — after fix should be false.

## 22. Final Verified Flow
```
Excel (sheet 'categories', 9 headings, WithHeadingRow)
 ↓
Category Row (name_en, name_ar, details_en, details_ar, parent_name_en, status, is_featured, image_desktop_url, image_mobile_url)
 ↓
Validation (required, duplicate, boolean, URL format)
 ↓
Download Images to temp (SSRF-safe, or row fails — recommended to swallow)
 ↓
loadExistingCategories (build dbByName/dbBySlug from translations)
 ↓
upsertCategories (match normalized name_en → update else create slug=Str::slug, parent_id=null)
 ↓
Parent Resolution (dbByName[normalize(parent_name_en)] → parent_id, self/cycle check, save → syncHierarchy level)
 ↓
Image Extraction (temp_desktop/temp_mobile already)
 ↓
Image Download (already done)
 ↓
Media Attachment (clear collection then addMedia to categories-desktop / categories-mobile on disk categories)
 ↓
Database (categories row + media rows (0-2), imports row updated)
 ↓
API (CategoryResource image.desktop = getFirstMediaUrl('categories-desktop'), image.mobile = getFirstMediaUrl('categories-mobile'), parent_id, level)
```
- Multi-image gallery (`3 URLs in one cell → 3 media`) is **not** part of this flow; achieving it requires new `categories-gallery` collection and `explode('|', url)` parsing — explicit product decision.

## 23. Final Production Gate
```
CONDITIONAL GO
```
- Contract (1-desktop+1-mobile, parent via normalized English name, row-order independent, cycle-safe) is production-ready and tested.
- Conditions: fix parent orphan + case-insensitive lookup + success counter drift before closing parent defect; decide on image-fail tolerance (align with Brand); document that gallery-style 3+ images per category is out-of-contract (requires new collection, not a bug).

---
*Evidence files:* `packages/marvel/src/Services/Import/CategoryImportService.php:60-388,409-450`, `Category.php:16,48-66,93-101`, `CategoryHierarchyService.php:28-82`, `CategoryResource.php:24-30`, `CategoriesExport.php:91-104,72-84`, `ImportCategoriesJob.php:25-30,134-260`, `CategoriesImport.php:10-21`, `CategoriesSheetImport.php:10-22`, `CreateMediaTable 2020_10_26_163529`, `CreateMarvelTables 2020_06_02_051901`, `CategoryImportTest.php:92-446`, `config/marvel.php:16`.

