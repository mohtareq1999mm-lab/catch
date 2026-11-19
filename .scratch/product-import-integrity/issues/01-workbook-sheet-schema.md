# 01: Workbook sheet schema as one module

**What to build:** One workbook-schema module owns each sheet's title, key columns, chunk size and row handler. The Excel import, the asynchronous image path, row counting and the export/sample contract all read the same definition, so renaming a column or adding a sheet is one edit.

Today the asynchronous image path re-implements the images reader with hardcoded columns and chunk size, ignores the documented pipe-separated `images` form, and the sheet importer that knows both forms is not used in production. The product-sheet name is repeated in row counting.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] Adding or renaming a sheet requires one definition change plus its handler.
- [ ] The asynchronous image path reads the same definition as the Excel import and supports both the single `image` cell and the pipe-separated `images` cell.
- [ ] Row counting uses the products-sheet definition instead of a hardcoded sheet name.
- [ ] Export and sample headers derive from the same definition (or a shared contract) so they cannot drift.
- [ ] An import with a pipe-separated `images` sheet stores every image; a row-count test and an image test cover it.
- [ ] Existing import/barcode/export suites stay green.
