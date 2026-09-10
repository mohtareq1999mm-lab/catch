# Test Coverage — Tag Module

---

## Test Files

### TagCrudTest.php (`tests/Feature/TagCrudTest.php`) — 13 tests / 52 assertions

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_index_returns_standard_response_wrapper` | Feature | GET /tags returns `{success, message, status, data}` envelope |
| 2 | `test_store_returns_201_with_standard_wrapper` | Feature | POST /tags returns 201 with `apiResponse` wrapper + slug |
| 3 | `test_store_syncs_products_relation` | Feature | POST with `products` syncs `product_tag` pivot |
| 4 | `test_store_rejects_invalid_product_ids` | Validation | POST with non-existent product id → 422 |
| 5 | `test_show_by_id_returns_wrapper_with_products` | Feature | GET /tags/{id} returns wrapper + eager-loaded products |
| 6 | `test_show_by_slug_returns_wrapper` | Feature | GET /tags/{slug} returns wrapper |
| 7 | `test_update_returns_200_with_wrapper` | Feature | PUT /tags/{id} returns 200 + wrapper |
| 8 | `test_update_syncs_products_relation` | Feature | PUT with `products` replaces pivot associations |
| 9 | `test_update_with_empty_products_clears_relation` | Feature | PUT with `products: []` clears associations |
| 10 | `test_update_rejects_invalid_product_ids` | Validation | PUT with non-existent product id → 422 |
| 11 | `test_destroy_returns_200_with_wrapper_and_true` | Feature | DELETE returns `{..., data: true}` + cascade cleanup |
| 12 | `test_guest_cannot_access_tags_crud` | Auth | No token → 401 on all endpoints |
| 13 | `test_user_without_permission_cannot_create` | Auth | Customer role → 403 on POST |

### ProductTagTest.php (`tests/Feature/ProductTagTest.php`) — 23 tests / 62 assertions (product→tag direction, unchanged)

Covers tag attachment/detachment from the product side, public tag listing, tag filtering, and pivot integrity.

---

## Recommended Additional Test Suite

### TagCrudTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_tags` | Feature | GET /tags returns paginated list |
| 2 | `test_create_tag` | Feature | POST /tags with valid data |
| 3 | `test_show_tag_by_id` | Feature | GET /tags/{id} returns tag |
| 4 | `test_show_tag_by_slug` | Feature | GET /tags/{slug} returns tag |
| 5 | `test_update_tag` | Feature | PUT /tags/{id} updates fields |
| 6 | `test_delete_tag` | Feature | DELETE /tags/{id} deletes |
| 7 | `test_show_non_existent_tag` | Edge Case | GET /tags/99999 → 404 |
| 8 | `test_update_non_existent_tag` | Edge Case | PUT /tags/99999 → 404 |
| 9 | `test_delete_non_existent_tag` | Edge Case | DELETE /tags/99999 → 404 |

### TagValidationTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_create_tag_requires_name` | Validation | Missing name → 422 |
| 2 | `test_create_tag_duplicate_name` | Validation | Existing name → 422 |
| 3 | `test_create_tag_non_array_name` | Validation | String instead of array → 422 |
| 4 | `test_update_tag_duplicate_name` | Validation | Name taken by another → 422 |
| 5 | `test_update_tag_partial` | Validation | Partial update succeeds |

### TagAuthorizationTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_view_only_cannot_create` | Auth | view-tags only → 403 on POST |
| 2 | `test_view_only_cannot_update` | Auth | view-tags only → 403 on PUT |
| 3 | `test_view_only_cannot_delete` | Auth | view-tags only → 403 on DELETE |
| 4 | `test_no_permission_all_endpoints_return_403` | Auth | No permissions → 403 on all |
| 5 | `test_guest_cannot_access_any_endpoint` | Auth | No token → 401 on all |

### TagTranslationTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_create_tag_with_bilingual_name` | Feature | POST with en + ar |
| 2 | `test_resource_returns_translated_name` | Feature | GET returns correct locale |
| 3 | `test_resource_does_not_return_raw_json` | Feature | Name is string, not object |

### TagResourceTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_paginated_response_structure` | Feature | Has data + pagination meta |
| 2 | `test_tag_resource_fields` | Feature | id, name, slug, image, icon present |
| 3 | `test_tag_resource_field_types` | Feature | Correct data types |

### TagMediaTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_upload_image_on_create` | Feature | POST with image file |
| 2 | `test_upload_icon_on_create` | Feature | POST with icon file |
| 3 | `test_update_replaces_image` | Feature | PUT with new image |
| 4 | `test_delete_does_not_clean_media` | Known Issue | Media preserved after delete |

### TagRegressionTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_store_returns_tag_resource` | Regression | store() returns data, not null |
| 2 | `test_show_works_with_numeric_id` | Regression | Numeric lookup works |
| 3 | `test_show_works_with_string_slug` | Regression | Slug lookup works |
| 4 | `test_unique_translation_on_tags_table` | Regression | Checks tags table, not categories |
| 5 | `test_create_rule_has_no_ignore` | Regression | CREATE unique rule without ->ignore() |

---

## Missing Coverage

- [ ] Arabic-locale assertions for tag response messages
- [ ] Slug auto-generation edge cases
- [ ] Concurrent tag operations
- [ ] Pagination boundary tests
- [ ] Image upload validation (file type, size)
- [ ] Large dataset performance
- [ ] Soft-delete / restore behavior (tags use hard delete by design)
