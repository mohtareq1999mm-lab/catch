# Bug Report - Product Feature

## Current State

8 test files in `tests/Feature/` + 1 Unit test. No known open bugs in tracking. The following were identified during investigation:

## Issue 1: Empty Migration Files (No-op)

- **Files:**
  - `packages/marvel/database/migrations/2023_08_15_061447_add_is_featured_column_to_products_table.php`
  - `database/migrations/2026_06_13_094100_add_product_filter_indexes.php`
- **Description:** Both migration files are empty (no schema changes). The `up()` method does nothing. This suggests the schema changes may have been applied manually or the migrations are placeholders.
- **Impact:** Low — no functional impact, but could cause confusion for new developers running migrations fresh.
- **Severity:** Low

## Issue 2: Duplicate Route Definitions

- **File:** `packages/marvel/src/Rest/Routes.php`
- **Description:** `Route::apiResource('products', ProductController::class)` appears twice — once in the public scope (line ~223) and once in the authenticated scope (line ~564 with `only: ['store', 'show', 'update', 'destroy']`). Also, `popular-products` is defined multiple times.
- **Impact:** Low — Laravel ignores duplicate named routes (last one wins), but could cause unexpected behavior if scoping differs.
- **Severity:** Low

## Issue 3: No Product Model Factory

- **Description:** Only `ProductVariantFactory` exists in `database/factories/`. There is no `ProductFactory`. Tests rely on `Product::create()` directly.
- **Impact:** Medium — makes test setup more verbose and less maintainable. Inconsistent with the rest of the codebase.
- **Severity:** Medium

## Issue 4: Missing ProductReview Event Classes

- **Files Referenced:**
  - `packages/marvel/src/Listeners/ProductReviewApprovedListener.php` listens for `ProductReviewApproved`
  - `packages/marvel/src/Listeners/ProductReviewRejectedListener.php` listens for `ProductReviewRejected`
- **Description:** The listener files exist and reference `ProductReviewApproved` and `ProductReviewRejected` events, but the event classes were not found in the codebase. They may be in a different namespace or not yet created.
- **Impact:** High — if these events are fired but the classes don't exist, it will cause runtime errors. If they've never been fired, the listeners are dead code.
- **Severity:** High

## Issue 5: Inconsistent Translatable Search

- **Files:**
  - `packages/marvel/src/Database/Repositories/ProductRepository.php` (uses `$field . '->' . $locale`)
  - `app/Services/General/ProductService.php` (has separate `applyTranslatableLike` method)
- **Description:** The admin repository and public service use different approaches for translatable field searching. The repository uses JSON path syntax while the service has its own implementation.
- **Impact:** Low — both work, but inconsistent patterns create maintenance burden.
- **Severity:** Low

## Issue 6: `available_stock` Accessor Uses COALESCE Unnecessarily

- **File:** `packages/marvel/src/Database/Models/Product.php`
- **Description:** The `available_stock` accessor uses `COALESCE(reserved_quantity, 0)` but `reserved_quantity` has a default of `0` in the migration, so `null` shouldn't be possible.
- **Impact:** Low — no functional impact, just unnecessary code.
- **Severity:** Low

## Issue 7: `fetchDigitalFilesForProduct`/`fetchDigitalFilesForVariation` Return Raw Data

- **File:** `packages/marvel/src/Http/Controllers/ProductController.php`
- **Description:** These GraphQL query resolvers return raw data from the controller rather than formatted API resources or JSON responses.
- **Impact:** Medium — inconsistent with resource pattern used elsewhere. Could cause formatting issues.
- **Severity:** Medium
