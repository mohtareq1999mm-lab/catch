# JIRA Stories — Admin Users

## Story ADMIN-USERS-001: User Listing with Filters

**Description**: As a SUPER_ADMIN, I want to list all users with filters (active/inactive/trash/type) and search (name/email) and pagination.

**Acceptance Criteria**:
- GET `/api/users` returns paginated users
- Supports `?active=true` and `?in_active=true` filters
- Supports `?trash=true` to show soft-deleted users
- Supports `?users=true` and `?admins=true` type filters
- Supports `?search=` for name/email partial matching
- Supports `?order_by=` and `?sort=` for sorting
- Default limit is 15
- Returns 403 for non-admin users

**Files**: `UserController::index()`, `routes: 137-145`

---

## Story ADMIN-USERS-002: Create Admin User

**Description**: As a SUPER_ADMIN, I want to create new admin users with roles and activation status.

**Acceptance Criteria**:
- POST `/api/admin-users/add` creates a user with type='admin'
- Required: name, email, password (min:6, confirmed), phone_number
- Optional: roles (array of role IDs), image, is_active
- Email and phone_number must be unique
- User is assigned specified roles
- Returns 403 for users without `create-user` permission

**Files**: `UserController::adminAddUsers()`, `AdminCreateUserRequest`

---

## Story ADMIN-USERS-003: Toggle User Activation

**Description**: As a SUPER_ADMIN, I want to activate/deactivate users.

**Acceptance Criteria**:
- PUT `/api/admin-users/update-activation` toggles `is_active`
- Toggle is idempotent (re-activating an active user deactivates them)
- Cannot deactivate an active super_admin user
- Requires `user_id` in request body
- Returns 422 for non-existent user_id

**Files**: `UserController::adminUpdateActivationUsers()`

---

## Story ADMIN-USERS-004: Soft Delete and Restore Users

**Description**: As a SUPER_ADMIN, I want to soft-delete and restore users.

**Acceptance Criteria**:
- DELETE `/api/admin-users/delete/{id}` soft-deletes a user
- PUT `/api/admin-users/restore/{id}` restores a soft-deleted user
- Cannot delete super_admin or self
- Cannot restore a non-trashed user
- Returns 404 for non-existent user

**Files**: `UserController::adminDeleteUsers()`, `adminRestoreUser()`

---

## Story ADMIN-USERS-005: Force Delete Users

**Description**: As a SUPER_ADMIN, I want to permanently delete (force delete) users.

**Acceptance Criteria**:
- DELETE `/api/admin-users/delete-forever/{id}` permanently deletes a user
- Cannot delete super_admin or self
- User must already be soft-deleted to force delete
- Returns 404 for non-existent user

**Files**: `UserController::adminDeleteUsersForever()`

---

## Story ADMIN-USERS-006: Update User Profile

**Description**: As a SUPER_ADMIN or the user themselves, I want to update user profile information.

**Acceptance Criteria**:
- PUT `/api/users/{id}` updates user
- SUPER_ADMIN can update any user
- Regular user can only update themselves
- Can update: name, email, avatar, profile, address
- Email uniqueness check ignores current user

**Files**: `UserController::update()`, `UserUpdateRequest`

---

## Story ADMIN-USERS-007: View Single User

**Description**: As a SUPER_ADMIN, I want to view a single user's details.

**Acceptance Criteria**:
- GET `/api/users/{id}` returns user with full details
- Admin users load roles + permissions
- Regular users load address
- Returns 404 for non-existent user

**Files**: `UserController::show()`

---

## Story ADMIN-USERS-008: Get Current User (Me)

**Description**: As any authenticated user, I want to get my own profile.

**Acceptance Criteria**:
- GET `/api/me` returns the authenticated user
- Includes `role` (first role name)
- Returns 401 if unauthenticated

**Files**: `UserController::me()`
