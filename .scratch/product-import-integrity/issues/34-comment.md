Implemented in commits `52f4822` and `0693f8f` — `feat(product-import): single workbook schema for sheets, images and exports` plus review fixes.

What changed:
- `Marvel\Workbook\ProductWorkbookSchema` is the single definition for all 9 sheets: title, key column, chunk size, dispatch chunk size, headings, importer and exporter.
- Sheet importers derive title/chunk size from it; `ProductsImport` and `ProductsExport` build their sheet maps from it; row counting reads the products-sheet identity.
- The asynchronous image path uses `ImagesSheetReader`: heading-mapped columns (not A/B), both `image` and pipe-separated `images`, raw cell values, physical Excel rows, shared Laravel-Excel heading formatter, 500-row dispatch chunks, and a loud error when the key column is missing.
- Export sheets derive titles/headings from the same contract; the product sample was regenerated to the full 24/11 header contract.
- Tests: schema registry, image reader, async media persistence (3 images stored), literal header contracts, export map/headings alignment, missing key column, and sample exact-match.

Acceptance criteria:
- [x] Adding or renaming a sheet requires one definition change plus its handler/exporter.
- [x] The async image path reads the same definition and supports both image forms.
- [x] Row counting uses the products-sheet definition.
- [x] Export and sample headers derive from the same contract and are exact-match tested.
- [x] A pipe-separated image sheet stores every image; a media-persistence test covers it.
- [x] Existing import/barcode/export suites stay green.

Evidence: 93 passed / 499 assertions across schema, images, integrity, lifecycle, invariant, barcode, report, route/storage and Excel-contract suites. Full unit suite: 623 passed, 14 unrelated pre-existing failures (worker config, seeders, frontend resource count).
