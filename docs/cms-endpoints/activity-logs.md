# Activity Logs API

## Overview

The Activity Logs module tracks all CRUD operations and status changes across the system using `Spatie/laravel-activitylog`. Every create, update, delete, restore, force-delete, and status change on Products, Categories, Brands, Coupons, Flash Sales, Promotions, Roles, and Users is automatically recorded via Laravel Observers.

Activity logging is implemented through **queue jobs** (`LogActivityJob`) dispatched from observers and listeners. All jobs are dispatched to the `medium` priority queue. In testing (sync queue), jobs execute synchronously.

---

## Authentication

| Guard | Required |
|-------|----------|
| `sanctum` | ✓ |

Authorization is enforced at the controller level:
1. Controller: requires `view-activity-log` permission (via `$this->middleware('permission:' . Permission::VIEW_ACTIVITY_LOG)`)
2. Route-level permission middleware is intentionally not used — the controller is the sole authorization source of truth

The `super_admin` role in the PermissionSeeder automatically receives all permissions including `view-activity-log`.

---

## Endpoints

### GET `/api/logs/activity`

Fetch paginated activity logs with optional filtering.

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `log_name` | string | No | — | Filter by log name (e.g. `products`, `users`, `roles`) |
| `event` | string | No | — | Filter by event type (e.g. `created`, `updated`, `deleted`, `statusChanged`, `roleUpdated`) |
| `subject_type` | string | No | — | Filter by subject model class (e.g. `Marvel\Database\Models\Product`). Must be URL-encoded if it contains backslashes. |
| `causer_id` | integer | No | — | Filter by the user ID who performed the action |
| `search` | string | No | — | Full-text search across `description` and `log_name` (uses SQL `LIKE` with wildcards) |
| `per_page` | integer | No | 15 | Number of results per page |

#### URL Examples

**Filter by log name + event:**
```
GET /api/v1/logs/activity?log_name=products&event=created
```

**Filter by subject type (URL-encode backslashes):**
```
GET /api/v1/logs/activity?subject_type=Marvel%5CDatabase%5CModels%5CUser
```

**Filter by causer:**
```
GET /api/v1/logs/activity?causer_id=5
```

**Search across description and log_name:**
```
GET /api/v1/logs/activity?search=admin
```

**Combined with pagination:**
```
GET /api/v1/logs/activity?log_name=users&event=roleUpdated&per_page=25
```

**Full example — all params:**
```
GET /api/v1/logs/activity?log_name=products&event=updated&causer_id=3&search=price&per_page=50
```

#### Business Rules

- Logs are returned in **descending** order by `created_at` (most recent first)
- Only users with `view-activity-log` permission can access this endpoint
- The `log_name` values use the **plural English** entity name: `products`, `categories`, `brands`, `coupons`, `flash_sales`, `promotions`, `roles`, `users`
- Event values: `created`, `updated`, `deleted`, `restored`, `forceDeleted`, `statusChanged`, `roleUpdated`
- Activity logging is **queued** via `LogActivityJob` (queue: `medium`). In production with a queue worker, there may be a slight delay before logs appear.
- Each model that is the subject of the activity is **re-retrieved from the database** inside the job at execution time, ensuring the log reflects the actual state when the job runs.

#### Success Response

```json
{
    "success": true,
    "message": "Activity logs fetched successfully.",
    "data": [
        {
            "id": 1,
            "log_name": "products",
            "description": "Product created",
            "event": "created",
            "subject_type": "Marvel\\Database\\Models\\Product",
            "subject_id": 42,
            "causer_type": "Marvel\\Database\\Models\\User",
            "causer_id": 1,
            "properties": {
                "attributes": {
                    "name": "{\"en\":\"Organic Apples\",\"ar\":\"\\u062a\\u0641\\u0627\\u062d \\u0639\\u0636\\u0648\\u064a\"}",
                    "price": 45.00
                }
            },
            "created_at": "2026-07-05T10:30:00+00:00",
            "updated_at": "2026-07-05T10:30:00+00:00"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 1,
        "last_page": 1,
        "from": 1,
        "to": 1
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated (no token) |
| 403 | User lacks `view-activity-log` permission |

```json
{
    "message": "NOT_AUTHORIZED"
}
```

---

## Resource Structure — `ActivityLogResource`

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Unique identifier |
| `log_name` | string | Category name (`products`, `users`, `roles`, etc.) |
| `description` | string | Human-readable description of the action |
| `event` | string | Event type (`created`, `updated`, `deleted`, etc.) |
| `subject_type` | string | Fully qualified model class name |
| `subject_id` | integer | ID of the affected model |
| `causer_type` | string | Fully qualified user model class name |
| `causer_id` | integer | ID of the user who performed the action (nullable — null for system/self-registration) |
| `properties` | object\|null | JSON object containing the changed attributes and metadata (shape varies by event type — see Events Reference below) |
| `created_at` | string | ISO 8601 timestamp (nullable) |
| `updated_at` | string | ISO 8601 timestamp (nullable) |

---

## Events Reference — Properties Shape

### Standard CRUD Events (`created`, `updated`, `deleted`, `restored`, `forceDeleted`)

**created / deleted / restored / forceDeleted:**
No `properties` are passed (null). Only the event type and description distinguish them.

**updated (model observer):**
```json
{
    "old": { "field_name": "old_value", ... },
    "new": { "field_name": "new_value", ... }
}
```

### `statusChanged`
```json
{
    "old": { "is_active": "0" },
    "new": { "is_active": "1" }
}
```

### `roleUpdated` (from `LogUserRolesUpdated` listener)
```json
{
    "old": { "roles": ["customer"] },
    "new": { "roles": ["super_admin", "editor"] },
    "previous_roles": ["customer"],
    "new_roles": ["super_admin", "editor"],
    "roles_added": ["super_admin", "editor"],
    "roles_removed": ["customer"]
}
```

*Promotion-specific events (`products_synced`, `categories_updated`, `brands_updated`) are planned for future implementation (translation keys exist but are not yet dispatched from any observer or job).*

---

## Database Schema

### `activity_log` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `log_name` | varchar(255) | NULLABLE | Category name (indexed) |
| `description` | text | — | Human-readable description |
| `subject_type` | varchar(255) | NULLABLE | Morphs: model class |
| `subject_id` | bigint | NULLABLE | Morphs: model ID |
| `causer_type` | varchar(255) | NULLABLE | Morphs: causer class |
| `causer_id` | bigint | NULLABLE | Morphs: causer ID |
| `event` | varchar(255) | NULLABLE | Event type |
| `properties` | json | NULLABLE | Changed attributes + metadata |
| `batch_uuid` | uuid | NULLABLE | Batch identifier |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

Indexes: `log_name`, `subject` (subject_type + subject_id), `causer` (causer_type + causer_id)

---

## Architecture — Queue Flow

```
Model Event (created/updated/deleted/...)
       │
       ▼
  Observer (e.g. ProductObserver)
       │
       ▼
  LogActivityJob::dispatch(...)    ← Queue: 'medium'
       │
       ▼
  [Queue Worker] (sync in testing)
       │
       ▼
  LogActivityJob::handle()
       │
       ├── Re-retrieves subject model from DB
       ├── Sets causer explicitly via ActivityLogger::causerBy()
       └── Calls activity()->log()
```

For role changes specifically:

```
$user->assignRole($role)
       │
       ▼
  UserRolesUpdated event
       │
       ▼
  LogUserRolesUpdated listener (ShouldQueue)
       │
       ▼
  LogActivityJob::dispatch(...)    ← Queue: 'medium'
```

---

## Dependencies

| Component | File |
|-----------|------|
| Controller | `packages/marvel/src/Http/Controllers/ActivityLogController.php` |
| Resource | `packages/marvel/src/Http/Resources/ActivityLogResource.php` |
| Route | `packages/marvel/src/Rest/Routes.php` (line 609) |
| Job | `app/Jobs/LogActivityJob.php` |
| Observers | `app/Observers/{Product,Category,Brand,Coupon,FlashSale,Promotion,Role,User}Observer.php` |
| Event | `app/Events/UserRolesUpdated.php` (uses `SerializesModels`) |
| Listener | `app/Listeners/LogUserRolesUpdated.php` (implements `ShouldQueue`) |
| Translations | `resources/lang/{en,ar}/activity.php` |
| Package | `spatie/laravel-activitylog` |
| Observer Registration | `app/Providers/EventServiceProvider.php` (`$observers` property) |
| Event Registration | `app/Providers/EventServiceProvider.php` (`$listen` property) |

---

## Log Name Reference

| Log Name | Observer | Events Emitted |
|----------|----------|----------------|
| `products` | `ProductObserver` | created, updated, deleted, restored, forceDeleted, statusChanged |
| `categories` | `CategoryObserver` | created, updated, deleted, statusChanged |
| `brands` | `BrandObserver` | created, updated, deleted, statusChanged |
| `coupons` | `CouponObserver` | created, updated, deleted, statusChanged |
| `flash_sales` | `FlashSaleObserver` | created, updated, deleted, restored, forceDeleted, statusChanged |
| `promotions` | `PromotionObserver` | created, updated, deleted, statusChanged |
| `roles` | `RoleObserver` | created, updated, deleted |
| `users` | `UserObserver` + `LogUserRolesUpdated` | created, updated, deleted, restored, forceDeleted, statusChanged, roleUpdated |
| `pickup_locations` | `PickupLocationObserver` | created, updated, deleted, statusChanged |

---

## Audit Fixes

### Fix AL-01: Missing activity log route in Routes.php

- **Date**: 2026-07-15
- **Issue**: The `GET /api/logs/activity` route was referenced in documentation but did not exist in `Routes.php`. The `ActivityLogController` existed but was orphaned — never wired to any route.
- **Root Cause**: Route registration was never completed when the Activity Log feature was implemented.
- **Files Modified**:
  - `packages/marvel/src/Rest/Routes.php` — Added `use Marvel\Http\Controllers\ActivityLogController` import and registered `Route::get('logs/activity', [ActivityLogController::class, 'index'])`.
- **Verification**: PHP syntax check passed. Route is now accessible at `GET /api/logs/activity`.

### Fix AL-02: Missing VIEW_ACTIVITY_LOG constant in Permission enum

- **Date**: 2026-07-15
- **Issue**: `ActivityLogController::__construct()` uses `Permission::VIEW_ACTIVITY_LOG` but the constant was not defined in `Marvel\Enums\Permission` — the enum only defined 5 constants. The permission string `view-activity-log` was correctly seeded to the database.
- **Root Cause**: The enum constant was never added when the permission was introduced.
- **Files Modified**:
  - `packages/marvel/src/Enums/Permission.php` — Added `public const VIEW_ACTIVITY_LOG = 'view-activity-log'`.
- **Verification**: PHP syntax check passed. Constant now resolves to the seeded permission string.

### Fix AL-03: Missing `subject_type` filter in ActivityLogController

- **Date**: 2026-07-15
- **Issue**: Documentation listed `subject_type` as a query parameter but the controller did not implement it.
- **Root Cause**: Filter was documented but never coded.
- **Files Modified**:
  - `packages/marvel/src/Http/Controllers/ActivityLogController.php` — Added `subject_type` filter before `causer_id` filter.
- **Verification**: PHP syntax check passed.

### Fix AL-04: Promotion-specific events documented but not implemented

- **Date**: 2026-07-15
- **Issue**: `products_synced`, `categories_updated`, `brands_updated` events were documented as emitted by `PromotionObserver` but no code dispatches them. Translation keys exist but are never referenced in any PHP logic. Categories and brands are not synced to promotions (no pivot tables or relationships exist).
- **Root Cause**: Events were planned but implementation was deferred.
- **Files Modified**:
  - `docs/cms-endpoints/activity-logs.md` — Removed `products_synced`, `categories_updated`, `brands_updated` from the "Events Emitted" table. Replaced the Property Shape section with a note that these events are planned for future implementation.
- **Verification**: Documentation now accurately reflects current implementation.

### Fix AL-05: Incorrect coupon event names in documentation

- **Date**: 2026-07-15
- **Issue**: Documentation listed `enabled`, `disabled` as event names for coupons, but `CouponObserver` dispatches `statusChanged` (consistent with all other observers). The description text uses `coupon_enabled`/`coupon_disabled` keys but the event is `statusChanged`.
- **Root Cause**: Documentation conflated descriptive text with event name.
- **Files Modified**:
  - `docs/cms-endpoints/activity-logs.md` — Changed `enabled, disabled` to `statusChanged` in the Log Name Reference table.
- **Verification**: Matches actual `CouponObserver::updated()` event dispatch.

### Fix AL-06: Incorrect route line reference

- **Date**: 2026-07-15
- **Issue**: Dependencies table referenced line 724 in Routes.php. File is 638 lines.
- **Root Cause**: Line numbers shifted as other routes were added/removed.
- **Files Modified**:
  - `docs/cms-endpoints/activity-logs.md` — Updated route reference to line 609.
- **Verification**: Route was added at line 609 in the SUPER_ADMIN group.

### Fix AL-07: Incorrect route group authorization in documentation

- **Date**: 2026-07-16
- **Issue**: Documentation stated the route requires `super_admin` route middleware, but route-level permissions are intentionally not used per project architecture. The controller is the sole authorization source.
- **Root Cause**: Documentation incorrectly described an additional authorization layer that doesn't exist.
- **Files Modified**:
  - `docs/cms-endpoints/activity-logs.md` — Updated Authentication section to state that controller middleware is the sole authorization layer.
- **Verification**: Documentation now accurately reflects the single controller-level authorization.

### Fix AL-08: Test prefix mismatch and assertion bug

- **Date**: 2026-07-15
- **Issue**: Test used `/api/v1/` prefix but actual route is at `/api/` (no `v1` prefix group exists in the route configuration). Test also asserted `meta.total = 2` but only 1 Activity entry is created.
- **Root Cause**: Test was written for a different route prefix convention. The `meta.total = 2` assertion was a pre-existing bug.
- **Files Modified**:
  - `tests/Feature/ActivityLogApiTest.php` — Changed `PREFIX` from `/api/v1` to `/api`. Changed `meta.total` assertion from `2` to `1`.
- **Verification**: Test now uses correct URL prefix matching the actual route registration.

### Fix AL-09: Observers unregistered — entire activity logging system non-functional

- **Date**: 2026-07-16
- **Issue**: All 9 observers (`ProductObserver`, `CategoryObserver`, `BrandObserver`, `CouponObserver`, `FlashSaleObserver`, `PromotionObserver`, `RoleObserver`, `UserObserver`, `PickupLocationObserver`) existed as classes but were never registered. No `Model::observe()` calls existed anywhere in the codebase. Similarly, `UserRolesUpdated` event was never dispatched and `LogUserRolesUpdated` listener was never registered in any EventServiceProvider. `ActivityLogService` was dead code.
- **Root Cause**: Observers were created during development but observer registration (`EventServiceProvider::$observers`) was never configured. The event/listener pair for role changes was never wired into `EventServiceProvider::$listen`. The old `ActivityLogService` was replaced by `LogActivityJob` but never removed.
- **Files Modified**:
  - `app/Providers/EventServiceProvider.php` — Added `$observers` property with all 9 observer registrations. Added `UserRolesUpdated::class => [LogUserRolesUpdated::class]` to `$listen`.
  - `packages/marvel/src/Http/Controllers/RoleAndPermissionController.php` — Added `UserRolesUpdated::dispatch()` in `assignRole()` and `removeRoleFromUser()`.
  - `packages/marvel/src/Http/Controllers/UserController.php` — Added `UserRolesUpdated::dispatch()` in `makeOrRevokeAdmin()`.
  - `app/Services/ActivityLog/ActivityLogService.php` — **Deleted** (dead code, replaced by `LogActivityJob`).
- **Database Changes**: None.
- **Route Changes**: None.
- **API Changes**: None (backward-compatible).
- **Architecture Changes**: None (registration was missing, now added).
- **Test Changes**: None required (existing tests pass).
- **Documentation Changes**: Removed `ActivityLogService` dead-code note. Added observer registration and event registration to dependencies table. Added `PickupLocationObserver` to Log Name Reference. Added this audit history entry.
- **Breaking Changes**: None.
- **Legacy Dependency Reported**: `ShopController::addStaff()` (legacy Marvel shop/staff logic) was intentionally excluded from `UserRolesUpdated` dispatch per Project Constitution. Role changes via `addStaff()` will not be logged until the shop module is removed or migrated to active architecture.
- **Final Verification**: All 9 observers now registered via `EventServiceProvider::$observers`. `UserRolesUpdated` event dispatched from 3 active-architecture role-change entry points. `LogUserRolesUpdated` listener registered in `EventServiceProvider::$listen`. `ActivityLogService` removed. Activity logging system is now fully functional for active architecture.
