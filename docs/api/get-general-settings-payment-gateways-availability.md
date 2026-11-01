# Endpoint Name

General Settings – Payment Gateway Availability Block (`GET /api/v1/general/settings`)

# Description

Returns the public Site Settings payload consumed by the storefront. This document covers the response extension added in 2026-10-31: a new top-level `data.payment_gateways` object carrying the per-gateway `{enabled, available}` state for the five online payment gateways (Stripe, MyFatoorah, Tap, Tabby, Tamara), rendered by `Marvel\Http\Resources\SettingR
esource` from the same `GatewaySettingsService` merge that the admin payment-gateway API and checkout authority use. No secrets are ever included; the block is additive and the pre-existing fields are unchanged.

The endpoint itself is pre-existing and NOT new; only the `payment_gateways` block is new. Backend enforcement of these states happens at checkout (`PaymentGatewayRegistry::canInitiate` → HTTP 422), which is the real authority — this snapshot is a display layer only.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/general/settings` |
| Path composition | `v1/general` prefix from `routes/api.php:51` (`Route::prefix('v1/general')`) + `settings` from `routes/api.php:96` inside a `Route::middleware(['api', 'throttle:public-api'])` group |
| Controller action | `App\Http\Controllers\Api\General\SettingController@index` → `SettingResource::make(...)` |
| Route name | `settings.front` |
| Authentication | None — public |
| Rate limit | `throttle:public-api` — 120/min **per IP** (code-verified: `RateLimiter::for('public-api')` in `app/Providers/AppServiceProvider.php`, `Limit::perMinute(120)->by($request->ip())`) |
| Caching | Tagged cache `FrontendResource::SETTINGS`, key `md5($request->fullUrl())`, rendered from a lazy closure (app-verified behavior): on MISS it runs `Settings::first()` and caches the MODEL (not the array), so localized fields re-serialize with the request locale on every hit. Flushed on every `PUT /api/v1/settings`, every `PUT /api/v1/admin/payment-gateways/{code}`, and every marvel settings update — a gateway toggle is always visible immediately on the next GET (test-verified both directions) |

There are no path, query, body, or file inputs. The cache key contains the URL only; the `lang` header changes the rendered locale per request, not the cache key (resource runs at render time — code-verified).

# Responses

## Success: settings payload with gateway availability — HTTP 200

Envelope via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make(...))`. Message is translated: `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` (lang-file verified). The complete `data.payment_gateways` shape and key set is test-verified (`SettingsResponsePaymentGatewaysTest::general_settings_response_contains_gateway_availability_map`, 5 codes × exactly `{enabled, available}` boolean keys).

Complete example (values illustrative except the shape; USD catalog assumed):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "site_name": "Meem",
    "site_desc": "…",
    "meta_desc": "…",
    "site_copy_right": "…",
    "logo": "…/logo.png",
    "footer_logo": "…/footer.png",
    "favicon": "…/favicon.png",
    "site_email": "support@example.com",
    "email_support": "support@example.com",
    "facebook": "https://…",
    "instagram": "https://…",
    "linkedin": "https://…",
    "promotion_video_url": "https://…",
    "youtube": "https://…",
    "tiktok": "https://…",
    "snapchat": "https://…",
    "phone": "+965…",
    "fast_shipping_page_publish": 1,
    "minimumOrderAmount": 0,
    "order_tax_enabled": false,
    "order_tax_rate": null,
    "currency_selection_enabled": true,
    "payment_gateways": {
      "stripe":     { "enabled": false, "available": false },
      "myfatoorah": { "enabled": true,  "available": false },
      "tap":        { "enabled": true,  "available": true },
      "tabby":      { "enabled": true,  "available": false },
      "tamara":     { "enabled": false, "available": false }
    },
    "options": { "…": "raw settings.options JSON passthrough" }
  }
}
```

`payment_gateways` field table (block-level):

| Field | Data type | Presence | Meaning |
| --- | --- | --- | --- |
| `payment_gateways` | object | Always present on this endpoint; on a successful subsystem resolution it always contains all FIVE codes (test-verified); otherwise `{}` | Keyed by gateway code: `stripe`, `myfatoorah`, `tap`, `tabby`, `tamara` (fixed registry membership — code-verified from `config/payment.php` + `GatewaySettingsService::clientAvailabilityMap`) |
| `payment_gateways.{code}.enabled` | boolean | Always present (test-verified exact key set) | Admin toggle: `true` when the Site Settings override — or the config default, when no override exists — enables the gateway. Source: `settings.options['payment_gateways'][code].enabled` merged over `config('payment.gateways')` |
| `payment_gateways.{code}.available` | boolean | Always present (test-verified) | Display-layer readiness: `enabled && configured && supports_catalog_currency`. `configured` = the gateway's env-backed adapter reports `isConfigured()`. `supports_catalog_currency` = catalog currency ∈ config `supported_currencies` (currency-subsystem failure degrades to `payment.default_currency`, default `KWD` — code-verified) |

Guarantees (all test-verified unless marked):

- Exactly the keys `{enabled, available}` per code — the richer admin-world fields (`display_name`, `configured`, `supported_currencies`, `sort_order`, `methods`) and all secrets are never present. A canary secret injected into config does not leak into the response body (test-verified).
- A disabled gateway is never reported `available: true` (test-verified).
- `available: false` with `enabled: true` means "missing credentials" or "catalog currency unsupported" (test-verified both directions: `myfatoorah` enabled+configured+KWD-only → `false` on USD catalog; `tabby` enabled but unconfigured → `false` even when the payload attempts to claim availability elsewhere).
- Fault tolerance: if the currency or settings subsystem throws, the resource degrades to `payment_gateways: {}` and still answers 200 with the rest of the payload (code-verified try/catch in `SettingResource::gatewayStates()`; also test-verified for the missing-settings-row case, where the block stays fully populated from config).
- Not a security boundary: state may change between this snapshot and order submit; checkout enforces every rule again (HTTP 422). The storefront must not treat `available` as an authorization signal.

# Errors

The route has no authentication, no permissions, no path parameters, and no input validation — there are no 401/403/404/422 paths on this endpoint (code-verified route + controller; no request validation exists). Full settings-response secret-scan is test-verified.

| HTTP status | Trigger | Response body | Message | Conditions |
| --- | --- | --- | --- | --- |
| 429 | Exceeding `throttle:public-api` (120/min per IP) | `{success: boolean, message: string, data: null}` (limiter shape, code-verified) | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."` (limiter test exists for the shape family; per-limiter message value code-verified) | More than 120 requests/min from one IP |
| 500 | Unhandled error before/inside the controller outside the guarded code paths (e.g. container failure, not the guarded `Settings::first()`/currency misses) | Handler error shape | `"Something went wrong."` family | Resource-level settings/currency/payment failures are caught and degrade to `data.payment_gateways: {}` instead of 500 (code-verified); only infrastructure failures reach 500 (code-traced — no dedicated test) |

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

# Change control

- Backward compatibility: additive top-level `data.payment_gateways` object; all pre-existing fields, ordering, envelope shape, message keys, and cache behavior unchanged (test-verified: pre-existing payload keys still present).
- Provenance of the block: one source of truth shared with checkout — `GatewaySettingsService` merge of `config/payment.php` over `settings.options['payment_gateways']` (read-allowlisted `{enabled, display_name, sort_order}`; engine only reads `enabled`). Test-verified end to end: dashboard toggle → tagged flush → this GET reflects it immediately (both directions).
- Companion document: *Admin Site Settings – Gateway Toggles Read/Save* (`docs/api/admin-settings-payment-gateways-control.md`) documents the write surface (`PUT /api/v1/settings` and the dedicated `PUT /api/v1/admin/payment-gateways/{code}`) and the full Dashboard→API workflow.
