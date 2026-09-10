# Test Coverage — Banner Module (Public API)

---

## Test Files

No existing test files for public banner endpoints.

**Recommended new file:** `tests/Feature/PublicBannerApiTest.php`

---

## Recommended Test Cases

### List Banners Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_banners` | Feature | GET /general/banners returns 200 with array |
| 2 | `test_list_banners_with_limit` | Feature | Respects limit parameter |
| 3 | `test_list_banners_with_order` | Feature | Respects order (asc/desc) |
| 4 | `test_list_banners_filter_by_ids` | Feature | `?bannersId=1,2` returns specified banners |
| 5 | `test_list_banners_filter_by_dates` | Feature | `?start_date=&end_date=` filters correctly |
| 6 | `test_list_banners_with_slug_param` | Feature | `?slug=x` returns single banner |
| 7 | `test_list_banners_empty` | Feature | No active banners returns `[]` |
| 8 | `test_list_banners_excludes_inactive` | Feature | Inactive banners excluded |
| 9 | `test_list_banners_excludes_soft_deleted` | Feature | Soft-deleted banners excluded |

### Get Banner by Slug Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_get_banner_by_slug` | Feature | Returns banner with products |
| 2 | `test_get_banner_by_slug_without_products` | Feature | `?with_products=false` excludes products |
| 3 | `test_get_banner_by_slug_with_products_false_string` | Feature | `?with_products=false` (literal string) |
| 4 | `test_get_banner_by_slug_not_found` | Feature | Non-existent slug returns 404 |
| 5 | `test_get_banner_by_slug_inactive` | Feature | Inactive banner returns 404 |
| 6 | `test_get_banner_by_slug_no_products` | Feature | Banner with no associated products |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_response_structure` | Feature | Validates top-level keys |
| 2 | `test_banner_object_structure` | Feature | id, title, slug, description, image, status |
| 3 | `test_image_object_structure` | Feature | image.desktop, image.mobile |
| 4 | `test_banner_detail_with_products_structure` | Feature | Products array included |
| 5 | `test_banner_detail_without_products_structure` | Feature | No products key when excluded |
| 6 | `test_translated_fields` | Feature | Title in correct locale |

### Channel Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_banners_channel_default` | Feature | No header works |
| 2 | `test_banners_channel_home` | Feature | X-Channel: home |
| 3 | `test_banners_channel_fast_shipping` | Feature | X-Channel: fast-shipping |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_banner_soft_deleted_excluded` | Feature | Soft-deleted not in list |
| 2 | `test_banner_inactive_excluded` | Feature | Inactive not in list |
| 3 | `test_banner_slug_auto_generated` | Feature | Slug from English title |
