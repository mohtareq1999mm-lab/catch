# Test Coverage — Review Module

---

## Test Files

| File | Lines | Focus |
|------|-------|-------|
| `tests/Feature/ProductCrudTest.php` (review section) | ~100 | Core API CRUD, toggle-approve, auth, 404s |

**Note:** There are no dedicated review test files. All 14 review tests reside within the `ProductCrudTest.php` file.

---

## Review Test Coverage (from ProductCrudTest.php)

### Guest Authorization Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_guest_cannot_list_reviews` | Authorization | GET /reviews without token → 401 |
| 2 | `test_guest_cannot_create_review` | Authorization | POST /reviews without token → 401 |
| 3 | `test_guest_cannot_show_review` | Authorization | GET /reviews/{id} without token → 401 |
| 4 | `test_guest_cannot_update_review` | Authorization | PUT /reviews/{id} without token → 401 |
| 5 | `test_guest_cannot_delete_review` | Authorization | DELETE /reviews/{id} without token → 401 |
| 6 | `test_guest_cannot_toggle_approve_review` | Authorization | PATCH /reviews/{id}/toggle-approve without token → 401 |

### Admin Success Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 7 | `test_admin_can_list_reviews` | Feature | GET /reviews with product_id returns paginated reviews |
| 8 | `test_list_reviews_requires_product_id` | Validation | GET /reviews without product_id → 422 |
| 9 | `test_admin_can_show_review` | Feature | GET /reviews/{id} returns review |
| 10 | `test_show_nonexistent_review_returns_404` | Edge Case | GET /reviews/99999 → 404 |
| 11 | `test_admin_can_toggle_review_approval` | Feature | PATCH /reviews/{id}/toggle-approve toggles approval |
| 12 | `test_toggle_approve_nonexistent_review_returns_error` | Edge Case | POST /reviews/99999/toggle-approve → 404 |
| 13 | `test_admin_can_delete_review` | Feature | DELETE /reviews/{id} soft deletes |
| 14 | `test_delete_nonexistent_review_returns_404` | Edge Case | DELETE /reviews/99999 → 404 |

---

## Coverage Summary

| Category | Count |
|----------|-------|
| Authorization Tests | 6 |
| Feature Tests (Success) | 4 |
| Validation Tests | 1 |
| Edge Case Tests | 3 |
| **Total** | **14** |

---

## Missing Tests (Recommended)

- [ ] **Create review** — POST /reviews with valid data → 200, review returned
- [ ] **Update review** — PUT /reviews/{id} with valid data → 200, updated review
- [ ] **Create validation** — Missing comment → 422
- [ ] **Create validation** — Missing rating → 422
- [ ] **Create validation** — Rating < 1 → 422
- [ ] **Create validation** — Rating > 5 → 422
- [ ] **Create validation** — Invalid product_id → 422
- [ ] **Update validation** — Missing comment → 422
- [ ] **Update validation** — Rating < 1 → 422
- [ ] **Create duplicate review** — Same product + same user → 400
- [ ] **Rate limiting** — 6+ POST requests in 1 minute → 429
- [ ] **JSON structure** — Single review response field verification
- [ ] **JSON structure** — List response pagination meta
- [ ] **JSON structure** — `is_approved` conditional presence
- [ ] **Empty review list** — Product with no reviews → 200 empty array
- [ ] **Soft delete verification** — Review's `deleted_at` is set after delete
- [ ] **Multiple reviews** — Different users reviewing same product
- [ ] **Toggle twice** — Toggle approval on → off → on = original state
- [ ] **Forbidden: delete without permission** — 403
- [ ] **Forbidden: toggle without permission** — 403
