# Review Module — QA Test Cases

## Test Files

- `tests/Feature/ProductCrudTest.php` (review section) — 14 tests, ~100 lines

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List reviews | GET /reviews?product_id=1 returns reviews | 200, array of reviews |
| F2 | Create review | POST /reviews with valid data | 200, review returned |
| F3 | Show review by ID | GET /reviews/{id} | 200, review data |
| F4 | Update review | PUT /reviews/{id} with valid data | 200, updated review |
| F5 | Delete review | DELETE /reviews/{id} | 200, soft deleted |
| F6 | Toggle approve | PATCH /reviews/{id}/toggle-approve | 200, approval toggled |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | List without product_id | Missing product_id | 422 |
| V2 | Create without comment | Missing comment | 422 |
| V3 | Create without rating | Missing rating | 422 |
| V4 | Create with rating < 1 | Rating = 0 | 422 |
| V5 | Create with rating > 5 | Rating = 6 | 422 |
| V6 | Create with invalid product_id | Non-existent product | 422 |
| V7 | Update without comment | Missing comment | 422 |
| V8 | Update without rating | Missing rating | 422 |
| V9 | Update with rating < 1 | Rating = 0 | 422 |
| V10 | Update with rating > 5 | Rating = 6 | 422 |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest cannot list | No auth token | 401 |
| A2 | Guest cannot create | No auth token | 401 |
| A3 | Guest cannot show | No auth token | 401 |
| A4 | Guest cannot update | No auth token | 401 |
| A5 | Guest cannot delete | No auth token | 401 |
| A6 | Guest cannot toggle | No auth token | 401 |
| A7 | User without approve permission | No `approve-reviews` permission | 403 |
| A8 | User without delete permission | No `delete-reviews` permission | 403 |

---

## Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Show non-existent review | GET /reviews/99999 | 404 |
| E2 | Update non-existent review | PUT /reviews/99999 | 404 |
| E3 | Delete non-existent review | DELETE /reviews/99999 | 404 |
| E4 | Toggle non-existent review | POST /reviews/99999/toggle-approve | 404 |
| E5 | Empty review list | Product has no reviews | 200, empty data array |
| E6 | Duplicate review | Submit second review for same product+user | 400 |
| E7 | Multiple reviews for same product | Two different users review same product | 200, both visible |

---

## Rate Limiting Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Exceed create rate limit | 6+ POST requests in 1 minute | 429 |
| R2 | Exceed update rate limit | 6+ PUT requests in 1 minute | 429 |

---

## JSON Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| J1 | Review response structure | Verify JSON keys | id, rating, comment, images, is_approved (conditional) |
| J2 | is_approved conditional | User with/without approve permission | Present only with permission |
| J3 | Pagination structure | List response | data array with review objects |

---

## Soft Delete Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| D1 | Review is soft deleted | DELETE → check deleted_at | Soft deleted |
| D2 | Review not visible after delete | GET /reviews/{id} after delete | 404 |

---

## Missing Coverage

- [ ] Rate limiting (429) on store/update — no test throttles hit the limit
- [ ] Create with max rating (5) and min rating (1) boundary
- [ ] Create with very long comment text (boundary: longText column)
- [ ] Multiple reviews for different products by same user
- [ ] Review with images (validation is commented out — test gap irrelevant until restored)
- [ ] Force delete review (no endpoint exists)
- [ ] Restore soft-deleted review (no endpoint exists)
- [ ] Concurrent duplicate review creation (race condition)
- [ ] Toggle approve multiple times (on/off/on = original state)
- [ ] JSON structure when is_approved is absent (user without permission)
- [ ] Review with rating = null (column allows null, but validation requires it)
