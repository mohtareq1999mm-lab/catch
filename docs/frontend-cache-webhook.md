# Frontend Cache Invalidation Webhook

## Overview

A generic, queue-based outbound webhook system that notifies the Next.js frontend server whenever a frontend resource cache needs to be invalidated. The frontend responds by executing `revalidateTag()` / `revalidatePath()`.

This is server-to-server push-based cache invalidation — not realtime browser communication.

---

## Architecture

```
Business Service
    │
    ▼
FrontendCacheInvalidation Event
    │
    ▼
DispatchFrontendCacheInvalidation Listener
    │
    ▼
SendFrontendWebhookJob (Queue: high)
    │
    ▼
FrontendWebhookService (implements FrontendWebhookDispatcher)
    │
    ▼
HTTP Client → Next.js /api/cache-webhook
    │
    ▼
revalidateTag() / revalidatePath()
```

---

## Sequence Diagram

```
┌──────────┐   ┌──────────────────────┐   ┌─────────────────────────────┐   ┌─────────────────────┐   ┌───────┐
│ Business  │   │ FrontendCache        │   │ DispatchFrontendCache       │   │ SendFrontendWebhook │   │Next.js│
│ Service   │──▶│ Invalidation Event   │──▶│ Invalidation Listener       │──▶│ Job (Queue: high)   │──▶│Server │
└──────────┘   └──────────────────────┘   └─────────────────────────────┘   └─────────────────────┘   └───────┘
     │                  │                            │                             │                    │
     │ dispatch()       │                            │                             │                    │
     │─────────────────▶│                            │                             │                    │
     │                  │ handle()                   │                             │                    │
     │                  │───────────────────────────▶│                             │                    │
     │                  │                            │ dispatch()                 │                    │
     │                  │                            │────────────────────────────▶│                    │
     │                  │                            │                             │ POST /cache-webhook│
     │                  │                            │                             │───────────────────▶│
     │                  │                            │                             │                    │ revalidate
     │                  │                            │                             │                    │────────
     │                  │                            │                             │ 200 OK             │
     │                  │                            │                             │◀───────────────────│
```

---

## Component Map

| Layer | Class | File |
|-------|-------|------|
| Enum | `FrontendResource` | `app/Enums/FrontendResource.php` |
| DTO | `FrontendCachePayload` | `app/DTOs/FrontendCachePayload.php` |
| Event | `FrontendCacheInvalidation` | `app/Events/FrontendCacheInvalidation.php` |
| Listener | `DispatchFrontendCacheInvalidation` | `app/Listeners/DispatchFrontendCacheInvalidation.php` |
| Job | `SendFrontendWebhookJob` | `app/Jobs/SendFrontendWebhookJob.php` |
| Contract | `FrontendWebhookDispatcher` | `app/Contracts/FrontendWebhookDispatcher.php` |
| Service | `FrontendWebhookService` | `app/Services/FrontendWebhookService.php` |
| Exception | `FrontendWebhookException` | `app/Exceptions/FrontendWebhookException.php` |
| VO | `WebhookSignature` | `app/ValueObjects/WebhookSignature.php` |
| Response | `WebhookResponse` | `app/Support/WebhookResponse.php` |
| Config | — | `config/frontend.php` |

---

## Configuration

### Environment Variables

```
FRONTEND_WEBHOOK_ENABLED=false
FRONTEND_WEBHOOK_URL=https://your-nextjs.com/api/cache-webhook
FRONTEND_WEBHOOK_SECRET=your-hmac-secret
FRONTEND_WEBHOOK_TIMEOUT=10
FRONTEND_WEBHOOK_RETRY_TIMES=3
FRONTEND_WEBHOOK_RETRY_SLEEP=1000
FRONTEND_WEBHOOK_VERSION=1
FRONTEND_WEBHOOK_USER_AGENT=MeemCommerce-Webhook/1.0
FRONTEND_WEBHOOK_QUEUE=high
```

---

## Payload

### Format

```json
{
    "version": 1,
    "request_id": "550e8400-e29b-41d4-a716-446655440000",
    "resource": "products",
    "occurred_at": "2026-07-30T09:00:00+00:00"
}
```

### Fields

| Field | Type | Description |
|-------|------|-------------|
| `version` | int | Schema version from config |
| `request_id` | string (UUID) | Unique idempotency key |
| `resource` | string | The frontend resource to invalidate |
| `occurred_at` | string (ISO 8601) | When the invalidation was triggered |

The payload contains **only** the information needed for cache invalidation — no event type, no action, no data.

---

## Supported Resources

| Enum Case | Value |
|-----------|-------|
| `PRODUCTS` | `products` |
| `CATEGORIES` | `categories` |
| `BRANDS` | `brands` |
| `FLASH_SALES` | `flash_sales` |
| `PROMOTIONS` | `promotions` |
| `SETTINGS` | `settings` |
| `COUPONS` | `coupons` |
| `FAQS` | `faqs` |
| `SLIDERS` | `sliders` |
| `BANNERS` | `banners` |
| `TAGS` | `tags` |
| `CONTENT_PAGES` | `content_pages` |
| `PICKUP_LOCATIONS` | `pickup_locations` |
| `FAST_SHIPPING_SETTINGS` | `fast_shipping_settings` |
| `SECTIONS` | `sections` |

---

## Security

Every request is signed with HMAC SHA256:

```php
hash_hmac('sha256', json_encode($payload), $secret)
```

### Headers

| Header | Value |
|--------|-------|
| `X-Webhook-Secret` | Shared secret |
| `X-Webhook-Signature` | HMAC SHA256 hex digest |
| `X-Webhook-Version` | Schema version |
| `X-Webhook-Request-Id` | Unique UUID |
| `User-Agent` | Configurable |
| `Accept` | `application/json` |
| `Content-Type` | `application/json` |

### Verification (Next.js)

```typescript
import crypto from 'crypto';

export async function POST(request: Request) {
  const body = await request.text();
  const signature = request.headers.get('x-webhook-signature') || '';
  const secret = process.env.FRONTEND_WEBHOOK_SECRET!;
  const expected = crypto.createHmac('sha256', secret).update(body).digest('hex');

  if (!crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) {
    return Response.json({ error: 'invalid signature' }, { status: 401 });
  }

  const { resource } = JSON.parse(body);
  revalidateTag(resource);
  revalidatePath('/', 'layout');

  return Response.json({ revalidated: true });
}
```

---

## Queue Flow

1. Business service dispatches `FrontendCacheInvalidation` event
2. Listener creates `FrontendCachePayload` and dispatches `SendFrontendWebhookJob`
3. Job is queued on the `high` queue
4. Queue worker resolves `FrontendWebhookDispatcher` from the container
5. Service sends signed HTTP POST to the configured URL
6. On success: log and complete
7. On failure: retry with backoff; after exhaustion, log and discard

---

## Retry Policy

| Level | Setting | Value |
|-------|---------|-------|
| Job | `tries` | 3 |
| Job | `backoff` | [10, 30, 60] seconds |
| Job | `timeout` | 30 seconds |
| Job | `retryUntil()` | 5 minutes |
| HTTP | `retry()` | 3 attempts, 1000ms sleep |

---

## Logging

### Success

```
INFO: Frontend webhook dispatched successfully.
{
  "request_id": "uuid",
  "resource": "products",
  "url": "https://...",
  "status": 200,
  "duration_ms": 45.23
}
```

### Failure

```
ERROR: Frontend webhook dispatch failed.
{
  "request_id": "uuid",
  "resource": "products",
  "url": "https://...",
  "error": "Connection timed out",
  "duration_ms": 10023.45,
  "exception": "Illuminate\\Http\\Client\\ConnectionException",
  "trace": "..."
}
```

### Job Exhausted

```
ERROR: SendFrontendWebhookJob failed after all retries.
{
  "request_id": "uuid",
  "resource": "products",
  "error": "...",
  "attempts": 3
}
```

---

## Usage

Invalidate a cache from any business service:

```php
use App\Enums\FrontendResource;
use App\Events\FrontendCacheInvalidation;

// After updating products
FrontendCacheInvalidation::dispatch(FrontendResource::PRODUCTS);

// After updating settings
FrontendCacheInvalidation::dispatch(FrontendResource::SETTINGS);
```

No other code changes needed — no service modifications, no listener modifications, no switch statements.

---

## Next.js Integration

### API Route

```typescript
// app/api/cache-webhook/route.ts
import { NextRequest, NextResponse } from 'next/server';
import crypto from 'crypto';
import { revalidateTag, revalidatePath } from 'next/cache';

const WEBHOOK_SECRET = process.env.FRONTEND_WEBHOOK_SECRET!;

export async function POST(request: NextRequest) {
  const body = await request.text();
  const signature = request.headers.get('x-webhook-signature') || '';

  const expected = crypto
    .createHmac('sha256', WEBHOOK_SECRET)
    .update(body)
    .digest('hex');

  if (!crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) {
    return NextResponse.json({ error: 'invalid signature' }, { status: 401 });
  }

  const { resource } = JSON.parse(body);

  revalidateTag(resource);
  revalidatePath('/', 'layout');

  return NextResponse.json({ revalidated: true });
}
```

---

## Adding a New Resource

1. Add a case to `FrontendResource` enum
2. Use the new case when dispatching `FrontendCacheInvalidation`

That's it. No other changes needed.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| No webhook sent | `FRONTEND_WEBHOOK_ENABLED=false` | Set to `true` |
| URL not configured exception | `FRONTEND_WEBHOOK_URL` is empty | Set the URL |
| 401 from Next.js | Secret mismatch | Verify both sides match |
| Job never processes | Queue worker not running | Run `php artisan queue:work --queue=high` |
| HTTP timeout | Endpoint unreachable | Check network / firewall |

---

## Production Recommendations

1. Run a dedicated queue worker for the `high` queue
2. Monitor failed webhook jobs via Telescope
3. Rotate `FRONTEND_WEBHOOK_SECRET` periodically
4. Use different secrets per environment
5. Aggregate logs from Laravel and Next.js for debugging
