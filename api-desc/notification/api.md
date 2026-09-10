# API Documentation - Notification Feature (Phase 1 / 2 / 3)

All user-facing endpoints under prefix `/api/v1`. Auth: `auth:sanctum`.

> Admin variants also exist under `/api/v1/admin/notifications` (gated by
> `view-notifications` / `manage-notifications`); they share the same controller
> and response shape. The Phase 1/2/3 user notifications are delivered to the
> endpoints below, scoped to the authenticated user.

---

## 1. List Notifications

**GET** `/api/v1/notifications` — Query: `limit` (default 15), `page` (default 1).

```json
{
    "success": true,
    "message": "",
    "data": [
        {
            "id": "550e8400-e29b-41d4-a716-446655440000",
            "type": "price.drop",
            "title": "Price dropped!",
            "message": "iPhone 15 is now cheaper.",
            "icon": "price-drop",
            "resource_type": "product",
            "resource_id": 123,
            "action_url": "/products/iphone-15",
            "created_at": "2026-08-13T10:30:00+00:00",
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
```

## 2. Unread Notifications

**GET** `/api/v1/notifications/unread` — returns `data` array + `meta.total` (unread count).

## 3. Single Notification

**GET** `/api/v1/notifications/{id}` — full notification object (same shape as list item).

## 4. Mark as Read

**PATCH** `/api/v1/notifications/{id}/read` → 200, `read_at` populated.
Idempotent. 404 if not found / not owned.

## 5. Mark All as Read

**POST** `/api/v1/notifications/read-all` → 200, `{ "marked_count": N }`.

## 6. Delete

**DELETE** `/api/v1/notifications/{id}` → 200. User-scoped (cannot delete others').
**DELETE** `/api/v1/notifications` (admin) → deletes all own.

---

## Realtime (Pusher)

- **Channel:** `private-users.{userId}` (authorization in `routes/channels.php` → `users.{id}`).
- **Event:** `Illuminate\Notifications\Events\BroadcastNotificationCreated`.
- **Payload** (mirrors `toDatabase()`; `title`/`message` are `{en,ar}` objects here):

```json
{
    "id": "550e8400-...",
    "type": "price.drop",
    "title": { "en": "Price dropped!", "ar": "انخفض السعر!" },
    "message": { "en": "...", "ar": "..." },
    "icon": "price-drop",
    "resource_type": "product",
    "resource_id": 123,
    "action_url": "/products/iphone-15",
    "product_id": 123,
    "old_price": "1200.00",
    "new_price": "999.00"
}
```

> The REST `title`/`message` are resolved to a single locale string via the
> `lang` request header; the broadcast payload keeps `{en,ar}` for client-side
> localization. `type` is identical (business id) in both channels.

## Quick Test

```bash
curl -X GET "http://example.com/api/v1/notifications?page=1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -H "lang: en"
```
