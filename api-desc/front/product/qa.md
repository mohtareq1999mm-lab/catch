# QA - Product Feature

## Test Environment Setup

- **PHP Version:** 8.x
- **Laravel Version:** As defined in `composer.json`
- **Package:** `packages/marvel/`
- **Database:** MySQL with `RefreshDatabase` trait
- **Authentication:** Sanctum for admin endpoints
- **Translations:** Spatie Translatable for name/description
- **Search:** Laravel Scout with Meilisearch driver
- **Media:** Spatie Media Library for images
- **Storage:** Local filesystem for uploaded images

## Existing Test Coverage

**8 test files in `tests/Feature/` + 1 Unit test:**

| Test File | Lines | Focus |
|-----------|-------|-------|
| `ProductCrudTest.php` | 807 | CRUD operations |
| `ProductAdminTest.php` | 322 | Admin CRUD with permissions |
| `ProductFilterTest.php` | 139 | Filtering |
| `ProductProductionHardenTest.php` | 610 | N+1, pricing, bulk operations |
| `ProductExportTest.php` | 139 | CSV export |
| `ProductImportTest.php` | 1207 | CSV import |
| `ProductTagTest.php` | 386 | Product-tag relations |
| `ProductPricingServiceTest.php` | Unit | Pricing unit tests |

## Test Matrix (Supplemental)

### Public API Tests

| TC ID | Description | Input | Expected |
|-------|-------------|-------|----------|
| TC-FT-001 | Public product listing (all) | `GET /api/v1/general/products` | 200, paginated |
| TC-FT-002 | Strategy: new arrivals | `?type=new_arrivals` | Products from last 15 days |
| TC-FT-003 | Strategy: best sellers | `?type=best_product_sales` | Ordered by volume |
| TC-FT-004 | Strategy: flash sales | `?type=flash_sales_product` | Flash sale products only |
| TC-FT-005 | Filter by category | `?category=electronics` | Filtered correctly |
| TC-FT-006 | Filter by brand | `?brand=nike` | Filtered correctly |
| TC-FT-007 | Filter by price range | `?minPrice=10&maxPrice=100` | Bounded prices |
| TC-FT-008 | Combined filters | `?category=shoes&brand=nike&minPrice=50` | All applied |
| TC-FT-009 | Full-text search | `?search=wireless` | Scout matched results |
| TC-FT-010 | Product detail | `GET /.../products/slug` | 200, full detail |
| TC-FT-011 | Product detail with flash sale | Product with active flash sale | current_price = flash price |
| TC-FT-012 | Product detail with discount | Product with active discount | current_price = discount price |

### Admin CRUD Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-CRUD-001 | Create product with required fields | 201 |
| TC-CRUD-002 | Create with missing name | 422 |
| TC-CRUD-003 | Create with missing categories | 422 |
| TC-CRUD-004 | Create with missing images | 422 |
| TC-CRUD-005 | Create variable product with variants | 201, variants created |
| TC-CRUD-006 | Update product partial | 200 |
| TC-CRUD-007 | Update product pricing | Price recalculated |
| TC-CRUD-008 | Delete product | 200, soft-deleted |
| TC-CRUD-009 | Bulk delete | All deleted |
| TC-CRUD-010 | Destroy all | All products deleted |

### Import/Export Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-IE-001 | Import valid CSV | Products created, job dispatched |
| TC-IE-002 | Import invalid file type | 422 |
| TC-IE-003 | Import with progress tracking | Status updated |
| TC-IE-004 | Export products | File download |
| TC-IE-005 | Export with filters | Filtered export |

### Authorization Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-AUTH-001 | Guest access to admin list | 401 |
| TC-AUTH-002 | User without view-products | 403 |
| TC-AUTH-003 | User without create-product | 403 |
| TC-AUTH-004 | User without update-product | 403 |
| TC-AUTH-005 | User without delete-product | 403 |
| TC-AUTH-006 | Public endpoints (no auth) | 200 |

### Pricing Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-PR-001 | Base price only | current_price = base |
| TC-PR-002 | Discount applied | current_price = discounted |
| TC-PR-003 | Flash sale overrides discount | current_price = flash sale |
| TC-PR-004 | Expired discount | discount_active = false |
| TC-PR-005 | Expired flash sale | flash_sale_active = false |
| TC-PR-006 | Variant pricing | variant price used |
| TC-PR-007 | Discount = percentage | Correct calculation |
| TC-PR-008 | Discount = fixed | Correct calculation |

### Edge Case Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-EC-001 | Empty product catalog | Empty data array |
| TC-EC-002 | Product with no images | Handles gracefully |
| TC-EC-003 | Product with 0 stock | Not in active listings |
| TC-EC-004 | Product with negative price | Validation error |
| TC-EC-005 | Import with 10,000+ rows | Chunked, no memory issues |
| TC-EC-006 | Bulk delete with duplicate IDs | Deduplicated |
| TC-EC-007 | Search with SQL injection characters | Safe |

## Manual Test Checklist

- [ ] Verify public product listing loads with correct strategy
- [ ] Verify product detail page shows all fields
- [ ] Verify admin can create simple product
- [ ] Verify admin can create variable product with variants
- [ ] Verify discount pricing is applied correctly
- [ ] Verify flash sale pricing overrides discount
- [ ] Verify admin can update product fields
- [ ] Verify admin can delete product (single + bulk)
- [ ] Verify CSV import creates products correctly
- [ ] Verify CSV export downloads correct data
- [ ] Verify rental price calculation returns breakdown
- [ ] Verify best selling/popular products endpoints work
- [ ] Verify permission enforcement for each CRUD operation
- [ ] Verify GraphQL queries return correct product data
- [ ] Verify GraphQL mutations create/update/delete products
- [ ] Verify fast shipping toggle works
- [ ] Verify draft products listing works
- [ ] Verify low stock products listing works
