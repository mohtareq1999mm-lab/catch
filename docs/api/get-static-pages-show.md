# Endpoint Name

Static Pages – Show Page (`GET /api/v1/static-pages/{static_page:slug}`)

# Description

Returns a single static page by slug with sections and media eager-loaded.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/static-pages/{slug}` (the `{static_page:slug}` placeholder binds on the `slug` column — an id does NOT resolve unless a row's slug literally equals it) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `static-pages/{static_page:slug}` from the static-pages group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (admin lookup — distinct from the public `Api\General\StaticPageController@show`, which additionally filters to active pages) |
| Controller action | `Marvel\Http\Controllers\StaticPageController@show` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-static-pages` via controller constructor `permission:view-static-pages` middleware (applies to `index` and `show`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (also selects the resolved `title` string) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `static_page` | path | slug string (implicit route-model binding on the `slug` column via the `:slug` marker) | Required | No | Resolved by Laravel before the method runs; unknown slugs throw `ModelNotFoundException` → `Handler` 404 (the method has no try/catch at all) | An existing page `slug` (active or not — no flag filter applies here, unlike the public lookup) | None | None | `about-us` | Identifies the page to return, by slug |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/static-pages/about-us",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, StaticPageResource::make($static_page))` after loading `staticSections.media`. Verified by `test_view_permission_allows_index_and_show_but_blocks_mutations` and `test_full_permissions_allows_all_admin_operations` (both `assertOk` on the slug URI; `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The page serialized by `App\Http\Resources\StaticPage\StaticPageResource` | `id`, `slug`, `title` (locale string), `is_active` (bool), `sections` (array — always present; every section carries `media`, possibly `null`) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "slug": "about-us",
    "title": "About Us",
    "is_active": true,
    "sections": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified (all use `getJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_cannot_access_admin_show` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-static-pages` permission (Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-static-pages`; verified by `test_user_without_permission_gets_403` expecting 403 on the slug URI | See 403 example |
| 404 | No page has `slug = {static_page}`: implicit binding throws `ModelNotFoundException` before the method runs → `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown slug, or a numeric id (binding is on the slug column — code-traced) | See 404 example |
| 405 | `POST` on `/api/v1/static-pages/{slug}` (no such route is registered; note `PUT` routes to the sibling update endpoint and suffixed POST URIs are separate actions) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a slug segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the load/serialize), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Serialization failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-static-pages` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown slug):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/static-pages/about-us`):

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
