# Test Coverage — Category Import / Export

---

## Test Files

| File | Tests | Focus |
|------|-------|-------|
| `tests/Feature/Categories/CategoryImportTest.php` | 21 | Import pipeline, identity, hierarchy, cancel, sample |
| `tests/Feature/Categories/CategoryExportTest.php` | 9 | Export queue, status, download, headings, mapping |

All tests pass (`CategoryImportTest` 21 tests / 86 assertions, `CategoryExportTest` 9 tests / 29 assertions) alongside the product import regression suite.

---

## CategoryImportTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_unauthenticated_user_cannot_import` | Auth | No token → 401 on POST /import |
| 2 | `test_import_validates_file_required` | Validation | Missing file → 422 |
| 3 | `test_import_validates_file_type` | Validation | Invalid format → 422 |
| 4 | `test_import_dispatches_job_and_returns_202` | Feature | POST /import → 202, import_id, pending |
| 5 | `test_status_endpoint_uses_successful_rows_mapping` | Feature | API exposes `successful_rows` (from `success_rows`) |
| 6 | `test_status_returns_404_for_nonexistent_import` | Edge Case | GET /import/99999 → 404 |
| 7 | `test_service_creates_categories_with_deterministic_slug` | Feature | `Str::slug(name_en)`, no random suffix |
| 8 | `test_service_creates_hierarchy_row_order_independent` | Feature | Child before parent → correct hierarchy |
| 9 | `test_service_updates_existing_category_on_reimport` | Feature | Same name_en → update, no duplicate |
| 10 | `test_service_slug_conflict_is_row_error` | Regression | Slug owned by another → row error |
| 11 | `test_service_missing_parent_is_row_error` | Edge Case | Unknown parent_name_en → row error |
| 12 | `test_service_self_parent_is_row_error_and_category_stays_root` | Edge Case | Self-parent → error, category stays root |
| 13 | `test_service_cycle_assignment_is_row_error` | Regression | Descendant as parent → error |
| 14 | `test_service_duplicate_name_in_file_is_row_error` | Validation | Duplicate name_en in file → error |
| 15 | `test_service_invalid_status_is_row_error` | Validation | Invalid status → error |
| 16 | `test_service_normalizes_boolean_like_values` | Feature | true/false/yes/no normalized |
| 17 | `test_service_empty_status_defaults_to_active_and_featured_to_zero` | Edge Case | Empty → status 1, is_featured 0 |
| 18 | `test_service_rollback_deletes_only_created_categories` | Feature | Cancel rollback → only created soft-deleted |
| 19 | `test_download_sample_returns_file` | Feature | GET /import/sample → xlsx |
| 20 | `test_cancel_import_returns_success` | Feature | POST /import/{id}/cancel → 200 cancelled |
| 21 | `test_cannot_cancel_completed_import` | Edge Case | Terminal → 409 |

---

## CategoryExportTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_unauthenticated_user_cannot_export` | Auth | No token → 401 on GET /export |
| 2 | `test_export_dispatches_job_and_returns_202` | Feature | GET /export → 202, export_id, pending |
| 3 | `test_export_status_endpoint_returns_status` | Feature | GET /export/{id} → 200, status payload |
| 4 | `test_download_returns_409_when_not_ready` | Edge Case | Not completed → 409 |
| 5 | `test_download_returns_file_when_completed` | Feature | Completed → xlsx streamed |
| 6 | `test_export_class_exposes_expected_headings` | Feature | 9 exact import columns |
| 7 | `test_export_class_maps_parent_name_en` | Feature | parent_name_en resolved |
| 8 | `test_export_job_completes_and_writes_file` | Feature | Job → completed, file on public disk |

---

## Coverage Summary

| Category | Count |
|----------|-------|
| Feature Tests (Success) | ~11 |
| Validation Tests | ~6 |
| Authentication / Authorization Tests | ~4 |
| Edge Case Tests | ~7 |
| Regression Tests | ~4 |
| **Total** | **~32** |

---

## Missing Tests (Recommended)

- [ ] Image import with a live HTTP endpoint (SSRF block, unsupported MIME, 5 MB limit, redirect handling)
- [ ] Full job run producing `completed_with_errors` with partial failures
- [ ] `failed` status on corrupt/unreadable Excel
- [ ] Cancel mid-import with mixed created/updated rows
- [ ] Large file performance (10k+ rows, depth 10)
- [ ] Concurrent imports with overlapping names (identity race condition)
- [ ] `cancelling` transient status via status endpoint while cancel signal exists