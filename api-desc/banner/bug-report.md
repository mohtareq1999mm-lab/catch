# Bug Report - Banner Feature

## Issue 1 (MEDIUM): Duplicate Route Registration

- **File:** `packages/marvel/src/Rest/Routes.php` (lines 217 and 259)
- **Description:** `Route::apiResource('banners', BannerController::class)` appears twice — once with the custom routes (`changeStatus`, `reorder`) and once standalone.
- **Impact:** Both route registrations work (duplicate is ignored by Laravel), but indicates code duplication. If the two differ in the future, one will silently override.

## Issue 2 (LOW): Duplicate Pagination Keys

- **File:** `packages/marvel/src/Http/Controllers/BannerController.php:30-44`
- **Description:** Same pattern as PickupLocation — manual extraction of pagination meta resulting in both `page` and `current_page` with the same value.
