# Notification Feature - API Investigation (Phase 1 / 2 / 3)

## Feature Name

User-Facing Notification System (Order / Payment / Coupon / Promotion / Flash Sale / Price / Stock / Review / Cart).

## Description

A unified notification program that informs **end users** (not admins) about
lifecycle and promotional events. Notifications are stored in Laravel's native
`notifications` table and delivered two ways: (1) persisted/listed via the
generic REST API under `/api/v1/notifications`, and (2) pushed in real time via
Pusher on the private channel `private-users.{id}`.

The `type` column stores a **stable business identifier** (e.g. `price.drop`),
never a PHP class name.

## Architecture

```
[Domain Event]  e.g. ProductPriceDrop / OrderCreated / PromotionActivated
    |
    v
[Queued Listener]  e.g. SendUserProductPriceDropNotification  (queue: meem-medium)
    |
    v
[Notification]  e.g. UserProductPriceDropNotification (implements ShouldQueue)
    |  via(['database','broadcast'])
    +---> DatabaseChannel  -> notifications table (type = 'price.drop')
    +---> BroadcastChannel -> Pusher event
                              Illuminate\Notifications\Events\BroadcastNotificationCreated
                              channel: private-users.{id}
    |
    v
[Consumer]
    |--- GET    /api/v1/notifications                (auth:sanctum)
    |--- GET    /api/v1/notifications/unread
    |--- GET    /api/v1/notifications/{id}
    |--- PATCH  /api/v1/notifications/{id}/read
    |--- POST   /api/v1/notifications/read-all
    |--- DELETE /api/v1/notifications/{id}
    |--- Pusher subscribe private-users.{id}
```

## Key Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/notifications` | Paginated list (own only) |
| GET | `/api/v1/notifications/unread` | Unread list + count |
| GET | `/api/v1/notifications/{id}` | Single detail |
| PATCH | `/api/v1/notifications/{id}/read` | Mark read |
| POST | `/api/v1/notifications/read-all` | Mark all read |
| DELETE | `/api/v1/notifications/{id}` | Delete one |

## Notification `type` catalog

**Phase 1 (Order/Payment/Coupon):** `order.created`, `payment.succeeded`,
`payment.failed`, `order.delivered`, `order.cancelled`, `order.refunded`,
`coupon.assigned`, `coupon.available`, `coupon.used`.

**Phase 2 (Promotion/Flash Sale available):** `promotion.available`,
`flash_sale.available`.

**Phase 3 (Wishlist/Review/Price/Stock/Ending soon/Cart):** `review.approved`,
`review.rejected`, `discount.changed`, `price.drop`, `back.in.stock`,
`promotion.price.drop`, `flash_sale.price.drop`, `cart.abandoned`,
`promotion.ending_soon`, `flash_sale.ending_soon`.

See `api.md` (full response), `database.md` (storage), `frontend.md` (consumption).
