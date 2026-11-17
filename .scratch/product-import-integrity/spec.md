## Problem Statement

When a catalog admin imports a supplier workbook, the import can report success while the catalog is wrong. `product_type` written from a `simple` cell can land as empty/`0` (MySQL silently stores `''` for values an ENUM cannot represent when strict mode is off); category, brand and tag links disappear without a trace when a slug does not exist; failed rows point at the wrong Excel row after a chunk boundary or a blank row; and image failures can be overwritten by the final import write. The result is a catalog that looks imported but behaves incorrectly in cart, checkout and storefront browsing.

The importer is also one large service that owns row writing, pricing, taxonomy linking, progress signalling, cancellation and reporting. The recent integrity fix made the failure modes loud, but the pipeline is still hard to change and the run outcome has no single owner.

Recovery context: a 4,820-product workbook was re-imported after data loss. The workbook references 117 category slugs and 2 brand slugs that do not exist in the catalog, so the product rows import but the taxonomy links are skipped.

## Solution

Keep the shipped integrity guarantees and finish deepening the import pipeline so that future changes are local and the run outcome is trustworthy.

Shipped guarantees (already in the codebase):
- `product_type` is normalised and the persisted value is verified; a schema that cannot store `simple`/`variable` fails the import before any row is written.
- A repair migration rewrites empty `product_type` values (keeping products with variants as `variable`) and widens a narrow ENUM; it stops loudly when it cannot repair.
- Blank `product_type` cells fall back to the default; invalid values fail the row with the allowed values.
- Unknown category/brand/tag/flash-sale/slider slugs are reported per slug with the affected SKUs; the product still imports and known links still sync.
- `in_stock=0` with a positive quantity is imported as written but reported, using the persisted quantity when the sheet omits it.
- Text booleans (`TRUE/FALSE`, `yes/no`, `on/off`) are accepted; unrecognised text is rejected instead of silently becoming `false`.
- Excel row numbers stay exact across chunks and blank rows.
- Import messages are translated (en/ar).

Remaining deepening:
- One workbook-schema module owns each sheet’s name, key columns, chunk size and row handler, shared by the Excel import, the asynchronous image path, row counting and the export/sample contract.
- One run-outcome module owns counters, the processed = success + failed invariant, status derivation and the single error-persistence path, including image errors merged before the terminal write.
- One operation-heartbeat module owns the progress/cancel signal files for import and export jobs.
- The importer asks the schema guard for the ENUM contract instead of restating it.
- A failed schema preflight keeps the uploaded workbook so the admin can retry after fixing the database.

## User Stories

1. As a catalog admin, I want `product_type` to persist exactly as the workbook says (`simple`/`variable`), so imported products behave correctly in cart and checkout.
2. As a catalog admin, I want an import to fail with a clear, actionable message when the database cannot store `simple`/`variable`, so I do not get thousands of corrupt rows.
3. As a catalog admin, I want existing empty `product_type` values repaired, without turning products that own variants into `simple`.
4. As a catalog admin, I want a blank `product_type` cell to use the default `simple`, so an optional cell does not fail the row.
5. As a catalog admin, I want an invalid `product_type` to fail the row and list the allowed values, so I can fix the file.
6. As a catalog admin, I want unknown category slugs reported one per error with the affected SKUs, so I can create or correct the taxonomy.
7. As a catalog admin, I want products with unknown taxonomy still imported with the links skipped, so one bad slug does not block thousands of products.
8. As a catalog admin, I want unknown brand, tag, flash-sale and slider slugs reported the same way.
9. As a catalog admin, I want `in_stock=0` with a positive quantity flagged but imported as written, so the sheet remains the source of truth.
10. As a catalog admin, I want that flag to use the stored quantity when the workbook omits the quantity column, so omissions do not hide the contradiction.
11. As a catalog admin, I want text booleans accepted and nonsense rejected, so flags such as `in_stock` and `has_discount` are never silently inverted.
12. As a catalog admin, I want error rows to reference the real Excel row after blank rows and chunk boundaries, so I can find them in the file.
13. As a catalog admin, I want aggregated warnings to survive the error-report cap, so the important summary is never truncated away.
14. As a catalog admin, I want image failures included in the final import report, so I know which products lack images.
15. As a catalog admin, I want `download_available` to be accurate after image work, so the UI does not promise a report that is not there.
16. As a catalog admin, I want the documented `image` and pipe-separated `images` forms to work in the asynchronous image path, so images are not silently skipped.
17. As a catalog admin, I want import error messages in my locale (`en`/`ar`).
18. As a catalog admin, I want a failed schema preflight to keep my uploaded workbook, so I can retry without re-uploading.
19. As an operator, I want a migration that repairs empty `product_type` values and widens a narrow ENUM, and stops loudly when it cannot decide.
20. As an operator, I want the schema preflight to run before any row is written.
21. As a developer, I want sheet names, columns, chunk sizes and handlers in one place, so adding or renaming a sheet is one edit.
22. As a developer, I want row counting, the Excel import and the asynchronous image path to read the same sheet definition.
23. As a developer, I want one owner for counters, the processed invariant, status and error persistence, so the reporting rules are not recomputed in three places.
24. As a developer, I want image errors merged before the terminal status write, so the final report is complete.
25. As a developer, I want one owner for the progress/cancel signal protocol, so jobs and controllers stop rebuilding file paths.
26. As a developer, I want the importer to ask the schema guard for the ENUM contract, so diagnostics cannot contradict the database.
27. As a developer, I want the import service split behind a narrow facade, so row writing, taxonomy linking and progress can be tested without a workbook.
28. As a QA engineer, I want characterization tests at the existing seams before the split, so the refactor is provably behaviour-preserving.
29. As a QA engineer, I want a real-workbook acceptance check (4,820 rows, zero failures, taxonomy gaps reported), so the pipeline is verified end to end.
30. As a catalog admin, I want existing behaviour — upsert by SKU, pricing, barcodes, variants, cancellation — unchanged.
31. As a catalog admin, I want a `completed` import with taxonomy gaps to still expose a non-zero error count, so a clean status never hides a broken catalog.
32. As a catalog admin, I want numeric flags other than `1`/`0` to fail the row, so a `2` or `0.5` cannot silently become `false`.

## Implementation Decisions

**Product type contract**
- `ProductTypeSchema` is the single owner of the column contract: compatibility check, canonical ENUM definition, widening and value repair. The importer and the migration both use it.
- Blank cells mean “not provided” and fall back to the column default; provided values are trimmed and lowercased.
- The persisted value is verified once per distinct expected type per import, not once per row.
- A schema that cannot represent the canonical values fails the import as a system error before rows are written; the migration stops with an actionable message when it cannot repair.

**Taxonomy**
- Missing taxonomy references are auxiliary errors: they appear in the downloadable report and never inflate product-row counters.
- Reporting is one entry per unknown slug, carrying the label, the affected SKU count and a bounded SKU preview.
- Missing references do not create records and do not abort the product row; known references still sync.
- Slug comparison follows the database collation (case-insensitive).
- Taxonomy gaps are auxiliary: the import status stays `completed` and `failed_rows` stays `0`; the gaps are visible through `errors`/`error_count`. The product-row status deliberately describes products, not links.
- Taxonomy labels and the “more items” suffix are translated, so an Arabic error message has no English fragments.

**Boolean flags**
- The parser accepts numeric values, native booleans, and the documented text forms; unknown non-empty text raises a row error.
- Numeric values are accepted only when they are exactly `1` or `0`; other numbers raise a row error instead of silently becoming `false`.
- Contradiction detection uses the sheet quantity when present and the persisted quantity otherwise.
- The persisted product-type verification reads the physical column through the query builder, bypassing model global scopes (the active channel must not decide whether the write succeeded).
- The variant path writes and verifies `product_type` inside the variant transaction, so a guard failure rolls back the variant too.

**Workbook schema**
- A single module owns per-sheet identity: title, key column, chunk size and row handler.
- The Excel import, asynchronous image dispatch, row counting, export and sample files all read that definition.
- Multiple image column shapes (`image`, pipe-separated `images`) are supported by one reader.
- Empty rows are skipped inside the sheet handler, not by the Excel engine, so physical row numbers remain exact.

**Run outcome**
- One module owns per-category counts, the processed = success + failed invariant, status derivation and error persistence.
- Image failures are merged before the terminal write; `download_available` is derived from the final error set.
- One error cap, with aggregated warnings placed before individual errors.

**Operation heartbeat**
- One module owns the progress/cancel signal protocol: path construction, write, read, cancel detection and cleanup.
- Jobs and controllers use it instead of rebuilding storage paths.

**Service split**
- Expand first: introduce collaborators beside the current service (row writer, taxonomy linker, progress reporter) and delegate to them.
- Migrate call sites in batches, keeping the existing seams green.
- Contract last: remove the duplicated logic from the service once no caller remains.
- The refactor preserves observable behaviour: same inputs, outputs, exceptions, side effects and ordering.

**Messages**
- All user-facing import messages live under `message.IMPORT.PRODUCT.*` in `en` and `ar`.

## Testing Decisions

A good test asserts external behaviour: the `Import` record (status, counters, errors) and the resulting database state (products, `product_type`, links, barcodes), or the schema contract. Tests must not reach into private methods or assert storage paths.

Seams, highest first:
1. **Import job outcome** — workbook in → `Import` status/counters/errors + products in the database. Anchored by the existing lifecycle, invariant and product-type integrity suites.
2. **Service outcome** — the service’s public surface plus the import report, without a job. Anchored by the lifecycle/validation tests.
3. **Schema contract** — `ProductTypeSchema` and the repair migration: compatibility, blank → simple, variant-aware repair, narrow-ENUM widening. Anchored by the product-type integrity suite.
4. **Chunked sheet reading** — physical Excel row mapping including blank rows. Anchored by the unit sheet-import test.

Prior art:
- Job-level characterization: `ProductImportLifecycleTest`, `ProductImportInvariantTest`.
- Integrity and schema: `ProductTypeIntegrityTest`.
- Barcode and round-trip: `ProductBarcodesImportExportTest`.
- Sheet arithmetic: `RowSheetImportTest`.
- Report aggregation and idempotence: `ImportReportTest`.

Additional testing decisions:
- A workbook-level blank-row test (styled empty row) proves the physical Excel row survives the engine’s row iterator, not just the sheet helper.
- Destructive schema tests refuse to run against the live `catch` database; they must be run against an isolated database.
- Known caveat: MySQL DDL auto-commits, so schema-altering tests can break the transactional test guard. They restore the schema in `finally`, but isolating them in a separate process is a follow-up.
- Report aggregation is unit-tested for idempotence and cross-chunk merging, not only through a workbook.

Refactor rule: capture characterization tests at the existing seams before moving code; keep them green after every batch. The real-workbook dry run (4,820 rows, zero failures, taxonomy gaps reported) is the end-to-end acceptance check.

## Out of Scope

- Auto-creating missing categories, brands, tags, flash sales or sliders.
- Changing variant-ownership semantics (`product_type` becomes `variable` when a variant row exists).
- Storefront or admin UI changes beyond the data returned by the existing endpoints.
- Re-running the production recovery import or restoring the wiped database.
- Repairing unrelated pre-existing failing tests (`DeepVerificationTest` helper, `ExcelContractTest` header drift, cache-version test).
- Replacing MySQL as the database or changing its strict mode.

## Further Notes

- Root-cause evidence: with `strict => false`, inserting boolean `false` into `enum('simple','variable')` stores `''`; this was reproduced directly. The exact database that showed `0/empty` was not available, so the fix is defence in depth: preflight, normalisation, persisted-value verification and a repair migration.
- The sample workbook also contains 469 rows with `in_stock=0` and `quantity>0`; these are imported as written and reported once per import.
- Work landed on `feat/guest-mobile-auth` as commits `c7e2f78`, `bf19b08` and the review follow-up `ead1452`; the branch name is unrelated to this feature and the commits can be cherry-picked.
- The remaining service split is a behaviour-preserving refactor; any bug found during it must be a separate change.
- Published as issue #33; sub-issues: #34 workbook sheet schema, #35 run outcome, #36 operation heartbeat, #37 product-type contract, #38 retryable schema failure, #39 facade split, #40 real-workbook acceptance.
- Review findings incorporated after `bf19b08`: the persisted read bypasses the fast-shipping global scope, report reads are idempotent across chunks, numeric booleans other than `1`/`0` fail loudly, variant `product_type` is written inside its transaction, taxonomy labels/preview are translated, a workbook-level blank-row test was added, and destructive schema tests skip the live database.
- Known risks / accepted trade-offs:
  - A non-ENUM `product_type` column is not guessed at: the migration stops loudly and the import preflight reports an actionable error.
  - Taxonomy gaps keep the import status `completed`; consumers must read `errors`/`error_count` (documented above).
  - Schema-altering tests still run inside the transactional suite; they restore the schema but should eventually move to a separate process.
  - `ERROR.PRODUCT_NOT_PURCHASABLE` arrived from the in-flight guest-cart work and was carried into the import commit; it is unrelated to this feature and should not be treated as part of the spec.
