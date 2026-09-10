# Bug Report - Slider Feature

## Issue 1 (HIGH): Missing Sliders Table Migration

- **Description:** The `sliders` table schema exists only in `tests/Concerns/CreatesTestTables.php` but there is **no migration file** in `database/migrations/`. The table cannot be created in production without manual SQL.
- **Impact:** Slider feature non-functional in production/staging environments.

## Issue 2 (MEDIUM): Duplicate Route Registrations

- **File:** `packages/marvel/src/Rest/Routes.php`
- **Description:** Slider routes appear in multiple places (apiResource + custom routes registered more than once).
- **Impact:** Potential for route conflicts; harder to maintain.

## Issue 3 (LOW): Inconsistent Media Collection Names

- **Description:** The code uses `sliders-desktop` / `sliders-mobile` in the repository, but has fallback logic for `slider-image-desktop` / `slider-image-mobile`. Some old records may use the old collection names.
- **Impact:** Old sliders may not display images if collection names mismatch.
