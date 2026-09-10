# Coupon Assignment — Frontend Integration Guide

## Overview

Coupon Assignment is an **admin-only** feature. All endpoints require `super_admin` role and specific permissions. The UI lives in the admin coupon edit page as a sub-section.

---

## Endpoints

### 1. GET /api/v1/coupons/{couponId}/assignments — List Assignments

**Purpose:** Show all users assigned to a coupon, with their usage and expiry.

**Authentication:** Required (Sanctum), role: `super_admin`
**Permission:** `view-coupon-assignments`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| limit | int | No (default 15) | Items per page |
| page | int | No (default 1) | Page number |

**Response:**
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
        "user": { "id": 2, "name": "Ahmed Ali", "email": "ahmed@example.com" },
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

**Error (404):**
```json
{ "status": 404, "message": "Not found", "success": false }
```

---

### 2. POST /api/v1/coupons/{couponId}/assignments — Create Assignment

**Purpose:** Assign a coupon to a user with usage limit and optional expiry.

**Authentication:** Required (Sanctum), role: `super_admin`
**Permission:** `create-coupon-assignment`

**Request:**
```json
{
  "user_id": 2,
  "max_uses": 5,
  "expires_at": "2026-08-25T10:00:00.000000Z"
}
```

**Response (201):**
```json
{
  "status": 201,
  "message": "Coupon assigned successfully.",
  "success": true,
  "data": { ... }
}
```

**Error (409 — duplicate):**
```json
{ "status": 409, "message": "This coupon is already assigned to the specified user.", "success": false }
```

---

### 3. GET /api/v1/coupons/{couponId}/assignments/{id} — Show Assignment

**Purpose:** View a single assignment with full details.

**Authentication:** Required (Sanctum), role: `super_admin`
**Permission:** `view-coupon-assignments`

**Response (200):** Single assignment object

---

### 4. PUT /api/v1/coupons/{couponId}/assignments/{id} — Update Assignment

**Purpose:** Edit max_uses or expires_at for an assignment.

**Authentication:** Required (Sanctum), role: `super_admin`
**Permission:** `update-coupon-assignment`

**Request (all optional):**
```json
{
  "max_uses": 10,
  "expires_at": null
}
```

**Response (200):** Updated assignment object

---

### 5. DELETE /api/v1/coupons/{couponId}/assignments/{id} — Delete Assignment

**Purpose:** Remove an assignment. Only allowed if `used = 0`.

**Authentication:** Required (Sanctum), role: `super_admin`
**Permission:** `delete-coupon-assignment`

**Response (200):**
```json
{ "status": 200, "message": "Assignment deleted successfully.", "success": true }
```

**Error (409 — has usage):**
```json
{ "status": 409, "message": "Cannot delete assignment with usage history.", "success": false }
```

---

## Frontend States

### Loading State
```jsx
// Table skeleton: 5 rows
<TableSkeleton rows={5} columns={['User', 'Max Uses', 'Used', 'Remaining', 'Expires', 'Actions']} />
```

### Empty State
- **No assignments:** `total = 0` — show "No users assigned yet. Click 'Add User' to assign."
- **No results:** After filtering/search — show "No matching assignments found."

### Error State
| HTTP | Scenario | UI Treatment |
|------|----------|--------------|
| 401 | Token expired | Redirect to login |
| 403 | Missing role/permission | Hide the entire section or show "Access denied" |
| 404 | Coupon not found | Show "Coupon not found" and disable the section |
| 409 | Duplicate | Inline error on user select: "User already assigned" |
| 409 | Delete with usage | Confirmation modal explaining why delete is blocked |
| 422 | Validation | Inline field-level errors on the form |
| 500 | Server error | Toast "Something went wrong. Please try again." |

---

## Key Considerations

1. **Role restriction:** All endpoints require `super_admin` role. The frontend should check user role before rendering the section
2. **Permission granularity:** 4 separate permissions — the UI should show/hide buttons based on the user's permissions (view, create, update, delete)
3. **User selector:** Create form needs a user search/select component. Use the existing admin user list endpoint
4. **`user_id` is immutable:** Cannot be changed after creation. Deleting and recreating is the only way to change the assigned user
5. **`used` is read-only:** Managed by the consumption flow (order/checkout). Cannot be modified via admin
6. **Computed fields:** `remaining` and `is_expired` are computed by the API. The frontend should use these values directly, not calculate them
7. **Blocked delete:** If `used > 0`, the delete button should either be disabled with a tooltip explaining why, or show a confirmation warning that deletion is not possible
8. **Expiry null:** `expires_at` can be set to `null` (no expiry). The UI should support clearing the date field
9. **Pagination:** List is paginated (default 15). Implement scroll or page controls
10. **Visibility is implicit:** Zero assignments = public coupon. The section should indicate this clearly
