# Test Coverage — Flash Sale Module

---

## Test Files

| File | Focus |
|------|-------|
| `tests/Feature/FlashSales/FlashSaleApiTest.php` | Core API CRUD, pagination, search, filtering |
| `tests/Feature/FlashSales/FlashSaleReorderTest.php` | Reorder scenarios |
| `tests/Feature/FlashSales/FlashSaleProductionHardenTest.php` | Production scenarios: pricing, vendor requests, edge cases |

---

## FlashSaleApiTest.php Coverage

### Flash Sale Listing Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_flash_sales` | Feature | GET /flash-sale returns paginated results |
| 2 | `test_list_flash_sales_pagination` | Feature | Verify pagination structure |
| 3 | `test_list_flash_sales_search` | Feature | Search by title |
| 4 | `test_list_flash_sales_order` | Feature | Order by various columns |
| 5 | `test_list_flash_sales_active_filter` | Feature | Filter by valid scope |
| 6 | `test_list_flash_sales_inactive_filter` | Feature | Filter by invalid scope |

### Show Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 7 | `test_show_flash_sale_by_id` | Feature | GET /flash-sale/{id} |
| 8 | `test_show_flash_sale_by_slug` | Feature | GET /flash-sale/{slug} |

### Create Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 9 | `test_create_flash_sale` | Feature | POST with valid data, images, products |
| 10 | `test_create_flash_sale_validation` | Validation | Missing required fields |

### Update Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 11 | `test_update_flash_sale` | Feature | PUT updates fields |
| 12 | `test_update_flash_sale_products` | Feature | Update product associations |
| 13 | `test_update_flash_sale_translations` | Feature | Update multilingual fields |

### Delete Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 14 | `test_delete_flash_sale` | Feature | DELETE soft deletes |
| 15 | `test_soft_delete_restore` | Feature | Restore soft-deleted |

### Response Structure

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 16 | `test_flash_sale_response_structure` | Feature | Verify JSON shape |

---

## FlashSaleReorderTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_reorder_flash_sales` | Feature | Basic reorder |
| 2 | `test_reorder_invalid_id` | Validation | Non-existent ID → 422 |
| 3 | `test_reorder_unauthorized` | Authorization | No permission → 403 |

---

## FlashSaleProductionHardenTest.php Coverage

### Slug Preservation

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_slug_preserved` | Regression | Non-title update → slug unchanged |
| 2 | `test_slug_changes_on_title_update` | Regression | Title update → slug regenerated |

### Unique Title Validation

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 3 | `test_duplicate_title_422` | Validation | Duplicate title → 422 |

### Soft Delete / Restore

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 4 | `test_soft_delete_restore` | Feature | Restore after soft delete |
| 5 | `test_pivot_removed_on_delete` | Edge Case | Pivot hard-deleted on flash sale delete |

### Media Lifecycle

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 6 | `test_create_with_images` | Feature | Images assigned to collections |
| 7 | `test_update_images` | Feature | Old replaced, new assigned |

### Flash Sale Types

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 8 | `test_percentage_discount` | Feature | Percentage price calculation |
| 9 | `test_fixed_rate_discount` | Feature | Fixed rate calculation |
| 10 | `test_final_price_discount` | Feature | Final price calculation |
| 11 | `test_percentage_with_max_cap` | Feature | Max discount amount applied |

### Price Recalculation

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 12 | `test_price_recalculated_on_update` | Feature | Changing discount recalculates prices |
| 13 | `test_price_cleared_on_deactivate` | Feature | Status=0 clears price_after_flash_sale |
| 14 | `test_price_cleared_on_expire` | Edge Case | Past end_date clears prices |

### Error Handling

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 15 | `test_missing_images_422` | Validation | No images → 422 |
| 16 | `test_nonexistent_update_404` | Edge Case | Update ID 99999 → 404 |
| 17 | `test_nonexistent_delete_404` | Edge Case | Delete ID 99999 → 404 |

---

## Coverage Summary

| Category | Count (estimated) |
|----------|-------------------|
| Feature Tests (Success) | ~20 |
| Validation Tests | ~8 |
| Regression Tests | ~4 |
| Edge Case Tests | ~6 |
| Security/Authorization Tests | ~5 |
| **Total (estimate)** | ~43 |

---

## Missing Tests (Recommended)

- [ ] **Reorder with empty array** `[]` → 422
- [ ] **Reorder with non-array** `"invalid"` → 422
- [ ] **Flash sale info with non-existent product ID** → 404 (or 200 empty)
- [ ] **Authorization: view-only user cannot create/update/delete** → 403
- [ ] **Public API works without token** → 200
- [ ] **Image upload exceeding max size** → 422
- [ ] **Image upload with invalid mime type** → 422
- [ ] **End date before start date** — no validation, should check behavior
- [ ] **`max_discount_amount` missing for percentage type** → 422
- [ ] **`max_discount_amount` present for non-percentage type** → allowed?
- [ ] **Product `has_flash_sale` flag** — verify flag after association and removal
- [ ] **Vendor request approve flow** — full E2E with product attachment
- [ ] **Vendor request disapprove flow** — product detachment
- [ ] **Flash sale with 0 discount** → allowed? (min:0)
- [ ] **Flash sale with negative discount** → 422
- [ ] **Create without status** → default behavior
- [ ] **Concurrent flash sale updates** — race condition
- [ ] **Flash sale with 1000+ products** — performance
