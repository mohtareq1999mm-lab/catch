# Flash Sale Module — QA Test Cases

## Test Files

- `tests/Feature/FlashSales/FlashSaleApiTest.php`
- `tests/Feature/FlashSales/FlashSaleReorderTest.php`
- `tests/Feature/FlashSales/FlashSaleProductionHardenTest.php`

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List flash sales | GET /flash-sale returns paginated results | 200, pagination structure |
| F2 | Create flash sale | POST /flash-sale with valid data | 200, flash sale returned |
| F3 | Show by ID | GET /flash-sale/{id} | 200, flash sale data |
| F4 | Show by slug | GET /flash-sale/{slug} | 200, flash sale data |
| F5 | Update flash sale | PUT /flash-sale/{id} with valid data | 200, updated |
| F6 | Delete flash sale | DELETE /flash-sale/{id} | 200, soft deleted |
| F7 | Reorder flash sales | PUT /flash-sale/reorder with IDs | 200, order updated |
| F8 | Product flash sale info | GET /product-flash-sale-info?id= | 200, flash sale array |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Create without title | Missing title | 422 |
| V2 | Create without image-desktop | Missing image file | 422 |
| V3 | Create with invalid image type | Non-image file | 422 |
| V4 | Create with duplicate title | Existing title | 422 |
| V5 | Create with invalid type | Not percentage/fixed_rate/final_price | 422 |
| V6 | Create without end_date | Missing end_date | 422 |
| V7 | Update with invalid status | Value other than 0/1 | 422 |
| V8 | Update with non-existent product | Invalid product ID | 422 |
| V9 | Reorder with invalid ID | Non-existent flash sale ID | 422 |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest cannot list | No auth token | 401 |
| A2 | Guest cannot create | No auth token | 401 |
| A3 | Guest cannot update | No auth token | 401 |
| A4 | Guest cannot delete | No auth token | 401 |
| A5 | Guest cannot reorder | No auth token | 401 |
| A6 | No view permission | Missing `view-flash-sale` | 403 |
| A7 | No create permission | Missing `create-flash-sale` | 403 |
| A8 | No update permission | Missing `update-flash-sale` | 403 |
| A9 | No delete permission | Missing `delete-flash-sale` | 403 |

---

## Discount Type Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| D1 | Percentage discount | type=percentage, discount=25 | Product price reduced by 25% |
| D2 | Percentage with max cap | discount=50, max_discount_amount=100 | Price reduction capped at 100 |
| D3 | Fixed rate discount | type=fixed_rate, discount=10 | Product price reduced by 10 |
| D4 | Final price | type=final_price, discount=49.99 | Product price set to 49.99 |
| D5 | Missing max_discount_amount for percentage | type=percentage, no max | 422 validation error |

---

## Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Show non-existent | GET /flash-sale/99999 | 404 |
| E2 | Update non-existent | PUT /flash-sale/99999 | 404 |
| E3 | Delete non-existent | DELETE /flash-sale/99999 | 404 |
| E4 | Empty list | No flash sales in database | 200, empty data |
| E5 | Search empty string | GET /flash-sale?search= | 200, all results |
| E6 | Contradictory filters | active=true & inactive=true | 200, empty result |
| E7 | End date before start date | start_date > end_date | 200 (no validation) |
| E8 | Product flash sale info not found | Non-existent product ID | 200, empty array |

---

## Slug Behavior Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Slug auto-generated from title | Create with title only | Slug = slugified en title |
| S2 | Slug preserved on non-title update | Update status/date | Slug unchanged |
| S3 | Slug regenerated on title update | Change title | Slug updates |

---

## Soft Delete / Restore Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| D1 | Flash sale is soft deleted | DELETE → check deleted_at | Soft deleted |
| D2 | Pivot removed on delete | Check flash_sale_products after | Hard deleted (CASCADE) |
| D3 | Flash sale can be restored | restore() → check deleted_at | Restored |

---

## Price Recalculation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| P1 | Create sets price_after_flash_sale | Create with products | Products have calculated price |
| P2 | Update recalculates prices | Change discount value | Prices recalculated |
| P3 | Deactivating clears prices | Set status=0 | price_after_flash_sale = null |
| P4 | Expired sale clears prices | end_date in the past | price_after_flash_sale = null |

---

## Missing Coverage

- [ ] Reorder with empty array `[]`
- [ ] Reorder with non-array input
- [ ] Create with all three discount types in detail
- [ ] Date validation: end_date before start_date (no server-side check)
- [ ] `max_discount_amount` validation for non-percentage types
- [ ] Image upload exceeding max size
- [ ] Image with invalid mime type
- [ ] Vendor request approve/disapprove full flow
- [ ] Vendor request with non-existent flash_sale_id
- [ ] Multiple flash sales for the same product
- [ ] Race condition: delete while reordering
- [ ] Public API endpoints authorization (should work without token)
- [ ] Product `has_flash_sale` flag after flash sale update
- [ ] Price recalculation for variable products
