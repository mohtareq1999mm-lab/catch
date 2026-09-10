# Flows — Mobile Notification & Invoice Access

## Flow 1: Mobile Connect (device registration)

```
Mobile App (A/B)
  │ login → POST /login → Sanctum token
  │
  ├─ POST /api/v1/general/device-tokens
  │     { token, client: client_a|client_b, platform }
  │        ↓ auth:sanctum + validate
  │     DeviceToken::updateOrCreate(token)
  │        ├── token existed under another user → reassigned
  │        └── new → created (uuid, last_used_at=now)
  ▼
200 { data:{ uuid } }
```

Logout: `DELETE /general/device-tokens {token}` — deletes own row only.

## Flow 2: Push Notification Dispatch (multi-project FCM)

```
Business event (order created / payment succeeded / …)
      ↓
Notification::send($user, new XNotification(...))
      ↓ via() = [database, broadcast, fcm]   (queue: meem-medium)
      ├─ database   → notifications table (toDatabase payload = authority)
      ├─ broadcast  → BroadcastMessage → Pusher
      └─ fcm        → FcmChannel::send()
                        ↓ resolve en/ar maps → plain strings (app locale)
                        ↓ data = remaining payload keys
                     SendFcmNotificationJob  (meem-medium · tries3 · backoff[30,120])
                        ↓ chunk(500) + groupBy('client')
                 ┌──────┴───────┐
                 ▼              ▼
          client_a tokens  client_b tokens
                 ▼              ▼
        Project A factory  Project B factory   (FirebaseProjectResolver)
                 ▼              ▼
           sendMulticast   sendMulticast
                 │              │
        invalid→delete row  invalid→delete row
        transient→log warn  transient→log warn
```
One failing token/client never affects the other project or the business transaction.

## Flow 3: View / Download invoice PDF (signed, no auth header)

```
App opens view_url / download_url from my-invoices item
      ↓
signed middleware → validates signature + expiry
      ├── tampered/expired/missing → 403 Invalid signature
      ↓
find Invoice by uuid → 404 if missing
      ↓ pdf_path null? → 404 "PDF not yet generated"
      ↓ file missing on disk? → 404
      ↓ (download only): downloaded_at once + timeline 'downloaded'
Storage::disk('public')->response(...)
      ▼
200 application/pdf  (inline | attachment; filename="INV-….pdf")
```

Signed URL TTL = **10 minutes**. On expiry the app refetches `my-invoices` (or order-invoice detail) for fresh links.

## Flow 4: Invoice detail JSON

`GET /orders/{orderId}/invoice` (owner-scoped) or `GET /invoices/show/uuid/{uuid}` signed variant → `CustomerInvoiceResource` incl. full `snapshot`.

## Localization

- Payload maps (`title.en/title.ar`, `message.en/message.ar`) are stored in DB unchanged.
- FCM resolves to a single locale string at dispatch using `app()->getLocale()`; data keys stay raw identifiers.
