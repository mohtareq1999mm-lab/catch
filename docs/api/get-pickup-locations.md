# Endpoint Name

Pickup Locations – List (`GET /api/v1/pickup-locations`)

# Description

Returns a paginated pickup-location list with strict string-flag filters and fixed ordering.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/pickup-locations` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `pickup-locations` from `Route::apiResource('pickup-locations', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-pickup-locations` gate decides) |
| Controller action | `Marvel\Http\Controllers\PickupLocationController@index` (fixed `display_order`+`id` ordering, per-URL cached, manual pagination payload) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice: route-level `view-pickup-locations|create-pickup-location|update-pickup-location|delete-pickup-location` pipe, then constructor `view-pickup-locations` (net effect: `view-pickup-locations` is mandatory — code-verified stacking) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `pickup-locations.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `per_page` | query | integer | Optional | No | No validation; wins over `limit` when both present | Any positive integer | `15` (when neither given) | None | `20` | Page size (preferred key) |
| `limit` | query | integer | Optional | No | No validation; fallback when `per_page` absent | Any positive integer | `15` | None | `50` | Page size (legacy key; NO 100-cap here, unlike categories — code-verified absence) |
| `active` | query | string | Optional | No | STRICT string comparison: ONLY `'true'` or `'1'` apply the `active()` (`status = true`) scope — everything else (including `'false'`, `'0'`, `'yes'`) is silently ignored (code-verified) | `'true'`, `'1'` | Absent (no filter) | Independent of `inactive` (both matching applies BOTH scopes → empty result; code-traced) | `true` | Active-only switch |
| `inactive` | query | string | Optional | No | Same strictness; applies the `inactive()` (`status = false`) scope | `'true'`, `'1'` | Absent (no filter) | See `active` | `true` | Inactive-only switch |
| `search` | query | string | Optional | No | No validation; `store_name LIKE %search%` when truthy (parameterized binding — code-verified, no injection vector) | Any string | Absent (no filter) | None | `Cairo` | Substring filter on store names (NOT translated — plain column) |

Soft-deleted rows are always excluded (model uses `SoftDeletes`). Ordering is FIXED (`display_order`, then `id`) — no order/sort inputs exist (code-verified difference from brands/attributes lists). There is no `422` contract — no validation runs.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/pickup-locations?search=Cairo&active=true&per_page=20",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of pickup locations — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the roles-list-style manual pagination payload. No endpoint-specific test exists (all expectations below are code-verified). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Manually built page payload | See `data` fields below |

`data` fields (code-verified; same contract as sibling lists):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.data[]` | array of objects | Always present (possibly empty) | Locations serialized by Marvel `PickupLocationResource` | Each item: `id`, `store_name`, `address`, `phone`, `email`, `latitude`, `longitude`, `working_hours` (array-cast), `status` (bool), `display_order`, `is_default` (bool), `created_at` (note: NO `updated_at` key — code-verified) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.path` / `data.per_page` / `data.total` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | mixed | Always present | Manual pagination with `?? ""` / `?? 0` fallbacks | Same semantics as sibling lists |

Caching (code-verified): per-URL `remember` under the `PICKUP_LOCATIONS` tag (up to 4h); mutations flush the tag. There is no cache-bypass parameter.

Complete success example (`en` locale; rows illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 2,
        "store_name": "Cairo Store",
        "address": "123 Main St",
        "phone": "01000000000",
        "email": null,
        "latitude": null,
        "longitude": null,
        "working_hours": null,
        "status": true,
        "display_order": 0,
        "is_default": true,
        "created_at": "2026-10-10T12:00:00.000000Z"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/pickup-locations",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/pickup-locations?page=1",
    "first_page_url": "http://localhost/api/v1/pickup-locations?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. No test covers this endpoint: 401/403 expectations are verified against the shared middleware paths exercised by named tests on sibling routes (cited per row); all other rows are code-verified or code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (identical rejection test-verified on sibling routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `view-pickup-locations` permission (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-pickup-locations` (code-traced — no endpoint-specific 403 test exists) | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/pickup-locations` path (no such routes are registered; note `POST /pickup-locations` IS the separate store endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `PUT`/`PATCH`/`DELETE` to the exact URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-pickup-locations` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/pickup-locations`):

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
