# Endpoint Name

Fast Shipping – Update Settings (`PUT /api/v1/fast-shipping/settings`)

# Description

Merges partial fast-shipping settings over defaults and invalidates both cache layers.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — no `PATCH` twin) |
| Full path | `/api/v1/fast-shipping/settings` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `fast-shipping/settings` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\FastShippingController@updateSettings` → `FastShippingRepository::updateSettings` (row-locked transaction merging `array_merge(defaults(), $data)` over the stored key, then `Cache::forget`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-fast-shipping` via controller constructor `permission:update-fast-shipping` middleware (applies only to `updateSettings`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching validation.

## Request inputs

Inline `$request->validate()` runs before the method body (no `FormRequest` class; a `ValidationException` renders through `Handler` as 422). All fields are optional — an empty body passes validation and rewrites stored settings to pure defaults (code-verified consequence of `array_merge(defaults(), [])`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `enabled` | body | boolean | Optional | No | `sometimes\|boolean` (strict boolean rule — `0`/`1`/strings FAIL; only JSON `true`/`false` pass. Code-verified rule strictness — note the contrast with the loose `in:` flags elsewhere) | `true`, `false` | Absent (falls back to default `false` in storage) | None | `true` | Global fast-shipping switch |
| `duration_minutes` | body | integer | Optional | No | `sometimes\|integer\|min:1\|max:1440` (1 minute – 24 hours) | `1`–`1440` | Absent (default `120`) | None | `60` | ETA window added to now by `calculateEta` |
| `fee` | body | number | Optional | No | `sometimes\|numeric\|min:0` | Any non-negative number | Absent (default `0`) | None | `5.5` | Surcharge amount |
| `start_hour` | body | string | Optional | No | `sometimes\|string\|date_format:H:i` | `"HH:MM"` 24h strings | Absent (default `'08:00'`) | Independent of `end_hour` (no cross-validation that start < end — code-verified gap; inverted windows silently alter `isWithinWorkingHours` behavior, untested) | `"09:00"` | Daily availability window start |
| `end_hour` | body | string | Optional | No | `sometimes\|string\|date_format:H:i` | `"HH:MM"` 24h strings | Absent (default `'22:00'`) | See `start_hour` | `"20:00"` | Daily availability window end |

There are no path parameters, query parameters, multipart fields, or file uploads. Any extra body fields are ignored (`updateSettings` receives only `$validated`).

Merge semantics (code-verified): stored value becomes `array_merge(defaults(), $validated)` — supplied keys overwrite defaults, OMITTED keys RESET to defaults (not preserved). Partial updates are therefore destructive to unmentioned keys. Both cache layers are invalidated (`Cache::forget` in the repository + controller `flushTag`).

## Request examples

Valid request enabling fast shipping (test-verified call shape):

```json
{
  "method": "PUT",
  "url": "/api/v1/fast-shipping/settings",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "enabled": true
  }
}
```

# Responses

## Success: settings updated — HTTP 200

Returned via `ApiResponse::apiResponse(FAST_SHIPPING_SETTINGS_UPDATED, 200, true)` with default empty data, so the `data` key is absent. No endpoint-specific success test exists (repository cache invalidation is unit-tested; HTTP coverage is the 401 test below). There is exactly one success contract.

Complete success example:

```json
{
  "status": 200,
  "message": "Fast shipping settings updated successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FAST_SHIPPING_SETTINGS_UPDATED` (locale from `lang` header) | `en` → `"Fast shipping settings updated successfully"`, `ar` → `"تم تحديث إعدادات الشحن السريع بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 401 contract is test-verified (`putJson`, matching this route); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `admin_fast_shipping_settings_update_requires_authentication` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-fast-shipping` permission (Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-fast-shipping` (no endpoint-specific 403 test exists — code-traced through the shared path) | See 403 example |
| 405 | `POST`, `PATCH`, or `DELETE` on `/api/v1/fast-shipping/settings` (no such routes are registered; note `GET` on the same URI is the separate get endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Inline validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `enabled`, `duration_minutes`, `fee`, `start_hour`, `end_hour`; exact messages **Unverified in source** — no update-specific 422 test exists) | Non-boolean `enabled` (including `0`/`1`/strings — code-traced strictness), non-integer/out-of-range `duration_minutes`, non-numeric/negative `fee`, non-`H:i` hour strings | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in validation-adjacent processing or the locked transaction, e.g. missing `settings` row causing a null write — code-traced: `lockForUpdate()->first()` may return null and `$settings->update` would then throw), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Transaction/persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-fast-shipping` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/fast-shipping/settings`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (non-boolean `enabled`):

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
