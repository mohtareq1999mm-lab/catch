# Category Import / Export — QA Test Cases

## Test Files

2 test files cover the import/export module in `tests/Feature/Categories/`:

| File | Lines | Focus |
|------|-------|-------|
| `CategoryImportTest.php` | ~500 | Import pipeline, validation, identity, hierarchy, cancel, sample |
| `CategoryExportTest.php` | ~260 | Export queue, status, download, headings, mapping |

> **Note:** `CategoryBulkDeleteTest.php` (11 tests) lives in the same folder but covers the separate bulk-delete feature, outside this module's scope.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | Import category file | POST /categories/import with xlsx | 202, import_id returned |
| F2 | Import status | GET /categories/import/{id} | 200, status payload |
| F3 | Cancel import | POST /categories/import/{id}/cancel | 200, status 'cancelled' |
| F4 | Download sample | GET /categories/import/sample | 200, xlsx binary |
| F5 | Queue export | GET /categories/export | 202, export_id returned |
| F6 | Export status | GET /categories/export/{id} | 200, status payload |
| F7 | Download export | GET /categories/export/{id}/download | 200, xlsx binary |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Import without file | Missing file field | 422 |
| V2 | Import invalid file type | e.g., .txt upload | 422 |
| V3 | Import oversized file | > 20 MB | 422 |

---

## Authentication / Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest import | No token on POST /import | 401 |
| A2 | Guest export | No token on GET /export | 401 |
| A3 | Missing import-category | Authenticated, no permission | 403 |
| A4 | Missing export-category | Authenticated, no permission | 403 |
| A5 | Super admin bypass | super_admin can import/export | 200 / 202 |

---

## Import Identity Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| I1 | Deterministic slug | New category gets `Str::slug(name_en)` | Correct slug |
| I2 | Row-order independence | Child before parent in file | Hierarchy still correct |
| I3 | Re-import updates | Same name_en updates existing category | No duplicate |
| I4 | Slug conflict | Slug owned by another category | Row error |
| I5 | Duplicate name in file | Same name_en twice | Row error on second |

---

## Parent Handling Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| P1 | Missing parent | parent_name_en matches nothing | Row error |
| P2 | Self-parent | parent_name_en = own name | Row error, category stays root |
| P3 | Cycle assignment | Assign descendant as parent | Row error |
| P4 | Multi-level hierarchy | Electronics → Phones → Smartphones | Correct levels |

---

## Status / Boolean Parsing Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Invalid status | e.g., "abc" | Row error |
| S2 | Boolean-like values | true/false/yes/no/on/off normalized | 1/0 |
| S3 | Empty status default | Empty → active | status = 1 |
| S4 | Empty is_featured default | Empty → not featured | is_featured = 0 |
| S5 | successful_rows mapping | status endpoint maps success_rows | Field named `successful_rows` |

---

## Cancellation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| C1 | Cancel pending/processing | Valid cancel | 200, cancelled |
| C2 | Cancel completed | Already terminal | 409 |
| C3 | Rollback on cancel | Created categories removed | Soft-deleted |

---

## Export Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Export job completes | Queue → completed, file written | File on public disk |
| E2 | Download before ready | status != completed | 409 |
| E3 | Download when completed | File exists | xlsx streamed |
| E4 | Headings exact | 9 import columns | Headings match |
| E5 | Parent name mapping | parent_name_en resolved | English parent name |

---

## Error Report Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Download errors with errors | Non-empty errors | xlsx with row/error info |
| R2 | Download errors with no errors | Empty errors | 404 'No errors found' |

---

## Missing Coverage

- [ ] Image import integration (SSRF block, MIME rejection, 5 MB limit) with a live HTTP endpoint
- [ ] `completed_with_errors` status via full job run with partial failures
- [ ] `failed` status on unreadable/corrupt Excel
- [ ] Cancel mid-import with mixed created/updated rows
- [ ] Large file performance (10k+ rows)
- [ ] Concurrent duplicate-name imports (race condition on identity)