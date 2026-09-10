# QA - Slider Feature

## Test Environment Setup

- **PHP Version:** 8.x
- **Laravel Version:** As defined in `composer.json`
- **Package:** `packages/marvel/`
- **Database:** MySQL with `DatabaseTransactions` trait
- **Media:** Spatie Media Library (local `sliders` disk)
- **Permissions:** Spatie Permission seeded with slider permissions

## Existing Test Coverage

**1 test file — `tests/Feature/SliderApiTest.php`** (615 lines, 29 test methods)

| Category | Tests |
|----------|-------|
| **List (index)** | Authenticated list, guest 401, empty data, pagination, active filter |
| **Show** | Authenticated show, guest 401, 404 for nonexistent |
| **Create** | Unauthenticated 401, forbidden 403, admin creates, missing title 422, missing images 422 |
| **Update** | Unauthenticated 401, forbidden 403, admin updates, 404 for nonexistent |
| **Delete** | Unauthenticated 401, forbidden 403, admin soft-deletes, 404 for nonexistent, not listed, 404 on show |
| **Change Status** | Unauthenticated 401, forbidden 403, admin toggles, 422 missing id, 422 nonexistent id |
| **Reorder** | Unauthenticated 401, forbidden 403, admin reorders, 422 missing sliders, 422 invalid ids |
| **Product Relation** | Create with product association |
| **Translation** | Title is translatable |
| **Response Structure** | Resource structure on show, title is object on show, title is string on index |

## Test Matrix (Supplemental)

### Functional Tests

| TC ID | Description | Input | Expected |
|-------|-------------|-------|----------|
| TC-FT-001 | Public slider listing | `GET /api/v1/general/sliders` | 200, array of sliders |
| TC-FT-002 | Public slider with limit | `?limit=3` | Max 3 results |
| TC-FT-003 | Public slider by slug | `GET /api/v1/general/sliders/summer-sale` | 200, single slider with products |
| TC-FT-004 | Public slider by slug (invalid) | `GET /api/v1/general/sliders/nonexistent` | 404 |
| TC-FT-005 | Admin listing with active filter | `?status=true` | Only active sliders |
| TC-FT-006 | Admin listing pagination | `?page=2&limit=5` | Paginated results |

### Security Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-SEC-001 | Guest access to admin list | 401 |
| TC-SEC-002 | Guest access to admin create | 401 |
| TC-SEC-003 | User without create-slider permission | 403 |
| TC-SEC-004 | User without delete-slider permission | 403 |
| TC-SEC-005 | Public endpoints (no auth) | 200 |

### Edge Case Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-EC-001 | Upload invalid image type (e.g., .pdf) | 422 |
| TC-EC-002 | Upload oversize image (>2MB) | 422 |
| TC-EC-003 | Reorder with empty array | 422 |
| TC-EC-004 | Create slider without images | 422 |
| TC-EC-005 | Update slider without changes | 200, unchanged |
| TC-EC-006 | Toggle status on already inactive slider | Toggles back to active |
| TC-EC-007 | Duplicate title (unique translation) | 422 |
| TC-EC-008 | Create slider with all locales | EN + AR titles required |

## Manual Test Checklist

- [ ] Verify public slider endpoint returns active sliders in correct order
- [ ] Verify slider detail endpoint returns products with pricing
- [ ] Verify admin can create slider with EN + AR titles and images
- [ ] Verify admin can update slider (title, images, status)
- [ ] Verify admin can soft-delete slider
- [ ] Verify soft-deleted slider absent from public and admin listings
- [ ] Verify status toggle works (active ↔ inactive)
- [ ] Verify drag-and-drop reorder updates `order` column
- [ ] Verify associated products appear in slider response
- [ ] Verify image fallback works (create vs update collections)
- [ ] Verify permission enforcement for each CRUD operation
