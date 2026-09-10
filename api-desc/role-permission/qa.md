# QA Checklist — Role & Permission

---

## Smoke Tests

### Roles

- [ ] `GET /roles` returns 200 with paginated roles
- [ ] `POST /roles` creates a new role with valid data
- [ ] `GET /roles/{id}` returns a single role
- [ ] `PUT /roles/{id}` updates an existing role
- [ ] `DELETE /roles/{id}` deletes an unassigned role

### User-Role Assignment

- [ ] `POST /users/{userId}/assign-role` assigns a role
- [ ] `POST /users/{userId}/remove-role` removes a role

### Permissions

- [ ] `GET /permissions` returns paginated permissions
- [ ] `POST /roles/{roleId}/permissions` assigns permissions to role
- [ ] `POST /users/{userId}/permissions` gives permission to user
- [ ] `PUT /users/{userId}/permissions` syncs user permissions
- [ ] `DELETE /users/{userId}/permissions` removes user permission

---

## Validation Tests

- [ ] `POST /roles` with empty/absent `display_name` returns **422**
- [ ] `POST /roles` with empty `display_name.en` returns **422**
- [ ] `PUT /roles/{id}` with invalid `id` returns **404**
- [ ] `POST /users/{userId}/assign-role` with missing `role_id` returns **422**
- [ ] `POST /users/{userId}/assign-role` with invalid `role_id` returns **404**
- [ ] `POST /roles/{roleId}/permissions` with non-array `permissions` returns **422**

---

## Authorization Tests

- [ ] All endpoints return **401** without token
- [ ] `DELETE /roles/{id}` without `DELETE_ROLES` permission returns **403**
- [ ] `GET /permissions` without `SUPER_ADMIN` returns **403**
- [ ] `POST /roles/{roleId}/permissions` without `SUPER_ADMIN` returns **403**
- [ ] `POST /users/{userId}/permissions` without `SUPER_ADMIN` returns **403**
- [ ] `PUT /users/{userId}/permissions` without `SUPER_ADMIN` returns **403**
- [ ] `DELETE /users/{userId}/permissions` without `SUPER_ADMIN` returns **403**
- [ ] `POST /users/{userId}/assign-role` without `ASSIGN_ROLE` returns **403**
- [ ] `POST /users/{userId}/remove-role` without `REMOVE_ROLE` returns **403**

---

## Business Logic Tests

- [ ] `POST /roles` auto-generates `name` from `display_name.en` (lowercase, underscores)
- [ ] `DELETE /roles/{id}` with assigned users returns **409**
- [ ] `POST /users/{userId}/assign-role` with customer-type user returns **403**
- [ ] `POST /users/{userId}/assign-role` replaces all current roles (syncRoles)
- [ ] `POST /roles/{roleId}/permissions` auto-creates permissions if they don't exist
- [ ] `POST /users/{userId}/permissions` auto-creates permissions if they don't exist
- [ ] `PUT /users/{userId}/permissions` replaces all direct permissions (sync)
- [ ] `DELETE /users/{userId}/permissions` only removes specified permissions
- [ ] Assigning/removing role dispatches `ClearUserCacheById` job

---

## Edge Cases

- [ ] Creating a role with `name` same as existing (different guard) – should succeed
- [ ] Creating a role with `name` same as existing (same guard) – should fail
- [ ] Deleting a role that is assigned to 1000+ users – returns 409
- [ ] Syncing permissions to empty array – removes all direct permissions
- [ ] Assigning role to non-existent user ID – returns 404
- [ ] Posting empty `permissions` array to `POST /roles/{roleId}/permissions` – no-op

---

## Translation & Label Tests

- [ ] Permission resource `label` field resolves via `__("permissions.{$name}")`
- [ ] Missing translation key returns the key itself as fallback
- [ ] Arabic translation file has matching keys for all English keys
- [ ] Role `display_name` correctly returns JSON when loaded
