# Bug Report - Activity Log Feature

> **Status: RESOLVED (2026-09-15)** — issues 1–6 fixed by the forensic activity-log implementation. See `activity-log-implementation-verification.md`.

## Issue 1: Duplicate Route Registration — RESOLVED

- **File:** `packages/marvel/src/Rest/Routes.php`
- **Before:** `GET /logs/activity` was registered twice (and later left unregistered entirely, causing the read API to return 404).
- **Now:** Registered once under the `auth:sanctum` + `throttle:admin` group; `permission:view-activity-log` enforced in the controller.
- **Evidence:** `Routes.php` → `Route::get('logs/activity', [ActivityLogController::class, 'index'])->name('admin.activity-log.index')`.

## Issue 2: Silent Failure When Subject Deleted — RESOLVED

- **File:** `app/Jobs/LogActivityJob.php`
- **Before:** `find($id)` returned null after hard delete → job silently exited without logging.
- **Now:** `LogActivityJob` carries a self-contained `ActivitySnapshot` (subject_type/id, event, old/new, actor, context). The writer persists via the Spatie `Activity` model directly; a missing subject never drops the record.
- **Evidence:** `app/Audit/ActivityAuditService.php`, `app/Audit/ActivitySnapshot.php`, `ActivityLogForensicTest::test_snapshot_survives_deleted_subject`.

## Issue 3: Soft-Deleted Subjects Not Found — RESOLVED

- **File:** `app/Jobs/LogActivityJob.php`
- **Before:** `find()` without `withTrashed()` dropped `restored`/`forceDeleted` and soft-deleted subjects.
- **Now:** Observers capture `getAttributes()` snapshots at dispatch; no re-fetch. `deleted`/`restored`/`forceDeleted` all persist.

## Issue 4: No Date Range Filters — OPEN (optional)

- **File:** `packages/marvel/src/Http/Controllers/ActivityLogController.php`
- **Status:** Not yet implemented. Not a blocker for the forensic requirements; still recommended for large-volume investigation.

## Issue 5: No Sort Customization — OPEN (optional)

- **File:** `packages/marvel/src/Http/Controllers/ActivityLogController.php`
- **Status:** Results always `latest()`; fixed sort is acceptable for current audit use cases.

## Issue 6: Inconsistent Translation Fallbacks — RESOLVED (partial)

- **Status:** All implemented event descriptions use translation keys with English fallbacks; `activity.flash_sale_restored` and status-change keys are now present in `resources/lang/{en,ar}/activity.php`.

---

## New coverage added by the implementation

- Delete/force-delete/restore snapshots for Product, Category, Brand, Coupon, FlashSale, Promotion, Role, User, PickupLocation.
- Settings, relationship sync, inventory, invoice, import lifecycle, bulk delete, destroy-all auditing.
- Actor/executor resolution, request context, request-id, centralized recursive redaction.
- 90-day retention + quarterly chunked prune with `activity_pruned` self-audit.
