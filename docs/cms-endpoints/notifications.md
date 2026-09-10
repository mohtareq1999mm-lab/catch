# Notifications API

## Overview

The Notifications module provides real-time and database-backed notifications for admin users. Notifications are created automatically via events:
- **New Order** — when an order is placed (`OrderCreated` event)
- **New Contact Message** — when a contact form is submitted (`ContactMessageReceived` event)
- **Admin Login** — when an admin logs in (`AdminLoggedIn` event)

All notifications are persisted to the `notifications` table and broadcast via Pusher to the `private-admin.notifications` channel for real-time delivery.

---

## Authentication

| Guard | Required |
|-------|----------|
| `sanctum` | ✓ |

| Middleware | Scope |
|-----------|-------|
| `admin` | All endpoints — user must have `type = 'admin'` |
| `permission:view-notifications` | `index`, `unread` |
| `permission:manage-notifications` | `markAsRead`, `markAllAsRead`, `destroy`, `destroyAll` |

---

## Endpoints

### GET `/api/v1/admin/notifications`

Fetch paginated notifications for the authenticated admin.

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `per_page` | integer | No | 15 | Number of results per page |

#### Success Response (200)

```json
{
    "success": true,
    "message": "Notifications fetched successfully.",
    "data": [
        {
            "id": "uuid",
            "type": "App\\Notifications\\NewOrderNotification",
            "title": "New Order",
            "message": "New Order #ORD-00000001 has been placed.",
            "icon": "shopping-cart",
            "resource_type": "order",
            "resource_id": 1,
            "action_url": "/admin/orders/1",
            "created_at": "2026-07-05T10:30:00+00:00",
            "read_at": null
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

---

### GET `/api/v1/admin/notifications/unread`

Fetch only unread notifications for the authenticated admin.

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `per_page` | integer | No | 15 | Number of results per page |

#### Success Response (200)

```json
{
    "success": true,
    "message": "Unread notifications fetched successfully.",
    "data": [ ... ],
    "meta": { "total": 5 }
}
```

---

### PATCH `/api/v1/admin/notifications/{id}/read`

Mark a single notification as read.

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | uuid | ✓ | Notification ID |

#### Success Response (200)

```json
{
    "success": true,
    "message": "Notification marked as read.",
    "data": {
        "id": "uuid",
        "type": "App\\Notifications\\NewOrderNotification",
        "title": "New Order",
        "message": "New Order #ORD-00000001 has been placed.",
        "icon": "shopping-cart",
        "resource_type": "order",
        "resource_id": 1,
        "action_url": "/admin/orders/1",
        "created_at": "2026-07-05T10:30:00+00:00",
        "read_at": "2026-07-05T12:00:00+00:00"
    }
}
```

#### Error Responses

| Status | Description |
|--------|-------------|
| 404 | Notification not found |

---

### PATCH `/api/v1/admin/notifications/read-all`

Mark all unread notifications as read for the authenticated admin.

#### Success Response (200)

```json
{
    "success": true,
    "message": "All notifications marked as read.",
    "data": {
        "marked_count": 3
    }
}
```

---

### DELETE `/api/v1/admin/notifications/{id}`

Delete a single notification.

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | uuid | ✓ | Notification ID |

#### Success Response (200)

```json
{
    "success": true,
    "message": "Notification deleted."
}
```

#### Error Responses

| Status | Description |
|--------|-------------|
| 404 | Notification not found |

---

### DELETE `/api/v1/admin/notifications`

Delete all notifications for the authenticated admin.

#### Success Response (200)

```json
{
    "success": true,
    "message": "All notifications deleted.",
    "data": {
        "deleted_count": 5
    }
}
```

---

## Real-Time Broadcasting

All notifications are broadcast to the `private-admin.notifications` Pusher channel.

| Notification | Broadcast Type | Icon | Resource Type |
|-------------|---------------|------|---------------|
| NewOrderNotification | `order.created` | `shopping-cart` | `order` |
| NewContactMessageNotification | `contact.message` | `mail` | `contact` |
| AdminLoggedInNotification | `admin.login` | `log-in` | `admin` |

### Client-Side Subscription (Laravel Echo)

```js
Echo.private('admin.notifications')
    .listen('order.created', (e) => { ... })
    .listen('contact.message', (e) => { ... })
    .listen('admin.login', (e) => { ... });
```

---

## Channel Authorization

Defined in `routes/channels.php`:

```php
Broadcast::channel('admin.notifications', function ($user) {
    return $user && $user->type === 'admin';
});
```

Only users with `type = 'admin'` can subscribe to the private channel.

---

## Database Tables

| Table | Description |
|-------|-------------|
| `notifications` | Stores all notifications (Laravel's built-in schema) |

### notifications Table Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | uuid (PK) | Unique notification ID |
| `type` | string | Notification class name |
| `notifiable_type` | string | Morphs: notifiable model class |
| `notifiable_id` | bigint | Morphs: notifiable model ID |
| `data` | text (JSON) | Notification payload |
| `read_at` | timestamp, nullable | When the notification was read |
| `created_at` | timestamp | When the notification was created |
| `updated_at` | timestamp | Last updated timestamp |

---

## Dependencies

| Component | File |
|-----------|------|
| Controller | `app/Http/Controllers/Api/NotificationController.php` |
| Routes | `routes/notifications.php` |
| Middleware | `app/Http/Middleware/AdminMiddleware.php` |
| Events | `app/Events/OrderCreated.php`, `app/Events/ContactMessageReceived.php`, `app/Events/AdminLoggedIn.php` |
| Listeners | `app/Listeners/SendNewOrderNotification.php`, `app/Listeners/SendContactMessageNotification.php`, `app/Listeners/SendAdminLoginNotification.php` |
| Notifications | `app/Notifications/NewOrderNotification.php`, `app/Notifications/NewContactMessageNotification.php`, `app/Notifications/AdminLoggedInNotification.php` |
| Permissions | `Marvel\Enums\Permission::VIEW_NOTIFICATIONS`, `Marvel\Enums\Permission::MANAGE_NOTIFICATIONS` |
| Translations | `resources/lang/en/message.php` |
| Channel Auth | `routes/channels.php` |
| Migration | `database/migrations/2026_07_05_080106_create_notifications_table.php` |

---

## Test Coverage

| File | Tests |
|------|-------|
| `tests/Feature/NotificationTest.php` | 38 tests covering auth, permissions, CRUD, events |
