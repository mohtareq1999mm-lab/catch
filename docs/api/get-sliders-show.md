# Endpoint Name

Sliders – Show Slider (`GET /api/v1/sliders/{slider}`)

# Description

Returns one slider by id with products and the bilingual title object.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/sliders/{slider}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sliders/{slider}` from `Route::apiResource('sliders', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-slider` gate decides) |
| Controller action | `Marvel\Http\Controllers\SliderController@show` (manual `findOrFail` — NOT binding; ANY exception funnels to the 404 envelope, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-slider` via controller constructor `permission:view-slider` middleware (applies to `index` and `show`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `sliders.show` (apiResource default naming — but NOTE: the resource's bilingual branch checks `routeIs('sliders.index')`, which is FALSE here, so `title` is the `{ar,en}` OBJECT on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (`title` stays bilingual here regardless) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `slider` | path | integer id (no route-level constraint; resolved by manual `findOrFail` under the `SoftDeletes` scope) | Required | No | Raw segment; unknown/trashed ids throw `ModelNotFoundException`, caught by `catch (\Exception)` → `apiResponse(NOT_FOUND, 404)` envelope (code-verified funnel — even non-miss failures 404 here) | Id of a non-trashed slider | None | None | `5` | Identifies the slider to return |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract. (Slug strings do NOT resolve here — unlike brands show, this lookup is id-only; a slug 404s. Code-verified difference.)

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/sliders/5",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: slider returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SliderResource::make($slider))` with `products` loaded. Verified by `test_authenticated_user_can_show_slider` (`assertOk`, `data.id` + `data.slug` paths) and the structure test (`data: [id, title, slug, image, status, order]`; both use `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The slider serialized by Marvel `SliderResource` — with the route NOT named `sliders.index`, `title` is the `{ar,en}` OBJECT (code-verified) | `id`, `title{ar, en}`, `slug`, `status` (bool), `order`, `image{desktop, mobile}` (with legacy fallbacks), `products` (array — present since loaded) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 5,
    "title": { "ar": null, "en": "Summer Sale" },
    "slug": "summer-sale",
    "status": true,
    "order": 1,
    "image": { "desktop": "", "mobile": "" },
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/404 contracts are test-verified (all use `getJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_show_slider` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-slider` permission (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-slider` (no show-specific 403 test exists; identical constructor-gate path test-verified on sibling slider routes) | See 403 example |
| 404 | No non-trashed slider matches `{slider}` (manual `findOrFail` miss) — OR ANY other exception in the lookup/serialize: the controller's `catch (\Exception)` converts EVERYTHING to `apiResponse(NOT_FOUND, 404)` with the translated notice (code-verified funnel) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — the test asserts status only) | None | Unknown id (verified by `test_show_slider_returns_404_for_nonexistent_id` for `9999`); trashed id (verified by `test_soft_deleted_slider_returns_404_on_show`); slug string (id-only lookup — code-traced); any lookup failure (code-traced) | See 404 example |
| 405 | `POST` on `/api/v1/sliders/{slider}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `DELETE` to destroy) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on this action (all lookup exceptions funnel to the 404 above — a REAL outage here still renders 404; code-verified consequence).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-slider` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{slider}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/sliders/5`):

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
