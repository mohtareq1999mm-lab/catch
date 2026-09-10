# Role & Permission API Reference

---

## Roles

### `GET /roles` — List All Roles

**Authentication:** Required (`api` guard)

**Permission:** `VIEW_ROLES`

**Request Parameters:** None

**Query Parameters:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Items per page |
| `orderBy` | string | `id` | Sort column |
| `sortedBy` | string | `desc` | Sort direction |
| `search` | string | — | Search term |
| `with` | string | — | Comma-separated relations (e.g. `permissions`) |

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "super_admin",
      "guard_name": "api",
      "display_name": "Super Admin",
      "created_at": "2025-01-01T00:00:00.000000Z",
      "updated_at": "2025-01-01T00:00:00.000000Z",
      "permissions": [
        {
          "id": 1,
          "name": "super_admin",
          "guard_name": "api",
          "label": "Super Admin",
          "created_at": "2025-01-01T00:00:00.000000Z",
          "updated_at": "2025-01-01T00:00:00.000000Z"
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 5
  }
}
```

---

### `POST /roles` — Create Role

**Authentication:** Required (`api` guard)

**Permission:** `CREATE_ROLES`

**Request Body:**
```json
{
  "name": "support_agent",
  "display_name": {
    "en": "Support Agent",
    "ar": "وكيل الدعم"
  },
  "guard_name": "api",
  "permissions": ["view_tickets", "reply_tickets"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | No* | Slug name. Auto-generated from `display_name.en` if omitted |
| `display_name` | object | **Yes** | JSON with locale keys (`en`, `ar`) |
| `guard_name` | string | No | Defaults to `api` |
| `permissions` | array | No | Array of permission name strings to assign |

**Processing Logic:**
1. If `name` is empty, generate from `display_name['en']` (lowercase, spaces → underscores)
2. Create role using `Role::create()`
3. If permissions provided, sync via `givePermissionTo()`
4. Return the created role with loaded permissions

**Success Response (201):**
```json
{
  "data": {
    "id": 6,
    "name": "support_agent",
    "guard_name": "api",
    "display_name": {
      "en": "Support Agent",
      "ar": "وكيل الدعم"
    },
    "created_at": "2025-01-01T00:00:00.000000Z",
    "updated_at": "2025-01-01T00:00:00.000000Z",
    "permissions": [...]
  }
}
```

**Error (422):**
```json
{
  "message": "Validation failed",
  "errors": {
    "display_name.en": ["The display name (English) is required."]
  }
}
```

---

### `GET /roles/{id}` — Show Role

**Authentication:** Required (`api` guard)

**Permission:** `VIEW_ROLE`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `id` | int | Role ID |

**Success Response (200):**
```json
{
  "data": {
    "id": 1,
    "name": "super_admin",
    "guard_name": "api",
    "display_name": "Super Admin",
    "created_at": "2025-01-01T00:00:00.000000Z",
    "updated_at": "2025-01-01T00:00:00.000000Z",
    "permissions": [...]
  }
}
```

**Error (404):**
```json
{
  "message": "Role not found"
}
```

---

### `PUT /roles/{id}` — Update Role

**Authentication:** Required (`api` guard)

**Permission:** `UPDATE_ROLES`

**Request Body:**
```json
{
  "name": "support_agent_v2",
  "display_name": {
    "en": "Support Agent V2",
    "ar": "وكيل الدعم V2"
  },
  "guard_name": "api",
  "permissions": ["view_tickets"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | No | Slug name |
| `display_name` | object | No | JSON with locale keys |
| `guard_name` | string | No | Guard name |
| `permissions` | array | No | Array of permission names to sync |

**Processing Logic:**
1. Find role by ID
2. Update provided fields
3. If `permissions` provided, sync via `syncPermissions()`
4. Return updated role with loaded permissions

**Success Response (200):** Same structure as create.

**Error (404):** `{ "message": "Role not found" }`

---

### `DELETE /roles/{id}` — Delete Role

**Authentication:** Required (`api` guard)

**Permission:** `DELETE_ROLES`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `id` | int | Role ID |

**Processing Logic:**
1. Find role by ID
2. Check `model_has_roles` — if users are assigned, throw 409
3. Delete role (cascades from `model_has_roles`, `role_has_permissions`)

**Success Response (200):**
```json
{
  "message": "Role deleted successfully"
}
```

**Error (409 — Conflict):**
```json
{
  "message": "Role is assigned to one or more users. Remove all user associations first."
}
```

**Error (404):**
```json
{
  "message": "Role not found"
}
```

---

### `POST /users/{userId}/assign-role` — Assign Role To User

**Authentication:** Required (`api` guard)

**Permission:** `ASSIGN_ROLE`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `userId` | int | User ID |

**Request Body:**
```json
{
  "role_id": 1
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `role_id` | int | **Yes** | ID of the role to assign |

**Processing Logic:**
1. Find user by ID
2. Verify user type is `'user'` (not a customer)
3. Validate role_id exists
4. Call `syncRoles($role)` (replaces all current roles)
5. Clear user cache via `ClearUserCacheById::dispatch($userId)`

**Success Response (200):**
```json
{
  "message": "Role assigned successfully"
}
```

**Error (403):**
```json
{
  "message": "This action is only for users not for customers."
}
```

**Error (404):** `{ "message": "User not found" }` or `{ "message": "Role not found" }`

---

### `POST /users/{userId}/remove-role` — Remove Role From User

**Authentication:** Required (`api` guard)

**Permission:** `REMOVE_ROLE`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `userId` | int | User ID |

**Request Body:**
```json
{
  "role_id": 1
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `role_id` | int | **Yes** | ID of the role to remove |

**Processing Logic:**
1. Find user by ID
2. Validate role_id exists
3. Call `removeRole($role)` on user
4. Clear user cache via `ClearUserCacheById::dispatch($userId)`

**Success Response (200):**
```json
{
  "message": "Role removed successfully"
}
```

**Error (404):** `{ "message": "User not found" }` or `{ "message": "Role not found" }`

---

## Permissions

### `GET /permissions` — List All Permissions

**Authentication:** Required (`api` guard)

**Permission:** `SUPER_ADMIN`

**Query Parameters:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Items per page |
| `orderBy` | string | `id` | Sort column |
| `sortedBy` | string | `desc` | Sort direction |
| `search` | string | — | Search term |
| `with` | string | — | Comma-separated relations |

**Success Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "super_admin",
      "guard_name": "api",
      "label": "Super Admin",
      "created_at": "2025-01-01T00:00:00.000000Z",
      "updated_at": "2025-01-01T00:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 40
  }
}
```

---

### `POST /roles/{roleId}/permissions` — Assign Permission To Role

**Authentication:** Required (`api` guard)

**Permission:** `SUPER_ADMIN`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `roleId` | int | Role ID |

**Request Body:**
```json
{
  "permissions": ["view_products", "create_products"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `permissions` | array | **Yes** | Array of permission name strings |

**Processing Logic:**
1. Find role by ID
2. Loop through permissions array
3. For each, find or create the permission by name with `api` guard
4. Call `givePermissionTo()` on role

**Success Response (200):**
```json
{
  "message": "Permission assigned to role successfully"
}
```

**Error (404):** `{ "message": "Role not found" }`

---

### `POST /users/{userId}/permissions` — Give Permission To User

**Authentication:** Required (`api` guard)

**Permission:** `SUPER_ADMIN`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `userId` | int | User ID |

**Request Body:**
```json
{
  "permissions": ["view_products"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `permissions` | array | **Yes** | Array of permission name strings |

**Processing Logic:**
1. Find user by ID
2. Find or create each permission by name with `api` guard
3. Call `givePermissionTo()` for each on user

**Success Response (200):**
```json
{
  "message": "Permission added to user successfully"
}
```

**Error (404):** `{ "message": "User not found" }`

---

### `PUT /users/{userId}/permissions` — Sync User Permissions

**Authentication:** Required (`api` guard)

**Permission:** `SUPER_ADMIN`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `userId` | int | User ID |

**Request Body:**
```json
{
  "permissions": ["view_products", "create_products"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `permissions` | array | **Yes** | Array of permission name strings to sync |

**Processing Logic:**
1. Find user by ID
2. Find or create each permission by name with `api` guard
3. Collect permission IDs
4. Call `syncPermissions($permissionIds)` on user (replaces all direct permissions)

**Success Response (200):**
```json
{
  "message": "User permissions synced successfully"
}
```

**Error (404):** `{ "message": "User not found" }`

---

### `DELETE /users/{userId}/permissions` — Remove Permission From User

**Authentication:** Required (`api` guard)

**Permission:** `SUPER_ADMIN`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `userId` | int | User ID |

**Request Body:**
```json
{
  "permissions": ["view_products"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `permissions` | array | **Yes** | Array of permission name strings to remove |

**Processing Logic:**
1. Find user by ID
2. Find each permission by name
3. Call `revokePermissionTo()` for each on user

**Success Response (200):**
```json
{
  "message": "Permission removed from user successfully"
}
```

**Error (404):** `{ "message": "User not found" }`

---

## Common Error Responses

| Code | Description |
|------|-------------|
| 401 | Unauthenticated (missing/invalid token) |
| 403 | Forbidden (missing required permission) |
| 404 | Resource not found (user, role, or permission) |
| 409 | Conflict (role in use) |
| 422 | Validation error |
| 500 | Server error |
