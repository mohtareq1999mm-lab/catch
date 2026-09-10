# Bug Report — Category Module

---

## BUG-CAT-001: Route `categories/feature` Conflict With `apiResource`

**Severity:** Low

**Component:** `packages/marvel/src/Rest/Routes.php` (lines 678-679)

**Description:** The `PUT categories/feature` route is defined before `apiResource('categories', ...)`. This is currently correct — the literal route matches before `{category}` parameter binding. However, if the route order were ever changed (e.g., alphabetically sorted or reorganized), `PUT categories/feature` would be caught by `PUT categories/{category}` → `update()` and would attempt to find a category with ID "feature", resulting in a 404 instead of the feature toggle.

**Code Location:** `packages/marvel/src/Rest/Routes.php` — lines 678-679

**Current Behavior:**
```php
Route::put('categories/feature', [CategoryController::class, 'addOrRemoveCategoryFromFeature']);  // line 678
Route::apiResource('categories', CategoryController::class);                                      // line 679
```

**Recommendation:** Add a defensive comment explaining the ordering requirement, or use a different URL pattern (e.g., `POST categories/{id}/toggle-feature`).

---

## BUG-CAT-002: `categoryUpdate()` Is Public

**Severity:** Low

**Component:** `Marvel\Http\Controllers\CategoryController`

**Description:** The `categoryUpdate()` helper method is declared `public` but is only called internally by `update()`. A public method could be called as a route action if misconfigured.

**Code Location:** `packages/marvel/src/Http/Controllers/CategoryController.php` — line 113

**Current Behavior:**
```php
public function categoryUpdate(CategoryUpdateRequest $request): Category
```

**Impact:** Low — no route is bound to it. But violates encapsulation principles.

---

## BUG-CAT-003: Inline Validation in `addOrRemoveCategoryFromFeature()`

**Severity:** Low

**Component:** `Marvel\Http\Controllers\CategoryController`

**Description:** The `addOrRemoveCategoryFromFeature()` method uses `$request->validate([...])` inline instead of a dedicated Form Request class. This violates separation of concerns where validation belongs in Form Requests.

**Code Location:** `packages/marvel/src/Http/Controllers/CategoryController.php` — `addOrRemoveCategoryFromFeature()` method

**Current Behavior:**
```php
$request->validate([
    "id"=>'required|integer|exists:categories,id',
]);
```

**Recommendation:** Extract to a dedicated `CategoryFeatureToggleRequest`.

---

## BUG-CAT-004: Delete Category With Children Returns Ambiguous Error

**Severity:** Medium

**Component:** `Marvel\Http\Controllers\CategoryController::destroy()`

**Description:** When deleting a category that has children, the FK RESTRICT constraint throws a `QueryException` which is caught as `CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES`. However, this generic message doesn't specify whether the restriction is due to children or other associations.

**Code Location:** `packages/marvel/src/Http/Controllers/CategoryController.php` — `destroy()` method

**Current Behavior:**
```php
catch (\Illuminate\Database\QueryException $e) {
    throw new MarvelException(CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES);
}
```

**Impact:** Low — message is functionally correct, just ambiguous.

---

## BUG-CAT-005: `parent_id` Validation Allows Non-Integer Strings

**Severity:** Low

**Component:** `Marvel\Http\Requests\CategoryUpdateRequest`

**Description:** The `parent_id` rule uses `integer` validation but also has a custom cycle-detection closure. If `parent_id` is passed as a string like `"abc"`, the `integer` validation catches it first and returns 422. However, the custom closure also runs and may receive unexpected types.

**Code Location:** `packages/marvel/src/Http/Requests/CategoryUpdateRequest.php`

**Impact:** Low — integer validation fires first and prevents the closure from receiving non-integer values.

---

## BUG-CAT-006: `details` Field Type Mismatch Between Create and Model

**Severity:** Low

**Component:** `Marvel\Http\Requests\CategoryCreateRequest`

**Description:** The create request validates `details` as a `string`, but the model declares `details` as translatable (JSON). This means `details` is stored as-is (plain string in the JSON column) rather than a structured `{"en": "...", "ar": "..."}` object. The `CategoryHomeResource` safely handles this with `mergeWhen`, but translation lookups on `details` may not work as expected.

**Code Location:** `packages/marvel/src/Http/Requests/CategoryCreateRequest.php`

**Impact:** Low — functional for single-language use, but multilingual details via the admin API may not work correctly.

---

## BUG-CAT-007: Duplicate Route Registration (GET /categories)

**Severity:** Low

**Component:** Routes

**Description:** `GET /api/v1/categories` is registered twice:
- Line 229: public group (no auth, `only: index, show`)
- Line 679: authenticated group (auth + permissions)

Laravel uses the first matching route, so the public route (line 229) wins. However, the authenticated route at line 679 also registers `index` and `show`. Since the public route is registered first, it handles all GET requests regardless of authentication.

**Code Location:** `packages/marvel/src/Rest/Routes.php` — lines 229, 679

**Impact:** Low — works in practice because both routes point to the same controller. The first-registered route wins.

---

## BUG-CAT-008: No Guard Against Deleting Featured Category With Children

**Severity:** Low

**Component:** `CategoryController::destroy()`

**Description:** If a featured category has children, the delete is blocked by FK RESTRICT. But if the category has no children, soft delete succeeds while `is_featured` remains `true`. The `is_featured` field is not reset on delete.

**Code Location:** `packages/marvel/src/Http/Controllers/CategoryController.php`

**Impact:** Low — soft-deleted categories are excluded from queries by default, so the `is_featured` flag on a deleted record is invisible.
