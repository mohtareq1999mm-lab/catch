# Endpoint Name

Fast Shipping – Get Settings (`GET /api/v1/fast-shipping/settings`)

# Description

Returns the cached global fast-shipping configuration, falling back to defaults.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/fast-shipping/settings` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `fast-shipping/settings` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (verified middleware chain in `storage/audit-endpoint-inventory.tsv`) |
| Controller action | `Marvel\Http\Controllers\FastShippingController@getSettings` → `FastShippingRepository::getSettings` (reads `settings.options.fast_shipping`, cached 3600s) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-fast-shipping` via controller constructor `permission:view-fast-shipping` middleware (applies only to `getSettings`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No inputs of any kind; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the repository.

## Request inputs

No inputs of any kind are honored. There is no validation, so there is no `422` contract.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/fast-shipping/settings",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: settings returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $settingsCache)`. No endpoint-specific success test exists (repository-level cache behavior is unit-tested; HTTP coverage is the 401 test below). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The `fast_shipping` options array: missing keys fall back to defaults (code-verified `data_get(..., $this->defaults())`) | `enabled` (bool, default `false`), `duration_minutes` (int, default `120`), `fee` (number, default `0`), `start_hour` (string `H:i`, default `'08:00'`), `end_hour` (string `H:i`, default `'22:00'`) |

Caching (code-verified, two layers): repository `Cache::remember('fast_shipping_settings', 3600)`, then controller `remember(FAST_SHIPPING_SETTINGS tag, md5(fullUrl))` up to 4h. Unit-tested (`fast_shipping_settings_use_cache`, `fast_shipping_settings_cache_invalidated_after_update`). There is no cache-bypass parameter.

Complete success example (`en` locale; values illustrative, keys/defaults verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "enabled": true,
    "duration_minutes": 120,
    "fee": 5.0,
    "start_hour": "08:00",
    "end_hour": "22:00"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 401 contract is test-verified (`getJson`, matching this route); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `admin_fast_shipping_settings_requires_authentication` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-fast-shipping` permission (Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-fast-shipping` (no endpoint-specific 403 test exists — code-traced through the shared path) | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/fast-shipping/settings` (only `Route::get` is registered; note `PUT` on the same URI is the separate update endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception, e.g. missing `settings` row causing a null-options read — code-traced: `data_get(null, ...)` returns defaults safely, so this path is effectively unreachable for reads), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-fast-shipping` permission; `en` locale):

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
