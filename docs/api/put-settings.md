# Endpoint Name

Settings – Update Site Settings (`PUT /api/v1/settings`)

# Description

Partially updates the singleton settings row with media, currency, and audit side effects.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — no `PATCH` twin) |
| Full path | `/api/v1/settings` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `settings` from the settings group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level `permission:update-settings` |
| Controller action | `Marvel\Http\Controllers\SettingsController@update` (whitelisted `$request->only(...)` + special `currency_selection_enabled` merge + three media uploads + cache flush + activity audit; NO null-guard on `Settings::first()` — an empty table fatals into 500, code-traced) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-settings` via BOTH route middleware and constructor middleware (aligned here, unlike the stacked read gates); the `SettingsRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json`, plus `multipart/form-data` when uploading `logo`/`footer_logo`/`favicon` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`SettingsRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional — an empty body passes validation and returns 200 rewriting nothing (but still flushes cache + writes an audit entry — code-verified side effects on empty calls). Only whitelisted keys persist; any extra body fields are silently ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json`, or `multipart/form-data` for uploads | None | None | `application/json` | Declares body encoding |
| `site_name` | body | object (locale → string) | Optional | No | `sometimes\|array`, per-locale `sometimes\|string\|min:3\|max:200` | Locales ≥3 chars ≤200 | Absent (unchanged) | None | `{"en": "My Store"}` | Site name translations |
| `site_desc` | body | object | Optional | No | `sometimes\|array`, per-locale `sometimes\|string\|min:3\|max:2000` | Locales ≥3 ≤2000 chars | Absent (unchanged) | None | `{"en": "..."}` | Site description translations |
| `meta_desc` | body | object | Optional | No | `sometimes\|array`, per-locale `sometimes\|string\|min:3\|max:2000` | Same bounds | Absent (unchanged) | None | `{"en": "..."}` | Meta description translations |
| `site_copy_right` | body | object | Optional | No | `sometimes\|array`, per-locale `sometimes\|string\|min:3\|max:200` | Same bounds | Absent (unchanged) | None | `{"en": "..."}` | Copyright translations |
| `logo` / `footer_logo` / `favicon` | body (files) | upload | Optional | No | `sometimes\|image\|mimes:jpeg,png,jpg,gif,svg\|max:2048` (2MB each) | Conforming images | Absent (kept) | Each present file is stored via `updateSingleImage` into its own collection; a storage failure throws `HttpException(422, <SPECIFIC_UPLOAD_FAILED>)` — three distinct messages (code-verified) | Binary upload | Brand images |
| `site_email` / `email_support` | body | string | Optional | No | `sometimes\|email` | Valid emails | Absent (unchanged) | None | `"support@example.com"` | Contact emails |
| `facebook` / `instagram` / `linkedin` / `promotion_video_url` / `youtube` / `tiktok` / `snapchat` | body | string | Optional | No | Each `sometimes\|url` | Valid URLs | Absent (unchanged) | None | `"https://facebook.com/x"` | Social/media links |
| `phone` | body | string | Optional | No | `sometimes\|string` | Any string | Absent (unchanged) | None | `"+201000000000"` | Contact phone |
| `fast_shipping_page_publish` | body | integer 0/1 | Optional | No | `sometimes\|in:0,1` (strict — JSON booleans FAIL; code-traced) | `0`, `1` | Absent (unchanged) | None | `1` | Fast-shipping page flag |
| `minimum_order_amount` | body | number | Optional | No | `sometimes\|numeric\|min:0` | Non-negative numbers | Absent (unchanged) | None | `50` | Minimum order (surfaced as `minimumOrderAmount`) |
| `currency_selection_enabled` | body | boolean | Optional | No | `sometimes\|boolean` (STRICT boolean rule — `0`/`1`/strings FAIL; test-verified: `'not-a-boolean'` → 422 AND `2` → 422, with storage provably untouched) | JSON `true`/`false` only | Absent (flag AND its side effects skipped entirely) | When PRESENT (even `false`): merged into `options` preserving sibling keys, and the effective-currency cache is forgotten (`forgetEffectiveCode`) — test-verified round trips both directions | `true` | Shopper currency-choice switch |
| `order_tax_enabled` | body | boolean | Optional | No | `sometimes\|boolean` (same strictness) | JSON `true`/`false` | Absent (unchanged) | None | `true` | Tax switch |
| `order_tax_rate` | body | number | Optional | Yes (`nullable`) | `nullable\|numeric\|min:0\|max:100` | `0`–`100` or null | Absent (unchanged) | None | `15` | Tax percent |
| `options` | body | object | Optional | No | `sometimes\|array` | Arbitrary map — MERGED only in the `currency_selection_enabled` branch (`array_merge` over existing); otherwise REPLACED wholesale by `$settings->update($data)` (code-verified asymmetry: bare `options` overwrites, flag-path merges) | Absent (unchanged) | None | `{"currency": "USD"}` | Raw options JSON |

There are no path parameters, query parameters, or multipart constraints beyond the three image fields.

Side effects on success (code-verified): settings-tag cache flush, `forgetEffectiveCode` when the currency flag is present, and an `ActivityAuditService::recordModel` entry (`settings_updated` with old/new attributes).

## Request examples

Valid request enabling currency selection (test-verified call):

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
    "currency_selection_enabled": true
  }
}
```

# Responses

## Success: settings updated — HTTP 200

Returned via `ApiResponse::apiResponse(SETTINGS_UPDATED_SUCCESSFULLY, 200, true, SettingResource::make($settings))` with the row re-read AFTER the write. Verified by `admin_can_enable_currency_selection` (`assertOk`, `success: true`, `data.currency_selection_enabled === true` + DB options proof), `admin_can_disable_currency_selection` (round trip to `false`), and `settings_cache_is_cleared_after_updating_currency_selection` (read-update-read; all use `putJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SETTINGS_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Settings updated successfully"`, `ar` → `"تم تحديث الإعدادات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh row serialized by `SettingResource` (same admin shape as the GET doc) | All keys as documented there, reflecting post-write values |

Complete success example (`en` locale; values illustrative):

```json
{
  "status": 200,
  "message": "Settings updated successfully",
  "success": true,
  "data": {
    "site_name": { "ar": null, "en": null },
    "currency_selection_enabled": true,
    "minimumOrderAmount": 0,
    "order_tax_enabled": false,
    "order_tax_rate": null,
    "options": { "currency_selection_enabled": true }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/422 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: validation 422s (Handler shape with `errors`), media-upload 422s (`HttpException` message, NO `errors` map), and everything else.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no settings-specific 401 test exists; identical rejection test-verified on sibling routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `update-settings` permission (route AND constructor gates both require it) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-settings` (no settings-specific 403 test exists; identical middleware path test-verified on sibling routes) | See 403 example |
| 405 | `POST`, `PATCH`, or `DELETE` on `/api/v1/settings` (no such routes are registered; note `GET` on the same URI is the separate read endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `SettingsRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (key verified from rule targets; exact messages **Unverified in source**) | Bad emails/URLs, short/long texts, non-0/1 flag, negative `minimum_order_amount`, non-boolean `currency_selection_enabled`/`order_tax_enabled` (verified triggers: `'not-a-boolean'` → 422 with storage untouched, `2` → 422), out-of-range tax rate, non-image/oversize logos, non-array `options` | See 422 example |
| 422 | Brand-image storage failure AFTER validation: `updateSingleImage` returns falsy → `throw new HttpException(422, ...)` with one of three SPECIFIC messages, rendered by `Handler`'s `HttpException` branch — NO `errors` map (code-verified shape difference from validation 422s). Note the row IS already updated at this point (media handling runs post-`$settings->update`) | `{message: string, status: boolean}` | None | One of (locale-resolved): logo → `en` `"Logo upload failed, please check the file format or size."` / `ar` `"فشل رفع الشعار، يرجى التحقق من تنسيق الملف أو حجمه."`; footer → `"Footer logo upload failed, ..."` / `"فشل رفع شعار التذييل، ..."`; favicon → `"Favicon upload failed, ..."` / `"فشل رفع أيقونة الموقع، ..."` (all file-verified strings) | None | Storage-backend failure for the respective image (code-traced, no test) | See 422 example (media) |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception: null-row fatal on empty tables, currency-service failure, audit failure), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-settings` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/settings`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (non-boolean `currency_selection_enabled`):

```json
{
  "message": "The currency selection enabled field must be true or false.",
  "status": false,
  "errors": {
    "currency_selection_enabled": [
      "The currency selection enabled field must be true or false."
    ]
  }
}
```

422 example (logo storage failure; `en` locale — note no `errors` map):

```json
{
  "message": "Logo upload failed, please check the file format or size.",
  "status": false
}
```

429 example (rate limit exceeded):

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```
