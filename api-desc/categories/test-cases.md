# Test Coverage — Category Module

---

## Test Files

13 test files in `tests/Feature/Categories/`:

| File | Lines | Focus |
|------|-------|-------|
| `CategoryCrudTest.php` | 180 | Core CRUD operations |
| `CategoryValidationTest.php` | 135 | Validation rules |
| `CategoryAuthorizationTest.php` | 168 | Granular permission checks |
| `CategoryAuthenticationTest.php` | 152 | Unauthenticated access |
| `CategorySoftDeleteTest.php` | 150 | Soft delete behavior |
| `CategoryTranslationTest.php` | 129 | Multi-locale translations |
| `CategoryRelationshipTest.php` | 177 | Parent-child relationships |
| `CategoryResourceTest.php` | 200 | Response JSON structure |
| `CategoryPivotUniqueTest.php` | 187 | category_product unique constraint |
| `CategoryFeaturedTest.php` | 180 | Featured toggle functionality |
| `CategoryMediaTest.php` | 33 | Media library interface |
| `CategoryMediaLifecycleTest.php` | 247 | Media upload life cycle |
| `CategoryRegressionTest.php` | 248 | Bug regression tests |

---

## CategoryCrudTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_create_category` | Feature | POST /categories with valid data |
| 2 | `test_list_categories` | Feature | GET /categories returns paginated list |
| 3 | `test_show_category` | Feature | GET /categories/{id} with children + products |
| 4 | `test_update_category` | Feature | PUT /categories/{id} updates fields |
| 5 | `test_delete_category` | Feature | DELETE /categories/{id} soft deletes |
| 6 | `test_show_non_existent_category` | Edge Case | GET /categories/99999 → 404 |
| 7 | `test_update_non_existent_category` | Edge Case | PUT /categories/99999 → 404 |
| 8 | `test_delete_non_existent_category` | Edge Case | DELETE /categories/99999 → 404 |

---

## CategoryValidationTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_create_category_requires_name` | Validation | Missing name → 422 |
| 2 | `test_create_category_requires_image` | Validation | Missing image → 422 |
| 3 | `test_create_category_with_invalid_parent` | Validation | Non-existent parent_id → 422 |
| 4 | `test_update_category_partial` | Validation | Partial update succeeds |
| 5 | `test_update_category_non_array_name` | Validation | Non-array name → 422 |

---

## CategoryAuthorizationTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_view_only_cannot_create` | Auth | view-categories only → 403 on POST |
| 2 | `test_view_only_cannot_update` | Auth | view-categories only → 403 on PUT |
| 3 | `test_view_only_cannot_delete` | Auth | view-categories only → 403 on DELETE |
| 4 | `test_create_only_cannot_delete` | Auth | create-category only → 403 on DELETE |
| 5 | `test_update_only_cannot_delete` | Auth | update-category only → 403 on DELETE |
| 6 | `test_delete_only_cannot_create` | Auth | delete-category only → 403 on POST |
| 7 | `test_no_permissions_at_all` | Auth | No permissions → 403 on all |

---

## CategoryAuthenticationTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_unauthenticated_cannot_list` | Auth | No token → 401 on GET |
| 2 | `test_unauthenticated_cannot_create` | Auth | No token → 401 on POST |
| 3 | `test_unauthenticated_cannot_show` | Auth | No token → 401 on GET /{id} |
| 4 | `test_unauthenticated_cannot_update` | Auth | No token → 401 on PUT |
| 5 | `test_unauthenticated_cannot_delete` | Auth | No token → 401 on DELETE |
| 6 | `test_unauthenticated_cannot_toggle_feature` | Auth | No token → 401 on PUT /feature |
| 7 | `test_public_featured_categories` | Auth | No token → 200 on GET /featured-categories |

---

## CategorySoftDeleteTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_soft_delete_sets_deleted_at` | Feature | DELETE sets deleted_at |
| 2 | `test_soft_deleted_excluded_from_index` | Feature | Not in list after delete |
| 3 | `test_show_soft_deleted_returns_404` | Edge Case | GET soft-deleted → 404 |
| 4 | `test_force_delete_removes` | Feature | forceDelete() removes permanently |
| 5 | `test_multiple_soft_deletes` | Edge Case | Delete already-deleted → 404 |

---

## CategoryTranslationTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_create_with_both_locales` | Feature | en + ar stored as JSON |
| 2 | `test_resource_returns_translated_name` | Feature | Response has string, not JSON |
| 3 | `test_resource_does_not_return_raw_json` | Feature | name is not JSON object in response |
| 4 | `test_locale_aware_details` | Feature | Details returned in correct locale |

---

## CategoryRelationshipTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_create_child_category` | Feature | parent_id set, level calculated |
| 2 | `test_children_returned_with_parent` | Feature | GET /{id} includes children |
| 3 | `test_delete_parent_with_children_fails` | Business Logic | 400 cannot delete with children |
| 4 | `test_level_auto_calculation` | Regression | Root=1, Child=2, Grandchild=3 |

---

## CategoryResourceTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_paginated_response_structure` | Feature | Has data, current_page, per_page, total |
| 2 | `test_expected_fields_in_index` | Feature | id, name, slug, image, is_featured, products_count, status |
| 3 | `test_details_omitted_in_index` | Feature | No details field in list response |
| 4 | `test_details_included_in_show` | Feature | Has details in single response |
| 5 | `test_response_field_types` | Feature | Correct types (boolean, integer, string) |

---

## CategoryPivotUniqueTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_duplicate_category_product_rejected` | Validation | Same pair → unique violation |
| 2 | `test_sync_deduplicates` | Edge Case | Sync with duplicates → single row |
| 3 | `test_direct_insert_violates_unique` | Validation | DB insert duplicate → exception |
| 4 | `test_soft_delete_keeps_pivot` | Feature | Pivot preserved after soft delete |

---

## CategoryFeaturedTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_featured_categories_public` | Feature | GET /featured-categories public |
| 2 | `test_toggle_featured_requires_permission` | Auth | No permission → 403 |
| 3 | `test_toggle_featured_works` | Feature | Toggle from false → true |
| 4 | `test_double_toggle_reverts` | Feature | Toggle twice → original |
| 5 | `test_toggle_featured_validation` | Validation | Invalid ID → 422 |

---

## CategoryMediaTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_category_implements_has_media` | Interface | Category implements HasMedia |
| 2 | `test_category_has_media_collections` | Feature | categories-desktop, categories-mobile |

---

## CategoryMediaLifecycleTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_upload_desktop_and_mobile_images` | Feature | Valid files → collections assigned |
| 2 | `test_update_removes_old_image` | Feature | Replace → old cleared, new assigned |
| 3 | `test_soft_delete_preserves_media` | Feature | Media not deleted on soft delete |
| 4 | `test_force_delete_removes_media` | Feature | forceDelete() removes media |
| 5 | `test_multiple_files_per_collection` | Feature | uploadSingleImage replaces single file |
| 6 | `test_independent_collections` | Feature | Desktop/mobile independent |

---

## CategoryRegressionTest.php Coverage (B1-B10)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| B1 | `test_soft_delete_does_not_cascade_to_children` | Regression | Children not cascade-deleted |
| B2 | `test_category_can_be_created_with_translated_name` | Regression | Bilingual creation |
| B3 | `test_public_featured_categories_do_not_require_auth` | Regression | No token → 200 |
| B4 | `test_translation_keys_exist` | Regression | Constants defined |
| B5 | `test_dead_route_returns_404` | Regression | Non-existent route → 404 |
| B6 | `test_null_slug_in_factory` | Regression | Factory produces null slug |
| B7 | `test_slug_auto_generation_from_name` | Regression | Create → slug generated |
| B8 | `test_slug_preserved_on_non_name_update` | Regression | Status update → slug unchanged |
| B9 | `test_explicit_slug_preserved_on_create` | Regression | Custom slug preserved |
| B10 | `test_parent_id_validation_rejects_non_existent` | Regression | Invalid parent → 422 |

---

## Coverage Summary

| Category | Count |
|----------|-------|
| Feature Tests (Success) | ~25 |
| Validation Tests | ~10 |
| Authorization Tests | ~15 |
| Edge Case Tests | ~8 |
| Business Logic Tests | ~3 |
| Translation Tests | ~4 |
| Regression Tests | ~10 |
| Media Tests | ~8 |
| **Total** | **~83** |

---

## Missing Tests (Recommended)

- [ ] Cycle detection on create (setting parent_id to self → 422)
- [ ] Cycle detection on update (reparenting to descendant → 422)
- [ ] Concurrent parent reassignment (race condition)
- [ ] Large hierarchy performance (1000+ categories, depth 10)
- [ ] Feature toggle with non-integer ID "abc"
- [ ] Create category with empty parent_id `""` vs `null`
- [ ] Navbar resource max level depth
- [ ] `details` as plain string vs JSON object in multilingual context
- [ ] Category export/import functionality
- [ ] Category-wise analytics endpoints
