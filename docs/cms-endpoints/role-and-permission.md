# Role & Permission API

## Overview

The Role & Permission module manages role-based access control (RBAC). It supports creating roles, assigning permissions to roles, and managing user-role mappings. All endpoints are restricted to `super_admin` users.

---

## Database Schema

### `roles` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `name` | varchar(255) | UNIQUE | Role slug (e.g. `super_admin`) |
| `display_name` | text | NULLABLE | Translatable display name |
| `guard_name` | varchar(255) | — | Auth guard (`api`) |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

### `permissions` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `name` | varchar(255) | — | Permission key (e.g. `create-roles`) |
| `guard_name` | varchar(255) | — | Auth guard (`api`) |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

### `role_has_permissions` Table (Pivot)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `permission_id` | bigint | FK → permissions.id ON DELETE CASCADE | Permission ID |
| `role_id` | bigint | FK → roles.id ON DELETE CASCADE | Role ID |

### `model_has_roles` Table (Pivot)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `role_id` | bigint | FK → roles.id ON DELETE CASCADE | Role ID |
| `model_type` | varchar(255) | — | Model class (e.g. `Marvel\Database\Models\User`) |
| `model_id` | bigint | — | User ID |

### `model_has_permissions` Table (Pivot)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `permission_id` | bigint | FK → permissions.id ON DELETE CASCADE | Permission ID |
| `model_type` | varchar(255) | — | Model class |
| `model_id` | bigint | — | User ID |

---

## Response Envelope

### Single Resource Response

```json
{
    "status": 200,
    "message": "Translated message string",
    "success": true,
    "data": {}
}
```

### Paginated Collection Response

```json
{
    "status": 200,
    "message": "Translated message string",
    "success": true,
    "data": {
        "data": [],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 10,
        "last_page": 1,
        "path": "http://...",
        "per_page": 10,
        "total": 5,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "http://...",
        "first_page_url": "http://..."
    }
}
```

---

## Resource Structure

### RoleResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Role ID |
| `display_name` | string | Translated display name (list) or raw JSON (single resource) |
| `permissions` | array|null | PermissionResource collection (when loaded) |

**Example (single resource):**
```json
{
    "id": 1,
    "display_name": "{\"en\":\"Moderator\",\"ar\":\"مشرف\"}",
    "permissions": [
        {
            "id": 1,
            "label": "View Products"
        }
    ]
}
```

**Example (list — translated):**
```json
{
    "id": 1,
    "display_name": "Moderator"
}
```

### PermissionResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Permission ID |
| `label` | string | Translated label (falls back to permission key) |

**Example:**
```json
{
    "id": 1,
    "label": "Create Roles"
}
```

### UserResource (roles/permissions context)

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | User ID |
| `name` | string | User name |
| `email` | string | Email address |
| `email_verified_at` | string|null | Email verification timestamp |
| `is_active` | bool | Active status |
| `shop_id` | int|null | Managed shop ID |
| `image` | string|null | User avatar URL |
| `roles` | array | RoleResource collection |
| `permissions` | array | PermissionResource collection (from roles) |

**Example:**
```json
{
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "email_verified_at": "2026-06-01T00:00:00.000000Z",
    "is_active": true,
    "shop_id": null,
    "image": null,
    "roles": [
        {
            "id": 1,
            "display_name": "Super Admin"
        }
    ],
    "permissions": [
        {
            "id": 1,
            "label": "Create Roles"
        }
    ]
}
```

---

## Endpoints

### GET /roles — List Roles

**Purpose:** Retrieve a paginated list of all roles with optional search.

**Method:** `GET`

**URL:** `/roles`

**Authentication:** Required

**Permissions:** `super_admin` role

**Roles:** `super_admin`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 10 | Results per page |
| `search` | string | — | Search by `name` or `display_name` (LIKE) |

**Business Logic:**
1. If `search` is provided, filters roles where `name` or `display_name` contains the search term
2. Paginates with given limit
3. Flattens pagination meta into the response envelope
4. Returns `RoleResource` collection in `data.data` (permissions not loaded)

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Roles fetched successfully",
    "success": true,
    "data": [
        {
            "id": 1,
            "display_name": "Super Admin"
        },
        {
            "id": 2,
            "display_name": "Customer"
        }
    ]
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 500 | Server error |

---

### GET /roles/{id} — Show Role

**Purpose:** Retrieve a single role by ID with its assigned permissions.

**Method:** `GET`

**URL:** `/roles/{id}`

**Authentication:** Required

**Roles:** `super_admin`

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | int | **Yes** | Role ID |

**Business Logic:**
1. Finds role by ID using `Role::findOrFail($id)`
2. Loads `permissions` relation
3. Returns role with permissions

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Role fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "display_name": "{\"en\":\"Super Admin\",\"ar\":\"مدير النظام\"}",
        "permissions": [
            {
                "id": 1,
                "label": "Create Roles"
            },
            {
                "id": 2,
                "label": "Update Roles"
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | Role not found |
| 500 | Server error |

---

### POST /roles — Create Role

**Purpose:** Create a new role with a translatable display name.

**Method:** `POST`

**URL:** `/roles`

**Authentication:** Required

**Permissions:** `create-roles`

**Roles:** `super_admin`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `display_name` | object | **Yes** | Must be an array (translatable) |
| `display_name.*` | string | **Yes** | Required, string, unique translation in `roles.display_name` |

**Example Request:**
```json
{
    "display_name": {
        "en": "Moderator",
        "ar": "مشرف"
    }
}
```

**Business Logic:**
1. Validates via inline `$request->validate()`
2. Generates `name` from `display_name.en`: lowercase, spaces → underscores (e.g. "Moderator" → "moderator")
3. Creates role with `guard_name = 'api'`
4. Returns created role

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Role added successfully",
    "success": true,
    "data": {
        "id": 3,
        "display_name": "{\"en\":\"Moderator\",\"ar\":\"مشرف\"}"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `create-roles` permission |
| 422 | Validation failure |
| 500 | Server error |

---

### PUT /roles/{id} — Update Role

**Purpose:** Update an existing role's display name.

**Method:** `PUT`

**URL:** `/roles/{id}`

**Authentication:** Required

**Permissions:** `update-roles`

**Roles:** `super_admin`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `display_name` | object | **Yes** | Must be an array (translatable) |
| `display_name.*` | string | **Yes** | Required, string, unique translation (ignores self) |

**Example Request:**
```json
{
    "display_name": {
        "en": "Senior Moderator",
        "ar": "مشرف أول"
    }
}
```

**Business Logic:**
1. Finds role by ID using `Role::findById($id, 'api')`
2. Validates via inline `$request->validate()`
3. Generates updated `name` from `display_name.en`
4. Updates role
5. Returns updated role

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Role updated successfully",
    "success": true,
    "data": {
        "id": 3,
        "display_name": "{\"en\":\"Senior Moderator\",\"ar\":\"مشرف أول\"}"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-roles` permission |
| 422 | Validation failure |
| 404 | Role not found |
| 500 | Server error |

---

### DELETE /roles/{id} — Delete Role

**Purpose:** Delete a role. If users have this role, pivot rows are cascade-deleted.

**Method:** `DELETE`

**URL:** `/roles/{id}`

**Authentication:** Required

**Permissions:** `delete-roles`

**Roles:** `super_admin`

**Business Logic:**
1. Finds role by ID using `Role::findById($id, 'api')`
2. Deletes the role (pivot rows in `model_has_roles` and `role_has_permissions` cascade-deleted)
3. Returns success message

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Role deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-roles` permission |
| 404 | Role not found |
| 500 | Server error |

---

### POST /users/{userId}/assign-role — Assign Role to User

**Purpose:** Assign one or more roles to a user, replacing all existing roles.

**Method:** `POST`

**URL:** `/users/{userId}/assign-role`

**Authentication:** Required

**Permissions:** `assign-role`

**Roles:** `super_admin`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `role_ids` | array | **Yes** | Array of integers |
| `role_ids.*` | int | **Yes** | Must exist in `roles` table with `guard_name = 'api'` |

**Example Request:**
```json
{
    "role_ids": [2, 3]
}
```

**Business Logic:**
1. Validates role IDs exist and belong to `api` guard
2. Finds user by ID
3. Calls `syncRoles()` — replaces all current roles with the given ones
4. Loads `roles` and `permissions` relations
5. Returns user with roles and permissions

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Role assigned successfully",
    "success": true,
    "data": {
        "id": 2,
        "name": "Jane Doe",
        "email": "jane@example.com",
        "email_verified_at": "2026-06-01T00:00:00.000000Z",
        "is_active": true,
        "shop_id": null,
        "image": null,
        "roles": [
            {
                "id": 2,
                "display_name": "Customer"
            },
            {
                "id": 3,
                "display_name": "Moderator"
            }
        ],
        "permissions": [
            {
                "id": 5,
                "label": "View Products"
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `assign-role` permission |
| 422 | Validation failure |
| 500 | Server error |

---

### POST /users/{userId}/remove-role — Remove Role from User

**Purpose:** Remove specific roles from a user without affecting other roles.

**Method:** `POST`

**URL:** `/users/{userId}/remove-role`

**Authentication:** Required

**Permissions:** `remove-role`

**Roles:** `super_admin`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `role_ids` | array | **Yes** | Array of integers |
| `role_ids.*` | int | **Yes** | Must exist in `roles` table with `guard_name = 'api'` |

**Example Request:**
```json
{
    "role_ids": [3]
}
```

**Business Logic:**
1. Validates role IDs exist and belong to `api` guard
2. Finds user by ID
3. Calls `removeRole()` for each role
4. Loads `roles` and `permissions` relations
5. Returns success message

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Role removed successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `remove-role` permission |
| 404 | User not found |
| 422 | Validation failure |
| 500 | Server error |

---

### GET /permissions — List Permissions

**Purpose:** Retrieve a paginated list of all permissions with optional search.

**Method:** `GET`

**URL:** `/permissions`

**Authentication:** Required

**Roles:** `super_admin`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 100 | Results per page |
| `search` | string | — | Search by `name` (LIKE) |

**Business Logic:**
1. If `search` is provided, filters permissions where `name` contains the search term
2. Paginates with given limit
3. Flattens pagination meta into the response envelope
4. Returns `PermissionResource` collection in `data.data`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Permissions fetched successfully",
    "success": true,
    "data": [
        {
            "id": 1,
            "label": "Create Roles"
        },
        {
            "id": 2,
            "label": "Update Roles"
        }
    ]
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 500 | Server error |

---

### POST /roles/{roleId}/permissions — Assign Permissions to Role

**Purpose:** Sync permissions on a role, replacing all existing permission assignments.

**Method:** `POST`

**URL:** `/roles/{roleId}/permissions`

**Authentication:** Required

**Roles:** `super_admin`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `permissions` | array | **Yes** | Array of integers |
| `permissions.*` | int | **Yes** | Integer, distinct, must exist in `permissions` table with `guard_name = 'api'` |

**Example Request:**
```json
{
    "permissions": [1, 2, 5]
}
```

**Business Logic:**
1. Validates permission IDs exist and belong to `api` guard
2. Finds role by ID using `Role::findById($roleId, 'api')`
3. Calls `syncPermissions()` — replaces all current permissions
4. Loads `permissions` relation
5. Returns role with permissions

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Permission assigned successfully",
    "success": true,
    "data": {
        "id": 3,
        "display_name": "{\"en\":\"Moderator\",\"ar\":\"مشرف\"}",
        "permissions": [
            {
                "id": 1,
                "label": "Create Roles"
            },
            {
                "id": 2,
                "label": "Update Roles"
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | Role not found |
| 422 | Validation failure |
| 500 | Server error |

---

### POST /users/{userId}/permissions — Give Permission to User

**Purpose:** Grant direct permissions to a user (additive — existing permissions are preserved).

**Method:** `POST`

**URL:** `/users/{userId}/permissions`

**Authentication:** Required

**Roles:** `super_admin`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `permissions` | array | **Yes** | Array of integers |
| `permissions.*` | int | **Yes** | Integer, distinct, must exist in `permissions` table with `guard_name = 'api'` |

**Example Request:**
```json
{
    "permissions": [10, 11]
}
```

**Business Logic:**
1. Validates permission IDs exist and belong to `api` guard
2. Finds user by ID
3. Calls `givePermissionTo()` — adds permissions without removing existing ones
4. Loads `roles` and `permissions` relations
5. Returns user with roles and permissions

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Permission assigned successfully",
    "success": true,
    "data": {
        "id": 2,
        "name": "Jane Doe",
        "email": "jane@example.com",
        "email_verified_at": "2026-06-01T00:00:00.000000Z",
        "is_active": true,
        "shop_id": null,
        "image": null,
        "roles": [
            {
                "id": 2,
                "name": "customer",
                "display_name": "Customer"
            }
        ],
        "permissions": [
            {
                "id": 10,
                "name": "view-orders",
                "label": "View Orders"
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | User not found |
| 422 | Validation failure |
| 500 | Server error |

---

### PUT /users/{userId}/permissions — Sync Permissions on User

**Purpose:** Replace all direct permissions on a user with a new set.

**Method:** `PUT`

**URL:** `/users/{userId}/permissions`

**Authentication:** Required

**Roles:** `super_admin`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `permissions` | array | **Yes** | Array of integers |
| `permissions.*` | int | **Yes** | Integer, distinct, must exist in `permissions` table with `guard_name = 'api'` |

**Example Request:**
```json
{
    "permissions": [5, 6]
}
```

**Business Logic:**
1. Validates permission IDs exist and belong to `api` guard
2. Finds user by ID
3. Calls `syncPermissions()` — removes all current direct permissions and sets the new ones
4. Loads `roles` and `permissions` relations
5. Returns user with roles and permissions

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Permission assigned successfully",
    "success": true,
    "data": {
        "id": 2,
        "name": "Jane Doe",
        "email": "jane@example.com",
        "is_active": true,
        "roles": [],
        "permissions": [
            {
                "id": 5,
                "name": "view-products",
                "label": "View Products"
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | User not found |
| 422 | Validation failure |
| 500 | Server error |

---

### DELETE /users/{userId}/permissions — Remove Permission from User

**Purpose:** Revoke specific direct permissions from a user.

**Method:** `DELETE`

**URL:** `/users/{userId}/permissions`

**Authentication:** Required

**Roles:** `super_admin`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `permissions` | array | **Yes** | Array of integers |
| `permissions.*` | int | **Yes** | Integer, distinct, must exist in `permissions` table with `guard_name = 'api'` |

**Example Request:**
```json
{
    "permissions": [10]
}
```

**Business Logic:**
1. Validates permission IDs exist and belong to `api` guard
2. Finds user by ID
3. Calls `revokePermissionTo()` for each permission — removes specific permissions without affecting others
4. Loads `roles` and `permissions` relations
5. Returns success message

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Permission assigned successfully",
    "success": true,
    "data": {
        "id": 2,
        "name": "Jane Doe",
        "email": "jane@example.com",
        "is_active": true,
        "roles": [],
        "permissions": []
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | User not found |
| 422 | Validation failure |
| 500 | Server error |

---

## Route Definitions

```php
// Super Admin routes (auth:sanctum, verified)
Route::get('/roles', [RoleAndPermissionController::class, 'getAllRoles']);
Route::get('/roles/{id}', [RoleAndPermissionController::class, 'showRole']);
Route::post('/roles', [RoleAndPermissionController::class, 'addRole']);
Route::put('/roles/{id}', [RoleAndPermissionController::class, 'updateRole']);
Route::delete('/roles/{id}', [RoleAndPermissionController::class, 'destroyRole']);
Route::post('/users/{userId}/assign-role', [RoleAndPermissionController::class, 'assignRole']);
Route::post('/users/{userId}/remove-role', [RoleAndPermissionController::class, 'removeRoleFromUser']);

Route::get('/permissions', [RoleAndPermissionController::class, 'getAllPermissions']);
Route::post('/roles/{roleId}/permissions', [RoleAndPermissionController::class, 'assignPermissionToRole']);
Route::post('/users/{userId}/permissions', [RoleAndPermissionController::class, 'givePermission']);
Route::put('/users/{userId}/permissions', [RoleAndPermissionController::class, 'syncPermissions']);
Route::delete('/users/{userId}/permissions', [RoleAndPermissionController::class, 'removePermission']);
```

Source: `packages/marvel/src/Rest/Routes.php` (lines 716-728)

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_ROLE` | `view-role` | `showRole` |
| `CREATE_ROLES` | `create-roles` | `addRole` |
| `UPDATE_ROLES` | `update-roles` | `updateRole` |
| `DELETE_ROLES` | `delete-roles` | `destroyRole` |
| `ASSIGN_ROLE` | `assign-role` | `assignRole` |
| `REMOVE_ROLE` | `remove-role` | `removeRoleFromUser` |

Only the methods listed above have explicit permission middleware via `$this->middleware('permission:...')` in the constructor. All other methods rely solely on the route group's `role:super_admin` gate.

Source: `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` (lines 25-34)

---

## Translation Messages

| Key | English |
|-----|---------|
| `MESSAGE.ROLES_FETCHED_SUCCESSFULLY` | Roles fetched successfully |
| `MESSAGE.ROLE_ADDED_SUCCESSFULLY` | Role added successfully |
| `MESSAGE.ROLE_UPDATED_SUCCESSFULLY` | Role updated successfully |
| `MESSAGE.ROLE_DELETED_SUCCESSFULLY` | Role deleted successfully |
| `MESSAGE.ROLE_ASSIGNED_SUCCESSFULLY` | Role assigned successfully |
| `MESSAGE.ROLE_REMOVED_SUCCESSFULLY` | Role removed successfully |
| `MESSAGE.PERMISSIONS_FETCHED_SUCCESSFULLY` | Permissions fetched successfully |
| `MESSAGE.PERMISSION_ASSIGNED_SUCCESSFULLY` | Permission assigned successfully |
| `ERROR.CANNOT_DELETE_ROLE_WITH_ASSIGNED_USERS` | Cannot delete role with assigned users |
| `ERROR.NOT_FOUND` | Not found |
| `ERROR.NOT_AUTHORIZED` | Not authorized |

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `RoleAndPermissionController` | Controller | `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` |
| `Role` | Model (Marvel) | `packages/marvel/src/Database/Models/Role.php` |
| `User` | Model | `packages/marvel/src/Database/Models/User.php` |
| `Permission` | Model (Spatie) | `vendor/spatie/laravel-permission/src/Models/Permission.php` |
| `RoleResource` | Resource | `packages/marvel/src/Http/Resources/RoleResource.php` |
| `PermissionResource` | Resource | `packages/marvel/src/Http/Resources/PermissionResource.php` |
| `UserResource` | Resource | `packages/marvel/src/Http/Resources/UserResource.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |
| `Role` | Enum | `packages/marvel/src/Enums/Role.php` |
| `ApiResponse` | Trait | `packages/marvel/src/Traits/ApiResponse.php` |

---

## Test Coverage

| File | Tests |
|------|-------|
| `tests/Feature/RoleAndPermissionTest.php` | 32 tests covering roles CRUD, permission assignment, user-role mapping, validation, and authorization |

**Test Scenarios:**
- ✓ List roles with/without search
- ✓ Show role by ID with permissions (loaded relation)
- ✓ Show nonexistent role returns 404
- ✓ Create role (success, validation error, 403 without permission)
- ✓ Update role (success, not found)
- ✓ Delete role (success, with assigned users cascade, not found)
- ✓ List permissions with/without search
- ✓ Assign permissions to role (success, invalid IDs, not found role)
- ✓ Assign role to user (success, not found user, invalid IDs, 403)
- ✓ Remove role from user (success, not found user, 403)
- ✓ Give/sync/remove direct permissions (success, invalid IDs)
- ✓ Unauthenticated access (401 on all endpoints)
