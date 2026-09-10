# Data Flow - Notification Feature

## Flow: List Notifications

```
Admin Client
  |
  GET /api/v1/admin/notifications?per_page=15
  Authorization: Bearer <token>
  |
  v
auth:sanctum middleware
  |
  v
permission:VIEW_NOTIFICATIONS middleware
  |
  v
NotificationController@index($request)
  |
  +-- $user->notifications()           // morphMany relation
  +-- ->latest()                        // ORDER BY created_at DESC
  +-- ->paginate(15)
  |
  +-- map through formatNotification()  // extract data JSON → typed fields
  |
  v
JSON Response (data + meta)
```

## Flow: Event-Driven Notification Creation

```
[Order Created]
OrderCreated::dispatch($order)
    |
    v
[Listener: NewOrderNotification]
    |  -- Query all admin users
    |  -- For each admin:
    |        Notification::create([
    |            'id' => Str::uuid(),
    |            'type' => NewOrderNotification::class,
    |            'notifiable_id' => $admin->id,
    |            'notifiable_type' => User::class,
    |            'data' => [
    |                'title' => 'New Order',
    |                'message' => "...",
    |                'resource_type' => 'order',
    |                'resource_id' => $order->id,
    |            ]
    |        ])
```

## Flow: Mark All as Read

```
NotificationController@markAllAsRead($request)
    |
    +-- $count = $user->unreadNotifications()->count()
    +-- $user->unreadNotifications()->update(['read_at' => now()])
    |
    v
JSON Response: { marked_count: $count }
```
