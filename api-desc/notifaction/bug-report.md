# Bug Report - Notification Feature

## Issue 1 (CRITICAL): Non-existent `admin` middleware — "Target class [admin] does not exist"

- **Status:** FIXED
- **Description:** `NotificationController::__construct()` registered `$this->middleware('admin')`, but no `admin` middleware exists in the HTTP Kernel. All 6 endpoints returned HTTP 500 with "Target class [admin] does not exist."
- **Fix:** Removed `$this->middleware('admin')` from the constructor. The permission middleware (`view-notifications`, `manage-notifications`) already provides sufficient access control.

## Issue 3 (MEDIUM): `SendAdminLoginNotification` listener not registered

- **Status:** FIXED
- **Description:** The `SendAdminLoginNotification` listener exists at `app/Listeners/SendAdminLoginNotification.php` but was never registered in `EventServiceProvider::$listen`. The `AdminLoggedIn` event dispatched notifications to no one.
- **Fix:** Registered `AdminLoggedIn::class => SendAdminLoginNotification::class` in `app/Providers/EventServiceProvider.php`.
