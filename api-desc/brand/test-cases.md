# Test Coverage — Brand Module

---

## Test Files

| File | Lines | Focus |
|------|-------|-------|
| `tests/Feature/BrandApiTest.php` | 643 | Core API CRUD, pagination, search, filtering, slugs |
| `tests/Feature/BrandProductionHardenTest.php` | 436 | Production scenarios: reorder, media, soft delete, edge cases |

---

## BrandApiTest.php Coverage

### Brand Listing Tests (Public + Admin)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_brands` | Feature | GET /brands returns paginated brands |
| 2 | `test_list_brands_pagination` | Feature | Verify pagination structure (page, per_page, total) |
| 3 | `test_list_brands_search` | Feature | Search by name returns filtered results |
| 4 | `test_list_brands_order` | Feature | Order by name/slug/id/status/created_at |
| 5 | `test_list_brands_active_filter` | Feature | Filter by active=true |
| 6 | `test_list_brands_inactive_filter` | Feature | Filter by inactive=true |

### Show Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 7 | `test_show_brand_by_id` | Feature | GET /brands/{id} returns brand |
| 8 | `test_show_brand_by_slug` | Feature | GET /brands/{slug} returns brand |

### Create Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 9 | `test_create_brand` | Feature | POST /brands with valid data, images, products |
| 10 | `test_create_brand_validation` | Validation | Missing required fields returns 422 |

### Update Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 11 | `test_update_brand` | Feature | PUT /brands/{id} updates fields |
| 12 | `test_update_brand_products` | Feature | Update product associations |
| 13 | `test_update_brand_translations` | Feature | Update multilingual fields |

### Delete Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 14 | `test_delete_brand` | Feature | DELETE /brands/{id} soft deletes |
| 15 | `test_brand_soft_delete_restore` | Feature | Restore soft-deleted brand |

### Slug Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 16 | `test_slug_auto_generation` | Regression | Create without slug → auto-generated |
| 17 | `test_slug_preserved_on_update` | Regression | Update non-name field → slug unchanged |
| 18 | `test_slug_changes_on_name_update` | Regression | Update name → slug regenerated |
| 19 | `test_slug_stable_on_status_change` | Regression | Update status → slug unchanged |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 20 | `test_brand_response_structure` | Feature | Verify JSON response shape |

---

## BrandProductionHardenTest.php Coverage

### Slug Preservation (Regression: BUG-1)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_slug_does_not_change_when_updating_non_name_field` | Regression | Status update → slug unchanged |
| 2 | `test_slug_changes_when_name_changes` | Regression | Name update → slug regenerated |
| 3 | `test_slug_does_not_change_when_updating_details_alone` | Regression | Details update → slug unchanged |

### Unique Name Validation

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 4 | `test_create_brand_with_duplicate_name_returns_422` | Validation | Duplicate name → 422 |

### Soft Delete / Restore

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 5 | `test_soft_deleted_brand_can_be_restored` | Feature | Restore after soft delete |
| 6 | `test_soft_deleted_brand_still_has_pivot_relations` | Edge Case | Pivot preserved after delete + restore |

### Media Lifecycle

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 7 | `test_create_brand_with_images` | Feature | Images assigned to collections |
| 8 | `test_update_brand_images` | Feature | Old images replaced, new images assigned |

### Brand-Product Sync

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 9 | `test_sync_brand_products_does_not_create_duplicates` | Edge Case | Sync same product twice → single pivot row |
| 10 | `test_update_brand_replaces_products` | Feature | Products replaced, old associations removed |

### Reorder

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 11 | `test_reorder_brands` | Feature | Swap order of two brands |
| 12 | `test_reorder_with_invalid_brand_id_returns_422` | Validation | Non-existent ID → 422 |

### Search and Filter Edge Cases

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 13 | `test_list_brands_with_empty_search_returns_all` | Edge Case | Empty search returns all |
| 14 | `test_list_brands_with_contradictory_filters_returns_empty` | Edge Case | active=true + inactive=true → empty |

### Mass Assignment Protection

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 15 | `test_mass_assignment_protection` | Security | Injecting `id` field is ignored |

### API Response Structure

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 16 | `test_brand_list_response_structure` | Feature | Verify JSON structure with all fields |

### Error Handling

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 17 | `test_create_brand_fails_with_no_images` | Validation | Missing images → 422 |
| 18 | `test_update_nonexistent_brand_returns_404` | Edge Case | Update brand 99999 → 404 |

---

## Coverage Summary

| Category | Count |
|----------|-------|
| Feature Tests (Success) | ~15 |
| Validation Tests | ~6 |
| Regression Tests | ~6 |
| Edge Case Tests | ~6 |
| Security Tests | ~1 |
| **Total (estimate)** | ~38 |

---

## Missing Tests (Recommended)

- [ ] **Force delete** — force delete a brand with products → pivot records removed
- [ ] **Restore via API** — no endpoint exists; test should verify this is a gap
- [ ] **Reorder with empty array** `[]` → should return 422
- [ ] **Reorder with non-array** `"invalid"` → should return 422
- [ ] **Reorder with single duplicate ID** `[1, 1]` → check behavior (duplicates in array)
- [ ] **Authorization: view-only user cannot create/update/delete** — verify 403
- [ ] **Authorization: user without permission cannot view brands** — verify 403
- [ ] **Public API authorization** — verify public endpoints work without token
- [ ] **Image upload exceeding max size** — file > 2MB → 422
- [ ] **Image upload with invalid mime type** — .txt file → 422
- [ ] **Create brand without image-desktop but with image-mobile** → 422
- [ ] **JSON structure of single brand response** — exact field match
- [ ] **Translation fallback** — GET brand with locale that has no translation → fallback behavior
- [ ] **Concurrent reorder requests** — race condition test
- [ ] **Delete brand while reordering** — brand deleted between validation and setNewOrder
- [ ] **Product sync with duplicate IDs** `[1, 1, 2]` → should deduplicate to `[1, 2]`
