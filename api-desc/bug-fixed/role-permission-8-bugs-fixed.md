# Bug Fix: Role & Permission API — 8 Production Bugs Fixed

**Date:** 2026-07-20

**Feature:** Role & Permission (RBAC)

**Revision:** 2

---

## Bugs Fixed

| # | Endpoint(s) | Issue | Root Cause | Fix |
|---|---|---|---|---|
| BUG-1 | `GET /permissions`, `POST /roles/{id}/permissions` | All return 403 Forbidden | Duplicate unauthenticated routes at `Routes.php:146–158` shadowed the authenticated routes inside the `super_admin` group; request matched unauthenticated route first so `auth:sanctum` was never applied | Removed all duplicate unauthenticated routes (lines 146–158) |
| BUG-2 | `POST /roles`, `PUT /roles/{id}` | `display_name` stored as boolean `false` | `Role` model uses Spatie's `HasTranslations` trait which intercepts mass-assignment on `display_name`; `Role::create([...])` and `$role->update([...])` silently convert the array to `false` | Changed to explicit property assignment (`$role->name = ...; $role->display_name = ...; $role->save()`) |
| BUG-3 | `GET /roles` | Response missing `name`, `guard_name`, `created_at`, `updated_at` | `RoleResource` only exposed `id` and `display_name` | Added all missing fields to `RoleResource::toArray()` |
| BUG-4 | `GET /users/{id}` | User detail missing `roles` | Same root cause as BUG-1: duplicate unauthenticated user routes at `Routes.php:136–138` shadowed the authenticated `apiResource('users')` | Removed duplicate user routes (lines 136–138) |
| BUG-5 | `POST /users/{id}/remove-role` | Always returns 403 | Same root cause as BUG-1 | Same fix — route now matches inside authenticated group |
| BUG-6 | `POST/PUT/DELETE /users/{id}/permissions` | All return 403 | Same root cause as BUG-1 | Same fix — routes now match inside authenticated group |
| BUG-7 | `DELETE /roles/{id}` | Succeeds even when users are assigned to the role | No check for assigned users before deletion; database cascade silently removed `model_has_roles` rows | Added `$role->users()->count() > 0` check before deletion, returning 409 `CANNOT_DELETE_ROLE_WITH_ASSIGNED_USERS` |
| BUG-8 | `POST /token` (customer login) | Response missing `permissions` and `role` arrays | `token()` method only returned `user` object without permission/role data; `adminToken()` already included these fields | Added `'permissions'` and `'role'` to response array in `token()` |

## Files Changed

| File | Change |
|---|---|
| `packages/marvel/src/Rest/Routes.php` | Removed duplicate unauthenticated role/permission routes (lines 136–138, 146–158) |
| `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` | `addRole()`/`updateRole()`: explicit property assignment; `destroyRole()`: users count check returning 409; `getAllRoles()`: flattened pagination metadata |
| `packages/marvel/src/Http/Resources/RoleResource.php` | Added `name`, `guard_name`, `created_at`, `updated_at` |
| `packages/marvel/src/Http/Controllers/UserController.php` | Added `permissions` + `role` to `token()` response |
| `tests/Feature/RoleAndPermissionTest.php` | Updated pagination assertion structure; updated cascade delete test to assert 409 conflict |

## Test Results

```
OK (32 tests, 159 assertions)
```

## Architecture Lesson

**Route ordering is critical in Laravel.** When duplicate URIs exist both outside and inside middleware groups, the first-defined route wins — regardless of middleware nesting. All permission/user endpoints were shadowed because their unauthenticated copies were registered before the authenticated `super_admin` group. Always define grouped routes BEFORE any catch-all or unauthenticated routes with the same URI.

## Production Deployment Note

After deploying, run:
```bash
php artisan route:clear
php artisan config:clear
```
