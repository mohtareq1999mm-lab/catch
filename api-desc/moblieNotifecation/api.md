# API Reference — Mobile Notification & Invoice Access

Envelope for JSON endpoints: `{ "status": int, "message": string, "success": bool, "data": … }`

---

## 1. POST /api/v1/general/device-tokens — Register FCM Token

**Auth:** `auth:sanctum` (Bearer token from login)

**Request Body:**
```json
{
    "token": "fY7…long-fcm-registration-token…x2Q",
    "client": "client_a",
    "platform": "android"
}
```

| Field | Rules |
|---|---|
| token | required, string, max 512 — the FCM registration token |
| client | required, in: **client_a, client_b** — selects the Firebase project |
| platform | optional, in: android, ios (default android) |

**Behavior:** upsert on unique `token` — same token re-registered by another user is **reassigned** to the new owner. One user may register many devices.

**Response 200:**
```json
{ "status": 200, "message": "Device token registered", "success": true,
  "data": { "uuid": "0f8f…", } }
```

**Errors:** 401 guest · 422 `{ "client": ["The selected client is invalid."] }`

---

## 2. DELETE /api/v1/general/device-tokens — Logout / Unregister

**Auth:** Sanctum

**Request Body:** `{ "token": "fY7…" }`

Deletes ONLY rows owned by the authenticated user; foreign tokens are silently untouched.

**Response 200:** `{ "status":200, "message":"Device token removed","success":true }`

---

## 3. GET /api/v1/general/invoices/my-invoices — My Invoices (lightweight)

**Auth:** Sanctum · Query: `limit` (default 15, max 100), `page`

Each item (no snapshot):
```json
{
    "uuid": "a2902eee-…",
    "invoice_number": "INV-2026-000001",
    "status": "ready",
    "subtotal": 210.0, "shipping_price": 0.0, "total_discount": 0.0,
    "total": 210.0, "currency": "USD",
    "payment_method": "pay_at_cashier", "payment_gateway": null,
    "generated_at": "2026-08-22T12:24:23+00:00",
    "pdf_generated_at": "2026-08-22T12:24:25+00:00",
    "verification_url": "https://…/api/v1/general/invoices/verify/a2902eee-…",
    "view_url":     "https://…/api/v1/general/invoices/view/a2902eee-…?expires=1787410926&signature=SIGNATURE",
    "download_url": "https://…/api/v1/general/invoices/download/a2902eee-…?expires=1787410926&signature=SIGNATURE"
}
```
Plus pagination block under `data.links`.

> Open `view_url` / `download_url` directly (browser/`url_launcher`). They embed a **10-minute signed signature — no Authorization header needed**. On `403 Invalid signature`, simply refetch this list for fresh links.

---

## 4. GET /general/invoices/view/{uuid}?expires&signature — View PDF (signed)

Returns the actual PDF **inline** (`Content-Type: application/pdf`, `Content-Disposition: inline; filename="INV-….pdf"`). No auth header, no JSON.

Errors: `403 Invalid signature` (expired/tampered) · `404 {"message":"PDF not yet generated", data:{status,…}}`.

---

## 5. GET /general/invoices/download/{uuid}?expires&signature — Download PDF (signed)

Same as view but `Content-Disposition: attachment`. Marks `downloaded_at` (first time) and records timeline event `downloaded`.

Errors identical to §4.

---

## 6. GET /api/v1/general/orders/{orderId}/invoice — Invoice Detail (JSON, canonical)

Owner-scoped full detail incl. `snapshot`; fresh signed `view_url`/`download_url`. 404 if foreign/missing/pending-no-invoice.

---

## 7. GET /api/v1/general/invoices/verify/{uuid} — Verify Authenticity

**Auth:** Sanctum · `throttle:5,1`

Response 200: `{ authentic:true, invoice:{…}, order:{…}, qr_content:"…" }` · 409 tampered · 404 unknown.

---

## Push notification payload (delivered via FCM)

Native keys:
```json
{ "title": "طلب جديد", "body": "تم إنشاء الطلب ORD-20260822-0042" }
```
Data payload (stable identifiers, never localized):
```json
{
    "type": "order-created",
    "resource_type": "order",
    "resource_id": "42",
    "order_number": "ORD-20260822-0042",
    "total_amount": "230.00",
    "payment_status": "pending",
    "order_status": "pending"
}
```
Exact business fields mirror each notification's database payload (`toDatabase()`) — one source of truth.