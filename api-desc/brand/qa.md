# Brand Module — QA Test Cases

## Test Files

- `tests/Feature/BrandApiTest.php` — 643 lines
- `tests/Feature/BrandProductionHardenTest.php` — 436 lines

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List brands | GET /brands returns paginated brands | 200, pagination structure |
| F2 | Create brand | POST /brands with valid data | 201, brand returned |
| F3 | Show brand by ID | GET /brands/{id} | 200, brand data |
| F4 | Show brand by slug | GET /brands/{slug} | 200, brand data |
| F5 | Update brand | PUT /brands/{id} with valid data | 200, updated brand |
| F6 | Delete brand | DELETE /brands/{id} | 200, soft deleted |
| F7 | Reorder brands | PUT /brands/reorder with IDs | 200, order updated |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Create without name | Missing name | 422 |
| V2 | Create without image-desktop | Missing image file | 422 |
| V3 | Create with invalid image type | Non-image file | 422 |
| V4 | Create with duplicate name | Existing name | 422 |
| V5 | Update with invalid status | Value other than 0/1 | 422 |
| V6 | Update with non-existent product | Invalid product ID in products array | 422 |
| V7 | Reorder with invalid brand ID | Non-existent ID | 422 |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest cannot create | No auth token | 401 |
| A2 | Guest cannot update | No auth token | 401 |
| A3 | Guest cannot delete | No auth token | 401 |
| A4 | Guest cannot reorder | No auth token | 401 |
| A5 | User without view permission | No `view-brands` permission | 403 |
| A6 | User without create permission | No `create-brand` permission | 403 |
| A7 | User without update permission | No `update-brand` permission (update + reorder) | 403 |
| A8 | User without delete permission | No `delete-brand` permission | 403 |

---

## Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Show non-existent brand | GET /brands/99999 | 404 |
| E2 | Update non-existent brand | PUT /brands/99999 | 404 |
| E3 | Delete non-existent brand | DELETE /brands/99999 | 404 |
| E4 | Empty brand list | No brands in database | 200, empty data array |
| E5 | Search with empty string | GET /brands?search= | 200, all brands |
| E6 | Contradictory filters | GET /brands?active=true&inactive=true | 200, empty result |
| E7 | Reorder with single brand | Array with one ID | 200, order = 1 |
| E8 | Brands with no products | Brand exists but no pivot | 200, products omitted |

---

## Slug Behavior Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Slug auto-generated from name | Create with name only | Slug = slugified en name |
| S2 | Slug preserved on non-name update | Update status/details | Slug unchanged |
| S3 | Slug regenerated on name update | Change name | Slug updates |
| S4 | Custom slug preserved | Create with explicit slug | Slug preserved |

---

## Soft Delete / Restore Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| D1 | Brand is soft deleted | DELETE → check deleted_at | Soft deleted |
| D2 | Brand can be restored | restore() → check deleted_at | Restored |
| D3 | Pivot preserved on soft delete | Check brand_product after delete | Count unchanged |
| D4 | Pivot accessible after restore | Check products() after restore | Count matches |

---

## Reorder Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Basic reorder | Swap order of two brands | order values swapped |
| R2 | Reorder with invalid ID | Array containing 99999 | 422 |

---

## Media Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| M1 | Create brand with images | Valid image files | Media assigned to collections |
| M2 | Update brand images | Replace existing images | Old cleared, new assigned |

---

## Missing Coverage

- [ ] Force delete brand → pivot records cleaned up
- [ ] Race condition: concurrent reorder requests
- [ ] Race condition: delete while reordering (brand deleted between validation and setNewOrder)
- [ ] Large reorder array (1000+ brands) performance
- [ ] Reorder with empty array `[]`
- [ ] Reorder with non-array `"invalid"`
- [ ] Brand created without images (should fail with 422)
- [ ] Image file exceeds max size (2MB)
- [ ] Image with invalid mime type
- [ ] Brand-Product sync: sending duplicate product IDs
- [ ] Multiple image uploads in single request (desktop + mobile)
- [ ] Translation fallback when locale not provided
