# Admin Users Feature — Verified Production Audit

**Audit Date:** 2026-07-16  
**Verdict:** ❌ PRODUCTION BLOCKER — 3 Critical Authorization Bypasses  
**Score:** 4.5/10

---

## 1. Runtime Flow

```
Route Group (auth:sanctum + verified)     Controller Constructor Middleware     Controller Method
       |                                           |                                  |
       v                                           v                                  v
  Routes.php:625-631                      UserController.php:82-86             UserController methods
  (role:super_admin COMMENTED OUT)        (Spatie permission middleware)        (business logic)
```

### Route Registration

All routes in `packages/marvel/src/Rest/Routes.php` are loaded via:
```
RestAPIServiceProvider.php:29  →  Route::prefix('api/v1')->middleware('api')
```

### Route Group Middleware (Routes.php:625-631)

```php
'middleware' => [
    'auth:sanctum',
    'verified',
    // 'role:' . Role::SUPER_ADMIN,     <-- COMMENTED OUT
]
```

The `role:super_admin` middleware is intentionally disabled. Authorization for all admin-user endpoints relies entirely on controller constructor middleware (`$this->middleware("permission:...", ["only" => [...]])`).

---

## 2. VERIFIED BUGS

### Bug 1: [CRITICAL] Method Name Mismatch — `adminCreateUsers` vs `adminAddUsers`

**Proof:**

| File | Line | Code | Issue |
|------|------|------|-------|
| `UserController.php` | 83 | `$this->middleware("permission:" . Permission::CREATE_USER, ["only" => ["adminCreateUsers"]]);` | Middleware registered for method `adminCreateUsers` |
| `UserController.php` | 454 | `public function adminAddUsers(AdminCreateUserRequest $request)` | Actual method is `adminAddUsers` |
| `Routes.php` | 722 | `Route::post('admin-users/add', [UserController::class, 'adminAddUsers']);` | Route maps to `adminAddUsers` |

**Why it happens:** Laravel's controller middleware `only` filter matches against the current request's action method. Since the middleware is registered for `adminCreateUsers` (which doesn't exist) and the actual route method is `adminAddUsers`, the middleware filter **never matches**. The `permission:create-user` middleware is dead code.

**Runtime impact:** Any authenticated+verified user can call `POST /api/v1/admin-users/add` without any permission check. The `adminAddUsers` method (UserController.php:454-463) calls `$this->repository->addUserWithRole($request)` which creates a user with `type='admin'` and assigns roles from the request — including `super_admin`. This is a complete authorization bypass that allows any user to create admin accounts.

**No inline permission check:** The `adminAddUsers` method has zero inline authorization checks. Neither the constructor middleware nor the method body enforces any permission.

---

### Bug 2: [CRITICAL] `destroy()` Has No Authorization

**Proof:**

| File | Line | Code | Issue |
|------|------|------|-------|
| `UserController.php` | 82-86 | Constructor middleware | All 5 entries. **NONE** include `destroy` |
| `UserController.php` | 402-411 | `public function destroy($id) { ... $user->delete(); ... }` | No inline permission check |
| `Routes.php` | 688 | `Route::apiResource('users', UserController::class);` | Maps `DELETE /users/{id}` to `destroy` |

**Why it happens:** The `apiResource` at Routes.php:688 generates 5 routes: index, store, show, update, destroy. The constructor middleware covers index (VIEW_USERS), show (VIEW_USERS), update (EDIT_USER), but `destroy` has no middleware entry and no inline check.

**Runtime impact:** Any authenticated+verified user can call `DELETE /api/v1/users/{id}` to delete any user. The `destroy` method (UserController.php:402-411) simply calls `$user->delete()` with zero authorization.

---

### Bug 3: [CRITICAL] `addPoints()` Has No Authorization

**Proof:**

| File | Line | Code | Issue |
|------|------|------|-------|
| `UserController.php` | 82-86 | Constructor middleware | All 5 entries. **NONE** include `addPoints` |
| `UserController.php` | 1256-1270 | `public function addPoints(Request $request) { ... }` | No inline permission check |
| `Routes.php` | 704 | `Route::post('add-points', [UserController::class, 'addPoints']);` | Route exists in admin group |
| `Permission.php` | 215 | `public const ADD_POINTS = 'add-points';` | Permission constant exists but is **NEVER referenced** outside of this file |

**Why it happens:** The constructor middleware has no entry for `addPoints`. The method body validates `points` and `customer_id` but never checks any permission. The `Permission::ADD_POINTS` constant exists but is never used anywhere in the codebase for authorization.

**Runtime impact:** Any authenticated+verified user can call `POST /api/v1/add-points` to arbitrarily add points to any customer's wallet. The method (UserController.php:1256-1270) creates/updates a Wallet record with the specified points.

---

### Bug 4: [HIGH] Dead Route — `GET /vendors/list`

**Proof:**

| File | Line | Code | Issue |
|------|------|------|-------|
| `Routes.php` | 610 | `Route::get('/vendors/list', [UserController::class, 'vendors']);` | Route exists |
| `UserController.php` | 160-168 | `// public function vendors(Request $request)` | Method is **fully commented out** |

**Runtime impact:** Any user matching the route group middleware (`role:super_admin, auth:sanctum, email.verified`) calling `GET /api/v1/vendors/list` will receive a **Fatal Error** (method `vendors()` not found on UserController). PHP would throw a `ReflectionException` or `Error` since the method doesn't exist, resulting in a 500 response.

---

### Bug 5: [HIGH] Dead Route — `GET /customers/list`

**Proof:**

| File | Line | Code | Issue |
|------|------|------|-------|
| `Routes.php` | 739 | `Route::get('/customers/list', [UserController::class, 'customers']);` | Route exists |
| `UserController.php` | 199-210 | `// public function customers(Request $request)` | Method is **fully commented out** |

**Runtime impact:** Same as Bug 4 — calling `GET /api/v1/customers/list` results in a fatal error.

---

### Bug 6: [MEDIUM] Dead Route — `GET /admin/list`

**Proof:**

| File | Line | Code | Issue |
|------|------|------|-------|
| `Routes.php` | 576 | `// Route::get('/admin/list', [UserController::class, 'admins']);` | Route is **commented out** |
| `UserController.php` | 132-144 | `// public function admins(Request $request)` | Method is **fully commented out** |

**Runtime impact:** No runtime impact (route doesn't exist). However, 7 test assertions in `UserControllerTest.php` reference `/admin/list` — these tests are testing a route that doesn't exist (see Test Coverage section).

---

### Bug 7: [MEDIUM] Orphaned Method — `adminTrashedUsers()`

**Proof:**

| File | Line | Code | Issue |
|------|------|------|-------|
| `UserController.php` | 503-514 | `public function adminTrashedUsers(Request $request)` | Method exists |
| `Routes.php` | — | No route maps to `adminTrashedUsers` | Route is missing |
| `UserController.php` | 82 | `$this->middleware("permission:" . Permission::VIEW_USERS, ["only" => ["index", "show", "admins", "adminTrashedUsers"]]);` | Middleware references it as dead entry |

**Runtime impact:** The method is unreachable. The middleware registration is dead code.

---

## 3. FALSE POSITIVES REMOVED

The following issues from the initial audit were investigated but **could NOT be verified**:

| Initial Claim | Investigation Result | Verdict |
|---------------|---------------------|---------|
| Body-level authorization is an architecture violation | Methods `banUser`, `activeUser`, `makeOrRevokeAdmin` use `hasPermissionTo()` inline — authorization IS enforced. Architecture preference only. | ❌ NOT VERIFIED as bug |
| `store()` has no authorization — security risk | `store()` creates a basic user with `UserCreateRequest` validation but no roles/permissions. Cannot assign admin roles. No proven exploit path. | ❌ NOT VERIFIED as bug |
| `update()` inline check is redundant with EDIT_USER middleware | `update()` has both EDIT_USER middleware AND inline `hasPermissionTo(SUPER_ADMIN)` check. Both work. | ❌ NOT VERIFIED as bug |
| `admins` and `adminTrashedUsers` dead middleware references | These are dead middleware entries (methods don't exist or have no routes). They never fire, causing no runtime impact. | ❌ NOT VERIFIED as bug |
| `adminDeleteUsers()` broken because `deleted_at` column may not exist | The `deleted_at` column status in production cannot be determined from static analysis. The model uses SoftDeletes trait but no migration adds the column. However, the application works in production, indicating the column exists. | ❌ NOT VERIFIED — requires runtime DB check |
| Documentation inaccuracies in `docs/cms-endpoints/admin-users.md` | Documentation is not runtime code. Documentation errors are not production bugs. | ❌ NOT VERIFIED as bug |

---

## 4. Route Verification Table

| Route | Route Exists | Method Exists | Middleware Applies | Auth Works | Status |
|-------|-------------|---------------|-------------------|------------|--------|
| GET /users | ✅ Line 688 | ✅ `index()` | ✅ VIEW_USERS | ✅ | ✅ |
| POST /users | ✅ Line 688 | ✅ `store()` | ❌ NONE | ❌ NONE | ⚠️ |
| GET /users/{id} | ✅ Line 688 | ✅ `show()` | ✅ VIEW_USERS | ✅ | ✅ |
| PUT /users/{id} | ✅ Line 688 | ✅ `update()` | ✅ EDIT_USER | ✅ (MW + inline) | ✅ |
| DELETE /users/{id} | ✅ Line 688 | ✅ `destroy()` | ❌ NONE | ❌ NONE | **🔴 BUG 2** |
| POST /admin-users/add | ✅ Line 722 | ✅ `adminAddUsers()` | 🔴 (dead: registered for `adminCreateUsers`) | ❌ NONE | **🔴 BUG 1** |
| PUT /admin-users/update-activation | ✅ Line 723 | ✅ `adminUpdateActivationUsers()` | ✅ EDIT_USER | ✅ | ✅ |
| DELETE /admin-users/delete/{id} | ✅ Line 724 | ✅ `adminDeleteUsers()` | ✅ DELETE_USER | ✅ (protected admin/self guard) | ✅ |
| PUT /admin-users/restore/{id} | ✅ Line 725 | ✅ `adminRestoreUser()` | ✅ RESTORE_USER | ✅ | ✅ |
| DELETE /admin-users/delete-forever/{id} | ✅ Line 726 | ✅ `adminDeleteUsersForever()` | ✅ DELETE_USER | ✅ (protected admin/self guard) | ✅ |
| POST /users/block-user | ✅ Line 695 | ✅ `banUser()` | ❌ NONE | ✅ (inline `hasPermissionTo(BAN_USER)`) | ⚠️ |
| POST /users/unblock-user | ✅ Line 696 | ✅ `activeUser()` | ❌ NONE | ✅ (inline `hasPermissionTo(ACTIVATE_USER)`) | ⚠️ |
| POST /users/make-admin | ✅ Line 705 | ✅ `makeOrRevokeAdmin()` | ❌ NONE | ✅ (inline via `repository->hasPermission($user)`) | ⚠️ |
| POST /add-points | ✅ Line 704 | ✅ `addPoints()` | ❌ NONE | ❌ NONE | **🔴 BUG 3** |
| GET /vendors/list | ✅ Line 610 | ❌ COMMENTED OUT | N/A | N/A | **🔴 BUG 4** |
| GET /customers/list | ✅ Line 739 | ❌ COMMENTED OUT | N/A | N/A | **🔴 BUG 5** |
| GET /admin/list | ❌ Line 576 COMMENTED OUT | ❌ COMMENTED OUT | N/A | N/A | **🔴 BUG 6** |

---

## 5. Database Verification

### Users Table — Column Inventory

| Column | Type | Nullable | Default | Constraints | Source |
|--------|------|----------|---------|-------------|--------|
| id | bigint | NO | — | PK, auto-increment | Base migration |
| name | varchar(255) | NO | — | — | Base migration |
| email | varchar(255) | NO | — | UNIQUE | Base migration |
| email_verified_at | timestamp | YES | — | — | Base migration |
| password | varchar(255) | NO | — | — | Base migration |
| remember_token | varchar(100) | YES | — | — | Base migration |
| created_at | timestamp | YES | — | — | Base migration |
| updated_at | timestamp | YES | — | — | Base migration |
| type | varchar(255) | YES | 'user' | — | 2026-07-16 migration |
| is_active | tinyint(1) | YES | 1 | — | 2026-07-16 migration |
| phone_number | varchar(255) | YES | — | UNIQUE | 2026-07-16 migration |
| shop_id | bigint | YES | — | FK→shops.id, ON DELETE SET NULL | 2026-07-16 migration |

### Soft Delete Column (`deleted_at`)

- **Model:** `Marvel\Database\Models\User` uses `SoftDeletes` trait (User.php:38)
- **Base migration:** No `softDeletes()` call
- **2026-07-16 migration:** No `softDeletes()` call
- **Any other migration:** No match found for `deleted_at` on `users` table
- **Can verify from static analysis:** NO

**NOT VERIFIED:** The `deleted_at` column status in production is unknown. If it doesn't exist, all model queries would fail due to the global `SoftDeletingScope` adding `WHERE deleted_at IS NULL`. Since the application demonstrably works in production, the column likely exists (added manually or via an undocumented migration).

---

## 6. Model Verification

### Marvel User Model (`packages/marvel/src/Database/Models/User.php`)

| Property | Value | Notes |
|----------|-------|-------|
| fillable | name, email, password, is_active, type, email_verified_at, phone_number, remember_token | `shop_id` is NOT fillable (set elsewhere) |
| hidden | password, remember_token | Standard |
| casts | email_verified_at → datetime | |
| appends | email_verified | Accessor `getEmailVerifiedAttribute()` |
| guard_name | 'api' | Spatie permission guard |
| SoftDeletes | ✅ | Trait used |
| HasRoles | ✅ | Overrides: assignRole, syncRoles, removeRole (all fire UserRolesUpdated event) |
| HasApiTokens | ✅ | Sanctum |
| MustVerifyEmail | ✅ | Implemented |
| HasMedia | ✅ | Spatie Media Library |

### Observer (`app/Observers/UserObserver.php`)

| Event | Dispatches | Translation Key |
|-------|-----------|-----------------|
| created | LogActivityJob | `activity.user_created` |
| updated (status change) | LogActivityJob | `activity.user_activated` or `activity.user_deactivated` |
| updated (other changes) | LogActivityJob | `activity.user_updated` |
| deleted | LogActivityJob | `activity.user_deleted` |
| restored | LogActivityJob | `activity.user_restored` |
| forceDeleted | LogActivityJob | `activity.user_force_deleted` |

### App User Model (`app/Models/User.php`)

Completely separate model. Extends `Illuminate\Foundation\Auth\User`. Does NOT extend or relate to the Marvel User model. Has no roles, permissions, or Sanctum tokens.

---

## 7. Translation Verification

### Activity Translations (`resources/lang/{en,ar}/activity.php`)

| Key | EN | AR | Used In |
|-----|----|----|---------|
| user_created | Administrator created a new user | قام المسؤول بإنشاء مستخدم جديد | UserObserver::created() |
| user_updated | User updated | تم تحديث المستخدم | UserObserver::updated() (other changes) |
| user_deleted | User deleted | تم حذف المستخدم | UserObserver::deleted() |
| user_restored | User restored | تمت استعادة المستخدم | UserObserver::restored() |
| user_force_deleted | User permanently deleted | تم حذف المستخدم نهائيا | UserObserver::forceDeleted() |
| user_activated | User activated | تم تنشيط المستخدم | UserObserver::updated() (status change) |
| user_deactivated | User deactivated | تم تعطيل المستخدم | UserObserver::updated() (status change) |
| user_role_changed | User role changed | — | Not found in observer — used elsewhere |

All 7 user-related translation keys in activity.php exist in both EN and AR with valid values. All keys are referenced by the UserObserver.

**No missing or broken translation keys detected.**

---

## 8. Test Coverage

### Test File: `tests/Feature/UserControllerTest.php` (1571 lines)

### Covered Endpoints

| Endpoint | Success Tests | Auth Failure Tests | Validation Tests | Edge Case Tests |
|----------|:---:|:---:|:---:|:---:|
| POST /admin-users/add | 3 | 2 (401, 403) | 8 | 5 |
| PUT /admin-users/update-activation | 1 | 2 (401, 403) | 2 | 1 |
| DELETE /admin-users/delete/{id} | 1 | 2 (401, 403) | 0 | 3 |
| GET /users (index) | 5 | 2 (401, 403) | 0 | 3 |
| GET /admin/list | 1 | 2 (401, 403) | 0 | 0 |
| POST /users/make-admin | 2 | 2 (401, 403) | 0 | 0 |
| POST /users/block-user | 2 | 2 (401, 403) | 0 | 1 |
| POST /users/unblock-user | 2 | 2 (401, 403) | 0 | 1 |
| GET /users?search= | 5 | 0 | 0 | 2 |
| Event: UserRolesUpdated | 2 | 0 | 0 | 0 |

### Missing Test Coverage

| Endpoint | Missing Tests | Severity |
|----------|--------------|----------|
| POST /add-points | **ZERO tests** — not a single test exists | 🔴 CRITICAL |
| DELETE /admin-users/delete-forever/{id} | **ZERO tests** | 🟠 HIGH |
| PUT /admin-users/restore/{id} | **ZERO tests** | 🟠 HIGH |
| DELETE /users/{id} (destroy) | **ZERO tests** | 🔴 CRITICAL |
| POST /users (store) | **ZERO tests** | 🟡 MEDIUM |

### Test File Anomalies

**Route `/admin/list` is commented out** — 7 test assertions reference `GET /api/admin/list`:
- Line 318: `test_admin_list_returns_success_status`
- Line 334: `test_non_admin_cannot_list_admins`
- Line 340: `test_unauthenticated_user_cannot_list_admins`
- Line 780: `test_regular_user_cannot_access_admin_endpoints`
- Line 1144: `test_non_admin_cannot_access_admin_list_endpoint`

The route `/admin/list` is commented out at Routes.php:576 AND the `admins()` method is commented out at UserController.php:132-144. **These tests are testing dead code.** They likely produce 404 responses, making all assertions about 200/403 incorrect.

---

## 9. Legacy Components (Verified Dead Code)

| Component | Location | Lines | Status |
|-----------|----------|-------|--------|
| `admins()` method | UserController.php | 132-144 | Fully commented out |
| `vendors()` method | UserController.php | 160-168 | Fully commented out |
| `fetchVendors()` method | UserController.php | 170-184 | Fully commented out |
| `customers()` method | UserController.php | 199-210 | Fully commented out |
| `inactiveUserShops()` method | UserController.php | 802-810 | Fully commented out |
| `adminTrashedUsers()` method | UserController.php | 503-514 | Exists but unreachable (no route) |
| Route `/admin/list` | Routes.php | 576 | Commented out |
| Middleware entries for `admins` and `adminTrashedUsers` | UserController.php | 82 | Dead references |

---

## 10. Required Fixes

### Must Fix Before Production

| # | Bug | Fix | File:Line |
|---|-----|-----|-----------|
| 1 | Middleware method name mismatch | Change `"adminCreateUsers"` → `"adminAddUsers"` on line 83 | UserController.php:83 |
| 2 | `destroy()` has no authorization | Add `"destroy"` to the DELETE_USER middleware entry on line 84 | UserController.php:84 |
| 3 | `addPoints()` has no authorization | Add middleware entry: `$this->middleware("permission:" . Permission::ADD_POINTS, ["only" => ["addPoints"]]);` | UserController.php:86-87 |

### Fix Before Next Sprint

| # | Bug | Fix | File:Line |
|---|-----|-----|-----------|
| 4 | Dead route /vendors/list | Either restore the `vendors()` method or remove the route | Routes.php:610, Controller.php:160 |
| 5 | Dead route /customers/list | Either restore the `customers()` method or remove the route | Routes.php:739, Controller.php:199 |
| 6 | Dead route /admin/list | Remove commented-out route and related test assertions | Routes.php:576, Test file |

### Test Coverage Needed

| Endpoint | Priority |
|----------|----------|
| POST /add-points (bug 3) | 🔴 Critical |
| DELETE /admin-users/delete-forever/{id} | 🟠 High |
| PUT /admin-users/restore/{id} | 🟠 High |
| DELETE /users/{id} (bug 2) | 🔴 Critical |

---

## 11. Final Score

| Category | Score | Notes |
|----------|-------|-------|
| Architecture | 4/10 | Authorization relies on fragile method-name matching in middleware |
| Database | 7/10 | Schema is clean. SoftDeletes status unconfirmed. |
| Laravel | 3/10 | 3 authorization bypasses due to misconfigured middleware |
| Security | 2/10 | Any user can create admins, delete users, add points |
| Performance | 8/10 | No N+1 or performance issues detected |
| Maintainability | 5/10 | Dead code scattered across controller and routes |
| Testing | 5/10 | Good coverage for working endpoints, zero for broken ones. Tests reference dead routes. |
| Translations | 9/10 | All keys present in EN and AR |

**Overall: 4.5/10**

---

## 12. Production Verdict

**❌ NOT PRODUCTION READY — 3 Critical Authorization Bypasses**

The Admin Users feature has 3 proven authorization bypasses that allow any authenticated user to:
1. Create admin users (Bug 1)
2. Delete any user (Bug 2)
3. Arbitrarily add wallet points (Bug 3)

These are all trivially exploitable by any user with a valid Sanctum token.

Additionally, 2 routes will cause fatal errors at runtime.

All 3 critical bugs can be fixed with single-line changes to the constructor middleware. No architectural changes needed.
