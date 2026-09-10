# API Reference — Admin Users

---

## GET /api/me

Get the currently authenticated user.

**Authentication**: `auth:sanctum`

**Response 200**:
```json
{
  "status": 200,
  "message": "User profile retrieved successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "email_verified_at": "2024-01-01T00:00:00.000000Z",
    "is_active": true,
    "image": null,
    "type": "user",
    "phone_number": "01000000001",
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z",
    "roles": [],
    "permissions": [],
    "address": []
  }
}
```

**Response 401**:
```json
{ "status": 401, "message": "Unauthenticated", "success": false }
```

---

## GET /api/users

Paginated list of users.

**Authentication**: `auth:sanctum`, permission: `view-users`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 15 | Items per page |
| search | string | - | Search by name or email (LIKE) |
| users | string | 'false' | Filter by type='user' |
| admins | string | 'false' | Filter by type='admin' |
| active | string | 'false' | Filter by is_active=true |
| in_active | string | 'false' | Filter by is_active=false |
| trash | string | 'false' | Show only soft-deleted |
| type | string | - | Exact type filter ('user' or 'admin') |
| order_by | string | 'created_at' | Column to order by |
| sort | string | 'desc' | Sort direction |

**Response 200**:
```json
{
  "status": 200,
  "message": "Users listed successfully",
  "success": true,
  "data": { ... },
  "current_page": 1,
  "from": 1,
  "to": 15,
  "last_page": 5,
  "per_page": 15,
  "total": 72,
  "next_page_url": "/api/users?page=2",
  "prev_page_url": "",
  "last_page_url": "/api/users?page=5",
  "first_page_url": "/api/users?page=1",
  "path": "/api/users"
}
```

---

## GET /api/users/{id}

Get a single user.

**Authentication**: `auth:sanctum`, permission: `view-users`

**Response 200** (admin user loads roles+permissions; regular user loads address):
```json
{
  "status": 200,
  "message": "User fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    ...
  }
}
```

**Response 404**: `{ "status": 404, "message": "Not found", "success": false }`

---

## PUT /api/users/{id}

Update user profile.

**Authentication**: `auth:sanctum`, permission: `edit-user`

**Request Body**:
```json
{
  "name": "Updated Name",
  "email": "updated@example.com",
  "avatar": "file (image, max 2MB)",
  "profile": { "bio": "..." },
  "address": [{ "id": 1, "title": "Home", ... }]
}
```

**Business Rules**:
- SUPER_ADMIN can update any user
- Regular user can only update themselves
- Email unique check ignores current user ID

**Response 200**:
```json
{ "status": 200, "message": "User updated successfully", "success": true, "data": { ... } }
```

**Response 403**: `{ "status": 403, "message": "Not authorized", "success": false }`

---

## POST /api/admin-users/add

Create an admin user with roles.

**Authentication**: `auth:sanctum`, permission: `create-user`

**Request Body**:
```json
{
  "name": "New Admin",
  "email": "admin@example.com",
  "password": "securePass123",
  "password_confirmation": "securePass123",
  "phone_number": "01000000002",
  "roles": [1, 2],
  "image": "file (jpeg,png,jpg,webp)",
  "is_active": 1
}
```

**Validation Rules**:
| Field | Rules |
|-------|-------|
| name | required |
| email | required, email, unique:users |
| password | required, min:6, confirmed, max:50 |
| phone_number | required, unique:users |
| roles | sometimes, array, each: integer, exists:roles,id |
| image | nullable, image, mimes:jpeg,png,jpg,webp |
| is_active | nullable, in:0,1 |

**Response 200**:
```json
{ "status": 200, "message": "User added successfully", "success": true, "data": { ... } }
```

**Response 422**:
```json
{
  "email": ["The email has already been taken."],
  "phone_number": ["The phone number has already been taken."],
  "password": ["The password confirmation does not match."]
}
```

---

## PUT /api/admin-users/update-activation

Toggle user activation status.

**Authentication**: `auth:sanctum`, permission: `edit-user`

**Request Body**:
```json
{ "user_id": 5 }
```

**Business Rules**:
- Toggles `is_active` (true ↔ false)
- Cannot deactivate an active super_admin user
- An already-inactive super_admin can be toggled back to active

**Response 200**:
```json
{ "status": 200, "message": "User updated successfully", "success": true }
```

**Response 400**:
```json
{ "status": 400, "message": "User cannot be updated", "success": false }
```

---

## DELETE /api/admin-users/delete/{id}

Soft delete a user.

**Authentication**: `auth:sanctum`, permission: `delete-user`

**Business Rules**:
- Cannot delete super_admin (hasRole 'super_admin')
- Cannot delete self
- Revokes all Sanctum tokens before deletion

**Response 200**:
```json
{ "status": 200, "message": "User deleted successfully", "success": true }
```

---

## PUT /api/admin-users/restore/{id}

Restore a soft-deleted user.

**Authentication**: `auth:sanctum`, permission: `restore-user`

**Business Rules**:
- Only works on trashed users
- Non-trashed user returns 400

**Response 200**:
```json
{ "status": 200, "message": "User restored successfully", "success": true }
```

---

## DELETE /api/admin-users/delete-forever/{id}

Permanently delete a user.

**Authentication**: `auth:sanctum`, permission: `delete-user`

**Business Rules**:
- Cannot delete super_admin or self
- Removes user permanently from database

**Response 200**:
```json
{ "status": 200, "message": "User deleted successfully", "success": true }
```
