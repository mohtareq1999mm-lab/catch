# Attribute Module — Test Coverage (CRUD)

## Existing Tests (AttributeApiTest — 12 attribute CRUD tests)

| # | Test | Type |
|---|------|------|
| 1 | `test_authenticated_user_can_list_attributes` | Success |
| 2 | `test_guest_cannot_list_attributes` | Auth |
| 3 | `test_list_attributes_returns_empty_data_when_none_exist` | Edge case |
| 4 | `test_authenticated_user_can_show_attribute_by_id` | Success |
| 5 | `test_guest_gets_403_for_attribute_show` | Auth |
| 6 | `test_authenticated_user_gets_404_for_nonexistent_attribute_id` | Error |
| 7 | `test_unauthenticated_user_cannot_create_attribute` | Auth |
| 8 | `test_authenticated_admin_can_create_attribute` | Success |
| 9 | `test_user_without_required_permission_gets_forbidden_for_create` | Auth |
| 10 | `test_unauthenticated_user_cannot_update_attribute` | Auth |
| 11 | `test_unauthenticated_user_cannot_delete_attribute` | Auth |
| 12 | `test_authenticated_admin_can_delete_attribute` | Success |
| 13 | `test_deleting_attribute_cascades_to_its_values` | Cascade |

## Existing Tests (AttributesProductionHardenTest — attribute CRUD portion)

| # | Test | Type |
|---|------|------|
| 1 | `test_guest_cannot_list_attributes` | Auth |
| 2 | `test_guest_cannot_show_attribute` | Auth |
| 3 | `test_guest_cannot_create_attribute` | Auth |
| 4 | `test_guest_cannot_update_attribute` | Auth |
| 5 | `test_guest_cannot_delete_attribute` | Auth |
| 6 | `test_view_only_user_cannot_create` | Auth |
| 7 | `test_view_only_user_cannot_update` | Auth |
| 8 | `test_view_only_user_cannot_delete` | Auth |
| 9 | `test_admin_can_create_attribute_without_values` | Success |
| 10 | `test_admin_can_create_attribute_with_values` | Success |
| 11 | `test_admin_can_show_attribute_by_id` | Success |
| 12 | `test_admin_can_show_attribute_by_slug` | Success |
| 13 | `test_show_nonexistent_attribute_returns_404` | Error |
| 14 | `test_admin_can_update_attribute_name_without_touching_values` | Success |
| 15 | `test_update_attribute_with_values_replaces_values` | Success |
| 16 | `test_admin_can_delete_attribute` | Success |
| 17 | `test_delete_nonexistent_attribute_returns_404` | Error |
| 18 | `test_create_attribute_requires_name` | Validation |
| 19 | `test_attribute_list_response_structure` | Resource |
| 20 | `test_attribute_show_response_structure` | Resource |
| 21 | `test_list_attributes_paginates` | Pagination |

**Total CRUD tests: ~34**

## Recommended Missing Tests

| # | Test | Priority |
|---|------|----------|
| 1 | `test_create_attribute_validates_name_min_length` | High |
| 2 | `test_create_attribute_validates_name_max_length` | High |
| 3 | `test_create_attribute_validates_name_unique` | High |
| 4 | `test_update_attribute_with_same_name_succeeds` | High |
| 5 | `test_update_attribute_with_duplicate_name_fails` | High |
| 6 | `test_create_attribute_with_empty_values_array` | Medium |
| 7 | `test_attribute_name_is_translated_in_list` | Medium |
| 8 | `test_attribute_name_is_raw_in_show` | Medium |
| 9 | `test_create_attribute_handles_special_characters` | Low |
