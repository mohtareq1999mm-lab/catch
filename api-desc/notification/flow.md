# Data Flow - Notification Feature (Phase 1 / 2 / 3)

## Flow: Event → Listener → Notification → Delivery

```
[Domain Event]  e.g. ProductPriceDrop($product, $oldPrice, $newPrice)
    |
    v
[Queued Listener]  SendUserProductPriceDropNotification  (implements ShouldQueue)
    |   $this->queue = 'meem-medium'
    |   receives ($event)
    |   builds UserProductPriceDropNotification($product, $oldPrice, $newPrice)
    |
    v
[Notify Recipient(s)]
    |   Phase 3 wishlist fan-out: NotifyWishlistUsersOfProduct action
    |     - queries wishlists where product_id = $product->id
    |     - for each user: $user->notify(clone $notification)   (excludes admins)
    |   Phase 1/2: $user->notify(...) on the order/coupon/promotion owner
    |
    v
[Notification::via()] = ['database', 'broadcast']
    |
    +---> DatabaseChannel::send()
    |        buildPayload():
    |          type = databaseType($notifiable)   // == broadcastType() == 'price.drop'
    |          data = toDatabase($notifiable)
    |        -> insert into notifications
    |
    +---> BroadcastChannel::send()
             new BroadcastNotificationCreated($notifiable, $notification, $data)
             -> dispatched (meem-medium)
             -> Pusher: channel private-users.{id}
                event   Illuminate\Notifications\Events\BroadcastNotificationCreated
                payload type = 'price.drop'
```

## Flow: List Notifications (REST)

```
User Client
  |
  GET /api/v1/notifications?page=1
  Authorization: Bearer <token>
  |
  v
auth:sanctum middleware
  |
  v
NotificationController@index($request)
  |
  +-- $user->notifications()           // morphMany relation
  +-- ->latest()                        // ORDER BY created_at DESC
  +-- ->paginate(15)
  |
  +-- map through formatNotification()  // resolve title/message by request locale
  |
  v
JSON Response (data + meta)
```

## Flow: Realtime Receive

```
Frontend (Laravel Echo)
  |
  echo.private(`users.${userId}`)
    .listen('.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated', e => {
        // e.type === 'price.drop'
        // e.title / e.message are {en,ar} -> pick by locale
        // e.action_url -> deep link
    })
  |
  v
Pusher auth -> routes/channels.php  Broadcast::channel('users.{id}', ...)
```

## Flow: Mark as Read

```
PATCH /api/v1/notifications/{id}/read
  -> $user->notifications()->findOrFail($id)->markAsRead()
  -> sets read_at, returns updated notification
```
