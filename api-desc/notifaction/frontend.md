# Frontend - Notification Feature

## Status

Admin SPA consumes these endpoints for the notification bell/dropdown and full notification page.

## Consumption

```javascript
export const notificationApi = {
  list(params)          // GET /api/v1/admin/notifications?per_page=
  unread()              // GET /api/v1/admin/notifications/unread
  markAsRead(id)        // PATCH /api/v1/admin/notifications/{id}/read
  markAllAsRead()       // PATCH /api/v1/admin/notifications/read-all
  destroy(id)           // DELETE /api/v1/admin/notifications/{id}
  destroyAll()          // DELETE /api/v1/admin/notifications
}
```

## Expected Frontend Components

```
NotificationBell.vue          → unread count + dropdown of recent unread
NotificationCenter.vue        → full list with pagination, mark as read, delete
NotificationToast.vue         → real-time notification popup (Pusher/WebSocket)
```
