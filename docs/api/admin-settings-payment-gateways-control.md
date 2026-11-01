# Endpoint Name

Admin Site Settings – Payment Gateway Toggles Read/Save (`GET` & `PUT` `/api/v1/settings`)

# Description

Admin Site Settings read/save endpoints (pre-existing routes). This document covers the extension from 2026-10-31: gateway enable/disable for the five online payment gateways (Stripe, MyFatoorah, Tap, Tabby, Tamara) is (a) exposed on the GET response as the compact `data.payment_gateways` map and (b) controllable from the dashboard THROUGH THIS endpoint — `PUT /api/v1/settings` now accepts a `payment_gateways` block of `{code: {enabled: boolean}}` patches, persisted into the canonical `settings.options['payment_gateways']` storage via the same write service the dedicated gateway endpoint uses, with no new tables and no second settings system.

The dedicated per-gateway route `PUT /api/v1/admin/payment-gateways/{code}` continues to exist (pass-1 contract, unchanged, same storage); both surfaces converge on the same row and are covered by the same cache flush.

# GET — read settings

## Request metadata (GET)

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/settings` |
| Path composition | marvel provider `Route::prefix('api/v1')` (`packages/marvel/src/Providers/RestAPIServiceProvider.php`) + `settings` from the `['auth:sanctum', 'throttle:admin', 'lang']` group at `packages/marvel/src/Rest/Routes.php:135`, declared at `Routes.php:137` — NOTE: the URL contains no `admin` segment |
| Controller action | `Marvel\Http\Controllers\SettingsController@index` → `SettingResource::make($cached)` |
| Authentication | Required — Sanctum bearer token in the `auth:sanctum` group (401 test-verified) |
| Permissions | Route middleware `permission:view-settings\|update-settings` (Spatie OR-split) AND controller constructor `permission:view-settings` for `index` (`SettingsController.php:33`). Effective read rule: **`view-settings` is mandatory**; the `|update-settings` route operand is redundant for GET. Test-verified asymmetry: a user holding ONLY `update-settings` receives 403 on GET (`SettingsResponsePaymentGatewaysTest`) |
| Rate limit | `throttle:admin` — 400/min by user id ?? IP (code-verified `RateLimiter::for('admin')`) |
| Same resource as the public endpoint | Both GETs render `Marvel\Http\Resources\SettingResource`, so `data.payment_gateways` is identical in shape (`{code: {enabled, available}}`); see the companion document `docs/api/get-general-settings-payment-gateways-availability.md` for the block contract. The full field set (including localized `site_name` as an `{ar, en}` object here) is unchanged from the pre-existing contract (backward compat test-verified) |
| Caching quirk | `$this->remember(tag, md5(fullUrl), $settings)` caches the MODEL (non-closure): the query runs on every request, the hit path skips re-serialization only if the model is already in cache. Regardless, every write path flushes this tag, so reads are always fresh after a toggle (test-verified) |

## Responses (GET)

Success — HTTP 200, envelope `{status: 200, message: string, success: true, data: object}` with read message `FETCH_DATA_SUCCESSFULLY`: `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"`, and `data` = `SettingResource::make($settings)` (the same resource as the public GET, so the `payment_gateways` block contract of the companion document applies identically here — test-verified: admin map present, 5 codes, exact `{enabled, available}` keys, no secrets).

The read-side errors (401, 403, 429, 500) are covered by the unified `# Errors` section below.

# PUT — save settings (now with gateway toggles)

## Request metadata (PUT)

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/settings` |
| Controller action | `Marvel\Http\Controllers\SettingsController@update` → settings row update + optional gateway patches through `App\Services\Payment\GatewaySettingsService::updateOverride()` |
| Authentication | Required — Sanctum bearer (group middleware) |
| Permissions | Route middleware `permission:update-settings` at `Routes.php:138` AND controller constructor `permission:update-settings` for `update` — identical check, no OR. Test-verified: `update-settings` ALONE is sufficient to save (including the gateway block); a permission-less user gets 403 |
| Rate limit | `throttle:admin` — 400/min by user id ?? IP |
| Side effects | Single `activity` audit record `settings_updated` (model-level old/new attributes snapshot, includes the final options state); tagged site-settings cache flushed early in the update flow — directly after the settings row and gateway patches are committed, BEFORE media handling (`SettingsController.php:110`) — so both settings GETs reflect the save immediately even if a later step (e.g. image upload) fails |
| Locale | `lang` header drives translated messages |

## Request inputs

All pre-existing settings fields keep their validation (excerpt: `site_name.*` string 3–200, `options` array, `currency_selection_enabled` boolean, `order_tax_rate` numeric 0–100, image uploads `logo`/`footer_logo`/`favicon` jpeg,png,jpg,gif,svg ≤2048 — code-verified `Marvel\Http\Requests\SettingsRequest`). New rules:

| Field | Location | Type | Required | Nullable | Validation rules | Accepted values | Constraints | Default | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `payment_gateways` | body | object keyed by gateway code | No | No | `sometimes\|array` (`SettingsRequest`) | Keys: `stripe`, `myfatoorah`, `tap`, `tabby`, `tamara` (fixed registry membership) | Omitted block = untouched toggles | Absent | `{"tap": {"enabled": true}}` | Per-gateway toggle patch mirrored from the GET response shape MINUS the read-only verdicts |
| `payment_gateways.{code}` | body | object | Only when the parent key is supplied | No | `sometimes\|array` (`payment_gateways.*`) | `{enabled: bool}` | Must be an object per code (shorthand `{"tap": true}` is rejected 422) | — | `{"enabled": true}` | One gateway patch |
| `payment_gateways.{code}.enabled` | body | boolean | Required whenever `payment_gateways.{code}` is supplied | No | `required\|boolean` (`payment_gateways.*.enabled`) | `true`/`false` (Laravel boolean: `1`/`0`, `"1"`/`"0"`, `true`/`false` accepted) | `available` and any other keys inside a per-gateway entry are NOT validation errors but are silently ignored — never writable | — | `true` | New enable state of that gateway |

Semantics (code-verified + test-verified where marked):

1. **All-or-nothing on unknown codes.** Unknown gateway codes raise a 422 validation error BEFORE anything is persisted, so a bad gateway block cannot partly save with the rest of the payload (test-verified: `{tap: {enabled: true}, no-such: {enabled: true}}` → 422 and tap stays unchanged).
2. **Only `enabled` is honored** — the `{enabled, display_name, sort_order}` write allowlist is enforced by `GatewaySettingsService::updateOverride()` (lockForUpdate transaction). `available` is computed and never writable (test-verified: sending `available: true` is accepted but ignored — the response shows the derived verdict).
3. **Options round-trip protection.** A save carrying its own `options` object that does NOT include `payment_gateways` preserves the stored gateway toggles (merge-in), so a typical dashboard payload cannot silently wipe toggles (test-verified). A save carrying `options.payment_gateways` explicitly replaces them as before. A top-level `payment_gateways` patch (applied after the row save, per-code through the service) wins for patched codes.
4. **No secrets write path.** Secrets stay env-only; nothing in `payment_gateways` can inject credentials, currencies, methods, or adapter classes (read-allowlist + write-allowlist; canary tests green).
5. **Checkout remains the authority** — toggling here changes initiation eligibility for FUTURE orders: `PaymentGatewayRegistry::canInitiate` rejects a now-disabled gateway at order time (HTTP 422). In-flight transactions, verification, webhooks, capture, and refunds of previously started operations are unaffected by design (`canVerify` never consults `enabled`).

## Request examples

Toggle Tap ON through Site Settings (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/settings",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "payment_gateways": {
      "tap": { "enabled": true }
    }
  }
}
```

Dashboard-style save that rounds its own site fields/options subset (toggles preserved):

```json
{
  "method": "PUT",
  "url": "/api/v1/settings",
  "headers": { "Authorization": "Bearer 1|abcdef123456", "Content-Type": "application/json" },
  "body": {
    "site_name": { "en": "Meem", "ar": "ميم" },
    "options": { "currency": "USD" }
  }
}
```

## Responses (PUT)

Success: order persisted — HTTP 200 envelope via `apiResponse(SETTINGS_UPDATED_SUCCESSFULLY, 200, true, SettingResource::make($fresh))`, where `data` is the fully refreshed settings resource INCLUDING the new `payment_gateways` block (test-verified: `data.payment_gateways.tap.enabled === true` in the PUT response immediately after toggling).

Complete success example:

```json
{
  "status": 200,
  "message": "Settings updated successfully",
  "success": true,
  "data": {
    "payment_gateways": {
      "stripe":     { "enabled": false, "available": false },
      "myfatoorah": { "enabled": true,  "available": false },
      "tap":        { "enabled": true,  "available": true },
      "tabby":      { "enabled": true,  "available": false },
      "tamara":     { "enabled": false, "available": false }
    },
    "options": { "payment_gateways": { "tap": { "enabled": true } } }
  }
}
```

`message` is translated: `en` → `"Settings updated successfully"`, `ar` → `"تم تحديث الإعدادات بنجاح"` (lang-file verified).

# Errors (both methods)

| HTTP status | Trigger | Response body | Message | Field errors | Conditions | Verification |
| --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token (`auth:sanctum`) | `{message: string, status: boolean}` (Handler shape) | `"Unauthenticated"` | None | Any unauthenticated request, both methods | Test-verified (`assertStatus(401)` on GET and on the guest PUT) |
| 403 | Authenticated user without the effective permission: GET requires `view-settings`; PUT requires `update-settings` | `{message: string, status: boolean}` | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Also covers the verified GET asymmetry (update-only user CANNOT read) | Test-verified both methods |
| 405 | Wrong method for the path | `{message: string, status: boolean}` | Handler fixed strings | None | e.g. `POST /api/v1/settings` | Code-traced |
| 422 | `SettingsRequest` validation failure — including unknown gateway codes (controller check before persistence) and non-boolean `enabled` | `{message: string, status: boolean, errors: object}` | First failure summary | `payment_gateways.{code}` → `["Unknown payment gateway."]`; `payment_gateways.{code}.enabled` → `"The payment gateways.tap.enabled field is required."` / boolean message family | Unknown code, malformed per-gateway entry, wrong boolean type | Test-verified: unknown code → 422 with nothing persisted; `"maybe"` → 422 |
| 429 | `throttle:admin` (400/min by user id ?? IP) | `{success: boolean, message: string, data: null}` (limiter shape) | `__('message.too_many_requests')` | None | Rate exceeded | Limiter definition code-verified |
| 500 | Infrastructure failure inside the update flow (DB write failure etc.) | Handler error shape | `"Something went wrong."` family | None | No dedicated test | Code-traced |

422 example (unknown gateway code):

```json
{
  "message": "Unknown payment gateway.",
  "status": false,
  "errors": {
    "payment_gateways.no-such-gateway": [
      "Unknown payment gateway."
    ]
  }
}
```

403 example:

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

# Workflow — Dashboard → API → storefront

1. Admin opens the Site Settings section in the dashboard and toggles a gateway (e.g. Tap ON).
2. Dashboard saves through `PUT /api/v1/settings` with `payment_gateways` (or, equivalently, the dedicated `PUT /api/v1/admin/payment-gateways/tap` — same storage, same flush). Both are persisted into the canonical settings table row: `settings.options['payment_gateways'][code].enabled` = bool (verified: value survives a reload/read round-trip).
3. Both write surfaces flush the `FrontendResource::SETTINGS` cache tag, so `GET /api/v1/general/settings` and `GET /api/v1/settings` reflect the new state IMMEDIATELY (test-verified, both directions, both surfaces).
4. The storefront reads `payment_gateways` from the GET (this is the exact deliverable of the task: gateway states available for the frontend to rely on) and may also use per-gateway rows on `GET /api/v1/general/payment-gateways`; meanwhile checkout re-checks and enforces the SAME enable flags — disabled ⇒ new initiation rejected 422; previously started operations stay verifiable/refundable regardless of toggles.

# Change control

- Backward compatibility: both endpoints' request validation is additive (`sometimes`); every pre-existing settings field, envelope, and message is unchanged (regressions green: `SettingsRegressionTest` 10/20, `ProductionRootCauseRegressionTest` 8/22, `FrontendCachePayloadTest` 8/20, `GatewaySettingsAdminTest` 14/202, `GatewaySettingsEnforcementTest` 5/54, `PublicPaymentGatewaysTest` 13/80).
- Verification source: `tests/Feature/Payment/SettingsResponsePaymentGatewaysTest.php` (6 tests / 93 assertions, all green, real MySQL `catch_verify`). Every "test-verified" claim above maps to an assertion in that suite.
