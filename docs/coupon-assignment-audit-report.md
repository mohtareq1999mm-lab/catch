# Coupon Assignment Feature — Production Audit Report

**Date:** 2026-07-25  
**Auditor:** AI Principal Architect  
**Feature Scope:** Admin CRUD for per-user coupon assignments + checkout consumption flow

---

## Executive Summary

| Category | Score |
|----------|-------|
| **Architecture** | 8/10 |
| **Security** | 7/10 |
| **Database** | 9/10 |
| **Concurrency** | 6/10 |
| **Test Coverage** | 9/10 |
| **Production Readiness** | **7/10 — NOT READY** |

**Verdict: DO NOT MERGE** — 1 critical, 1 high, 2 medium, and 1 low issue must be resolved before production deployment.

---

## Issue Register

### 🔴 CRITICAL — 1 issue

#### C-1: TOCTOU Race Condition in `assignCoupon()`

| Field | Value |
|-------|-------|
| **File** | `packages/marvel/src/Database/Repositories/CouponAssignmentRepository.php:60` |
| **Method** | `assignCoupon()` |
| **Evidence** | Lines 64-70 check for duplicate outside the transaction. Lines 72-81 create inside transaction. Between the check and create, a concurrent request can insert the same `(coupon_id, user_id)`. |
| **Impact** | Two concurrent admin requests to assign the same coupon to the same user will:
1. Both pass the `exists()` check (line 64)
2. One succeeds with the `create()` (line 73)
3. The second hits the DB unique constraint violation → `QueryException` → 500 Internal Server Error

The user sees a 500 error instead of a clean 409 Conflict.

| **Recommendation** | Move the duplicate check INSIDE the transaction and use `lockForUpdate()` or `insertOrIgnore()` with explicit validation. |
| **Severity** | Critical |

---

### 🟠 HIGH — 1 issue

#### H-1: Broad Exception Handling in Controller Masks Errors

| Field | Value |
|-------|-------|
| **File** | `packages/marvel/src/Http/Controllers/CouponAssignmentController.php` |
| **Method** | `index()` (line 31), `store()` (line 50), `show()` (line 64), `update()` (line 76), `destroy()` (line 90) |
| **Evidence** | Every method wraps logic in `try { ... } catch (Exception $e) { return $this->apiResponse(NOT_FOUND, 404, false); }` or `SOMETHING_WENT_WRONG`. |
| **Impact** | Any unexpected error (DB connection failure, constraint violation, null reference) is silently swallowed. The index method always returns 404 even for genuinely unexpected errors. This makes debugging production issues nearly impossible. |
| **Recommendation** | Remove broad `catch (Exception $e)` blocks. Let Laravel's exception handler deal with unexpected errors. Only catch known exceptions (`ModelNotFoundException`, `MarvelBadRequestException`). Add a dedicated exception handler for `QueryException` (unique constraint) in the repository. |
| **Severity** | High |

---

### 🟡 MEDIUM — 2 issues

#### M-1: Routes Not Inside Admin Middleware Group

| Field | Value |
|-------|-------|
| **File** | `packages/marvel/src/Rest/Routes.php:240` |
| **Evidence** | Lines 240-246 register coupon assignment routes at the TOP LEVEL of routes.php. No `auth:sanctum` or `role:super_admin` middleware group wraps them. Compare with coupon routes at lines 723-725 which are inside `role:SUPER_ADMIN, auth:sanctum, email.verified`. |
| **Impact** | While Spatie's `permission:` middleware does trigger authentication, guest users get a 403 (Forbidden) instead of 401 (Unauthorized). Pattern inconsistency with the rest of the codebase. The `email.verified` middleware is also missing. |
| **Recommendation** | Move the coupon assignment routes inside the `SUPER_ADMIN` role group at line 696, alongside `coupons.store` and `coupons.destroy`. |
| **Severity** | Medium |

#### M-2: Index Method Does Not Validate Coupon Existence

| Field | Value |
|-------|-------|
| **File** | `packages/marvel/src/Http/Controllers/CouponAssignmentController.php:31` |
| **Method** | `index()` |
| **Evidence** | `listByCoupon()` simply filters by coupon_id. If couponId = 99999, it returns an empty paginated result instead of 404. |
| **Impact** | Users cannot distinguish between "coupon doesn't exist" and "coupon has no assignments." The store/show/update/destroy methods DO validate coupon existence via `findOrFail()` or `findAssignment()`. |
| **Recommendation** | Add a `Coupon::findOrFail($couponId)` check at the start of `index()` for consistency. |
| **Severity** | Medium |

---

### 🔵 LOW — 1 issue

#### L-1: Dead Code — Old App-Level Resource Not Referenced

| Field | Value |
|-------|-------|
| **File** | `app/Http/Resources/Coupons/CouponAssignmentResource.php` |
| **Evidence** | Zero `use` references across the entire codebase. The old resource lacks `remaining` and `is_expired` computed fields that the package-level resource provides. |
| **Impact** | None — it is dead code. But leaving it may confuse future developers. |
| **Recommendation** | Delete the file after confirming no references exist. |
| **Severity** | Low |

---

## 1. Architecture Review

### Duplicated Resources
- **FOUND:** `app/Http/Resources/Coupons/CouponAssignmentResource.php` (old, dead) vs `packages/marvel/src/Http/Resources/CouponAssignmentResource.php` (new, active)
- The old resource is **not imported anywhere** in the codebase (`grep` returned zero matches).
- Verified all CouponAssignment references use the Marvel package namespace.

### Duplicated Business Logic
- No duplication found. `CouponAssignmentValidator` (app/) handles consumption validation, `CouponAssignmentRepository` (marvel/) handles admin CRUD. Correct separation.

### Marvel ↔ App Separation
- **Marvel** (`packages/marvel/`): Models, Repository, Controller, Requests, Resource, Routes, Enums, Constants — correct.
- **App** (`app/`): `CouponAssignmentValidator`, `CouponOrchestrator`, `OrderService::recordCouponUsage()`, `AssignedCouponConsumed` event — correct.
- **Violation:** None. All marvel code is back-office admin CRUD. All business logic is in app/.

---

## 2. Database Audit

### Migration: `2026_07_15_000003_create_coupon_assignments_table`

| Column | Type | Nullable | Default | Index |
|--------|------|----------|---------|-------|
| id | bigint PK | No | — | PK |
| coupon_id | bigint FK | No | — | FK → coupons(cascadeOnDelete) |
| user_id | bigint FK | No | — | FK → users(cascadeOnDelete) |
| max_uses | unsigned int | No | 1 | — |
| used | unsigned int | No | 0 | — |
| assigned_at | timestamp | No | CURRENT_TIMESTAMP | — |
| expires_at | timestamp | Yes | NULL | — |
| created_at | timestamp | Yes | — | — |
| updated_at | timestamp | Yes | — | — |
| **UNIQUE(coupon_id, user_id)** | | | | ✅ Unique constraint |

### Migration: `2026_07_15_000004_create_coupon_assignment_usages`

| Column | Type | Nullable | Default | Index |
|--------|------|----------|---------|-------|
| id | bigint PK | No | — | PK |
| coupon_assignment_id | bigint FK | No | — | FK → coupon_assignments(cascadeOnDelete), Index |
| order_id | bigint FK | Yes | NULL | FK → orders(nullOnDelete) |
| used_at | timestamp | No | CURRENT_TIMESTAMP | — |
| created_at | timestamp | Yes | — | Index |
| updated_at | timestamp | Yes | — | — |
| **INDEX(coupon_assignment_id, created_at)** | | | | ✅ Composite index |

### Verdict: Database is well-designed. All necessary indexes, FKs, and constraints are in place. `nullOnDelete` on `order_id` is correct (preserves audit trail).

---

## 3. Route Audit

### Registered Routes

| Method | Path | Middleware |
|--------|------|-----------|
| GET | `/api/v1/coupons/{coupon}/assignments` | `permission:view-coupon-assignments` |
| POST | `/api/v1/coupons/{coupon}/assignments` | `permission:create-coupon-assignment` |
| GET | `/api/v1/coupons/{coupon}/assignments/{assignment}` | `permission:view-coupon-assignments` |
| PUT | `/api/v1/coupons/{coupon}/assignments/{assignment}` | `permission:update-coupon-assignment` |
| DELETE | `/api/v1/coupons/{coupon}/assignments/{assignment}` | `permission:delete-coupon-assignment` |

### Issues Found
- **No `auth:sanctum` middleware** at group level — relies on Spatie's permission middleware which triggers auth implicitly. This is inconsistent with the rest of the codebase.
- **Missing `email.verified` middleware** — other admin routes include this.
- **Route model binding not used** — uses manual ID casting `(int) $couponId` instead of implicit/explicit route model binding.

---

## 4. Security Audit

### Authorization
- ✅ 4 distinct permission enums for granular control
- ✅ Permission middleware on each controller method
- ⚠️ Missing `role:super_admin` group middleware (see M-1)

### IDOR Prevention
- ✅ Repository scopes ALL queries by `coupon_id`
- ✅ `findAssignment()` checks both `coupon_id` and `id`
- ✅ `removeAssignment()` uses `lockForUpdate` with coupon scope
- ✅ Test coverage: `show_returns_404_when_assignment_belongs_to_different_coupon`

### Mass Assignment Protection
- ✅ Model uses `$fillable` — explicitly whitelisted fields
- ✅ Controller uses `$request->validated()` — only validated fields passed
- ✅ `updateAssignment()` manually builds `$allowed` array — no mass update vulnerability

### Validation
- ✅ `CouponAssignmentRequest`: valid user_id exists, max_uses >= 1, expires_at is future date
- ✅ `UpdateCouponAssignmentRequest`: max_uses optional, min:1, expires_at nullable
- ⚠️ `after:now` rule uses server time — timezone consistency should be verified

---

## 5. Integration Audit — Complete Flow Analysis

### Flow: Admin Creates Assignment
```
POST /coupons/{coupon}/assignments
  → CouponAssignmentRequest (validation)
  → CouponAssignmentRepository::assignCoupon()
      → Coupon::findOrFail() [verifies coupon exists]
      → CouponAssignment::exists() [duplicate check]
      → DB::transaction → CouponAssignment::create()
  → CouponAssignmentResource (serialization)
```

### Flow: User Checks Out With Assigned Coupon
```
POST /orders/checkout/verify
  → CouponOrchestrator::validate()
      → CouponAssignmentValidator::validate()
          → assignments()->exists() [check if coupon has any assignments]
          → CouponAssignment::where(coupon_id, user_id)->first() [find user's assignment]
          → Check expires_at (reject if past)
          → Check used < max_uses (reject if exhausted)
      → CouponValidator::validate() [standard coupon rules]

POST /orders (payment)
  → OrderService::processPayment()
      → OrderService::recordCouponUsage()
          → lockForUpdate on assignment row
          → Check usage not already recorded for this order
          → coupon->increment('used')
          → assignment->increment('used')
          → CouponAssignmentUsage::create()
          → DB::afterCommit → dispatch AssignedCouponConsumed
```

### Flow: Order Cancellation / Refund
```
Intentional design: Usage is NEVER rolled back on cancellation or refund.
Documented in OrderService.php:598-601:
  "It is NEVER automatically returned on cancellation or refund.
   This prevents abuse where a user could re-use the same quota
   by repeatedly cancelling and re-ordering."
```
✅ Correct by design. This is a documented policy decision.

### Flow: Retry Payment
```
If payment fails, no usage is recorded (recordCouponUsage is inside the success path).
If payment succeeds but the order creation fails before the transaction commits,
the usage recording is inside DB::afterCommit, so it only fires after
the outer transaction commits.
```
✅ Correct. The usage guard at lines 643-648 prevents duplicate recording.

---

## 6. Test Execution Results

| Test Suite | Tests | Assertions | Status |
|-----------|-------|-----------|--------|
| CouponAssignmentApiTest | 30 | 101 | ✅ All passed |
| CouponAssignmentValidationTest | 13 | 50 | ✅ All passed |
| CouponsProductionHardenTest | 44 | 112 | ✅ All passed |
| CouponSystemTest | 21 | 47 | ✅ All passed |
| AssignedCouponSystemTest | 47 | 104 | ✅ All passed |
| **Total** | **155** | **414** | **✅ All passed** |

### Test Coverage Gaps

| Gap | Description | Severity |
|-----|-------------|----------|
| Concurrency test | No test for two simultaneous assignment creations | High |
| Concurrency test | No test for two simultaneous checkout with same assigned coupon | Medium |
| Coupon disabled | No test verifying disabled coupon still blocks assigned users | Medium |
| Soft-deleted user | No test assigning coupon to soft-deleted user | Low |

---

## 7. Concurrency Audit

### `assignCoupon()` — ❌ RACE CONDITION (CRITICAL)
```
LINE 64-70  ->  $exists = CouponAssignment::where(...)->exists();  ← OUTSIDE transaction
LINE 72-81  ->  DB::transaction(function () { CouponAssignment::create(...); });

Window: Between line 70 and line 72, another request can create the same assignment.
Result: QueryException (duplicate key) → 500 Internal Server Error
```

### `removeAssignment()` — ✅ PROPER LOCKING
```
LINE 111     ->  DB::transaction(function () {
LINE 112-115 ->      CouponAssignment::where(...)->lockForUpdate()->firstOrFail();
LINE 117-119 ->      if ($assignment->used > 0) { throw; }
LINE 121     ->      $assignment->delete();
LINE 122     ->  });
```

### `recordCouponUsage()` — ✅ PROPER LOCKING
```
LINE 630-632 ->  CouponAssignment::where(...)->lockForUpdate()->first();
LINE 643-645 ->  CouponAssignmentUsage::where(...)->lockForUpdate()->exists();
```

---

## 8. Cleanup Validation

### Question 1: Is `app/Http/Resources/Coupons/CouponAssignmentResource.php` unused?
- **Evidence:** Zero `use App\Http\Resources\Coupons\CouponAssignmentResource` references found anywhere in the codebase.
- **Verdict:** ✅ Confirmed dead code. Can be safely deleted.

### Question 2: Does `CouponAssignmentUsage` model exist?
- **Evidence:** ✅ `packages/marvel/src/Database/Models/CouponAssignmentUsage.php` exists. Properly defined with `$fillable`, `$casts`, and `BelongsTo` relationships.
- **Verdict:** ✅ Model exists and is correct.

### Question 3: Route placement — is it consistent?
- **Evidence:** Routes at top level (line 240-246), no `auth:sanctum` group wrapper.
- **Verdict:** ⚠️ Inconsistent with codebase patterns. Should be moved inside the `SUPER_ADMIN` role group.

### Question 4: Any additional cleanup?
- No additional issues found beyond what's listed in this report.

---

## 9. Production Readiness Summary

| Requirement | Status |
|------------|--------|
| SOLID principles | ✅ Largely followed |
| DRY | ✅ No logic duplication |
| KISS | ✅ Simple, clear design |
| Security (IDOR, XSS, SQLi) | ⚠️ See C-1 (concurrency race) |
| Validation (all paths) | ✅ Comprehensive |
| Authorization (RBAC) | ✅ Per-action permissions |
| Database design | ✅ Proper FK, indexes, constraints |
| Concurrency safety | ❌ See C-1 (TOCTOU in assignCoupon) |
| Error handling | ❌ See H-1 (broad catch blocks) |
| Test coverage | ✅ Strong (155 tests, 414 assertions) |
| Translations (EN + AR) | ✅ Complete |
| N+1 prevention | ✅ Eager loading used |
| Logging | ⚠️ Silent error handling masks issues |

---

## Final Verdict

**PRODUCTION READY: NO** 🚫

### Must Fix Before Merge:
1. **🔴 CRITICAL C-1:** Fix TOCTOU race condition in `CouponAssignmentRepository::assignCoupon()` — move duplicate check inside transaction with `lockForUpdate`
2. **🟠 HIGH H-1:** Remove broad `catch (Exception $e)` blocks from controller — let the framework handle unexpected errors
3. **🟡 MEDIUM M-1:** Move coupon assignment routes inside the `super_admin` middleware group
4. **🟡 MEDIUM M-2:** Add coupon existence check in `index()` method

### Should Fix:
5. **🔵 LOW L-1:** Delete dead code `app/Http/Resources/Coupons/CouponAssignmentResource.php`

### Estimated Fix Time: 1-2 hours
### Risk if Merged Now: Concurrent admin operations can produce 500 errors instead of clean 409 responses. Unexpected DB errors are silently swallowed, making production debugging extremely difficult.
