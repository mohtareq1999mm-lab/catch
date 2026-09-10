# Category Module — Jira Tasks

---

## Task 1: Extract Inline Validation Into CategoryFeatureToggleRequest

**Priority:** Medium
**Component:** Category Controller
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/CategoryController.php`
- `packages/marvel/src/Http/Requests/CategoryFeatureToggleRequest.php` (new)

**Description:** The `addOrRemoveCategoryFromFeature()` method uses `$request->validate([...])` inline instead of a dedicated Form Request. Validation belongs in Form Request classes.

**Acceptance Criteria:**
- [ ] `CategoryFeatureToggleRequest` created with `id` => `required|integer|exists:categories,id`
- [ ] `addOrRemoveCategoryFromFeature()` type-hints `CategoryFeatureToggleRequest $request`
- [ ] Validation errors return 422 with proper field names

---

## Task 2: Change `categoryUpdate()` Visibility to Private

**Priority:** Low
**Component:** Category Controller
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Controllers/CategoryController.php`

**Description:** The `categoryUpdate()` helper method is declared `public` but is only called internally by `update()`. A public method exposes unnecessary surface area.

**Acceptance Criteria:**
- [ ] `categoryUpdate()` changed from `public` to `private`
- [ ] `update()` can still call `categoryUpdate()`
- [ ] No external code calls `categoryUpdate()`

---

## Task 3: Add Defensive Comment for Route Ordering

**Priority:** Low
**Component:** Routes
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Rest/Routes.php`

**Description:** The `PUT categories/feature` route must be defined before `apiResource('categories', ...)` to avoid `{category}` parameter capturing "feature". Add a comment explaining this.

**Acceptance Criteria:**
- [ ] Comment explains the ordering requirement
- [ ] Test verifies `PUT /categories/feature` hits `addOrRemoveCategoryFromFeature()`, not `update()`

---

## Task 4: Add Validation for `parent_id` Self-Reference

**Priority:** Medium
**Component:** Category Create Request
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Requests/CategoryCreateRequest.php`

**Description:** The `CategoryCreateRequest` validates `parent_id` as `nullable|integer|exists:categories,id` but does not prevent self-referencing at the request level. The model's `saving` event handles this via `CategoryHierarchyService`, but the error appears as a 500 rather than a clean 422 validation error.

**Acceptance Criteria:**
- [ ] Creating a category with `parent_id = its own ID` returns 422
- [ ] Error message: "A category cannot be its own parent."

---

## Task 5: Add Comprehensive Category Test Suite

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/Categories/` (13 existing test files)

**Description:** Existing test suite covers CRUD, validation, auth, soft delete, translations, relationships, resources, pivot uniqueness, featured toggle, media, and regression. Additional tests needed:
- Cycle detection on create
- Cycle detection on update (parent reassignment)
- Concurrent category updates

**Status:** Partial — 13 test files exist. Cycle detection and concurrent tests are missing.

**Acceptance Criteria:**
- [ ] Test cycle detection on create (parent_id set to self)
- [ ] Test cycle detection on update (reparent to descendant)
- [ ] Test concurrent reassignment of same parent
