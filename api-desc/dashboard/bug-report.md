# Bug Report - Dashboard Feature

## Issue 1 (MEDIUM): Order Stats Hardcoded Statuses

- **File:** `app/Services/Dashboard/DashboardService.php` lines 106-114
- **Description:** Only `pending`, `completed`, `cancelled` queried. `processing`, `refunded`, `failed`, `local_facility`, `out_for_delivery` hardcoded to 0.
- **Impact:** Stats are incorrect for these statuses.

## Issue 2 (LOW): Recent Orders Missing orderBy

- **File:** `app/Services/Dashboard/DashboardService.php` line 130
- **Description:** `Order::with([...])->take($limit)->get()` without `orderBy('created_at', 'desc')`.
- **Impact:** Order depends on global scope.

## Issue 3 (LOW): Magic Number Thresholds

- **Description:** Low stock threshold (10), category limits (15), product limits (10) are hardcoded.
- **Impact:** Not configurable.
