# Test Cases - Category Feature

## Current Coverage

**12 test files in `tests/Feature/Categories/`** with comprehensive coverage.

---

## Existing Test Classes

### 1. CategoryCrudTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_admin_can_create_category` | Creates category with name, slug, images, parent |
| 2 | `test_admin_can_list_categories` | Paginated listing with correct structure |
| 3 | `test_admin_can_show_category` | Fetch single category by ID with children |
| 4 | `test_admin_can_update_category` | Partial update of name, slug, parent |
| 5 | `test_admin_can_soft_delete_category` | Soft delete + verification |
| 6 | `test_returns_404_for_nonexistent_category` | Invalid ID returns 404 |

### 2. CategoryValidationTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_create_requires_name` | Missing name → 422 |
| 2 | `test_create_accepts_partial_update` | Update with partial data succeeds |
| 3 | `test_create_rejects_invalid_parent_id` | Non-existent parent_id → 422 |
| 4 | `test_create_rejects_non_array_name` | Name as string instead of array → 422 |

### 3. CategoryAuthenticationTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_unauthenticated_user_cannot_create` | No token → 401 |
| 2 | `test_unauthenticated_user_cannot_update` | No token → 401 |
| 3 | `test_unauthenticated_user_cannot_delete` | No token → 401 |
| 4 | `test_unauthenticated_user_cannot_toggle_feature` | No token → 401 |
| 5 | `test_unauthenticated_can_access_featured_categories` | Public endpoint → 200 |
| 6 | `test_authenticated_super_admin_can_access_all` | Super admin → 200 for all |

### 4. CategoryAuthorizationTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_view_only_user_can_index_and_show` | view-categories only → 200 for index/show |
| 2 | `test_view_only_user_cannot_create` | view-categories only → 403 for create |
| 3 | `test_view_only_user_cannot_update` | view-categories only → 403 for update |
| 4 | `test_view_only_user_cannot_delete` | view-categories only → 403 for delete |
| 5 | `test_view_only_user_cannot_toggle` | view-categories only → 403 for toggle |
| 6 | `test_granular_create_permission` | Only create-category → can create but not update/delete |

### 5. CategoryTranslationTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_create_category_with_multiple_languages` | Create with en + ar names |
| 2 | `test_resource_returns_translated_string` | Resource returns string, not JSON |
| 3 | `test_details_field_is_locale_sensitive` | Details returns correct locale |

### 6. CategorySoftDeleteTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_soft_delete_works` | deleted_at set |
| 2 | `test_deleted_category_absent_from_index` | Not in listing |
| 3 | `test_show_returns_404_for_deleted` | 404 on show |
| 4 | `test_force_delete_removes_permanently` | Row removed |
| 5 | `test_multiple_soft_deletes` | Multiple deletes don't error |

### 7. CategoryRelationshipTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_parent_child_relationship` | Parent-children tree works |
| 2 | `test_children_returned_with_parent` | Children loaded on parent show |
| 3 | `test_deleting_parent_does_not_cascade_to_children` | No cascade |
| 4 | `test_invalid_parent_id_validation` | Non-existent → 422 |

### 8. CategoryResourceTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_paginated_structure` | Correct pagination format |
| 2 | `test_resource_has_expected_fields` | All required fields present |
| 3 | `test_details_omitted_in_index` | No details in listing |
| 4 | `test_details_present_in_show` | Details present in detail |
| 5 | `test_type_assertions` | Correct data types |
| 6 | `test_featured_categories_endpoint` | Structure valid |

### 9. CategoryPivotUniqueTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_sync_does_not_create_duplicates` | No duplicate pivots |
| 2 | `test_direct_insert_violates_unique_constraint` | DB-level unique enforced |
| 3 | `test_different_categories_can_share_products` | Shared products allowed |
| 4 | `test_cascade_on_force_delete` | Pivot removed on force delete |
| 5 | `test_soft_delete_preserves_pivot` | Pivot preserved on soft delete |

### 10. CategoryMediaTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_category_implements_has_media` | HasMedia interface |
| 2 | `test_category_has_two_media_collections` | desktop + mobile |

### 11. CategoryMediaLifecycleTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_upload_media_on_create` | Files attached |
| 2 | `test_update_replaces_old_media` | Old removed, new added |
| 3 | `test_soft_delete_preserves_media` | Files not deleted |
| 4 | `test_force_delete_removes_all_media` | Files deleted |
| 5 | `test_independent_collections` | Desktop ≠ Mobile |
| 6 | `test_crud_unaffected_by_media` | CRUD works without media |

### 12. CategoryFeaturedTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_featured_endpoint_is_public` | No auth required |
| 2 | `test_toggle_requires_update_permission` | 403 without permission |
| 3 | `test_toggle_works` | Flag flipped |
| 4 | `test_toggle_back` | Toggle back to false |
| 5 | `test_validates_id_required_and_exists` | 422 for invalid |

### 13. CategoryRegressionTest

| # | Test | Description |
|---|------|-------------|
| 1 | `test_category_has_soft_delete_trait` | Trait present |
| 2 | `test_resource_returns_translated_name` | String not JSON |
| 3 | `test_resource_returns_translated_details` | String not JSON |
| 4 | `test_featured_endpoint_public` | Public access |
| 5 | `test_translation_keys_exist` | Keys in en/ar files |
| 6 | `test_dead_route_returns_404` | Invalid route → 404 |
| 7 | `test_slug_handling_non_json` | String slug handled |
| 8 | `test_slug_preserved_on_update` | Slug not overwritten |

---

## Recommended Additional Tests

### Feature Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Public category listing with pagination | Verify meta fields |
| FT-002 | Public category search by name | Search filter works |
| FT-003 | Public category by slug with products | Products loaded |
| FT-004 | Public category by slug (not found) | 404 returned |
| FT-005 | Parent-only filter on public listing | Only root categories |
| FT-006 | Category create with products sync | Products associated |

### Edge Case Tests

| # | Test | Description |
|---|------|-------------|
| EC-001 | Circular reference detection | Self-parenting → 422 |
| EC-002 | Deep hierarchy (level 10+) | Level correctly calculated |
| EC-003 | Update parent to descendant | Cycle detected → 422 |
| EC-004 | Delete category with products | Pivot preserved |
| EC-005 | Create category with all locales | en, ar, de, etc. |
| EC-006 | Max field lengths (2500 chars) | Validation passes/fails |

### API Contract Tests

| # | Test | Description |
|---|------|-------------|
| CT-001 | JSON structure matches resource definition | Field types verified |
| CT-002 | Children excluded when not loaded | No empty arrays |
| CT-003 | Details absent on index | Route name check works |
| CT-004 | Featured endpoint returns correct sort order | Desc by products_count |
