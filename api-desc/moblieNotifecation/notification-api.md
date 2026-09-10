# Notification API — Customer / Authenticated User

All endpoints require `auth:sanctum` (Bearer token). Every notification row belongs to the authenticated user — no cross-user access.

Payload = the authoritative `toDatabase()` structure stored in `notifications.data`, e.g.:

```json
{
    "title": { "en": "Order Created", "ar": "تم إنشاء الطلب" },
    "message": { "en": "…ORD-2026-000001", "ar": "…" },
    "type": "order-created",
    "resource_type": "order",
    "resource_id": 42,
    "action_url": "/orders/42",
    "icon": "shopping-cart",
    …business extras
}
```

`id`, `read_at`, `created_at` come from the notifications table itself.

---

## 1. GET /api/v1/notifications — List

**Query:** `per_page` (default 15)

**Response 200:**
```json
{
  "status": 200, "message": "Notifications fetched successfully", "success": true,
  "data": [
    {
      "id": "0e2f1c9a-…",
      "type": "order-created",
      "title": {"en":"Order Created","ar":"تم إنشاء الطلب"},
      "message":{"en":"…","ar":"…"},
      "resource_type":"order","resource_id":42,
      "action_url":"/orders/42","icon":"shopping-cart",
      "read_at": null,
      "created_at":"2026-08-23T10:00:00+00:00"
    }
  ],
  "meta": { "current_page":1,"per_page":15,"total":8,"last_page":1,"from":1,"to":8 }
}
```

---

## 2. GET /api/v1/notifications/unread — Unread Only

Same item shape; `data` is an unpaginated array and `meta.total` = unread count.

**Response 200:** `{ status:200, message:"Unread notifications fetched successfully", success:true, data:[…], meta:{ total: n } }`

---

## 3. GET /api/v1/notifications/{id} — Show One

**Response 200:** envelope with a single formatted item (as above).
**404** if id unknown or belongs to another user (`findOrFail` on user scope).

---

## 4. PATCH /api/v1/notifications/{id}/read — Mark Read

Idempotent: already-read returns current state unchanged.

**Response 200:** envelope with the formatted item (`read_at` now filled).

**404** unknown/foreign id.

---

## 5. POST /api/v1/notifications/read-all — Mark All Read

**Response 200:** standard success envelope, no data payload.

---

## 6. DELETE /api/v1/notifications/{id} — Delete One

**Response 200:** success envelope. **404** unknown/foreign.

---

## Admin equivalents (separate surface)

| Endpoint | Permission |
|---|---|
| GET `/api/v1/admin/notifications`, `/unread` | `view-notifications` |
| PATCH `/admin/notifications/{id}/read`, POST `/read-all` | `manage-notifications` |
| DELETE `/admin/notifications/{id}` , `/` (all) | `manage-notifications` |

---

## FCM relationship

Push delivery mirrors these payloads via the `fcm` channel (see [../moblieNotifecation/api.md](../moblieNotifecation/api.md)). Native title/body are locale-resolved strings from the same maps shown here; all other keys arrive as FCM `data`. Reading/marking read stays API-side — opening a push does not mutate `read_at`.

---

# FIREBASE CONNECTION FOR MOBILE

## A. Register the device (once per login / token rotation)

**POST** `/api/v1/general/device-tokens`

```json
{
    "token": "<FCM_REGISTRATION_TOKEN>",
    "client": "client_a",          // client_a | client_b  → selects Firebase project
    "platform": "android"          // android | ios
}
```

| Response | Meaning |
|---|---|
| `200 {"message":"Device token registered", data:{uuid}}` | stored/upserted (same token re-registered by another user is reassigned) |
| `422` | validation (`client` must be client_a/client_b; no default) |
| `401` | missing/expired Sanctum token |

Call this **after every login** and whenever Firebase rotates the token. One user may register multiple devices.

## B. Remove on logout

**DELETE** `/api/v1/general/device-tokens` with `{ "token": "…" }` → deletes **only** your own row.

## C. What you receive

Once registered, every notification sent to your user triggers:

```
Laravel Notification → fcm channel → job (queue meem-medium)
   → your client's Firebase project → FCM → device tray
```

- Native `title` / `body`: pre-localized plain strings (backend resolves en/ar).
- `data`: stable identifiers from the payload table above (`type`, `resource_type`, `resource_id`, …) for deep-linking.

## D. Opening invoices from a push

Pushes never contain file URLs. Fetch fresh signed links from
`GET /general/invoices/my-invoices` (or order-invoice detail) and open
`view_url` / `download_url` directly — they are valid **10 minutes**, no Authorization header needed.
A `403 {"message":"Invalid signature"}` simply means expired → refetch.

## E. Delivery guarantees

| Case | Behavior |
|---|---|
| Invalid/expired FCM token | detected by Firebase response → row auto-deleted |
| Transient Firebase error | logged; retried by queue (tries 3); other tokens unaffected |
| Other user's invoice/token | privacy **404** everywhere |
| Business failure (payment/order rollback) | push is async — never blocks or rolls back business operations |