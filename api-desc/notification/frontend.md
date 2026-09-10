# Frontend - Notification Feature (Phase 1 / 2 / 3)

## Status

End-user SPA consumes these endpoints for the notification bell/dropdown, full
notification center, and real-time toasts.

## Consumption (API client)

```javascript
export const notificationApi = {
  list(params)          // GET /api/v1/notifications?limit=&page=
  unread()              // GET /api/v1/notifications/unread
  show(id)              // GET /api/v1/notifications/{id}
  markAsRead(id)        // PATCH /api/v1/notifications/{id}/read
  markAllAsRead()       // POST /api/v1/notifications/read-all
  destroy(id)           // DELETE /api/v1/notifications/{id}
}
```

## Expected Frontend Components

```
NotificationBell.vue          → unread count + dropdown of recent unread
NotificationCenter.vue        → full list with pagination, mark as read, delete
NotificationToast.vue         → real-time popup (Pusher)
NotificationItem.vue          → renders by `type` (icon + action_url)
```

## Type → UI mapping

Switch on `type` (and `resource_type`) to pick icon + deep link:

| `type` | Icon | `action_url` target |
|--------|------|---------------------|
| `order.created` | shopping-cart | `/orders/{id}` |
| `payment.succeeded` | credit-card | `/orders/{id}` |
| `payment.failed` | credit-card | `/orders/{id}` |
| `order.delivered` | truck | `/orders/{id}` |
| `order.cancelled` | x-circle | `/orders/{id}` |
| `order.refunded` | refresh | `/refunds/{id}` |
| `coupon.assigned` | tag | `/coupons/{id}` |
| `coupon.available` | tag | `/coupons/{id}` |
| `coupon.used` | tag | `/coupons/{id}` |
| `promotion.available` | tag | `/promotions/{id}` |
| `flash_sale.available` | bolt | `/flash-sales/{id}` |
| `review.approved` | star | `/reviews/{id}` |
| `review.rejected` | star | `/reviews/{id}` |
| `discount.changed` | tag | `/products/{slug\|id}` |
| `price.drop` | price-drop | `/products/{slug\|id}` |
| `back.in.stock` | box | `/products/{slug\|id}` |
| `promotion.price.drop` | tag | `/promotions/{id}` |
| `flash_sale.price.drop` | bolt | `/flash-sales/{id}` |
| `cart.abandoned` | cart | `/cart` |
| `promotion.ending_soon` | hourglass | `/promotions/{id}` |
| `flash_sale.ending_soon` | hourglass | `/flash-sales/{id}` |

## Localization

- REST `title`/`message` are single strings resolved by the `lang` header
  (`en` / `ar`). Send `lang: ar` to get Arabic.
- Realtime payload `title`/`message` are `{en, ar}` objects — localize on the
  client by the active locale.

## Realtime setup (Laravel Echo)

```js
echo.private(`users.${userId}`)
  .listen('.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated', (e) => {
    store.dispatch('notifications/push', {
      id: e.id,
      type: e.type,
      title: e.title[locale] ?? e.title.en,
      message: e.message[locale] ?? e.message.en,
      icon: e.icon,
      resourceType: e.resource_type,
      resourceId: e.resource_id,
      actionUrl: e.action_url,
    });
  });
```

## Usage flow

1. On login: open `private-users.{userId}` subscription; push incoming items to store + bump badge.
2. On open/refresh: `GET /api/v1/notifications` to hydrate; `GET .../unread` to sync count.
3. Render by `type` map above.
4. On click: navigate to `action_url`, then `PATCH .../{id}/read`.
5. "Mark all read": `POST .../read-all`.
