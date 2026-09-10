# Test Coverage — Brand Module (Public API)

---

## Test Files

No existing test files for public brand endpoints.

**Recommended new file:** `tests/Feature/PublicBrandApiTest.php`

---

## Recommended Test Cases

### List Brands Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_brands` | Feature | GET /general/brands returns 200 with array |
| 2 | `test_list_brands_with_limit` | Feature | Respects limit parameter |
| 3 | `test_list_brands_with_order` | Feature | Respects order (asc/desc) |
| 4 | `test_list_brands_filter_by_ids` | Feature | `?brandsId=1,2` returns specified brands |
| 5 | `test_list_brands_filter_by_dates` | Feature | `?start_date=&end_date=` filters correctly |
| 6 | `test_list_brands_with_slug_param` | Feature | `?slug=nike` returns single brand |
| 7 | `test_list_brands_empty` | Feature | No active brands returns `[]` |
| 8 | `test_list_brands_excludes_inactive` | Feature | Inactive brands excluded |
| 9 | `test_list_brands_excludes_soft_deleted` | Feature | Soft-deleted brands excluded |

### Get Brand by Slug Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_get_brand_by_slug` | Feature | Returns brand with products |
| 2 | `test_get_brand_by_slug_not_found` | Feature | Non-existent slug returns 404 |
| 3 | `test_get_brand_by_slug_inactive` | Feature | Inactive brand returns 404 |
| 4 | `test_get_brand_by_slug_no_products` | Feature | Brand with no products returns empty products array |
| 5 | `test_get_brand_by_slug_with_pricing` | Feature | Products have price_after_discount with active discount |

### Brands Products by Quantity Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_brands_products_default_limits` | Feature | Default 10 products, 10 brands |
| 2 | `test_brands_products_custom_limits` | Feature | `?limit=3&limit_brand=2` respected |
| 3 | `test_brands_products_empty` | Feature | No active brands returns `[]` |
| 4 | `test_brands_products_pricing` | Feature | Products have price_after_discount |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_response_structure` | Feature | Validates top-level keys |
| 2 | `test_brand_object_structure` | Feature | id, name, slug, image, status |
| 3 | `test_image_object_structure` | Feature | image.desktop, image.mobile |
| 4 | `test_brand_detail_response_structure` | Feature | Products array included |
| 5 | `test_product_object_structure` | Feature | id, name, slug, price, price_after_discount, rating, image |

### Channel Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_brands_channel_default` | Feature | No header uses home channel |
| 2 | `test_brands_channel_home` | Feature | X-Channel: home |
| 3 | `test_brands_channel_fast_shipping` | Feature | X-Channel: fast-shipping |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_brand_soft_deleted_excluded` | Feature | Soft-deleted brand not in list |
| 2 | `test_brand_status_inactive_excluded` | Feature | Inactive brand not in list |
| 3 | `test_brand_slug_translation` | Feature | Arabic slug lookup works |
| 4 | `test_brand_large_product_count` | Feature | Brand with 50+ products returns correctly |
