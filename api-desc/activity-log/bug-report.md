# Bug Report - Activity Log Feature

## Issue 1: Duplicate Route Registration

- **File:** `packages/marvel/src/Rest/Routes.php`
- **Description:** `GET /logs/activity` is registered twice — at line 174 (no middleware group) and line 804 (inside auth:sanctum + verified group where super_admin role check is commented out). Both effectively require `auth:sanctum` + `view-activity-log` permission.
- **Impact:** Low — harmless but redundant.

## Issue 2: Silent Failure When Subject Deleted

- **File:** `app/Jobs/LogActivityJob.php` line 31
- **Description:** If the subject entity is deleted (hard delete) between observer dispatch and job execution, `find($id)` returns null, and the job silently exits without logging.
- **Impact:** Medium — lost audit trail for time-sensitive deletions.

## Issue 3: Soft-Deleted Subjects Not Found

- **File:** `app/Jobs/LogActivityJob.php`
- **Description:** Uses `::find($subjectId)` without `withTrashed()`. Config sets `subject_returns_soft_deleted_models = true`, but the job does not use it.
- **Impact:** Medium — `restored` and `forceDeleted` events may fail to log.

## Issue 4: No Date Range Filters

- **File:** `packages/marvel/src/Http/Controllers/ActivityLogController.php`
- **Description:** No `date_from` / `date_to` query parameters. Users cannot filter logs by date range.
- **Impact:** Low — can paginate through, but auditing large volumes is impractical.

## Issue 5: No Sort Customization

- **File:** `packages/marvel/src/Http/Controllers/ActivityLogController.php`
- **Description:** Results always sorted by `latest()` (created_at DESC). No `sort_by` / `sort_order` parameters.
- **Impact:** Low — fixed sort is acceptable for most audit use cases.

## Issue 6: Inconsistent Translation Fallbacks

- **Description:** Some observers use `__('activity.key') ?: 'Fallback text'` while others (e.g., PickupLocationObserver) don't have fallback text. Missing translations could return raw key strings as descriptions.
- **Impact:** Low — translations exist for EN and AR.
