# API Documentation - Notification Feature

All endpoints under prefix `/api/v1/admin/notifications`.

## 1. List Notifications

**GET** `/api/v1/admin/notifications`

**Query:** `per_page` (default 15)

User-scoped — returns only the authenticated user's notifications. Ordered by `latest()`.

```json
{
    "status": 200,
    "success": true,
    "message": "Notifications fetched successfully.",
    "data": {
        "data": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440000",
                "type": "App\\Notifications\\NewOrderNotification",
                "title": "New Order",
                "message": "A new order has been placed.",
                "icon": "bell",
                "resource_type": "order",
                "resource_id": 42,
                "action_url": "/admin/orders/42",
                "created_at": "2026-07-20T10:30:00+00:00",
                "read_at": null
            }
        ],
        "meta": {
            "current_page": 1,
            "per_page": 15,
            "total": 25,
            "last_page": 2,
            "from": 1,
            "to": 15
        }
    }
}
```

## 2. List Unread Notifications

**GET** `/api/v1/admin/notifications/unread`

No pagination — returns all unread. Only `total` in meta.

```json
{
    "data": {
        "data": [...],
        "meta": { "total": 5 }
    }
}
```

## 3. Mark as Read

**PATCH** `/api/v1/admin/notifications/{id}/read`

Idempotent — no-op if already read. Returns the notification resource with `read_at` populated.

## 4. Mark All as Read

**PATCH** `/api/v1/admin/notifications/read-all`

```json
{
    "data": { "marked_count": 3 }
}
```

## 5. Delete Single

**DELETE** `/api/v1/admin/notifications/{id}`

Hard deletes from DB.

## 6. Delete All

**DELETE** `/api/v1/admin/notifications`

User-scoped — deletes only authenticated user's notifications.

```json
{
    "data": { "deleted_count": 10 }
}
```

## Business Rules

1. **User-scoped:** All operations scoped to `$user->notifications()` or `$user->unreadNotifications()`
2. **Permissions:** `view-notifications` for reads, `manage-notifications` for writes (mark/delete)
3. **Notification format:** Custom `formatNotification()` extracts `title`, `message`, `icon`, `resource_type`, `resource_id`, `action_url` from the `data` JSON column
4. **Events trigger creation:** Notifications are created by event listeners, not via this API
