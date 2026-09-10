# Test Coverage — Navigation Bar

---

## Test Files

No existing test files for the nav-data endpoint.

**Recommended new file:** `tests/Feature/NavDataTest.php`

---

## Recommended Test Cases

### CrudTest.php (NavDataCrudTest)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_fetch_nav_data_success` | Feature | GET /general/nav-data returns 200 with category tree |
| 2 | `test_fetch_nav_data_empty` | Feature | No active categories returns 200 with empty array |
| 3 | `test_fetch_nav_data_with_level_parameter` | Feature | `level=1` returns only root categories (no children) |
| 4 | `test_fetch_nav_data_excludes_inactive` | Feature | Inactive categories excluded from response |
| 5 | `test_fetch_nav_data_excludes_soft_deleted` | Feature | Soft-deleted categories excluded from response |

### CacheTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_nav_data_is_cached` | Feature | Second request within TTL returns same data without DB query |
| 2 | `test_nav_data_cache_expires` | Feature | After TTL expires, fresh data is fetched |
| 3 | `test_nav_data_different_level_different_cache` | Feature | Different level values use different cache keys |
| 4 | `test_nav_data_clear_cache` | Feature | `clearCache()` invalidates nav-data cache |

### ChannelTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_nav_data_default_channel` | Feature | No X-Channel header uses 'home' cache |
| 2 | `test_nav_data_home_channel` | Feature | X-Channel: home works correctly |
| 3 | `test_nav_data_fast_shipping_channel` | Feature | X-Channel: fast-shipping works correctly |
| 4 | `test_nav_data_invalid_channel_non_strict` | Feature | Invalid channel falls back to default |
| 5 | `test_nav_data_invalid_channel_strict` | Feature | Invalid channel with strict mode returns 400 |

### ResponseStructureTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_nav_data_response_structure` | Feature | Validates JSON keys: status, message, success, data |
| 2 | `test_nav_data_category_structure` | Feature | Each category has id, name, slug, level, image, children |
| 3 | `test_nav_data_image_structure` | Feature | Image has desktop and mobile keys |
| 4 | `test_nav_data_children_is_array` | Feature | children is always an array, never null |
| 5 | `test_nav_data_hierarchy_depth` | Feature | Default level=3 returns up to 3 levels |

### RegressionTest.php

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_nav_data_after_category_created` | Feature | Creating a new category → updated nav-data after cache clear |
| 2 | `test_nav_data_after_category_updated` | Feature | Updating a category name → updated nav-data |
| 3 | `test_nav_data_after_category_deleted` | Feature | Deleting a category → removed from nav-data |
| 4 | `test_nav_data_with_large_dataset` | Feature | 100+ categories still returns under 500ms |
| 5 | `test_nav_data_translation_locale` | Feature | Category names match the expected locale |
