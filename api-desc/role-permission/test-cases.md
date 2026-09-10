# Test Coverage — Role & Permission

---

## Test File

**File:** `tests/Feature/RoleAndPermissionTest.php`

**Total Tests:** ~40 (803 lines)

---

## Role Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_user_can_list_roles` | Feature | Verify GET /roles returns paginated roles |
| 2 | `test_user_can_create_role` | Feature | Verify POST /roles creates a role with display_name |
| 3 | `test_user_can_create_role_without_name` | Feature | Verify name is auto-generated from display_name.en |
| 4 | `test_user_cannot_create_role_without_display_name` | Validation | Verify 422 when display_name is missing |
| 5 | `test_user_can_view_role` | Feature | Verify GET /roles/{id} returns role details |
| 6 | `test_user_can_update_role` | Feature | Verify PUT /roles/{id} updates role fields |
| 7 | `test_user_can_delete_role` | Feature | Verify DELETE /roles/{id} deletes unassigned role |
| 8 | `test_user_cannot_delete_assigned_role` | Business Logic | Verify 409 when role has assigned users |
| 9 | `test_user_cannot_view_non_existent_role` | Edge Case | Verify 404 for non-existent role ID |

---

## User-Role Assignment Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 10 | `test_user_can_assign_role_to_user` | Feature | Verify POST /users/{id}/assign-role |
| 11 | `test_user_can_remove_role_from_user` | Feature | Verify POST /users/{id}/remove-role |
| 12 | `test_user_cannot_assign_role_to_customer` | Business Logic | Verify 403 for customer-type user |
| 13 | `test_assign_role_dispatches_cache_clear` | Side Effect | Verify ClearUserCacheById job dispatched |
| 14 | `test_remove_role_dispatches_cache_clear` | Side Effect | Verify ClearUserCacheById job dispatched |

---

## Permission Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 15 | `test_user_can_list_permissions` | Feature | Verify GET /permissions returns paginated permissions |
| 16 | `test_user_can_assign_permission_to_role` | Feature | Verify POST /roles/{id}/permissions |
| 17 | `test_user_can_give_permission_to_user` | Feature | Verify POST /users/{id}/permissions |
| 18 | `test_user_can_sync_user_permissions` | Feature | Verify PUT /users/{id}/permissions replaces all |
| 19 | `test_user_can_remove_permission_from_user` | Feature | Verify DELETE /users/{id}/permissions |

---

## Authorization Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 20 | `test_unauthenticated_user_cannot_access_roles` | Auth | Verify 401 without token for all role endpoints |
| 21 | `test_unauthenticated_user_cannot_access_permissions` | Auth | Verify 401 without token for all perm endpoints |
| 22 | `test_user_without_permission_cannot_list_permissions` | Auth | Verify 403 for non-SUPER_ADMIN on GET /permissions |
| 23 | `test_user_without_permission_cannot_assign_permission_to_role` | Auth | Verify 403 for assignPermissionToRole |
| 24 | `test_user_without_permission_cannot_give_permission_to_user` | Auth | Verify 403 for givePermission |
| 25 | `test_user_without_permission_cannot_sync_permissions` | Auth | Verify 403 for syncPermissions |
| 26 | `test_user_without_permission_cannot_remove_permission` | Auth | Verify 403 for removePermission |

---

## Validation Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 27 | `test_create_role_validation_missing_display_name` | Validation | Verify 422 |
| 28 | `test_create_role_validation_empty_display_name_en` | Validation | Verify 422 |
| 29 | `test_assign_role_validation_missing_role_id` | Validation | Verify 422 |
| 30 | `test_assign_permission_validation_non_array` | Validation | Verify 422 |

---

## Translation Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 31 | `test_permission_resource_returns_label` | Feature | Verify permission label via translation |
| 32 | `test_role_resource_returns_display_name` | Feature | Verify role display_name in response |

---

## Coverage Summary

| Category | Count |
|----------|-------|
| Feature Tests (Success) | 12 |
| Validation Tests | 4 |
| Authorization Tests | 7 |
| Business Logic Tests | 3 |
| Edge Case Tests | 2 |
| Translation Tests | 2 |
| Side Effect Tests | 2 |
| **Total** | ~32-40 |

---

## Missing Tests (Recommended)

- [ ] Test assigning 100+ permissions in single request (batch size)
- [ ] Test concurrent role assignment on same user (race condition)
- [ ] Test concurrent permission assignment on same role (race condition)
- [ ] Test removing last Super Admin role (lockout prevention)
- [ ] Test permission label fallback when translation missing
- [ ] Test JSON structure of RoleResource (exact field match)
- [ ] Test JSON structure of PermissionResource (exact field match)
- [ ] Test role name auto-generation with special characters
- [ ] Test guard_name defaults to `api` when not provided
- [ ] Test that deleted role cascades properly in pivot tables
