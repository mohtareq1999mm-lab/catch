# Endpoint Name

Sliders – List (`GET /api/v1/sliders`)

# Description

Returns a paginated slider list with locale-string names and promotion-aware totals.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/sliders` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sliders` from `Route::apiResource('sliders', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-slider` gate decides) |
| Controller action | `Marvel\Http\Controllers\SliderController@index` → `SliderRepository::getSliders` (per_page/limit, `filter_var` active flag, allowlisted ordering with sortable fallback, paginated, per-URL cached) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice: route-level `view-slider|create-slider|update-slider|delete-slider` pipe, then constructor `view-slider` (net effect: `view-slider` is mandatory — code-verified stacking, same as brands) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `sliders.index` (apiResource default naming — LOAD-BEARING: the resource returns the locale STRING name only on this route name; every other slider endpoint returns the `{ar,en}` object) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale AND each item's `name` string |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `per_page` | query | integer | Optional | No | No validation; wins over `limit` when both present | Any positive integer | `15` (when neither given) | None | `2` (test-verified: 3 rows → 2 items, `per_page: 2`, `total: 3`) | Page size (preferred key) |
| `limit` | query | integer | Optional | No | No validation; fallback when `per_page` absent | Any positive integer | `15` | None | `20` | Page size (legacy key) |
| `active` | query | boolean-ish | Optional | No | `filter_var($value, FILTER_VALIDATE_BOOLEAN)` — proper boolean parsing (`"false"`/`"0"`/`""`/`null` are falsy, UNLIKE the brands truthiness check; code-verified difference) | Truthy values | Absent (no filter) | None | `true` (test-verified: active+inactive rows → 1 item) | Restricts to active sliders |
| `order` | query | string | Optional | No | Allowlisted: `id`, `title`, `slug`, `order`, `status`, `created_at`, `updated_at` — anything else falls back to the sortable `ordered()` scope (code-verified) | Allowlist values | Absent (sortable default) | None | `slug` | Sort column |
| `sortedBy` | query | string | Optional | No | Only the exact string `'desc'` selects descending; EVERYTHING else sorts ascending (code-verified) | `'desc'` or anything | `'asc'` behavior | None | `desc` | Sort direction |

Soft-deleted rows are always excluded (model uses `SoftDeletes`; test-verified count exclusion). There is no `422` contract — no validation runs.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/sliders?per_page=2&active=true",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of sliders — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the roles-list-style manual pagination payload. Verified by `test_authenticated_user_can_list_sliders` (200, `success: true`, 2 items, full pagination structure), `test_list_sliders_returns_empty_data_when_none_exist` (200, 0 items, `total: 0`), `test_list_sliders_pagination`, and `test_list_sliders_active_filter` (all use `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Manually built page payload | See `data` fields below |

`data` fields (code-verified; same contract as the users/roles/brands lists):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.data[]` | array of objects | Always present (possibly empty — verified) | Sliders serialized by Marvel `SliderResource` — with the `sliders.index` name check TRUE, so `name` is the LOCALE STRING (code-verified difference from every other slider endpoint) | Each item: `id`, `name` (string), `slug`, `status` (bool), `order`, `image{desktop, mobile}` (with legacy-collection fallbacks), `products` (array — present only when loaded; NOT loaded here, so absent) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.path` / `data.per_page` / `data.total` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | mixed | Always present | Manual pagination with `?? ""` / `?? 0` fallbacks | Same semantics as sibling lists |

Caching (code-verified): per-URL `remember` under the `SLIDERS` tag (up to 4h); mutations flush the tag. There is no cache-bypass parameter.

Complete success example (`en` locale; rows illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 3,
        "name": "Summer Sale",
        "slug": "summer-sale",
        "status": true,
        "order": 1,
        "image": { "desktop": "", "mobile": "" }
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/sliders",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/sliders?page=1",
    "first_page_url": "http://localhost/api/v1/sliders?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (all use `getJson`); the 403 row has no list-specific test (the suite covers 403s on store/update/destroy through the identical constructor gates — cited); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_list_sliders` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-slider` permission (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-slider` (no list-specific 403 test exists; identical constructor-gate path test-verified on sibling slider routes) | See 403 example |
| 405 | `POST`, `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/sliders` path (only `Route::get`+`Route::post`... note `POST /sliders` IS the separate store endpoint — only `PUT`/`PATCH`/`DELETE` 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `PUT`/`PATCH`/`DELETE` to the exact URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

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

405 example (wrong method, e.g. `PUT /api/v1/sliders`):

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
