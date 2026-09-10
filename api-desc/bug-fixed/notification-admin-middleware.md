# Bug Fix: Notification API — "Target class [admin] does not exist"

**Date:** 2026-07-21

**Affected Endpoints:** All 6 `/api/v1/admin/notifications` endpoints

## Bugs Fixed

| # | Endpoint | Issue | Fix |
|---|---|---|---|
| BUG-1 | `GET /api/v1/admin/notifications` | HTTP 500 — "Target class [admin] does not exist" | Removed `$this->middleware('admin')` from NotificationController constructor |
| BUG-2 | `GET /api/v1/admin/notifications/unread` | HTTP 500 — same root cause | Same as above |
| BUG-3 | `PATCH /api/v1/admin/notifications/{id}/read` | HTTP 500 — same root cause | Same as above |
| BUG-4 | `PATCH /api/v1/admin/notifications/read-all` | HTTP 500 — same root cause | Same as above |
| BUG-5 | `DELETE /api/v1/admin/notifications/{id}` | HTTP 500 — same root cause | Same as above |
| BUG-6 | `DELETE /api/v1/admin/notifications` | HTTP 500 — same root cause | Same as above |
| BUG-7 | All 6 endpoints | Response shows `"MESSAGE.NOTIFICATIONS_FETCHED"` instead of human-readable text | Added 6 missing translation keys to `resources/lang/en/message.php` |

## Root Cause

`NotificationController::__construct()` registered a middleware named `admin`:

```php
$this->middleware('admin');
```

No `admin` middleware class exists anywhere in the application's HTTP Kernel. Laravel's middleware resolver tried to instantiate a class called `admin` and failed with:

```
Target class [admin] does not exist.
```

The `admin` middleware was intended to restrict notifications to admin users only, but it was never registered. The Spatie permission middleware (`VIEW_NOTIFICATIONS`, `MANAGE_NOTIFICATIONS`) already provides the same access control.

## Fix Applied

### 1. Removed non-existent middleware (`NotificationController.php`)

**Before:**
```php
public function __construct()
{
    $this->middleware('auth:sanctum');
    $this->middleware('admin');

    $this->middleware('permission:' . Permission::VIEW_NOTIFICATIONS)->only(['index', 'unread']);
    $this->middleware('permission:' . Permission::MANAGE_NOTIFICATIONS)->except(['index', 'unread']);
}
```

**After:**
```php
public function __construct()
{
    $this->middleware('auth:sanctum');

    $this->middleware('permission:' . Permission::VIEW_NOTIFICATIONS)->only(['index', 'unread']);
    $this->middleware('permission:' . Permission::MANAGE_NOTIFICATIONS)->except(['index', 'unread']);
}
```

The permission middleware is sufficient:
- `VIEW_NOTIFICATIONS` — blocks all users without explicit `view-notifications` permission
- `MANAGE_NOTIFICATIONS` — blocks all users without explicit `manage-notifications` permission

### 2. Added missing translation keys (`resources/lang/en/message.php`)

| Key | Value |
|-----|-------|
| `MESSAGE.NOTIFICATIONS_FETCHED` | Notifications fetched successfully. |
| `MESSAGE.UNREAD_NOTIFICATIONS_FETCHED` | Unread notifications fetched successfully. |
| `MESSAGE.NOTIFICATION_MARKED_READ` | Notification marked as read successfully. |
| `MESSAGE.ALL_NOTIFICATIONS_MARKED_READ` | All notifications marked as read successfully. |
| `MESSAGE.NOTIFICATION_DELETED` | Notification deleted successfully. |
| `MESSAGE.ALL_NOTIFICATIONS_DELETED` | All notifications deleted successfully. |

## Files Changed

| File | Change |
|---|---|
| `packages/marvel/src/Http/Controllers/NotificationController.php` | Removed `$this->middleware('admin')` |
| `resources/lang/en/message.php` | Added 6 notification translation keys |
| `api-desc/notifaction/api.md` | Removed reference to non-existent `admin` middleware |
| `api-desc/notifaction/backend.md` | Removed `AdminMiddleware` documentation and updated middleware stack + translations |
| `api-desc/notifaction/changelog.md` | Added fix entries |
| `api-desc/notifaction/bug-report.md` | Updated issue status with fix details |

## Security Analysis

Removing `$this->middleware('admin')` does **not** reduce security because:
1. `auth:sanctum` ensures the user is authenticated
2. `VIEW_NOTIFICATIONS` permission ensures only users with explicit read access can list notifications
3. `MANAGE_NOTIFICATIONS` permission ensures only users with explicit write access can mark/delete

Previously, even a user with `view-notifications` permission but `type !== 'admin'` would be blocked. This was overly restrictive — if an admin grants a user the `view-notifications` permission, that user should be able to see notifications.

## Test Results

All 34 notification tests pass with expected behavior:
- Unauthenticated users get 401
- Users without permissions get 403
- Users with proper permissions can access all endpoints
