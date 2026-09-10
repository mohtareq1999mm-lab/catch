# Test Coverage — Flash Sale Module (Public API)

---

## Existing Tests

| File | Test | Coverage |
|------|------|----------|
| `FastShippingControllerTest` | `flash_sales_endpoint_works_with_channel_header` | Channel header only |

---

## Recommended Test Cases

### List Flash Sales Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_flash_sales` | Feature | 200, paginated, valid only |
| 2 | `test_list_flash_sales_with_limit` | Feature | Respects limit |
| 3 | `test_list_flash_sales_filter_by_ids` | Feature | `?flashSalesId=1,2` |
| 4 | `test_list_flash_sales_with_slug_param` | Feature | `?slug=x` returns single |
| 5 | `test_list_flash_sales_excludes_expired` | Feature | Past end_date excluded |
| 6 | `test_list_flash_sales_excludes_inactive` | Feature | status=false excluded |
| 7 | `test_list_flash_sales_empty` | Feature | No valid → `[]` |

### Get Flash Sale by Slug Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_get_flash_sale_by_slug` | Feature | 200 with products |
| 2 | `test_get_flash_sale_by_slug_not_found` | Feature | 404 |
| 3 | `test_get_flash_sale_by_slug_expired` | Feature | Expired → 404 (once fixed) |
| 4 | `test_get_flash_sale_by_slug_no_products` | Feature | No products → empty array |

### Flash Sale Products Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_flash_sale_products_by_qty` | Feature | Products from valid flash sales |
| 2 | `test_flash_sale_products_ending_this_week` | Feature | Products ending within 7 days |
| 3 | `test_flash_sale_products_ending_today` | Feature | Products ending today |
| 4 | `test_flash_sale_products_ending_this_week_empty` | Feature | No products ending this week → `[]` |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_response_structure` | Feature | Top-level JSON keys |
| 2 | `test_flash_sale_object_structure` | Feature | id, name, slug, dates, image |
| 3 | `test_detail_with_products_structure` | Feature | Products array included |
| 4 | `test_product_mini_structure` | Feature | ProductMiniResource fields |

### Channel Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_flash_sales_channel_home` | Feature | X-Channel: home |
| 2 | `test_flash_sales_channel_fast_shipping` | Feature | X-Channel: fast-shipping |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_soft_deleted_flash_sale_excluded` | Feature | Soft-deleted not in list |
| 2 | `test_flash_sale_pricing_enrichment` | Feature | Products show current_price with flash sale discount |
| 3 | `test_flash_sale_typo_discription` | Feature | Response has discription key (known bug) |
