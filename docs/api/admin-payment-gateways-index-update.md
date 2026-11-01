# Endpoint Name

Admin Payment Gateways – Read & Toggle (`GET /api/v1/admin/payment-gateways`, `PUT /api/v1/admin/payment-gateways/{code}`)

# Description

Dedicated admin surface for the Site-Settings-owned payment-gateway controls — one row per registry member of `config/payment.php → gateways` (currently `myfatoorah`, `stripe`, `paypal`, `tap`, `tabby`, `tamara` — registry-verified 2026-11-01, grows with every configured gateway):

- **GET** returns every known gateway as an admin-fidelity row (enable state, credential/configured verdict derived from env-only config, supported currencies, display name, sort order, catalog-currency support preview) plus the catalog currency. Never contains secrets.
- **PUT** persists an admin override for ONE gateway into the canonical `settings.options['payment_gateways'][code]` storage (write allowlist `{enabled, display_name, sort_order}`), flushes the tagged site-settings cache, and dispatches an audit record. This is the store's authoritative enable/disable control also reachable through `PUT /api/v1/settings` (both converge on the same row and the same flush).

These endpoints are the persistence layer behind the dashboard's Site Settings → payment gateways section and the `payment_gateways` blocks on the two settings GETs (`{code: {enabled, available}}` — see `docs/api/get-general-settings-payment-gateways-availability.md`). Checkout remains the enforcement authority (`canInitiate` → HTTP 422 for a disabled/misconfigured gateway); this API is not a security boundary.

# Request

## Request metadata (both routes)

| Property | Value |
| --- | --- |
| Full path base | `/api/v1/admin/payment-gateways` |
| Path composition | marvel provider `Route::prefix('api/v1')` (`packages/marvel/src/Providers/RestAPIServiceProvider.php`) + group `Route::prefix('admin/payment-gateways')` at `packages/marvel/src/Rest/Routes.php:511` |
| Group middleware | `['api', 'auth:sanctum', 'throttle:admin', 'lang']` (`Routes.php:511`) |
| Controller | `App\Http\Controllers\Api\Admin\PaymentGatewaySettingsController` (constructor DI: `GatewaySettingsService`, `CurrencyService`; no constructor permission middleware) |
| Rate limit | `throttle:admin` — 400/min keyed by user id ?? IP (code-verified `RateLimiter::for('admin')`, `app/Providers/AppServiceProvider.php`) |
| Locale | `lang` header selects response message locale; messages are plain strings (no per-gateway localized names unless an admin sets `display_name` overrides) |

## GET /api/v1/admin/payment-gateways

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Controller action | `index()` → `GatewaySettingsService::getAdminView()` (+ per-row `supports_catalog_currency`) |
| Authentication | Sanctum bearer (group middleware) |
| Permissions | Route middleware `permission:view-settings\|update-settings` (Spatie OR): a user holding EITHER `view-settings` OR `update-settings` may read |
| Inputs | None — no path, query, body, or file parameters |
| Writes/side effects | None (pure read) |

## PUT /api/v1/admin/payment-gateways/{code}

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/admin/payment-gateways/{code}` (path segment, no trailing slash conventions) |
| Controller action | `update(UpdateGatewaySettingsRequest $request, string $code)` |
| Authentication | Sanctum bearer (group middleware) |
| Permissions | Route middleware `permission:update-settings` — exact match; users with only `view-settings` CANNOT update |
| `{code}` path parameter | Plain string (no model binding); resolved against `GatewaySettingsService::adminRow()`/config-known codes; unknown code → 404 BEFORE any persistence |

Requested body fields (accepted — `App\Http\Requests\Payment\UpdateGatewaySettingsRequest`; anything else is DROPPED by `validated()`, e.g. secrets, `supported_currencies`, `methods`, `class` can never take this path):

| Field | Location | Type | Required | Nullable | Validation rules | Accepted values | Constraints | Default | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `enabled` | body | boolean | No | No | `sometimes\|boolean` | `true`/`false` (`1`/`0`, `"1"`/`"0"` accepted by Laravel boolean rule) | Admin toggle — the enable state | Absent = leave unchanged | `false` | Disable/enable the gateway for NEW initiations (in-flight operations unaffected) |
| `display_name` | body | string | No | Yes | `sometimes\|nullable\|string\|max:60` | Admin-set label; `null` clears the override and re-derives the config default | Rendered to storefront admins AND the public gateway rows — must be escaped on render (HTML/JS escaping is the frontend's job; documented in the controller) | Absent = unchanged; explicit `null` = clear override | `"Tap KNET Card"` | Override of the displayed name |
| `sort_order` | body | integer | No | Yes | `sometimes\|nullable\|integer\|min:0\|max:999999` | Explicit ordering; `null` clears back to config array position | Rows sort by this value, ties broken by code | Absent = unchanged; explicit `null` = clear override | `1` | Display order in admin and public rows |

Side effects (PUT):

1. Persist via `GatewaySettingsService::updateOverride()` — `lockForUpdate` transaction, read+write allowlisted; ONE gateway per call (other gateways' overrides are untouched — independence test-verified).
2. Immediately flushes the tagged `FrontendResource::SETTINGS` cache — so `GET /api/v1/general/settings`, `GET /api/v1/settings`, and the cached `payment_gateways.availability` map reflect the toggle at once (test-verified both directions).
3. Dispatches an async audit record (canonical `ActivitySnapshot`, log name `settings`, event `payment_gateway_updated`, `context: {gateway, source: 'admin-api'}`) and writes an activity-log row when the queue is sync (test-verified).

## Request examples

GET (test-verified in `GatewaySettingsAdminTest`):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/payment-gateways",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

PUT — disable Tap (test-verified call pattern):

```json
{
  "method": "PUT",
  "url": "/api/v1/admin/payment-gateways/tap",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "enabled": false
  }
}
```

PUT — rename the display label:

```json
{
  "method": "PUT",
  "url": "/api/v1/admin/payment-gateways/stripe",
  "headers": { "Authorization": "Bearer 1|abcdef123456", "Content-Type": "application/json" },
  "body": {
    "display_name": "Stripe Card",
    "sort_order": 1
  }
}
```

# Responses

## GET — admin view — HTTP 200

Envelope `apiResponse(PAYMENT_GATEWAYS_FETCHED, 200, true, {catalog_currency, gateways})`. Message: `en` → `"Payment gateways fetched successfully."`, `ar` → `"تم جلب بوابات الدفع بنجاح."` (lang-file verified).

```json
{
  "status": 200,
  "message": "Payment gateways fetched successfully.",
  "success": true,
  "data": {
    "catalog_currency": "KWD",
    "gateways": [
      {
        "code": "myfatoorah",
        "display_name": "MyFatoorah",
        "enabled": true,
        "configured": true,
        "supported_currencies": ["KWD", "SAR", "AED", "BHD", "QAR", "OMR", "EGP"],
        "methods": ["online"],
        "sort_order": 0,
        "supports_catalog_currency": true
      },
      {
        "code": "stripe",
        "display_name": "Stripe",
        "enabled": false,
        "configured": true,
        "supported_currencies": ["USD", "EUR", "KWD", "SAR", "AED"],
        "methods": ["online"],
        "sort_order": 1,
        "supports_catalog_currency": true
      }
    ]
  }
}
```

(Sample values above mirror the config defaults `MYFATOORAH_SUPPORTED_CURRENCIES`/`STRIPE_SUPPORTED_CURRENCIES` from `config/payment.php` for illustration. In a real deployment the lists are env-backed and `stripe` ships default-disabled, so `enabled`/`supports_catalog_currency` always reflect the deployment's actual state, not this example.)

Row field table (all rows, sorted by `sort_order` then code — shape test-verified via `list_shape_sorted_and_catalog_support_flags`):

| Field | Data type | Presence | Meaning | Notes |
| --- | --- | --- | --- | --- |
| `code` | string | Always | Registry key (`myfatoorah`, `stripe`, `paypal`, `tap`, `tabby`, `tamara` — mirrors `config/payment.php → gateways`) | Used verbatim in the PUT path |
| `display_name` | string | Always | Effective label: settings override → config default → code | **Admin-editable string** — escape before HTML/JS render |
| `enabled` | boolean | Always | Effective enable state (synced from `settings.options`) | Drives checkout initiation via `canInitiate` |
| `configured` | boolean | Always | Env-backed adapter reports `isConfigured()` (credentials exist) | Fail-closed; NEVER a secret; not writable |
| `supported_currencies` | string[] | Always | Config-allowed currency codes (upper-cased) | Env-backed; not writable via this API |
| `methods` | string[] | Always | Flow types (online gateways: `["online"]`) | Not writable via this API |
| `sort_order` | integer | Always | Effective ordering | Admin-overridable via this API |
| `supports_catalog_currency` | boolean | Always (GET only) | `catalog_currency ∈ supported_currencies` | Computed per request against the catalog currency; NOT present on PUT responses |

## PUT — override persisted — HTTP 200

Envelope `apiResponse(PAYMENT_GATEWAY_UPDATED, 200, true, $row)`; message: `en` → `"Payment gateway updated successfully."`, `ar` → `"تم تحديث بوابة الدفع بنجاح."` (lang-file verified). `data` is the refreshed admin row for that code with the same field set as the GET rows MINUS `supports_catalog_currency` (code-verified; the PUT response is the raw service row).

```json
{
  "status": 200,
  "message": "Payment gateway updated successfully.",
  "success": true,
  "data": {
    "code": "tap",
    "display_name": "Tap",
    "enabled": false,
    "configured": true,
    "supported_currencies": ["KWD", "AED", "USD"],
    "methods": ["online"],
    "sort_order": 3
  }
}
```

The storefront-facing verdict (`available`) is derived downstream (`enabled && configured && supports_catalog_currency`) and is never accepted or echoed here — see the settings documents.

# Errors

| HTTP status | Trigger | Response body | Message | Field errors | Conditions | Verification |
| --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid/expired Sanctum token | `{message: string, status: boolean}` (Handler shape) | `"Unauthenticated"` | None | Both methods, any request without a valid bearer token | Test-verified (`unauthenticated_requests_are_rejected_with_401`) |
| 403 | Authenticated user without the required permission: GET needs `view-settings` OR `update-settings`; PUT needs `update-settings` | `{message: string, status: boolean}` | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Includes a plain customer or a user with unrelated permissions | Test-verified (`user_without_settings_permission_is_forbidden`) |
| 404 | Unknown `{code}` on PUT (checked BEFORE persistence, message rendered by the controller) | `{status: integer, message: string, success: boolean}` (apiResponse envelope; `data` omitted) | Translated `ERROR.PAYMENT_GATEWAY_NOT_FOUND`: `en` → `"Payment gateway not found."`, `ar` → `"بوابة الدفع غير موجودة."` | None | Any code not in the registry | Test-verified (`unknown_code_returns_404`) |
| 405 | Wrong method on the paths (e.g. `POST /api/v1/admin/payment-gateways`) | `{message: string, status: boolean}` | `"Method Not Allowed"` family | None | Only GET `/`, PUT `/{code}` registered | Code-traced |
| 422 | `UpdateGatewaySettingsRequest` validation failure (non-boolean `enabled`, overlong `display_name` >60, non-integer or out-of-range `sort_order`) | `{message: string, status: false, errors: object}` | First failure summary | Keyed by field name (`enabled`, `display_name`, `sort_order`) | Standard Laravel wording (code-traced) | Test-verified (`validation_errors_return_422`) |
| 429 | `throttle:admin` (400/min, keyed by user id ?? IP) | `{success: boolean, message: string, data: null}` (limiter shape) | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded | Limiter definition code-verified; translations lang-file verified |
| 500 | Infrastructure failure (DB write failure inside the update transaction, audit dispatcher crash before async dispatch) | Handler error shape | `"Something went wrong."` family | None | No dedicated test | Code-traced |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

404 example (PUT `/api/v1/admin/payment-gateways/no-such-gateway`):

```json
{
  "status": 404,
  "message": "Payment gateway not found.",
  "success": false
}
```

422 example (`{"enabled": "maybe"}`):

```json
{
  "message": "The enabled field must be true or false.",
  "status": false,
  "errors": {
    "enabled": [
      "The enabled field must be true or false."
    ]
  }
}
```

# Invariants & notes

- **Secret hygiene**: responses never contain credentials, and the write allowlist guarantees secrets/currency lists/adapter classes cannot be persisted through this API (test-verified: `responses_never_contain_secrets_and_secrets_are_never_stored` with canary injection; `read_merge_ignores_non_allowlisted_override_keys`).
- **Independent toggles**: updating one gateway never alters another gateway's override (test-verified: `enabled_toggles_are_independent_across_gateways`).
- **Behavior after disable**: a disabled gateway rejects NEW payment initiations at checkout (HTTP 422, no transaction rows — proven in `GatewaySettingsEnforcementTest`/`GatewayRegistryTest`) while previously started verification/webhooks/capture/refunds keep working (`canVerify` ignores `enabled`; pass-1 audit §10).
- **Cache & visibility chain**: PUT → flush `FrontendResource::SETTINGS` → both settings GETs + public gateway rows reflect immediately; checkout reads the settings row uncached. Verified chain test: `admin_toggle_reflects_immediately_on_public_gateway_endpoint` (flip both directions through this PUT, read the unauthenticated public endpoint).
- **Round-trip & resort**: `display_name`/`sort_order` overrides persist and re-sort the list (test-verified: `update_display_name_and_sort_order_persist_and_resort`); clearing via `null` returns to config defaults.
- **Effect on the settings responses**: the same row feeds `payment_gateways: {code: {enabled, available}}` on `GET /api/v1/general/settings` and `GET /api/v1/settings` (the cached availability map under the same `FrontendResource::SETTINGS` tag — flushed by this PUT — test-verified in `site_settings_cache_is_flushed_so_website_sees_the_toggle`).
- **Scope note**: the `Route::apiResource('products', ...)` line adjacent to this group in `routes/api.php` is an unrelated product CRUD surface and is out of this document's scope.

Verification source: `tests/Feature/Payment/GatewaySettingsAdminTest.php` (14 tests / 202 assertions, green on the real MySQL `catch_verify` database), `GatewaySettingsEnforcementTest.php` (5/54) and the audit at `docs/audits/payment-gateway-settings-unification-audit.md` (§1–§18).
