# Bug Report - Shipping Feature

## Issue 1 (HIGH): Missing Translation Keys for Country & City Messages

- **Description:** The following translation keys are missing from both `resources/lang/en/message.php` and `resources/lang/ar/message.php`:
  - `MESSAGE.COUNTRY_CREATED_SUCCESSFULLY`
  - `MESSAGE.COUNTRY_UPDATED_SUCCESSFULLY`
  - `MESSAGE.COUNTRY_DELETED_SUCCESSFULLY`
  - `ERROR.COUNTRY_NOT_FOUND`
  - `MESSAGE.GOVERNORATES_FETCHED_SUCCESSFULLY`
  - `MESSAGE.CITY_CREATED_SUCCESSFULLY`
  - `MESSAGE.CITY_UPDATED_SUCCESSFULLY`
  - `MESSAGE.CITY_DELETED_SUCCESSFULLY`
- **Impact:** `__($key)` falls through to return the raw key name as message (e.g., `"MESSAGE.COUNTRY_CREATED_SUCCESSFULLY"` instead of "Country created successfully").

## Issue 2 (MEDIUM): No auth Middleware on Route Group

- **File:** `packages/marvel/src/Rest/Routes.php`
- **Description:** These routes are not wrapped in `auth:sanctum`. Authentication relies solely on the Spatie `permission:` middleware in each controller constructor. If permission middleware is misconfigured, endpoints could be accessible without authentication.

## Issue 4 (CRITICAL): Governorate `PUT /change-status` route conflict — FIXED

- **Description:** Route `PUT /api/v1/governorates/change-status` was defined AFTER `Route::apiResource('governorates', ...)`. Laravel's `apiResource` registers `PUT /governorates/{governorate}` first, causing `"change-status"` to be captured as `{governorate}`. Request hit `GovernorateController@update(int $id)` with `$id = "change-status"`, causing `TypeError: must be of type int, string given`.
- **Fix:** Moved `change-status`, `fast-shipping`, and `cities` routes BEFORE `apiResource('governorates')` in `Routes.php`.

## Issue 5 (MEDIUM): Country `POST /change-status` — no conflict, but inconsistency

- **Status:** Not a bug — `POST /countries/change-status` doesn't conflict with `POST /countries` (different URL segment count).
