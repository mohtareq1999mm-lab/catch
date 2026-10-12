# Endpoint Name

Settings – Get Site Settings (`GET /api/v1/settings`)

# Description

Returns the singleton site-settings row with locale-paired texts and media URLs.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/settings` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `settings` from the settings group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level `permission:view-settings|update-settings` (Spatie pipe = EITHER permission suffices at route level) |
| Controller action | `Marvel\Http\Controllers\SettingsController@index` (`Settings::first()` singleton, per-URL cached) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice: route-level `view-settings|update-settings` (either) AND constructor `view-settings` (both must... no — BOTH middlewares run: the route gate passes with EITHER permission, then the constructor gate requires `view-settings` specifically. Net effect: `view-settings` is mandatory; `update-settings` alone FAILS the constructor gate — code-verified stacking) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No inputs of any kind; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

## Request inputs

No inputs of any kind are honored. There is no validation, so there is no `422` contract.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (the texts themselves always return BOTH locales — see Responses) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/settings",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: settings returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make($settingCache))`. Verified by `admin_can_read_the_currency_selection_setting` (`assertOk`, `data.currency_selection_enabled === false` default; uses `getJson`, matching this route) and `settings_cache_is_cleared_after_updating_currency_selection` (read-update-read round trip). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The singleton row serialized by `SettingResource` | See `data` fields below (admin shape — NOT the `settings.front` single-locale shape) |

`data` fields (code-verified; this route is never named `settings.front`, so the `{ar,en}` object branches always apply):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.site_name` / `site_desc` / `meta_desc` / `site_copy_right` | objects | Always present | `{ar, en}` translation pairs (each side nullable) | E.g. `{"ar": "موقع تجريبي", "en": "Test Site"}` |
| `data.logo` / `footer_logo` / `favicon` | string or null | Always present | First media URLs of the respective collections, else `""`/null per media library (code-traced falsy form) | URL string or empty |
| `data.site_email` / `email_support` / `facebook` / `instagram` / `linkedin` / `promotion_video_url` / `youtube` / `tiktok` / `snapchat` / `phone` / `fast_shipping_page_publish` | mixed | Always present | Stored scalars (nullable columns surface as `null`) | As stored |
| `data.minimumOrderAmount` | number or null | Always present | Stored minimum (note camelCase key vs snake_case input/column — code-verified naming split) | E.g. `0` |
| `data.order_tax_enabled` | boolean | Always present | Cast bool | `true`/`false` |
| `data.order_tax_rate` | number or null | Always present | Float-cast when non-null | E.g. `15.0` or `null` |
| `data.currency_selection_enabled` | boolean | Always present | `options.currency_selection_enabled` defaulting `false` (test-verified default) | `true`/`false` |
| `data.options` | object or null | Always present | Raw stored `options` JSON | Full options map or `null` |

Empty-table behavior (code-verified null invariant in the resource — no test): `Settings::first()` returning null still yields 200 with the null shape (`{ar:null,en:null}` texts, null scalars, `order_tax_enabled: false`, `currency_selection_enabled: false`). Caching (code-verified): per-URL `remember` up to 4h under the `SETTINGS` tag; unit/integration-tested invalidation on update (see the PUT doc).

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "site_name": { "ar": "موقع تجريبي", "en": "Test Site" },
    "site_desc": { "ar": null, "en": null },
    "meta_desc": { "ar": null, "en": null },
    "site_copy_right": { "ar": null, "en": null },
    "logo": null,
    "footer_logo": null,
    "favicon": null,
    "site_email": null,
    "email_support": null,
    "facebook": null,
    "instagram": null,
    "linkedin": null,
    "promotion_video_url": null,
    "youtube": null,
    "tiktok": null,
    "snapchat": null,
    "phone": null,
    "fast_shipping_page_publish": null,
    "minimumOrderAmount": 0,
    "order_tax_enabled": false,
    "order_tax_rate": null,
    "currency_selection_enabled": false,
    "options": { "currency": "USD" }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified through the permission matrix below (all use `getJson`); the 403 row deserves care — see Conditions.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no settings-specific 401 test exists; identical rejection test-verified on sibling routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Caller fails the stacked gates: the route `view-settings\|update-settings` pipe passes with EITHER, but the constructor `view-settings` check then rejects anyone lacking it — net effect `view-settings` is mandatory (code-verified stacking; a hypothetical `update-settings`-only caller gets 403 here despite passing the route gate) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-settings` (no settings-specific 403 test exists; identical middleware path test-verified on sibling routes) | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/settings` (only `Route::get` is registered for reads; note `PUT` on the same URI is the separate update endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-settings` permission; `en` locale):

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
