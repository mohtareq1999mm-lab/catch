## Problem Statement

The product import pipeline was deepened (workbook schema, row writer, taxonomy linker, progress reporter, run outcome, heartbeat) — but the two sibling import families, category and brand, still run on shallow copies of the machinery product just outgrew: hand-rolled counters, per-family invariant/cap/status tie-breaks and their own terminal writes. The image download path is a chain of four shallow hops with the error-merge rule duplicated in a job, and the nine export sheets re-implement the same filter contract with drifting semantics (the images sheet silently ignores status/product-type filters and includes soft-deleted rows).

An admin importing or exporting through any of these families gets inconsistent behaviour: a counter fix that lands for products may not apply to categories or brands, image failures are handled by a different rule than product failures, and an export filter that works on the products sheet can silently do nothing on the images sheet.

## Solution

Bring the whole file-operation family to the depth the product pipeline reached:

1. **Shared run outcome**: generalize the product run outcome (counters, processed = success + failed invariant clamped to the known total, status derivation, one error cap, single import-row write shape) so the brand and category importers run on the same module behind thin family adapters.
2. **One image seam**: collapse the four-hop image path (reader → job → full-service re-instantiation → facade method → handler) into one image importer module the image job calls directly; error merging moves into the report.
3. **One export filter contract**: a single filter applier that every export sheet declares which filters it supports, replacing nine drifting copies; the images sheet's filter behaviour becomes an explicit declaration, not an accident.

## User Stories

1. As a catalog admin, I want category and brand imports to apply the same counter invariant as product imports, so no import family can report inflated or inconsistent progress.
2. As a catalog admin, I want category and brand import failures capped and reported like product failures, so the error report behaves the same everywhere.
3. As a catalog admin, I want category and brand import statuses (completed / completed_with_errors / failed / cancelled) derived by one rule, so the admin UI is consistent across families.
4. As a catalog admin, I want image download failures merged into the final import report on a synchronous queue, so I never lose the list of images that failed.
5. As a catalog admin, I want every export sheet to honour the filters I pass, or to have its unsupported filters declared, so an export filter never silently does nothing.
6. As a catalog admin, I want the images export to exclude soft-deleted rows, so deleted products' images don't reappear in exports.
7. As an operator, I want the image pipeline to reuse one downloader per run, so image imports don't multiply handler setup.
8. As an operator, I want category and brand imports to keep their cancellation, heartbeat and progress behaviour unchanged, so existing operational runbooks keep working.
9. As a developer, I want the run-outcome unit tests to be the contract for every import family, so a counter fix is written and verified once.
10. As a developer, I want the image error-merge rule unit-tested, so it can't regress into duplication or loss.
11. As a developer, I want each export sheet to declare its supported filters in one place, so adding a filter is a single edit.
12. As a developer, I want brand and category importers split behind their own row/taxonomy collaborators, mirroring the product facade, so no 800+ line service remains in the family.
13. As a QA engineer, I want characterization suites for brand and category imports taken before the port, so the refactor is provably behaviour-preserving.
14. As a QA engineer, I want a cross-family verification run proving product, category and brand imports agree on outcome semantics, so the families can't drift again.

## Implementation Decisions

- **Family-neutral outcome**: the product run outcome's counters, invariant, cap, status derivation and row-write shape are generalized so any file-operation family can feed it its own counters. The product family keeps its adapter; brand and category get their own.
- **Per-family adapters**: each importer (product, category, brand) feeds its own row semantics into the shared outcome; family-specific sheet handling stays with the family, invariant/cap/status do not.
- **Behaviour-preserving port**: brand and category importers are ported behind characterization suites taken from their existing broadcast/lifecycle tests before any code moves; no bug fixes bundled.
- **Image importer seam**: one module owns the per-row image download (handler reuse, duplicate-media check, URL validation) and reports failures into the import report; the image job shrinks to dispatch + persist; the error-merge rule lives in the report (already idempotent) instead of the job.
- **Export filter contract**: one filter applier with per-filter application; each export sheet declares its supported filters in the workbook schema (columns already live there), and the images sheet's filter behaviour is made explicit (either "no row filters" or full filters — decided as part of the ticket with the soft-delete exclusion fixed).
- **Messages**: any new user-facing message follows the established en/ar import-message pattern.

## Testing Decisions

A good test asserts external behaviour: the import/export record (status, counters, errors) and resulting catalog state — never private methods or storage paths.

Seams (highest first):
1. **Import job outcome** per family — brand/category characterization suites already cover broadcast, progress, cancellation and lifecycle; they run unchanged before and after the port.
2. **Family-neutral outcome unit tests** — clamping, status derivation, cap, warning ordering, terminal total fallback (prior art: the product RunOutcome/ImportReport unit tests).
3. **Image importer unit test** — per-row download, duplicate skip, failure reporting (prior art: the async image sheet test).
4. **Export filter tests** — per-sheet filter application and the images-sheet soft-delete exclusion (prior art: ExcelContractTest / ExportLifecycleTest filter tests).

Rule: characterization first, then move code; suites stay green per batch.

## Out of Scope

- The speculative import-job orchestrator extraction (revisit when a second orchestration change lands in the same catch arms).
- Queue infrastructure changes, new external systems, or schema changes.
- Changes to storefront/admin UI beyond the data returned by existing endpoints.
- Pre-existing unrelated test failures (worker config, seeders, FrontendResource count, CategoryBulkDeleteCancelEndpointTest FK seeding).

## Further Notes

- The product family already demonstrates the target shape end to end; its suites are the regression safety net for the shared module.
- A cross-family verification run closes the spec: product, category and brand imports exercised on isolated databases with evidence attached to the tracker.
