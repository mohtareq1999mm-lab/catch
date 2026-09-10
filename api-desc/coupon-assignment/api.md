# API Reference — Coupon Assignment (Admin CRUD)

---

## Endpoints

| Method | Endpoint | Auth | Permission | Description |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/coupons/{coupon}/assignments` | Sanctum | `view-coupon-assignments` | List assignments for a coupon |
| POST | `/api/v1/coupons/{coupon}/assignments` | Sanctum | `create-coupon-assignment` | Assign coupon to a user |
| GET | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Sanctum | `view-coupon-assignments` | Show a single assignment |
| PUT | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Sanctum | `update-coupon-assignment` | Update assignment (max_uses, expires_at) |
| DELETE | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Sanctum | `delete-coupon-assignment` | Delete assignment (blocked if used > 0) |

---

### GET /api/v1/coupons/{coupon}/assignments

Paginated list of all user assignments for a specific coupon.

**Authentication:** `auth:sanctum`, role: `super_admin`, permission: `view-coupon-assignments`

**Path Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| coupon | int | Coupon ID |

**Query Parameters:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| limit | int | 15 | Items per page |
| page | int | 1 | Page number |

**Response 200:**
```json
{
  "status": 200,
  "message": "Assignments fetched successfully.",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "coupon_id": 1,
        "user_id": 2,
        "user": {
          "id": 2,
          "name": "Ahmed Ali",
          "email": "ahmed@example.com"
        },
        "max_uses": 5,
        "used": 2,
        "remaining": 3,
        "is_expired": false,
        "assigned_at": "2026-07-25T10:00:00.000000Z",
        "expires_at": "2026-08-25T10:00:00.000000Z"
      }
    ],
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

**Response 404 (coupon not found):**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Business Rules:**
- Results are paginated with `limit` (default 15)
- Only returns assignments belonging to the specified coupon (cross-coupon isolation)
- `user` is included when relation is loaded (eager-loaded via `with('user')`)
- `remaining = max(0, max_uses - used)` — computed in resource
- `is_expired` = true when `expires_at` is in the past or null

---

### POST /api/v1/coupons/{coupon}/assignments

Assign a coupon to a specific user with usage limits and optional expiry.

**Authentication:** `auth:sanctum`, role: `super_admin`, permission: `create-coupon-assignment`

**Path Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| coupon | int | Coupon ID |

**Request Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| user_id | int | **Yes** | User ID (must exist in users table) |
| max_uses | int | **Yes** | Maximum number of times the user can use this coupon (min: 1) |
| expires_at | string | No | ISO 8601 datetime — assignment expiry (must be in the future) |

**Request Body (JSON):**
```json
{
  "user_id": 2,
  "max_uses": 5,
  "expires_at": "2026-08-25T10:00:00.000000Z"
}
```

**Validation Rules:**
| Field | Rules |
|-------|-------|
| user_id | required, integer, exists:users,id |
| max_uses | required, integer, min:1 |
| expires_at | nullable, date, after:now |

**Response 201:**
```json
{
  "status": 201,
  "message": "Coupon assigned successfully.",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 1,
    "user_id": 2,
    "user": {
      "id": 2,
      "name": "Ahmed Ali",
      "email": "ahmed@example.com"
    },
    "max_uses": 5,
    "used": 0,
    "remaining": 5,
    "is_expired": false,
    "assigned_at": "2026-07-25T10:00:00.000000Z",
    "expires_at": "2026-08-25T10:00:00.000000Z"
  }
}
```

**Response 409 (duplicate assignment):**
```json
{
  "status": 409,
  "message": "This coupon is already assigned to the specified user.",
  "success": false
}
```

**Response 404 (coupon not found):**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Response 422 (validation):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "user_id": ["The user id field is required."],
    "max_uses": ["The max uses must be at least 1."],
    "expires_at": ["The expires at must be a date after now."]
  }
}
```

**Business Rules:**
- Duplicate `(coupon_id, user_id)` pairs are rejected with 409
- `used` starts at 0 (database default)
- `assigned_at` is set automatically to current timestamp
- Transactional — creation and duplicate check happen in `DB::transaction()`
- Coupon must exist (validated via route model binding fallback + manual query)

---

### GET /api/v1/coupons/{coupon}/assignments/{assignment}

Get a single assignment by ID, scoped to the parent coupon.

**Authentication:** `auth:sanctum`, role: `super_admin`, permission: `view-coupon-assignments`

**Path Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| coupon | int | Coupon ID |
| assignment | int | Assignment ID |

**Response 200:**
```json
{
  "status": 200,
  "message": "Assignments fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 1,
    "user_id": 2,
    "user": {
      "id": 2,
      "name": "Ahmed Ali",
      "email": "ahmed@example.com"
    },
    "max_uses": 5,
    "used": 2,
    "remaining": 3,
    "is_expired": false,
    "assigned_at": "2026-07-25T10:00:00.000000Z",
    "expires_at": "2026-08-25T10:00:00.000000Z"
  }
}
```

**Response 404:**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Business Rules:**
- Returns 404 if the assignment belongs to a different coupon (scoped query: `where('coupon_id', $couponId)->where('id', $assignmentId)`)
- `user` relation is included in the resource
- `remaining` and `is_expired` are computed fields

---

### PUT /api/v1/coupons/{coupon}/assignments/{assignment}

Update an existing assignment — modify `max_uses` and/or `expires_at`.

**Authentication:** `auth:sanctum`, role: `super_admin`, permission: `update-coupon-assignment`

**Path Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| coupon | int | Coupon ID |
| assignment | int | Assignment ID |

**Request Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| max_uses | int | No | New max usage limit (min: 1, must be >= current `used`) |
| expires_at | string/null | No | New expiry datetime, or null to clear expiry |

**Request Body (JSON):**
```json
{
  "max_uses": 10,
  "expires_at": "2026-09-01T10:00:00.000000Z"
}
```

**Validation Rules:**
| Field | Rules |
|-------|-------|
| max_uses | sometimes, integer, min:1 |
| expires_at | nullable, date, after:now |

**Response 200:**
```json
{
  "status": 200,
  "message": "Assignment updated successfully.",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 1,
    "user_id": 2,
    "user": null,
    "max_uses": 10,
    "used": 2,
    "remaining": 8,
    "is_expired": false,
    "assigned_at": "2026-07-25T10:00:00.000000Z",
    "expires_at": "2026-09-01T10:00:00.000000Z"
  }
}
```

**Response 422 (max_uses below used):**
```json
{
  "status": 422,
  "message": "max_uses cannot be less than the current usage count.",
  "success": false
}
```

**Response 404:**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Business Rules:**
- `max_uses` cannot be set below current `used` value — returns 422
- `expires_at` can be set to `null` to remove expiry
- `user_id` and `coupon_id` cannot be changed (immutable after creation)
- Not transactional (single atomic update)

---

### DELETE /api/v1/coupons/{coupon}/assignments/{assignment}

Delete an assignment. Blocked if the assignment has usage history.

**Authentication:** `auth:sanctum`, role: `super_admin`, permission: `delete-coupon-assignment`

**Path Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| coupon | int | Coupon ID |
| assignment | int | Assignment ID |

**Response 200:**
```json
{
  "status": 200,
  "message": "Assignment deleted successfully.",
  "success": true
}
```

**Response 409 (has usage history):**
```json
{
  "status": 409,
  "message": "Cannot delete assignment with usage history.",
  "success": false
}
```

**Response 404:**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Business Rules:**
- Deletion is **blocked** when `used > 0` to prevent audit loss — returns 409
- Uses `DB::transaction()` with `lockForUpdate()` for concurrency safety
- Deleting an unused assignment restores quota for the user

---

## Common Error Responses

| Code | Description |
|------|-------------|
| 401 | Unauthenticated (missing/invalid token) |
| 403 | Forbidden (missing `super_admin` role or required permission) |
| 404 | Resource not found (coupon or assignment) |
| 409 | Conflict (duplicate assignment, or deletion with usage history) |
| 422 | Validation error |
| 500 | Server error |

---

## Resource Structure

| Field | Type | Description |
|-------|------|-------------|
| id | int | Auto-increment ID |
| coupon_id | int | Parent coupon ID |
| user_id | int | Assigned user ID |
| user | object/null | User data (id, name, email) — null if not loaded |
| max_uses | int | Max usage limit |
| used | int | Current usage count |
| remaining | int | Computed: `max(0, max_uses - used)` |
| is_expired | bool | Computed: `true` if `expires_at` is in the past |
| assigned_at | string/null | ISO 8601 datetime of assignment creation |
| expires_at | string/null | ISO 8601 datetime of expiry, or null if no expiry |

---

## Database Schema

**Table:** `coupon_assignments`

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| id | bigint unsigned | auto | Primary key |
| coupon_id | bigint unsigned | — | FK to coupons.id |
| user_id | bigint unsigned | — | FK to users.id |
| max_uses | int unsigned | — | Max usage limit |
| used | int unsigned | 0 | Current usage count |
| assigned_at | timestamp | current | When assignment was created |
| expires_at | timestamp | null | When assignment expires (null = never) |
| created_at | timestamp | current | Laravel timestamp |
| updated_at | timestamp | current | Laravel timestamp |

**Unique Constraint:** `(coupon_id, user_id)` — prevents duplicate assignments

**Relationships:**
- `CouponAssignment` belongsTo `Coupon`
- `CouponAssignment` belongsTo `User`

---

## Dependencies

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/CouponAssignmentController.php` |
| Repository | `packages/marvel/src/Database/Repositories/CouponAssignmentRepository.php` |
| Model | `packages/marvel/src/Database/Models/CouponAssignment.php` |
| Resource | `packages/marvel/src/Http/Resources/CouponAssignmentResource.php` |
| Request (Store) | `packages/marvel/src/Http/Requests/CouponAssignmentRequest.php` |
| Request (Update) | `packages/marvel/src/Http/Requests/UpdateCouponAssignmentRequest.php` |
| Permissions | `packages/marvel/src/Enums/Permission.php` (4 constants) |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 720-726) |
| Config | `packages/marvel/config/constants.php` (7 constants) |
| Translations (EN) | `resources/lang/en/message.php` (7 keys) |
| Translations (AR) | `resources/lang/ar/message.php` (7 keys) |
