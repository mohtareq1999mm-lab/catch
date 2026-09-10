# Brand Module — Jira Tasks

---

## Task 1: Extract Inline Validation Into BrandsReorderRequest

**Priority:** Medium
**Component:** Brand Controller
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/BrandController.php`
- `packages/marvel/src/Http/Requests/BrandsReorderRequest.php` (new)

**Description:** The `reorder()` method in BrandController uses `$request->validate([...])` inline instead of a dedicated Form Request. This violates separation of concerns — validation belongs in Form Request classes.

**Status:** ✅ Completed

**Acceptance Criteria:**
- [x] `BrandsReorderRequest` created with `brands` => `required|array`, `brands.*` => `required|integer|exists:brands,id`
- [x] `BrandController::reorder()` type-hints `BrandsReorderRequest $request`
- [x] Validation errors return 422 with proper field names
- [x] Existing reorder tests pass

---

## Task 2: Change `brandUpdate()` Visibility to Private

**Priority:** Low
**Component:** Brand Controller
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Controllers/BrandController.php`

**Description:** The `brandUpdate()` helper method is declared `public` but is only called internally by `update()`. A public method exposes unnecessary surface area and could be called as a route action if misconfigured.

**Status:** ✅ Completed

**Acceptance Criteria:**
- [x] `brandUpdate()` changed from `public` to `private`
- [x] `update()` can still call `brandUpdate()`
- [x] No external code calls `brandUpdate()` (verify test coverage)

---

## Task 3: Wrap `reorder()` in Database Transaction

**Priority:** Low
**Component:** Brand Repository
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Database/Repositories/BrandRepository.php`

**Description:** The `reorder()` method calls `setNewOrder()` without a database transaction. If the operation fails mid-way, some brands may have updated order values while others remain at their previous positions. Wrap the call in `DB::transaction()` for atomicity.

**Acceptance Criteria:**
- [ ] `DB::transaction()` wraps the `setNewOrder()` call
- [ ] Failed reorder rolls back all order changes
- [ ] Error is re-thrown as `HttpException(500)`

---

## Task 4: Add Defensive Comment for Route Ordering

**Priority:** Low
**Component:** Routes
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Rest/Routes.php`

**Description:** The `PUT brands/reorder` route must be defined before `apiResource('brands', ...)` to avoid `{brand}` parameter capturing "reorder". Add a comment explaining this dependency to prevent accidental reordering during refactoring.

**Acceptance Criteria:**
- [ ] Comment explains the ordering requirement
- [ ] Test verifies `PUT /brands/reorder` hits `reorder()` method, not `update()`

---

## Task 5: Add Comprehensive Brand Test Suite

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/BrandApiTest.php`
- `tests/Feature/BrandProductionHardenTest.php`

**Description:** Two test files exist but coverage could be expanded:
- Test reorder with invalid brand IDs
- Test concurrent brand updates (race conditions)
- Test media deletion when brand is force-deleted
- Test brand-product pivot cleanup on brand force-delete

**Status:** Partial — `BrandProductionHardenTest.php` covers reorder validation (invalid ID returns 422).

**Acceptance Criteria:**
- [ ] Reorder with empty brands array returns 422
- [ ] Reorder with non-array input returns 422
- [ ] Force-delete cleans up pivot records
- [ ] Concurrent updates don't cause data loss
