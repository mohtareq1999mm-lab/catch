# Jira — Mobile / Frontend Tasks (Notifications & Invoices)

---

## FE-FCM-001: Firebase SDK setup per flavor
Client A app → Firebase Project A `google-services.json`/plist.
Client B app → Firebase Project B files. Never mix flavors.

## FE-FCM-002: Send registration token after login
```
POST /api/v1/general/device-tokens
Authorization: Bearer <token>
{ "token": "<FCM_TOKEN>", "client": "client_a|client_b", "platform": "android|ios" }
```
- Re-send whenever FCM token rotates (app start is fine — idempotent).
- Handle 401 → re-login flow.

## FE-FCM-003: Delete token on logout
```
DELETE /api/v1/general/device-tokens   { "token": "<FCM_TOKEN>" }
```
Call before clearing local credentials.

## FE-FCM-004: Handle push payload
- Native `title`/`body` are pre-localized by backend — display as-is.
- Data keys (`type`, `resource_type`, `resource_id`, …) drive deep-link routing.
- Pushes may arrive in any order; treat as hints, refresh from API.

## INV-APP-001: Open invoice PDFs via signed URLs
- Use item's `view_url` (open inline) / `download_url` (save file) directly with `url_launcher`/browser — **no Authorization header**.
- Links expire after **10 minutes**: on `403 {"message":"Invalid signature"}` refetch the list/detail for fresh URLs.
- `download_url` sets `Content-Disposition: attachment` — save instead of render.

## INV-APP-002: Invoice list & detail
- List: `GET /general/invoices/my-invoices?limit=…` (lightweight, no snapshot).
- Detail JSON + snapshot: `GET /general/orders/{orderId}/invoice`.
- Verification badge: `GET /general/invoices/verify/{uuid}` (throttled 5/min) — handle `409 tampered`.

## Error contract
| Code | Meaning | Action |
|---|---|---|
| 401 | missing/expired Sanctum token | re-login |
| 403 Invalid signature | signed URL expired/tampered | refetch list/detail |
| 404 Not found / PDF not yet generated | resource absent or PDF still generating | poll later |
| 422 flat errors object | validation failure | show field messages |