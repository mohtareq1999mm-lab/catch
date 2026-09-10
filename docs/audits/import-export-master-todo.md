# Import/Export Master TODO

Statuses: `[ ]` Not Started · `[~]` In Progress · `[x]` Done · `[!]` Blocked

---

TODO-IE-001
Priority: P2
Module: Brand Import/Export (NEW)
Endpoint: POST /brands/import · GET /brands/import/sample · GET /brands/import/{id} · POST /brands/import/{id}/cancel · GET /brands/import/{id}/download-errors · GET /brands/export · GET /brands/export/{id} · GET /brands/export/{id}/download
Problem: Brand surface had no import/export at all
Required Change: full Category-pattern implementation (service, importer, exporter, 2 jobs, 2 controllers, request, routes, sample, permissions, translations)
Files: see docs/audits/IMPORT_EXPORT_E2E_AUDIT.md §22–31 list
Tests: tests/Feature/Brands/BrandImportExportTest.php (5) + IE-BRD-* live battery (18)
Status: [x] Done — 18/18 live checks green

TODO-IE-002
Priority: P1
Module: Product Import
Endpoint: POST /products/import
Problem: importer requires all 8 template sheets; partial workbooks abort entirely
Required Change: product decision — either ship only the full sample/template (document contract in API docs + frontend guidance) or make sheet mapping tolerant of missing optional sheets
Files: packages/marvel/src/Imports/ProductsImport.php; packages/marvel/resources/products/product-import-sample.xlsx
Tests: partial-template import test per chosen behavior
Status: [x] Done (closure pass: contract CONFIRMED as strict-template by live evidence; sample regenerated to match; decision recorded in ledger IE-ERR-002)

TODO-IE-003
Priority: P2
Module: Product Export
Endpoint: (none — should be GET /products/export)
Problem: ProductExportController + ExportProductsJob + ProductsExport exist but no route registers them; legacy test targets dead route
Files: packages/marvel/src/Rest/Routes.php; packages/marvel/src/Http/Controllers/ProductExportController.php; tests/Feature/ProductExportTest.php
Required Change: decide to wire export route (+ async job variant if needed) or remove dead surface + fix/remove stale test
Tests: ProductExportTest 4/4 + live FC-EXP-* checks (artifact parsed independently; status-filter + invalid-type 422 verified)
Status: [x] Done — route GET /products/export registered before apiResource (reusing existing synchronous controller); async ExportProductsJob intentionally left unused

TODO-IE-004
Priority: P3
Module: Product Import sample
Endpoint: (none)
Problem: ProductImportController::downloadSample() + sample file exist but no route
Files: packages/marvel/src/Rest/Routes.php (add GET products/import/sample) or remove method
Tests: FC-SAMPLE-* live round-trip (sample downloaded -> imported -> completed -> DB rows verified)
Status: [x] Done — route wired AND sample file regenerated to canonical 8-sheet contract; round-trip proven

TODO-IE-005
Priority: P3
Module: Settings bootstrap
Endpoint: GET /general/settings
Problem: 500 on migrated-but-unseeded database (null settings row)
Files: SettingService::getSetting / SettingResource consumers
Required Change: null-guard returning safe defaults
Tests: fresh-migrate-only settings probe
Status: [ ] Not Started

TODO-IE-006
Priority: P1
Module: Queue/Mail (environment)
Endpoint: OTP paths
Problem: Resend API key invalid locally → OTP jobs retry indefinitely
Files: .env mail credentials
Required Action: provision valid key per environment
Status: [!] Blocked external credential

TODO-IE-007
Priority: P3
Module: Search
Endpoint: n/a
Problem: Meilisearch service unavailable locally → index lifecycle unverifiable
Required Action: staging service provisioning
Status: [!] Blocked environment

---

Completed during this engagement (reference):
[x] TODO-IE-000-A — Category gate closure: 23/23 live checks incl. hierarchy, cancel+rollback, error/export artifacts, cache invalidation, round-trip upsert.
[x] TODO-IE-000-B — Product gate closure: 12/12 checks incl. pricing-ADR equality via ProductPricingService, dependency semantics, media disk proof, strict-template documentation.
[x] TODO-IE-000-C — BulkDeleteCategoriesRequest ids.* exists rule + queue-test alignment (CategoryQueueAssignmentTest fixture uses real row).
[x] TODO-IE-000-D — products:purge-old-deleted command (30-day soft-delete purge) implemented, scheduled daily 02:30, live-verified.

TODO-IE-008
Priority: P1
Module: Product Export (closure)
Endpoint: GET /products/export
Problem: dead surface (unrouted) despite complete controller/classes and encoded test contract; exporter also lacked mandatory 'tags' sheet required by importer
Required Change: register route before apiResource; add TagsSheetExport as 8th sheet
Files: packages/marvel/src/Rest/Routes.php; packages/marvel/src/Exports/ProductsExport.php; NEW packages/marvel/src/Exports/Sheets/TagsSheetExport.php
Tests: ProductExportTest 4/4; live FC-EXP-* + P22 product round-trip (0 errors after fix)
Status: [x] Done

TODO-IE-009
Priority: P2
Module: Product Import sample
Endpoint: GET /products/import/sample
Problem: route missing AND shipped sample out of contract (7 sheets, no tags, image_url header, wide variant columns)
Required Change: wire sample route; regenerate canonical 8-sheet sample matching importer exactly
Files: packages/marvel/src/Rest/Routes.php; packages/marvel/resources/products/product-import-sample.xlsx
Tests: live sample download -> import -> completed -> DB rows verified (FC-SAMPLE-*)
Status: [x] Done

TODO-IE-010
Priority: P2
Module: Export storage security
Endpoint: all export downloads (category/product/brand)
Problem: artifacts stored on PUBLIC disk under timestamped names; conditional exposure if public/storage symlink is created in production
Required Change: migrate export writes to private disk ('local') or signed URLs; keep authenticated streaming downloads
Files: ExportCategoriesJob.php; ProductsExport store path; BrandExportController download path
Tests: unauthenticated direct-URL 404 assertion + artifact download still 200 via endpoint
Status: [!] DEFERRED (deployment evidence: no storage:link step exists in repo deploy model; hardening recommended before any symlink-based static serving is introduced)
