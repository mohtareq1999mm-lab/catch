# Mobile Notification & Invoice Access — API Investigation

## Purpose

Documents how the **mobile applications (Client A / Client B)** connect to the backend for:

1. Device registration for Firebase Cloud Messaging (FCM) push notifications.
2. Receiving push notifications (multi-project FCM routing).
3. Viewing / downloading their invoices as PDFs (signed URLs).

## Architecture Overview

```
Mobile App (A or B flavor)
    │  login → Sanctum Bearer token
    │
    ├── POST /general/device-tokens        (register FCM token + client)
    │
    │  business event happens (order, payment…)
    │
    ▼
Laravel Notification (25 classes)
    ├─ database channel  → notifications table (payload authority)
    ├─ broadcast channel → Pusher (real-time)
    └─ fcm channel       → SendFcmNotificationJob (meem-medium)
                              │  group tokens by client column
                              ▼
                       FirebaseProjectResolver
                              ▼
                client_a → Project A factory
                client_b → Project B factory
                              ▼
                        FCM multicast send
                              ▼
                       Mobile OS tray
```

## Key Rules (verified)

- **Client identification:** single authoritative field = `client` body value (`client_a` | `client_b`) validated server-side; persisted on every `device_tokens` row; drives Firebase project selection. No headers, no fallbacks.
- **Payload authority:** FCM title/body/data are derived ONLY from `$notification->toDatabase($notifiable)` (localized en/ar maps resolved to plain strings at send-time using app locale). Zero `toFcm()` methods exist.
- **Signed URLs:** `view_url` / `download_url` are temporary (10 min, `expires` + `signature`) so the app can open them directly without Authorization headers. Expired/tampered → `403 {"message":"Invalid signature","status":false}`.
- **Ownership:** device tokens and invoices are strictly owner-scoped; foreign resources return privacy-safe **404**.

## Files

| Doc | Content |
|---|---|
| [api.md](api.md) | Endpoint reference (request/response) |
| [flow.md](flow.md) | Connection, push-dispatch, PDF access flows |
| [jira.md](jira.md) | Backend task board |
| [jira-frontend.md](jira-frontend.md) | Mobile team task board |

## Related modules

Invoice internals: [`api-desc/invoice/`](../invoice/) · Order endpoints: [`api-desc/order/`](../order/)
