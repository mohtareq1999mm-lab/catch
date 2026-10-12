# Endpoint Name

Brands – Show Brand (`GET /api/v1/brands/{brand}`)

# Description

Returns one brand by id or slug with products, using the bilingual name object.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/brands/{brand}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/{brand}` from `Route::apiResource('brands', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-brands` gate decides) |
| Controller action | `Marvel\Http\Controllers\BrandController@show` (numeric segment → id lookup, else slug lookup, both under the `SoftDeletes` scope with `products` eager-loaded; ANY exception — including the miss — funnels to `MarvelException(NOT_FOUND)`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-brands` via controller constructor `permission:view-brands` middleware (applies to `index` and `show` — note: NOT the singular `view-brand`, which exists as an enum value but gates nothing here; code-verified); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `brands.show` (apiResource default naming — LOAD-BEARING: the resource returns the `{ar,en}` name object ONLY on this route name) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (`name` is bilingual here regardless — see Responses) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `brand` | path | integer id OR slug string (no route-level constraint; `is_numeric` dispatch) | Required | No | Numeric → `where('id', (int))`; otherwise → `where('slug', ...)`; both under the `SoftDeletes` scope with `products` eager-loaded. ANY failure (miss, trashed, DB error) is caught by `catch (\Exception)` → `MarvelException(NOT_FOUND)` → 404 translated (code-verified funnel — even non-miss failures 404 here) | Id of a non-trashed brand, or its slug | None | None | `5` or `nike` | Identifies the brand to return |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

Valid request showing a brand by slug (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/brands/nike",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: brand returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, BrandResource::make($brand))`. Verified by `test_authenticated_user_can_show_brand_by_id` (`data.id` path), `test_authenticated_user_can_show_brand_by_slug` (slug URI 200), and `test_brand_resource_structure_on_show` (structure `data: [id, name, slug, image{desktop, mobile}, details, status]` + empty-string image URLs with no media; all use `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The brand serialized by Marvel `BrandResource` — with the `brands.show` name check TRUE, so `name` is the `{ar, en}` OBJECT (code-verified difference from every other brand endpoint, where it is a locale string) | `id`, `name{ar, en}`, `slug`, `image{desktop, mobile}` (URLs or `""`), `details` (locale string), `status` (bool), `products` (array — present since loaded; item shape `{id, name, slug, status, image{thumbnail}}`) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 5,
    "name": { "ar": "نايك", "en": "Nike" },
    "slug": "nike",
    "image": { "desktop": "", "mobile": "" },
    "details": "Sportswear",
    "status": true,
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/404 contracts are test-verified (all use `getJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_show_brand` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-brands` permission (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-brands` (no show-specific 403 test exists; identical constructor-gate path test-verified on sibling brand routes) | See 403 example |
| 404 | No non-trashed brand matches `{brand}` (by id or slug): the miss — or ANY other exception — is caught by `catch (\Exception)` → `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice (code-verified funnel) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — tests assert status only) | None | Unknown id (verified by `test_show_brand_returns_404_for_nonexistent_id` for `9999`), unknown slug (verified by `test_show_brand_returns_404_for_nonexistent_slug`), or trashed id/slug (verified by `test_soft_deleted_brand_returns_404_on_show`) | See 404 example |
| 405 | `POST` on `/api/v1/brands/{brand}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `DELETE` to destroy) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on the lookup path as such (all exceptions funnel to the 404 above — a REAL outage here would still render 404; code-verified consequence).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-brands` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown/trashed `{brand}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/brands/nike`):

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
