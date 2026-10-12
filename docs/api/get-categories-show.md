# Endpoint Name

Categories – Show Category (`GET /api/v1/categories/{category}`)

# Description

Returns one category by id with parent, children, products, and bilingual names.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/categories/{category}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/{category}` from `Route::apiResource('categories', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-categories` gate decides) |
| Controller action | `Marvel\Http\Controllers\CategoryController@show` (manual id lookup with `parent` + `products` eager loads, `products_count`, direct-children load via `CategoryHierarchyService`; ANY exception — including the miss — funnels to `MarvelException(NOT_FOUND)` since the catch is `MarvelException`-typed... wait: `findOrFail` throws `ModelNotFoundException`, which is NOT a `MarvelException`, so the `catch (MarvelException $e)` does NOT catch it — it escapes to `Handler`'s `ModelNotFoundException` branch → 404 FIXED string. Code-verified: the catch only converts an already-`MarvelException` (effectively dead for the miss path)) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-categories` via controller constructor `permission:view-categories` middleware (applies to `index` and `show` — note: NOT the singular `view-category`, which exists as an enum value but gates nothing here; code-verified); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `categories.show` (apiResource default naming — LOAD-BEARING: the resource returns the `{ar,en}` name object ONLY on this route name) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (`name` is bilingual here regardless) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `category` | path | integer id (no route-level constraint; resolved by manual `where('id', ...)->firstOrFail()` — slugs do NOT resolve here, unlike brands show; a slug 404s. Code-verified difference) | Required | No | Raw segment; unknown/trashed ids throw `ModelNotFoundException`, which ESCAPES the `MarvelException`-only catch → `Handler` 404 fixed string (code-verified) | Id of a non-trashed category | None | None | `9` | Identifies the category to return |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/categories/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: category returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CategoryResource::make($category))` with `parent`, `products`, `products_count`, and direct children loaded. The category suites assert 200s on this URI across auth/authz files (all use `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The category serialized by Marvel `CategoryResource` — with the `categories.show` name check TRUE, so `name` is the `{ar,en}` OBJECT (code-verified) | `id`, `name{ar, en}`, `slug`, `parent_id`, `parent{id, name, slug}` or null, `level`, `image{desktop, mobile}`, `is_featured`, `products_count`, `status`, `details` (locale string — present: not the index route), `children` (array — present when the relation loaded non-empty), `products` (array — present since loaded; item shape `{id, name, slug, status, image{thumbnail}}`) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 9,
    "name": { "ar": "أحذية", "en": "Shoes" },
    "slug": "shoes",
    "parent_id": null,
    "parent": null,
    "level": 0,
    "image": { "desktop": "", "mobile": "" },
    "is_featured": false,
    "products_count": 3,
    "status": true,
    "details": "Top shoes",
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified through the auth/authz suites (all use `getJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_view_category` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-categories` permission (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-categories` (no show-specific 403 test found in this pass; identical constructor-gate path test-verified on the sibling index route) | See 403 example |
| 404 | No non-trashed category matches `{category}`: the manual `firstOrFail` throws `ModelNotFoundException`, which ESCAPES the `MarvelException`-only catch → `Handler` renders its `ModelNotFoundException` branch (code-verified — fixed string, NOT the translated notice) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown/trashed id, or a slug string (id-only lookup — code-traced) | See 404 example |
| 405 | `POST` on `/api/v1/categories/{category}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `DELETE` to destroy) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on the lookup path as such (misses escape as 404 fixed; anything else would need a catch-all that doesn't exist — serialization failures bubble to generic 500, code-traced edge).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-categories` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{category}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/categories/9`):

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
