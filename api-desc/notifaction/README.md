# Notification Feature - API Investigation

## Feature Name

Admin Notification Management

## Description

6 endpoints under `/api/v1/admin/notifications` for managing Laravel's `DatabaseNotification` model. Uses Laravel's built-in notification system — the `notifications` table stores polymorphic notifications. Scoped to the authenticated user's own notifications only. Protected by `auth:sanctum` and Spatie permissions.

## Architecture

```
[Admin Client]
    |
    |--- GET    /admin/notifications                  (VIEW_NOTIFICATIONS)
    |--- GET    /admin/notifications/unread           (VIEW_NOTIFICATIONS)
    |--- PATCH  /admin/notifications/{id}/read        (MANAGE_NOTIFICATIONS)
    |--- PATCH  /admin/notifications/read-all         (MANAGE_NOTIFICATIONS)
    |--- DELETE /admin/notifications/{id}             (MANAGE_NOTIFICATIONS)
    |--- DELETE /admin/notifications                  (MANAGE_NOTIFICATIONS)
    |
    v
[NotificationController]
    |--- auth:sanctum → permission
    |--- Uses Laravel's DatabaseNotification (polymorphic, user-scoped)
    |
    v
[notifications table (Laravel)]
    |--- uuid id, type, notifiable_type, notifiable_id, data, read_at
    |
    v
[Event-Driven Creation]
    |--- OrderCreated       → NewOrderNotification
    |--- ContactMessageReceived → NewContactMessageNotification
    |--- AdminLoggedIn       → AdminLoggedInNotification
```

## Key Endpoints

| Method | URI | Controller Method | Permission |
|--------|-----|-------------------|------------|
| GET | `/admin/notifications` | `index` | VIEW_NOTIFICATIONS |
| GET | `/admin/notifications/unread` | `unread` | VIEW_NOTIFICATIONS |
| PATCH | `/admin/notifications/{id}/read` | `markAsRead` | MANAGE_NOTIFICATIONS |
| PATCH | `/admin/notifications/read-all` | `markAllAsRead` | MANAGE_NOTIFICATIONS |
| DELETE | `/admin/notifications/{id}` | `destroy` | MANAGE_NOTIFICATIONS |
| DELETE | `/admin/notifications` | `destroyAll` | MANAGE_NOTIFICATIONS |

## Key Files

| Layer | Path |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/NotificationController.php` |
| Enum (Permission) | `packages/marvel/src/Enums/Permission.php` |
| Enum (UserType) | `app/Enums/UserType.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 248–255) |
| Test | `tests/Feature/NotificationTest.php` (845 lines, 38 tests) |

## Tech Stack

- **Laravel** `DatabaseNotification` (polymorphic, UUID-keyed)
- **Sanctum** authentication
- **Spatie permissions** — `VIEW_NOTIFICATIONS`, `MANAGE_NOTIFICATIONS`
- **Event-driven creation** — OrderCreated, ContactMessageReceived, AdminLoggedIn
