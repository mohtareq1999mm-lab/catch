# Admin Users API

## Overview

Endpoints for managing admin users. All endpoints require authentication via Sanctum and `email.verified` middleware. Authorization is enforced at the controller level using Spatie permission middleware on individual methods.

The admin-user management feature implements the following dedicated endpoints:
- `POST /admin-users/add` — Create a new user with role assignment
- `PUT /admin-users/update-activation` — Toggle a user's `is_active` status
- `DELETE /admin-users/delete/{id}` — Delete a user (with guards)
- `PUT /admin-users/restore/{id}` — Restore a soft-deleted user
- `DELETE /admin-users/delete-forever/{id}` — Force delete a soft-deleted user
- `POST /users/block-user` — Ban/deactivate a user
- `POST /users/unblock-user` — Unban/activate a user
- `POST /users/make-admin` — Toggle admin (SUPER_ADMIN) permission on a user
- `POST /add-points` — Add wallet points to a customer
- `DELETE /users/{id}` — Delete a user (legacy, via resource controller)

Additional listing endpoints:
- `GET /users` — List all users with filtering (raw paginator return)
- `GET /users/{id}` — Show a single user

**Admin Identification:** Admin users are identified by `type = 'admin'` in the `users` table (single source of truth). Route authorization uses per-method Spatie permission middleware for backward compatibility with the Marvel package.

**Soft delete IS implemented on the User model** (`Marvel\Database\Models\User` uses `SoftDeletes`, and the users migration adds a `deleted_at` column). The `adminDeleteUsers` endpoint performs a soft delete, with guards against deleting super admin users or yourself. The `adminRestoreUser` and `adminDeleteUsersForever` endpoints work with trashed users via `User::withTrashed()`.

---

## 1. Add Admin User

**Endpoint**

`POST /admin-users/add`

**Purpose**

Create a new user and assign roles. Unlike the registration endpoint (which only creates customers), this endpoint allows assigning any role including `super_admin`, `store_owner`, etc.

**Authentication**

| Field | Value |
|---|---|
| Required | Yes |
| Guard | Sanctum |
| Permission | `create-user` |

**Authorization**

- Constructor middleware: `permission:create-user` on `adminAddUsers`

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | User's full name |
| `email` | string | Yes | User's email address |
| `password` | string | Yes | User's password (min 6, max 50) |
| `password_confirmation` | string | Yes | Must match `password` |
| `roles` | array | Yes | Array of role IDs from `roles` table |
| `roles.*` | integer | Yes | Each must exist in `roles` table |
| `is_active` | boolean | No | Defaults to `true` (DB default) |

**Validation Rules**

Based on `AdminCreateUserRequest`:
- `name`: required, string
- `email`: required, email
- `password`: required, min:6, confirmed, max:50
- `roles`: required, array
- `roles.*`: integer, exists:roles,id
- `is_active`: boolean (optional)

**Business Rules**

- User is created with `email_verified_at = now()` (auto-verified)
- Roles are assigned by **role ID** (not permission strings) from the `roles` table
- The user's effective permissions are derived from the roles assigned
- User must have at least one role (roles is required)

**Success Response (200)**

```json
{
    "status": 200,
    "message": "User added successfully",
    "success": true,
    "data": {
        "id": 2,
        "name": "New Admin",
        "email": "newadmin@example.com",
        "email_verified_at": "2024-01-01T00:00:00Z",
        "is_active": true,
        "shop_id": null,
        "roles": [
            {
                "id": 1,
                "name": "super_admin",
                "display_name": "{\"en\":\"Super Admin\",\"ar\":\"مدير النظام\"}",
                "guard_name": "api"
            }
        ],
        "permissions": [
            {
                "id": 1,
                "name": "super_admin",
                "guard_name": "api"
            }
        ]
    }
}
```

**Note:** This endpoint uses the wrapped `apiResponse()` format. The `roles` and `permissions` fields are included via `UserResource` using `whenLoaded('roles')` and `getPermissionsViaRoles()`.

**Error Responses**

| Code | Description |
|---|---|
| 401 | Unauthenticated |
| 403 | Forbidden — requires `create-user` permission |
| 422 | Validation error (invalid fields, password mismatch, etc.) |
| 500 | Something went wrong |

---

## 2. Update User Activation Status

**Endpoint**

`PUT /admin-users/update-activation`

**Purpose**

Toggle a user's `is_active` status. Cannot deactivate a `super_admin` user unless the request is made by that same user or the user is already inactive.

**Authentication**

| Field | Value |
|---|---|
| Required | Yes |
| Guard | Sanctum |
| Permission | `edit-user` |

**Authorization**

- Constructor middleware: `permission:edit-user` on `adminUpdateActivationUsers`

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `user_id` | integer | Yes | ID of the user to toggle activation |

**Validation Rules**

- `user_id`: required, integer, exists:users,id

**Business Rules**

- If the target user has the `super_admin` role:
  - The operation is **blocked** with 400 "User cannot be updated" UNLESS:
    - The requesting user is the same as the target user (self-deactivation allowed), OR
    - The target user is already inactive (re-toggling an inactive super_admin is blocked)
- Otherwise, toggles the `is_active` boolean field (`true` → `false` or `false` → `true`)

**Success Response (200)**

```json
{
    "status": 200,
    "message": "User updated successfully",
    "success": true
}
```

**Error Responses**

| Code | Description |
|---|---|
| 400 | User cannot be updated (super_admin guard triggered) |
| 401 | Unauthenticated |
| 403 | Forbidden — requires `edit-user` permission |
| 404 | User not found |
| 422 | Validation error |
| 500 | Something went wrong |

---

## 3. Delete User

**Endpoint**

`DELETE /admin-users/delete/{id}`

**Purpose**

Hard delete a user from the database. Cannot delete users with the `super_admin` role or yourself. This is a permanent delete — soft delete is NOT implemented on the User model.

**Authentication**

| Field | Value |
|---|---|
| Required | Yes |
| Guard | Sanctum |
| Permission | `delete-user` |

**Authorization**

- Constructor middleware: `permission:delete-user` on `adminDeleteUsers`

**Path Parameters**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | User ID to delete |

**Business Rules**

- Cannot delete a user with the `super_admin` role — returns 400
- Cannot delete yourself — returns 400
- Performs a hard delete via `$user->delete()`
- Soft delete is NOT implemented on the User model
- Routes `PUT /admin-users/restore/{id}` and `DELETE /admin-users/delete-forever/{id}` exist but will fail at the model level until `SoftDeletes` is added to the User model

**Success Response (200)**

```json
{
    "status": 200,
    "message": "User deleted successfully",
    "success": true
}
```

**Error Responses**

| Code | Description |
|---|---|
| 400 | User cannot be deleted (super_admin or self-deletion) |
| 401 | Unauthenticated |
| 403 | Forbidden — requires `delete-user` permission |
| 404 | User not found |
| 500 | Something went wrong |

---

## 4. List All Users

**Endpoint**

`GET /users`

**Purpose**

Retrieve a paginated, filterable list of all users. Supports filtering by type, active status, search, sorting, and pagination.

**Authentication**

| Field | Value |
|---|---|
| Required | Yes |
| Guard | Sanctum |
| Permission | `view-users` |

**Authorization**

- Constructor middleware: `permission:view-users` on `index`, `show`
- Constructor middleware: `permission:view-users` on `adminTrashedUsers` (no route registered)

**Query Parameters**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `limit` | integer | No | Items per page (default: 15) |
| `page` | integer | No | Page number |
| `active` | boolean | No | Filter by `is_active = true` |
| `in_active` | boolean | No | Filter by `is_active = false` |
| `search` | string | No | LIKE search on `name` and `email` fields |
| `order_by` | string | No | Column to sort by (default: `created_at`) |
| `sort` | string | No | Sort direction `asc` or `desc` (default: `desc`) |
| `type` | string | No | Filter by user type (e.g., `user`, `admin`) |
| `users` | string | No | `true` to filter by type `user` |
| `admins` | string | No | `true` to filter by type `admin` |

**Business Rules**

- Uses `withQueryString()` so pagination links preserve all active filters
- Eager loads `profile`, `address`, `permissions` relationships
- Returns raw paginator (not wrapped in `apiResponse`)
- `active` and `in_active` are applied as query scopes on `is_active` column

**Success Response (200)**

```json
{
    "current_page": 1,
    "data": [
        {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "email_verified_at": "2024-01-01T00:00:00Z",
            "is_active": true,
            "shop_id": null,
            "created_at": "2024-01-01T00:00:00Z",
            "updated_at": "2024-01-01T00:00:00Z",
            "profile": { ... },
            "address": [ ... ],
            "permissions": [ ... ]
        }
    ],
    "from": 1,
    "last_page": 5,
    "per_page": 15,
    "to": 15,
    "total": 72,
    "next_page_url": "http://example.com/api/users?page=2",
    "prev_page_url": null,
    "last_page_url": "http://example.com/api/users?page=5",
    "first_page_url": "http://example.com/api/users?page=1",
    "path": "http://example.com/api/users"
}
```

**Note:** This is a legacy endpoint returning the raw paginator format. Filtering support is currently limited — see the test suite for the exact filter implementations that are tested.

**Error Responses**

| Code | Description |
|---|---|
| 401 | Unauthenticated |
| 403 | Forbidden — requires `view-users` permission |
| 500 | Something went wrong |

---

## 5. Ban User

**Endpoint**

`POST /users/block-user`

**Purpose**

Deactivate a user by setting `is_active = false`. Cannot ban yourself.

**Authentication**

| Field | Value |
|---|---|
| Required | Yes |
| Guard | Sanctum |
| Permission | `ban-user` |

**Authorization**

- Constructor middleware: `permission:ban-user` on `banUser`
- Method-level check: `$user->hasPermissionTo(Permission::BAN_USER)`

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | ID of the user to ban |

**Business Rules**

- Cannot ban yourself (checked by `$user->id != $request->id`)
- User must have `ban-user` permission (checked at method level)
- Sets `is_active = false`

**Success Response (200)**

```json
{
    "status": 200,
    "message": "User Deactivated Successfully",
    "success": true
}
```

**Error Responses**

| Code | Description |
|---|---|
| 401 | Unauthenticated |
| 403 | Forbidden — requires `ban-user` permission |
| 404 | User not found |
| 500 | Something went wrong |

---

## 6. Unban User

**Endpoint**

`POST /users/unblock-user`

**Purpose**

Reactivate a banned user by setting `is_active = true`. Cannot activate yourself.

**Authentication**

| Field | Value |
|---|---|
| Required | Yes |
| Guard | Sanctum |
| Permission | `activate-user` |

**Authorization**

- Constructor middleware: `permission:activate-user` on `activeUser`
- Method-level check: `$user->hasPermissionTo(Permission::ACTIVATE_USER)`

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | ID of the user to unban |

**Business Rules**

- Cannot activate yourself (checked by `$user->id != $request->id`)
- User must have `activate-user` permission (checked at method level)
- Sets `is_active = true`

**Success Response (200)**

```json
{
    "status": 200,
    "message": "User Activated Successfully",
    "success": true
}
```

**Error Responses**

| Code | Description |
|---|---|
| 401 | Unauthenticated |
| 403 | Forbidden — requires `activate-user` permission |
| 404 | User not found |
| 500 | Something went wrong |

---

## 7. Toggle Admin Status

**Endpoint**

`POST /users/make-admin`

**Purpose**

Grant or revoke `SUPER_ADMIN` permission from a user. If user is an admin, revokes it; if not, grants it.

**Authentication**

| Field | Value |
|---|---|
| Required | Yes |
| Guard | Sanctum |
| Permission | `super_admin` (via repository `hasPermission` check) |

**Authorization**

- No constructor middleware — uses method-level `$this->repository->hasPermission($user)` which checks for `SUPER_ADMIN` permission
- Dispatches `UserRolesUpdated` event on change

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| `user_id` | integer | Yes | ID of the user to toggle admin status |

**Business Rules**

- Only users with `SUPER_ADMIN` permission can toggle admin status
- If user has `type = admin`, their type is changed to `user` and admin role is removed
- If user does not have `type = admin`, their type is changed to `admin` and `super_admin` role is assigned
- Dispatches `UserRolesUpdated` event with old and new roles

**Success Response (200)**

```json
{
    "status": 200,
    "message": "User updated successfully",
    "success": true
}
```

**Error Responses**

| Code | Description |
|---|---|
| 401 | Unauthenticated |
| 403 | Forbidden — requires SUPER_ADMIN |
| 404 | User not found |
| 500 | Something went wrong |

---

## Database Impact

| Table | Relation | Type |
|---|---|---|
| `users` | Main table | Hard deletes only (no `deleted_at` column) |
| `roles` | Role assignment via `model_has_roles` | Read by `adminAddUsers` |
| `permissions` | Permission assignment via `model_has_permissions` | Read via `getPermissionsViaRoles()` |

**No soft delete infrastructure exists.** If soft-delete functionality is required in the future, the following changes would be needed:
1. Add `SoftDeletes` trait to `Marvel\Database\Models\User`
2. Add `$table->softDeletes()` to the users migration
3. Create new endpoints for restore, force-delete, and trashed listing

---

## Dependencies

| Component | File |
|---|---|
| Controller | `packages/marvel/src/Http/Controllers/UserController.php` |
| Repository | `packages/marvel/src/Database/Repositories/UserRepository.php` |
| Model | `packages/marvel/src/Database/Models/User.php` |
| Request (Add) | `packages/marvel/src/Http/Requests/AdminCreateUserRequest.php` |
| Resource | `packages/marvel/src/Http/Resources/UserResource.php` |
| Resource (Role) | `packages/marvel/src/Http/Resources/RoleResource.php` |
| Resource (Permission) | `packages/marvel/src/Http/Resources/PermissionResource.php` |
| Trait (API Response) | `packages/marvel/src/Traits/ApiResponse.php` |
| Permission Enum | `packages/marvel/src/Enums/Permission.php` |
| Role Enum | `packages/marvel/src/Enums/Role.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` |
| Translations (EN) | `resources/lang/en/activity.php` |
| Constants | `packages/marvel/config/constants.php` |

---

## Architecture Decision — Admin Identification

The project has ONLY two user types: **Admin** and **User**.

- **Admin** — identified by `type = 'admin'` in the `users` table (single source of truth)
- **User** — identified by `type = 'user'` (default)

The Application layer (`app/`) has **zero** references to `Permission::SUPER_ADMIN`. Admin identification in the app layer uses `where('type', 'admin')` exclusively.

The following Marvel legacy permissions are NOT used for admin identification in the Application:
- `SUPER_ADMIN`, `STORE_OWNER`, `STAFF`, `CUSTOMER`

### Marvel Boundary

Marvel package retains internal permission-based admin identification (`Permission::SUPER_ADMIN`) for:
- Internal trait queries (`UsersTrait::getAdminUsers()`, `SmsTrait::adminList()`)
- Controller-level per-method permission middleware
- Internal controller identification (e.g., `adminList()`, banned user checks)

This boundary ensures:
- Marvel operates independently without modification
- The Application maintains its own single source of truth
- New admin users receive both `type = 'admin'` and the `super_admin` permission upon creation

## Implementation Status

| # | Endpoint | Controller Method | Status |
|---|---|---|---|
| 1 | `POST /admin-users/add` | `adminAddUsers` | ✅ Active |
| 2 | `PUT /admin-users/update-activation` | `adminUpdateActivationUsers` | ✅ Active |
| 3 | `DELETE /admin-users/delete/{id}` | `adminDeleteUsers` | ✅ Active |
| 4 | `PUT /admin-users/restore/{id}` | `adminRestoreUser` | ⚠️ Route exists — requires SoftDeletes on User model |
| 5 | `DELETE /admin-users/delete-forever/{id}` | `adminDeleteUsersForever` | ⚠️ Route exists — requires SoftDeletes on User model |
| 6 | `GET /users` | `index` | ✅ Active |
| 7 | `GET /users/{id}` | `show` | ✅ Active |
| 8 | `DELETE /users/{id}` | `destroy` | ✅ Active (legacy resource route) |
| 9 | `POST /users/block-user` | `banUser` | ✅ Active |
| 10 | `POST /users/unblock-user` | `activeUser` | ✅ Active |
| 11 | `POST /users/make-admin` | `makeOrRevokeAdmin` | ✅ Active |
| 12 | `POST /add-points` | `addPoints` | ✅ Active |

Soft delete is NOT implemented on the User model. Routes 4 and 5 require the `SoftDeletes` trait on `Marvel\Database\Models\User` before they become functional.
