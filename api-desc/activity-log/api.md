# API Documentation - Activity Log Feature

## Endpoint

---

### 1. List Activity Logs

**GET** `/api/v1/logs/activity`

**Purpose:** Retrieve paginated activity log entries with filtering. Provides an audit trail for all entity changes and order/payment events.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `view-activity-log` |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `log_name` | `string` | No | Filter by entity type (e.g., `users`, `products`, `orders`, `categories`, `brands`, `coupons`, `flash_sales`, `promotions`, `roles`, `pickup_locations`) |
| `event` | `string` | No | Filter by event (e.g., `created`, `updated`, `deleted`, `order_created`, `order_cancelled`, `order_status_changed`, `payment_succeeded`) |
| `causer_id` | `integer` | No | Filter by user who performed the action |
| `search` | `string` | No | Search in `description` and `log_name` fields (LIKE) |
| `per_page` | `integer` | No | Results per page (default: 15) |

#### Success Response (200)

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
            "subject_id": 1,
            "causer_type": "Marvel\\Database\\Models\\User",
            "causer_id": 1,
            "properties": {
                "product_name": "Wireless Headphones"
            },
            "created_at": "2026-07-20T10:00:00.000000Z",
            "updated_at": "2026-07-20T10:00:00.000000Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 3,
        "per_page": 15,
        "total": 35
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-activity-log` permission |

---

## Resource Structure

### ActivityLogResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | `integer` | Primary key |
| `log_name` | `string` | Entity category (users, products, orders, etc.) |
| `description` | `string` | Human-readable description |
| `event` | `string` | Event type |
| `subject_type` | `string` | Fully qualified model class |
| `subject_id` | `integer` | Model ID |
| `causer_type` | `string` | Actor model class (always User) |
| `causer_id` | `integer` | Actor user ID |
| `properties` | `object` | Arbitrary metadata payload |
| `created_at` | `string` | ISO 8601 timestamp |
| `updated_at` | `string` | ISO 8601 timestamp |

## Business Rules

1. **Retention:** Logs older than 60 days are automatically deletable (configurable via `ACTIVITY_LOGGER_ENABLED`)
2. **Exclusions:** GET requests are excluded from logging to prevent recursive logging of the activity endpoint itself
3. **Soft Deletes:** Subject can return soft-deleted models (`config: subject_returns_soft_deleted_models = true`)
4. **Queue:** All logging is dispatched to the `medium` queue for async processing
5. **Silent Failure:** If the subject entity is deleted before the job processes, no log is created
6. **Auth Context:** `Auth::id()` determines the causer; null for unauthenticated actions (console, queue workers)
