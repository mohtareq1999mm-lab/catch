# Activity Log Feature - API Investigation

## Feature Name

Activity Log (Audit Trail)

## Description

The Activity Log feature provides a comprehensive audit trail for all entity changes across the system. It uses `spatie/laravel-activitylog` with a queued `LogActivityJob` dispatched from Eloquent Observers (9 entities) and Event Listeners (6 order/payment events). The read endpoint supports filtering by log name, event type, causer, and search.

## Architecture Overview

```
[User Action]
    |
    ├── Eloquent Observer Path:
    |     Model → Observer → LogActivityJob → activity_log table
    |     (Users, Products, Categories, Brands, Coupons,
    |      FlashSales, Promotions, Roles, PickupLocations)
    |
    └── Event Listener Path:
          Event → Listener → LogActivityJob → activity_log table
          (OrderCreated, OrderCancelled, OrderStatusChanged,
           PaymentSucceeded, PaymentFailed, UserRolesUpdated)
    |
    v
[ActivityLogController] ← GET /api/v1/logs/activity
    |-- permission: view-activity-log
    |-- Filters: log_name, event, causer_id, search
    |-- Paginated
    v
[ActivityLogResource]
    v
[JSON Response]
```

## Key Endpoint

**GET** `/api/v1/logs/activity`

| Aspect | Detail |
|--------|--------|
| Method | GET |
| Guard | `sanctum` |
| Permission | `view-activity-log` |

### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `log_name` | `string` | Filter by entity type (users, products, orders, etc.) |
| `event` | `string` | Filter by event (created, updated, deleted, etc.) |
| `causer_id` | `integer` | Filter by user who performed the action |
| `search` | `string` | Search in description and log_name (LIKE) |
| `per_page` | `integer` | Pagination size (default 15) |

### Success Response (200)

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
            "subject_id": 1,
            "causer_id": 1,
            "properties": {"product_name": "Sample Product"},
            "created_at": "2026-07-20T10:00:00.000000Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 10,
        "last_page": 1
    }
}
```

### Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-activity-log` permission |

## Key Files

| Layer | Path |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/ActivityLogController.php` |
| Resource | `packages/marvel/src/Http/Resources/ActivityLogResource.php` |
| Job | `app/Jobs/LogActivityJob.php` |
| Config | `config/activitylog.php` |
| Permission | `packages/marvel/src/Enums/Permission.php` (VIEW_ACTIVITY_LOG) |
| Translation (EN) | `resources/lang/en/activity.php` |
| Translation (AR) | `resources/lang/ar/activity.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` |
| Test | `tests/Feature/ActivityLogApiTest.php` |
| Test | `tests/Feature/EventSystemTest.php` |
| Seeder | `database/seeders/ActivityLogSeeder.php` |

### Observers (9)

| Observer | Entities | Events Logged |
|----------|----------|---------------|
| `UserObserver` | User | created, updated, statusChanged, deleted, restored, forceDeleted |
| `ProductObserver` | Product | created, updated, statusChanged, deleted, restored, forceDeleted |
| `CategoryObserver` | Category | created, updated, statusChanged, deleted |
| `BrandObserver` | Brand | created, updated, statusChanged, deleted |
| `CouponObserver` | Coupon | created, updated, statusChanged, deleted |
| `FlashSaleObserver` | FlashSale | created, updated, statusChanged, deleted, restored, forceDeleted |
| `PromotionObserver` | Promotion | created, updated, statusChanged, deleted |
| `RoleObserver` | Role | created, updated, deleted |
| `PickupLocationObserver` | PickupLocation | created, updated, statusChanged, deleted |

### Log Names

| Log Name | Source | Events |
|----------|--------|--------|
| `users` | UserObserver | created, updated, statusChanged, deleted, restored, forceDeleted, roleUpdated |
| `products` | ProductObserver | created, updated, statusChanged, deleted, restored, forceDeleted |
| `categories` | CategoryObserver | created, updated, statusChanged, deleted |
| `brands` | BrandObserver | created, updated, statusChanged, deleted |
| `coupons` | CouponObserver | created, updated, statusChanged, deleted |
| `flash_sales` | FlashSaleObserver | created, updated, statusChanged, deleted, restored, forceDeleted |
| `promotions` | PromotionObserver | created, updated, statusChanged, deleted |
| `roles` | RoleObserver | created, updated, deleted |
| `pickup_locations` | PickupLocationObserver | created, updated, statusChanged, deleted |
| `orders` | Event Listeners | order_created, order_cancelled, order_status_changed, payment_succeeded, payment_failed |

## Database

### `activity_log` Table

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `log_name` | `varchar(255)` | NULLABLE, INDEXED |
| `description` | `text` | NOT NULL |
| `subject_type` | `varchar(255)` | NULLABLE (morphs) |
| `subject_id` | `bigint unsigned` | NULLABLE (morphs) |
| `causer_type` | `varchar(255)` | NULLABLE (morphs) |
| `causer_id` | `bigint unsigned` | NULLABLE (morphs) |
| `event` | `varchar(255)` | NULLABLE |
| `properties` | `json` | NULLABLE |
| `batch_uuid` | `char(36)` | NULLABLE |
| `created_at` | `timestamp` | NULLABLE |
| `updated_at` | `timestamp` | NULLABLE |

## Tech Stack

- **spatie/laravel-activitylog** package
- **Queued Job** (`LogActivityJob` on `medium` queue)
- **Eloquent Observers** for entity changes
- **Event → Listener** chain for order/payment events
- **Spatie Permission** for authorization
- **Sanctum** for authentication
- **Config**: 60-day retention, GET excluded from logging, subject returns soft-deleted
