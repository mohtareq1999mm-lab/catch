# Test Cases — Admin Users

## Test Files

| File | Tests |
|------|-------|
| `tests/Feature/UserControllerTest.php` | 48 tests — listing, create admin, activation, delete, search, pagination, make/revoke admin, ban/activate, events |
| `tests/Feature/UserCrudTest.php` | 25 tests — show, store, update, destroy, force delete, restore, trashed, add-points, regression tests |

## Test Coverage Summary

| Endpoint | Test Cases | File |
|----------|-----------|------|
| **GET /api/users** | 12 | UserControllerTest |
| **GET /api/users/{id}** | 4 | UserCrudTest |
| **PUT /api/users/{id}** | 5 | UserCrudTest |
| **POST /api/admin-users/add** | 14 | UserControllerTest |
| **PUT /api/admin-users/update-activation** | 5 | UserControllerTest |
| **DELETE /api/admin-users/delete/{id}** | 5 | UserControllerTest |
| **PUT /api/admin-users/restore/{id}** | 3 | UserCrudTest |
| **DELETE /api/admin-users/delete-forever/{id}** | 4 | UserCrudTest |
| **GET /api/me** | (tested in UserControllerTest implicitly) | |

## Detailed Test Cases

### GET /api/users

| # | Test | Expected |
|---|------|----------|
| 1 | List all users without filters | 200, contains both active and inactive |
| 2 | Filter active=true | 200, only active users |
| 3 | Filter in_active=true | 200, only inactive users |
| 4 | Paginated response structure | 200, includes all pagination fields |
| 5 | Custom per_page (limit=1) | 200, count=1 |
| 6 | Large page number (page=9999) | 200, empty data |
| 7 | Search by name | 200, matching results |
| 8 | Search by email | 200, matching results |
| 9 | Search partial match | 200, LIKE matching |
| 10 | Search no results | 200, empty data |
| 11 | Search with pagination | 200, count respects limit |
| 12 | Search with active filter | 200, combined filters |
| 13 | Search with inactive filter | 200, combined filters |
| 14 | Unauthenticated | 401 |
| 15 | Regular user (no permission) | 403 |

### POST /api/admin-users/add

| # | Test | Expected |
|---|------|----------|
| 1 | Create with valid data + roles | 200, success=true, user in DB with type='admin' |
| 2 | Create without roles | 200, success=true, type='admin' |
| 3 | Create with phone_number | 200, phone persisted in DB |
| 4 | Create with is_active=0 | 200, is_active=false in DB |
| 5 | Missing name | 422 |
| 6 | Missing email | 422 |
| 7 | Invalid email | 422 |
| 8 | Missing password | 422 |
| 9 | Short password (< 6) | 422 |
| 10 | Missing password_confirmation | 422 |
| 11 | Mismatched password confirmation | 422 |
| 12 | Invalid is_active value | 422 |
| 13 | Non-existent role ID | 422 |
| 14 | Duplicate email | 422 |
| 15 | Duplicate phone_number | 422 |
| 16 | Nullable phone (not provided) | 200 |
| 17 | Single role assignment | 200, user has role |
| 18 | Multiple role assignment | 200, user has both roles |
| 19 | Duplicate role IDs | 200, role assigned once only |
| 20 | Mixed valid + invalid role IDs | 422 |
| 21 | Regular user (no permission) | 403 |
| 22 | Unauthenticated | 401 |
| 23 | Response structure | Contains status, message, success, data.id, data.name, data.email |
| 24 | Type persisted as admin | type='admin' in DB |

### PUT /api/admin-users/update-activation

| # | Test | Expected |
|---|------|----------|
| 1 | Toggle active → inactive | 200, is_active=false in DB |
| 2 | Toggle inactive → active | 200, is_active=true in DB |
| 3 | Cannot deactivate active super_admin | 400 |
| 4 | Missing user_id | 422 |
| 5 | Non-existent user_id | 422 |
| 6 | Regular user (no permission) | 403 |
| 7 | Unauthenticated | 401 |
| 8 | Response structure | Contains status, message, success |

### DELETE /api/admin-users/delete/{id}

| # | Test | Expected |
|---|------|----------|
| 1 | Delete regular user | 200, soft deleted (not in DB) |
| 2 | Cannot delete super_admin | 400 |
| 3 | Cannot delete self | 400 |
| 4 | Non-existent ID | 404 |
| 5 | Regular user (no permission) | 403 |
| 6 | Unauthenticated | 401 |
| 7 | Response structure | Contains status, message, success |

### PUT /api/admin-users/restore/{id}

| # | Test | Expected |
|---|------|----------|
| 1 | Restore soft-deleted user | 200, deleted_at=null |
| 2 | Non-trashed user | 400 |
| 3 | Non-existent ID | 404 |
| 4 | Unauthenticated | 401 |

### DELETE /api/admin-users/delete-forever/{id}

| # | Test | Expected |
|---|------|----------|
| 1 | Force delete soft-deleted user | 200, removed from DB |
| 2 | Cannot force delete super_admin | 400 |
| 3 | Non-existent ID | 404 |
| 4 | Unauthenticated | 401 |

### GET /api/users/{id} (show)

| # | Test | Expected |
|---|------|----------|
| 1 | View user by ID | 200, returns id, name, email |
| 2 | Non-existent ID | 404 |
| 3 | Regular user (no permission) | 403 |
| 4 | Unauthenticated | 401 |
| 5 | All resource fields present | Has id, name, email, email_verified_at, is_active, type, phone_number, created_at, updated_at |

### PUT /api/users/{id} (update)

| # | Test | Expected |
|---|------|----------|
| 1 | SUPER_ADMIN updates any user | 200, name updated |
| 2 | User cannot update self without permission | 403 |
| 3 | User cannot update other user | 403 |
| 4 | Unauthenticated | 401 |
| 5 | Update with same email | 200 (unique check ignores self) |

### GET /api/me

| # | Test | Expected |
|---|------|----------|
| 1 | Authenticated user | 200, returns profile with role |
| 2 | Unauthenticated | 401 |

### Event Tests

| # | Test | Expected |
|---|------|----------|
| 1 | UserRolesUpdated dispatched on make-admin | Event asserted |
| 2 | UserRolesUpdated dispatched on revoke-admin | Event asserted |
| 3 | UserRolesUpdated dispatched twice on toggle twice | Dispatched 2 times |

### Regression Tests

| # | Bug | Test | Expected |
|---|-----|------|----------|
| 1 | BUG-1 | makeOrRevokeAdmin does not crash | 200, type='admin' |
| 2 | BUG-3 | Update user with same email | 200, success=true |
| 3 | BUG-4 | Store user persists phone_number | phone_number in DB |
| 4 | BUG-6 | Cannot delete self | 400, not soft deleted |
| 5 | BUG-6 | Cannot delete super_admin | 400, not soft deleted |
| 6 | BUG-7 | Show user returns all resource fields | type and phone_number present |
