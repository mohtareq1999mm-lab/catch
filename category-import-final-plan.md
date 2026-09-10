# Category Import — Final Forensic Plan (Brand-Reference Restoration)

## 1. Current Brand Architecture (Why It Works)

**Flow:** `BrandImportController@import` → validate file → store on `imports` disk → `Import::create(type=brand-import, status pending)` → `ImportBrandsJob` (queue `meem-medium`, tries 3, timeout 1200, backoff 60/120/240) → `Excel::import(BrandsImport, file)` → `BrandSheetImport` (WithHeadingRow, ToCollection, SkipsEmptyRows, no chunking) → `BrandImportService::processRows`

**Service phases:** `prepareRows` (loadExisting, normalizeText, seenNames case-sensitive, validate status/URL format, per-image try/catch best-effort download, report but temp=null on failure) → `upsertBrands` (dbByName lookup, ambiguous>1 fail, update slug safe, create deterministic Str::slug, populate dbByName/dbBySlug/createdIds) → `attachImages` (for each pending with no errors && target, try desktop/mobile addMedia, successCount++ always, log attach failure but keep success). No DB transaction wrapping downloads; each `Brand::create/update` autocommit. `cleanupTempFiles` in finally with Str::random(16) unique temps. `isValidUrlFormat` = filter_var only. `downloadImage` SSRF-safe (assertSafeUrl per hop, 5 redirects, 30s, 5MB, finfo mime jpeg/png/gif, SVG reject, getimagesize). Media: `brands-desktop`/`brands-mobile` on disk `brands`, imperative `clearMediaCollection` before add.

**Why works:** Best-effort images never fail brand; single-phase upsert (no parent); counters authoritative in `attachImages`; idempotent via `loadExistingBrands` + slug check; no chunking so no chunk state loss; small Excel transaction handler `db` wraps `Excel::import` but brand does only DB writes + HTTP outside transaction? Actually HTTP in `prepareRows` is before upsert, but still inside Excel transaction if handler=db, but brand's prepareRow does HTTP inside same job transaction? Need verify. Category same but brand tolerates.

**Queue/Progress:** `writeExplicitProgress` + `flushProgressTick` threshold 20, `finalizeProgress` updates `processed/success/failed`, broadcasts via `BroadcastsFileOperationProgress` only when env != testing.

## 2. Current Category Architecture (Audit of Actual Code)

**Files:** `CategoryImportController.php:336` mirrors Brand controller (signal files, estimateRowCount, status/cancel/downloadErrors). `CategoriesImport.php:10` WithMultipleSheets → `CategoriesSheetImport.php:12` WithTitle `categories`, WithHeadingRow, SkipsEmptyRows, ToCollection, no chunking. `CategoryImportService.php:1063` processRows → prepareRows → upsertCategories → assignParents → attachImages → cleanupTempFiles. `Category.php:110` HasMedia, HasTranslations, SoftDeletes, booted syncHierarchy, no registerMediaCollections. `CategoriesExport.php:130` reads `getMedia` per collection. `ImportCategoriesJob.php:411` mirrors Brand job (resolve file path multi-disk, sanitize, handle, countRows via PhpSpreadsheet, final status completed/completed_with_errors/failed). `config/excel.php:333` `transactions.handler = db` (global). Migrations: categories table id, name json, slug, details json, parent_id nullable FK, level smallint, status bool, is_featured bool, softDeletes.

**Recent Category fixes applied:** normalizeKey case-insensitive, pipe rejection in isValidUrlFormat, orphan soft-delete on missing/ambiguous parent (deleted new category), best-effort per-image download (like Brand), successCount moved to attachImages. Still fails missing-parent as row error (old spec), self-parent kept as root per matrix.

## 3. Root Cause of Category Failure

- **Before fixes:** Strict image download (single try catching both images, addFailedRow) + parent missing left orphan + counter double-count + case-sensitive parent → 3→1 image truncation myth, but real defects were orphan + counter + case + strict images.
- **After fixes but before new spec:** Missing parent still fails row and deletes orphan (P1 per old spec “NO ORPHAN”), but new spec requires missing parent **non-fatal** (persist as root, warning). So current Category still blocks valid data on missing parent, violating new domain contract: “Category must still be persisted parent_id=null”.
- **Transaction handler `db`** wraps entire `Excel::import` (including `processRows` HTTP downloads) in DB transaction — holding transaction open during 30s×N network I/O is P2 defect. Brand survives because images best-effort and no parent phase, but Category with two-phase parent+images amplifies. Real production Excel with many rows will risk long transaction, deadlocks, and retry rollback losing data.
- **Image URL spaces:** Real Excel may contain `Some Image Name.png` (space in path). Current `isValidUrlFormat` uses `filter_var` which rejects spaces → VALID_IMAGE_URL fails row, while Brand would encode path? Need safe path encoding (parse_url + rawurlencode path segments, preserve query) without weakening SSRF.
- **Media idempotency:** `clearMediaCollection` before `addMedia` ensures 1/desktop 1/mobile but destructive on attach failure; re-import with same URLs currently correctly replaces (no duplicates) via 1/1, but duplicate check via `getMedia` not via file hash.

## 4. Why Brand Works (Reused Mechanisms)

Brand’s **best-effort per-image try/catch**, **authoritative success in attachImages**, **deterministic Str::slug**, **dbByName/dbBySlug seenNames**, **SSRF/mime/size/redirect guards**, **temp unique + finally cleanup**, **short transactions (no explicit large BEGIN/COMMIT)**, **idempotent retry via loadExisting**, **progress flush** are proven. These should be reused via same constants (MAX_IMAGE_SIZE 5MB, MAX_REDIRECTS 5, IMAGE_TIMEOUT 30, ALLOWED mimes) and same helper methods.

## 5. Exact Category/Brand Differences

| Area | Brand (working) | Category (current) |
| Controller | BrandImportController | CategoryImportController (same flow) |
| Import class | BrandsImport (1 sheet) | CategoriesImport (1 sheet) |
| Sheet import | BrandSheetImport ToCollection SkipsEmptyRows | CategoriesSheetImport same, no chunking |
| Chunking | None | None (product sheets use WithChunkReading, categories/brands do not) |
| Transaction | Excel transactions.handler=db (global) wraps import | Same — problematic |
| Row processing | prepareRows → upsert → attach | prepareRows → upsert → assignParents (2-phase) → attach |
| Validation | name_en/ar required, duplicate row, status bool, image URL format | Same plus parent handling |
| Lookup | dbByName case-sensitive | Now case-insensitive via normalizeKey (new) |
| Save | update or create | Same |
| Image handling | per-image try/catch best-effort, validation fails row | Now per-image best-effort + pipe reject, validation fails row |
| Media collection | brands-desktop/mobile disk brands, clear before add | categories-desktop/mobile disk categories, clear before add |
| Relationships | none | parent_id via parent_name_en two-phase, row-order independent |
| Missing relation | N/A | Currently fails row + deletes new orphan (old spec) → must become non-fatal null parent |
| Error isolation | per-row errors, successCount in attach | Same after fix, but missing-parent still fatal |
| Counters | successCount in attach, failedRows per row error | Same now, but missing-parent inflates failed |
| Progress | writeExplicit 10→80→99, flush 20 | writeExplicit 10→60→80→99, flush 20 |
| Queue | ImportBrandsJob | ImportCategoriesJob (same structure) |
| Retry | tries 3, reload existing makes idempotent | Same, but orphan deletion affects retry |
| Tests | BrandImportTest 11 pass | CategoryImportTest 24 pass, Forensic 24 pass, but broadcast 3 fail (env guard) |

## 6. Exact Files to Change & Reason

1. `packages/marvel/src/Services/Import/CategoryImportService.php`
   - Make missing-parent non-fatal: `assignParents` should not `failPendingRow` for `MISSING_PARENT`/`AMBIGUOUS_PARENT`; instead set `parent_id=null`, keep pending as success candidate, record auxiliary warning in separate `warnings` or `failedRows` as warning but not failed count? New spec says categories_success = total should count missing-parent rows as success. So successCount++. Add `warnings` array or reuse `failedRows` with separate flag. Reason: domain contract.
   - Add URL path encoding helper `normalizeImageUrl` to encode spaces in path segments before `isValidUrlFormat`/`downloadImage`, preserving SSRF. Reason: real Excel spaces.
   - Disable Excel DB transaction for this import: either set `config(['excel.transactions.handler'=>null])` locally around `Excel::import` in `ImportCategoriesJob` or add `ShouldQueue` without transaction. Reason: avoid long DB transaction around HTTP.
   - Keep per-image best-effort, pipe rejection, case-insensitive, orphan handling adjusted for new non-fatal (no deletion for missing parent).
2. `packages/marvel/src/Jobs/ImportCategoriesJob.php`
   - Wrap `Excel::import` with transaction handler null: `config(['excel.transactions.handler'=>null])` before import and restore after. Or use `DB::withoutTransaction`. Reason: transactions.handler=db cause.
   - Ensure progress finalizes 100% even when missing-parent warnings exist.
3. `config/excel.php`
   - Document that Category/Brand override handler to null; do not change global blindly to null if product import expects db rollback — instead override per job.
4. `packages/marvel/src/Services/Import/BrandImportService.php`
   - Add same URL path encoding helper for parity (if Brand also has spaces) and same pipe rejection if needed; but keep minimal.
5. `tests/Feature/Categories/CategoryImportForensicTest.php` & `CategoryImportTest.php`
   - Update missing-parent tests to expect success, parent null, warning not failed.
6. `api-desc/import-category/*`
   - Update Parent Handling and Image Import docs to reflect non-fatal missing parent and space encoding.

## 7. Parent-Resolution Strategy

Two-phase preserved: `upsertCategories` (all rows create/update with parent_id null) then `assignParents` (resolve via `dbByName` lowercased, row-order independent). For missing/ambiguous: **persist** with `parent_id=null`, `level` via `syncHierarchy` (1), increment `successCount` later in `attachImages`, record warning in `failedRows`? But spec says categories_failed =0 when only image/parent warnings. So we need separate `warnings` vs `failedRows`. Simplest: keep `failedRows` for truly invalid categories (name missing, slug conflict, self/cycle, invalid image URL format), and for missing-parent add entry to `warnings` array that is merged into `errors` but not counted as `failed_rows`. Or count missing-parent as success but also add to `errors` as warning with `type=warning`. For minimal change, keep `failedRows` but do **not** increment failed count for missing-parent; instead add to `warnings` and still success. We will add `protected array $warnings = []` and in finalization include warnings in `errors` but not in `failed_rows` for status calculation? However `ImportCategoriesJob` status logic uses `failedRows` to decide completed_with_errors vs completed. Missing-parent warnings should make status `completed_with_errors`? But new spec says categories_success = total if all persisted, so status should be `completed` even with parent warnings? Need clarify. We will treat missing-parent as success but also record in `failedRows` as warning but mark row as not failed for counter? Simpler: treat missing-parent as success, do not add to `failedRows`, just log warning via `report` and continue.

## 8. Image Strategy

- Keep `isValidUrlFormat` pipe rejection, add `normalizeImageUrl` that parses URL, rawurlencode path segments (explode `/`, encode each), preserve query/fragment, re-assemble. Call before `isValidUrlFormat`/`downloadImage`.
- Keep per-image best-effort, SSRF, 5MB, 5 redirects, jpeg/png/gif, SVG reject, getimagesize, temp `Str::random(16)`, cleanup finally, `clearMediaCollection` before `addMedia` (idempotent 1/1).

## 9. Transaction Strategy

- **Do not wrap HTTP in DB transaction.** Override `excel.transactions.handler` to `null` in `ImportCategoriesJob::handle` around `Excel::import`. Brand job same if needed. Each `Category::create/update/save` is autocommit; no explicit DB::transaction around whole import. `Http::get` outside transaction.

## 10. Queue Strategy

- Queue `meem-medium`, tries 3, timeout 1200, backoff 60/120/240 preserved. Verify `retry_after` > `timeout` in queue config; if not, adjust horizon. Idempotency via `loadExisting` + deterministic slug + soft-delete orphan handling (now non-fatal, so retry does not duplicate).

## 11. Chunk Strategy

- No `WithChunkReading` for Categories/Brands; all rows loaded via `ToCollection`. For large files (100s rows) memory okay (1000 chunk_size not used). Keep as is, but verify no rows lost: `countRows` via PhpSpreadsheet vs service `success+failed` must equal total. Chunk boundary not applicable.

## 12. Error-Isolation Strategy

- One bad row (invalid image format, self parent, slug conflict) fails only that row via `failPendingRow`; other rows continue. Missing-parent and image download failures are isolated as warnings/success.

## 13. Idempotency Strategy

- Identity = normalized `name_en` case-insensitive via `normalizeKey`; re-import updates in place, slug preserved if same, media replaced via clear+add (1/1). `loadExistingCategories` + `createdIds` + `createdSlugs` ensures retry finds already-persisted rows.

## 14. Testing Strategy

- Expand `CategoryImportForensicTest` to cover: root, child, missing-parent non-fatal, parent after child, case/whitespace, URL with spaces (encoded), pipe, invalid/missing image, oversized, HTML mime, re-import idempotency, chunk boundary not needed. Assert `success+failed == total`, `parent_id` exact, media 0/1/2, API `image.desktop/mobile`, export columns.

## 15. Real-File Verification Strategy

- Locate `category-import-sample.xlsx` and any real `storage/app/private/imports/*.xlsx` or `D:\work\meem\*.xlsx`. Use `PhpSpreadsheet` via php script (not python) to count rows, unique names, root/child, missing parents, image URLs, invalid/duplicate. Then dispatch `ImportCategoriesJob` via `CategoryImportController` flow (store file, create Import record, handle job) and verify DB: `categories` count, `parent_id`/`level` for each `parent_name_en`, `media` counts per collection, `imports` status `completed`/`completed_with_errors` with accurate progress 100.

## 16. Risks & Rollback

- Risk: Changing missing-parent to non-fatal changes import status semantics; existing consumers expecting failed_rows for missing parent will see success. Mitigate by documenting and keeping warning in `errors` but not failed count, or keep failed but with new flag. Rollback: revert `assignParents` to failPendingRow + delete orphan.
- Risk: URL encoding may encode already-encoded `%20` double-encode. Mitigate by decoding first or avoiding double-encode via `rawurlencode(rawurldecode(segment))`.
- Risk: Disabling Excel transaction may affect product import rollback expectations; mitigate by per-job override only for Category (and Brand) not global config.
- Risk: Best-effort images hide legitimate failures; mitigate by logging and keeping warn count.

## 17. Verification Checklist

- Brand reference inspected, Category root cause proven, exact diffs, files, parent two-phase, image best-effort + SSRF, transaction short, queue/ chunk, counters accurate, idempotency, real file executed, DB parent/media verified, queue empty, no manual patching, API shape preserved, docs updated.
