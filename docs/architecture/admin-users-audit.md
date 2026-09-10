# Admin Users Feature — Production Audit Report

**Audit Date:** 2026-07-16
**Verdict:** ❌ PRODUCTION BLOCKER — 3 Critical Vulnerabilities Found
**Score:** 4.3/10

---

## Executive Summary

The Admin Users feature has **3 CRITICAL security vulnerabilities** that require immediate remediation before production deployment. The most severe is a **method name mismatch** that causes the `create-user` permission middleware to silently never apply to `POST /admin-users/add`, allowing any authenticated user to create admin users.

Additionally, `destroy()` (DELETE /users/{id}) and `addPoints()` (POST /add-points) have **zero authorization** — no middleware and no inline checks.

---

## Finding 1: [CRITICAL] Method Name Mismatch — `adminCreateUsers` vs `adminAddUsers`

**Severity:** CRITICAL — Authentication Bypass
**File:** `packages/marvel/src/Http/Controllers/UserController.php`
**Lines:** 83 (middleware registration), 454 (method declaration)

### The Bug

The constructor middleware registers `CREATE_USER` permission for method `adminCreateUsers`:

```php
// Line 83
$this->middleware("permission:" . Permission::CREATE_USER, ["only" => ["adminCreateUsers"]]);
```

But the actual method is named `adminAddUsers`:

```php
// Line 454
public function adminAddUsers(AdminCreateUserRequest $request)
```

### Impact

The `CREATE_USER` permission middleware **never fires**. Any authenticated user (even a customer) can call `POST /admin-users/add` and create a new admin user with any role, including `super_admin`. This is a complete authorization bypass.

### Why It Passes Tests

The test file at `tests/Feature/UserControllerTest.php` line 777 tests that a regular user gets 403 on `POST /admin-users/add`:

```php
$this->postJson(self::PREFIX . '/admin-users/add', [])->assertStatus(403);
```

This test passes because the regular user in the test (`$this->createRegularUser()`) has **no permissions at all**, so the Spatie permission middleware throws an `Illuminate\Auth\Access\AuthorizationException` which Laravel converts to 403. However, this is testing **Spatie's own middleware logic**, not the application's controller middleware. The middleware registration is broken — it's checking a method name that doesn't exist, so the `permission:create-user` middleware is never actually applied to any route.

If a user has ANY permission (e.g., `view-products`), the Spatie `permission` middleware would let them through because it's not even registered for the `adminAddUsers` method.

### Fix

Rename `adminCreateUsers` to `adminAddUsers` in the constructor middleware:

```php
// Line 83 — Change this:
$this->middleware("permission:" . Permission::CREATE_USER, ["only" => ["adminCreateUsers"]]);
// To this:
$this->middleware("permission:" . Permission::CREATE_USER, ["only" => ["adminAddUsers"]]);
```

---

## Finding 2: [CRITICAL] No Authorization on `destroy()` Method

**Severity:** CRITICAL — Unauthorized User Deletion
**File:** `packages/marvel/src/Http/Controllers/UserController.php`
**Lines:** 82-86 (middleware registration), 402-411 (method)

### The Bug

The `destroy()` method has **no middleware** and **no inline permission check**:

```php
// Line 402-411
public function destroy($id)
{
    try {
        $user = $this->repository->findOrFail($id);
        $user->delete();
        return $this->apiResponse(USER_DELETED_SUCCESSFULLY, 200, true);
    } catch (MarvelException $e) {
        throw new MarvelException(NOT_FOUND);
    }
}
```

The middleware only checks DELETE_USER for `adminDeleteUsers` and `adminDeleteUsersForever`, but NOT for the `destroy` method which is mapped by `Route::apiResource('users', UserController::class)` at `Routes.php:688`.

### Impact

Any authenticated user can call `DELETE /users/{id}` and delete any user. This is a full authorization bypass. The route is registered at `Routes.php:688` as part of the `apiResource` but no middleware protects it.

---

## Finding 3: [CRITICAL] No Authorization on `addPoints()` Method

**Severity:** CRITICAL — Unauthorized Point Manipulation
**File:** `packages/marvel/src/Http/Controllers/UserController.php`
**Lines:** 1256-1270

### The Bug

The `addPoints()` method has **no middleware** and **no inline permission check**:

```php
// Line 1256-1270
public function addPoints(Request $request)
{
    $request->validate([
        'points' => 'required|numeric',
        'customer_id' => ['required', 'exists:Marvel\Database\Models\User,id']
    ]);
    $points = $request->points;
    $customer_id = $request->customer_id;

    $wallet = Wallet::firstOrCreate(['customer_id' => $customer_id]);
    $wallet->total_points = $wallet->total_points + $points;
    $wallet->available_points = $wallet->available_points + $points;
    $wallet->save();
    return $this->apiResponse(POINTS_ADDED_SUCCESSFULLY, 200, true);
}
```

A `Permission::ADD_POINTS` constant exists at `Permission.php:215` but is never used in middleware or inline checks.

### Impact

Any authenticated user can arbitrarily add points to any customer's wallet. This is a full authorization bypass.

### Testing Gap

There are **zero tests** for the `addPoints` endpoint in `UserControllerTest.php` (1572 lines).

---

## Finding 4: [HIGH] Body-Level Authorization Instead of Controller Middleware

**Severity:** HIGH — Architecture Violation
**File:** `packages/marvel/src/Http/Controllers/UserController.php`

The following methods use inline `$user->hasPermissionTo()` checks inside the method body instead of controller middleware, violating the project constitution:

| Method | Line | Permission Check | Code |
|--------|------|-----------------|------|
| `banUser()` | 789 | `$user->hasPermissionTo(Permission::BAN_USER)` | Body-level |
| `activeUser()` | 816 | `$user->hasPermissionTo(Permission::ACTIVATE_USER)` | Body-level |
| `makeOrRevokeAdmin()` | 1296 | `$this->repository->hasPermission($user)` which checks `Permission::SUPER_ADMIN` | Body-level |
| `update()` | 372 | `$user->hasPermissionTo(Permission::SUPER_ADMIN)` (redundant — EDIT_USER is in middleware) | Body-level (redundant) |

While these methods ARE protected (the authorization works), they violate the project constitution rule that "Controller Permission Middleware is the ONLY authorization source of truth." The preferred approach is to register these checks in the constructor middleware.

### Impact

Medium from a security perspective (the checks do work), but High from an architecture/maintainability perspective. These permissions should be in the constructor middleware for consistency.

---

## Finding 5: [HIGH] Dead Routes Pointing to Commented-Out Methods

**Severity:** HIGH — Runtime Errors
**File:** `packages/marvel/src/Rest/Routes.php`, `packages/marvel/src/Http/Controllers/UserController.php`

| Route | File:Line | Target Method | Method Status |
|-------|-----------|---------------|---------------|
| `GET /vendors/list` | Routes.php:610 | `UserController::vendors` | Commented out (line 160-168) |
| `GET /customers/list` | Routes.php:739 | `UserController::customers` | Commented out (line 199-210) |

Calling either of these routes will result in a **Fatal Error** because the methods are fully commented out. The `vendors` route is inside the admin group (authenticated + verified), while `customers/list` is also inside the admin group.

### Impact

Any authenticated admin user calling these routes will receive a 500 error from an unhandled exception (method not found). These should either be uncommented or the routes should be removed.

---

## Finding 6: [MEDIUM] Dead Middleware References

**Severity:** MEDIUM — Maintainability
**File:** `packages/marvel/src/Http/Controllers/UserController.php`, Line 82

The middleware registration references two methods that either don't exist or have no route:

```php
$this->middleware("permission:" . Permission::VIEW_USERS, ["only" => ["index", "show", "admins", "adminTrashedUsers"]]);
```

- `admins` — The method is fully commented out (lines 132-144), no route exists
- `adminTrashedUsers` — The method EXISTS (lines 503-514) but NO route maps to it; it's dead code

This is not a security issue (the middleware simply never fires for these), but it's misleading and should be cleaned up.

---

## Finding 7: [MEDIUM] `store()` Has No Authorization

**Severity:** MEDIUM
**File:** `packages/marvel/src/Http/Controllers/UserController.php`, Lines 304-312

The `store()` method (mapped by `Route::apiResource('users', UserController::class)` → `POST /users`) has no middleware and no inline permission check. Unlike `adminAddUsers` which creates admin users with roles, `store()` uses `UserCreateRequest` and `UserRepository::storeUser()` which just creates a basic user record.

### Impact

Any authenticated user can create a basic user via `POST /users`. The created user gets default `type = null` and no roles. However, since registration (`POST /register`) is publicly available, this is a lower severity issue — it just creates users without email verification.

---

## Finding 8: [LOW] Documentation Inaccuracies

**File:** `docs/cms-endpoints/admin-users.md`

| Claim in Docs | Reality | Line |
|---------------|---------|------|
| "Route group: permission:super_admin" (appears multiple times) | The role:super_admin middleware at Routes.php:629 is **commented out** | Routes.php:629 |
| "Constructor middleware: permission:create-user on adminAddUsers" | Middleware is registered for method `adminCreateUsers` (which doesn't exist), NOT `adminAddUsers` | Controller.php:83, 454 |
| "GET /admin/list — List admin users" | No route `/admin/list` exists. The method `admins()` is fully commented out | Routes.php (searched), Controller.php:132-144 |
| "Soft delete is NOT implemented" | True, BUT the test migration at UserControllerTest.php:68 includes `$table->softDeletes()` and the Marvel User model uses `SoftDeletes` trait | User.php (Marvel), Test:68 |
| Five endpoints documented as "Not planned" (restore, force-delete, trashed) | These endpoints actually exist: `adminRestoreUser` (line 490), `adminDeleteUsersForever` (line 477), and `adminTrashedUsers` (line 503) — although `adminTrashedUsers` has no route | Controller.php |

---

## Summary of Required Actions

| Priority | Action | Type | File |
|----------|--------|------|------|
| 🔴 CRITICAL | Fix method name `adminCreateUsers` → `adminAddUsers` in constructor middleware | Security fix | Controller.php:83 |
| 🔴 CRITICAL | Add `DELETE_USER` middleware to `destroy` method, or add inline permission check | Security fix | Controller.php:402 |
| 🔴 CRITICAL | Add `ADD_POINTS` middleware or inline permission check to `addPoints` | Security fix | Controller.php:1256 |
| 🟠 HIGH | Move `banUser`, `activeUser`, `makeOrRevokeAdmin` permission checks to constructor middleware | Architecture fix | Controller.php:82-86 |
| 🟠 HIGH | Remove or fix dead routes for `/vendors/list` and `/customers/list` | Bug fix | Routes.php:610, 739 |
| 🟡 MEDIUM | Remove dead middleware references (`admins`, `adminTrashedUsers`) | Cleanup | Controller.php:82 |
| 🟡 MEDIUM | Add authorization to `store()` method | Security hardening | Controller.php:304 |
| 🟢 LOW | Update documentation for accuracy | Docs fix | admin-users.md |

## Test Coverage Gaps

| Endpoint | Auth Test | Success Test | Edge Case Tests |
|----------|-----------|-------------|-----------------|
| `POST /admin-users/add` | ✅ | ✅ | ✅ |
| `PUT /admin-users/update-activation` | ✅ | ✅ | ✅ |
| `DELETE /admin-users/delete/{id}` | ✅ | ✅ | ✅ |
| `DELETE /admin-users/delete-forever/{id}` | ❌ | ❌ | ❌ |
| `PUT /admin-users/restore/{id}` | ❌ | ❌ | ❌ |
| `GET /admin-users/trashed` (no route) | ❌ | ❌ | ❌ |
| `POST /users/make-admin` | ✅ | ✅ | ✅ |
| `POST /users/block-user` | ✅ | ✅ | ✅ |
| `POST /users/unblock-user` | ✅ | ✅ | ✅ |
| `POST /add-points` | ❌ | ❌ | ❌ |
| `DELETE /users/{id}` (destroy) | ❌ | ❌ | ❌ |
| `POST /users` (store) | ❌ | ❌ | ❌ |
| `GET /vendors/list` (dead route) | ❌ | ❌ | ❌ |
| `GET /customers/list` (dead route) | ❌ | ❌ | ❌ |

---

## Decision: Fix or Defer

**Must Fix Before Production:**
1. Finding 1 — Method name mismatch (CRITICAL)
2. Finding 2 — destroy() authorization (CRITICAL)
3. Finding 3 — addPoints() authorization (CRITICAL)

**Fix Before Next Sprint:**
4. Finding 4 — Body-level authorization → middleware
5. Finding 5 — Dead routes
6. Finding 7 — store() authorization

**Fix When Convenient:**
7. Finding 6 — Dead middleware references
8. Finding 8 — Documentation inaccuracies
9. Test coverage for missing endpoints
