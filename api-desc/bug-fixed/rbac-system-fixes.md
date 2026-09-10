# Bug Fixes: RBAC System (Role-Based Access Control)

**Date:** 2026-07-20

**Affected Endpoints:** Multiple role/permission/user management endpoints

---

## Bug 1: Permissions Endpoints Return 403

**Affected:** `GET /api/v1/permissions?limit=200`, `POST /api/v1/roles/{roleId}/permissions`

**Root Cause:** The controller used `$this->middleware('permission:super_admin')` which checks for a permission named `super_admin`. However, `super_admin` is a **role name**, not a permission name. The `super_admin` role was seeded but no permission named `super_admin` existed in the `permissions` table. Result: the middleware check always failed with 403.

**Fix:** Changed the middleware from `'permission:super_admin'` to `'role:super_admin'`. This checks the authenticated user's role instead of a non-existent permission.

**File Changed:** `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` (line 37)

---

## Bug 2: Create Role Stores `display_name` as `false`

**Affected:** `POST /api/v1/roles` with `display_name[en]=...&display_name[ar]=...`

**Root Cause:** The `Role` model uses `Spatie\Translatable\HasTranslations` trait for the `display_name` column. The original code used `Role::create([...])` with `'display_name' => $request->display_name` (array). The `HasTranslations` trait's `setAttribute` does handle arrays, but `create()` bypasses it — mass-assignment sets the raw array without translation processing, storing `false` instead of the proper JSON structure.

**Fix:** Replaced `Role::create([...])` with individual property assignment and `$role->setTranslations('display_name', $request->display_name)`. Also fixed `$role->update([...])` in `updateRole` the same way.

**File Changed:** `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` (lines 91-98, 133-138)

### Frontend Integration

```http
POST /api/v1/roles
Content-Type: application/json

{
  "display_name": {
    "en": "Role Name English",
    "ar": "اسم الدور بالعربية"
  }
}
```

The `display_name` must be sent as a JSON object with locale keys (`en`, `ar`), not as separate form fields.

---

## Bug 3: Roles List Response Missing Fields

**Affected:** `GET /api/v1/roles`

**Root Cause:** `RoleResource` only returned `id` and `display_name` (with raw JSON due to a route-name check that never matched). No `name`, `guard_name`, `created_at`, `updated_at`, `permissions`, or pagination metadata.

**Fix:**
- Added `name`, `guard_name`, `created_at`, `updated_at` to `RoleResource`
- Changed `display_name` to always return translated value for current locale
- Added `->with('permissions')` eager loading in `getAllRoles()`
- Added pagination metadata (`page`, `current_page`, `from`, `to`, `last_page`, `path`, `per_page`, `total`, `next_page_url`, `prev_page_url`, `last_page_url`, `first_page_url`)

**Files Changed:**
- `packages/marvel/src/Http/Resources/RoleResource.php` (lines 17-24)
- `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` (lines 50-71)

### Response Structure

```json
{
  "data": [
    {
      "id": 1,
      "name": "super_admin",
      "display_name": "Super Admin",
      "guard_name": "api",
      "created_at": "2026-07-17T12:00:00.000000Z",
      "updated_at": "2026-07-17T12:00:00.000000Z",
      "permissions": [
        { "id": 1, "label": "view-products" }
      ]
    }
  ],
  "page": 1,
  "per_page": 10,
  "total": 5,
  "last_page": 1
}
```

---

## Bug 4: User Detail Missing `roles` Array

**Affected:** `GET /api/v1/users/{id}`

**Root Cause:** The `show()` method only loaded `roles` and `permissions` for users where `type === 'admin'`. Regular users (including staff, store owners) had no roles loaded, so the `roles` field was always empty/null in the response.

**Fix:** Changed `$user->load(['roles', 'permissions'])` to execute for ALL user types unconditionally. The `address` relation is still loaded conditionally for `type === 'user'`.

**File Changed:** `packages/marvel/src/Http/Controllers/UserController.php` (line 236)

---

## Bug 5: Remove-Role Endpoint Returns 403

**Affected:** `POST /api/v1/users/{userId}/remove-role`

**Root Cause:** The role/permission management routes existed in BOTH the public section (no auth middleware) and the super admin section (with `auth:sanctum`). Laravel matched the public route first, which had no authentication. The controller's `permission:remove-role` middleware then failed because the user wasn't authenticated through the route.

**Fix:** Removed duplicate public role/permission routes. All role/permission endpoints now only exist in the super admin middleware group with `auth:sanctum` and `verified` middleware.

**File Changed:** `packages/marvel/src/Rest/Routes.php` (lines removed from public section)

---

## Bug 6: User Direct Permission Endpoints Return 403

**Affected:** `POST /api/v1/users/{userId}/permissions`, `PUT /api/v1/users/{userId}/permissions`, `DELETE /api/v1/users/{userId}/permissions`

**Root Cause:** Same as Bug 1 — these methods used `'permission:super_admin'` middleware which checked for a non-existent permission.

**Fix:** Same as Bug 1 — changed to `'role:super_admin'` middleware. Routes also moved to super admin section (Bug 5 fix).

**File Changed:** `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` (line 37)

---

## Bug 7: Delete Role Succeeds With Assigned Users

**Affected:** `DELETE /api/v1/roles/{id}`

**Root Cause:** The `destroyRole()` method had no check for assigned users before deleting the role. When a role had users assigned via `model_has_roles`, the DELETE succeeded silently, leaving orphaned records.

**Fix:** Added `$role->users()->count() > 0` check before deletion. If users are assigned, returns `409 Conflict` with `"Cannot delete role with assigned users"`.

**File Changed:** `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` (lines 154-157)

### Expected Behavior

```http
DELETE /api/v1/roles/{id}

# Role with no users → 200, deleted
# Role with users → 409, not deleted
```

---

## Bug 8: Login Response Missing `permissions`/`role`

**Affected:** `POST /api/v1/token`

**Root Cause:** The `token()` method only returned `token` and `email_verified` in the response. Frontend route guards need `permissions` and `role` to determine which routes the user can access.

**Fix:** Added `"permissions" => $user->getAllPermissions()->pluck('name')` and `"role" => $user->roles->pluck('name')` to the login response. Also removed incorrect `AdminLoggedIn` event dispatch from the regular user login (that event is only relevant for admin login).

**File Changed:** `packages/marvel/src/Http/Controllers/UserController.php` (lines 497-498)

### Response Structure

```json
{
  "token": "1|abc123...",
  "email_verified": true,
  "permissions": ["super_admin", "view-products", "create-roles"],
  "role": ["Super Admin"]
}
```

---

## Files Modified

| File | Changes |
|------|---------|
| `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` | Middleware `permission:super_admin` → `role:super_admin`; `setTranslations` for display_name; eager load permissions; pagination metadata; destroyRole users check |
| `packages/marvel/src/Http/Controllers/UserController.php` | Always load roles/permissions in `show()`; add permissions/role to token response |
| `packages/marvel/src/Http/Resources/RoleResource.php` | Added `name`, `guard_name`, `created_at`, `updated_at`; fixed `display_name` translation |
| `packages/marvel/src/Rest/Routes.php` | Removed duplicate public role/permission and user routes |
