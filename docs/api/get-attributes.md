# Endpoint Name

Attributes – List (`GET /api/v1/attributes`)

# Description

Returns a paginated attribute list with values eager-loaded and locale-string names.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/attributes` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `attributes` from `Route::apiResource('attributes', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-attributes` gate decides) |
| Controller action | `Marvel\Http\Controllers\AttributeController@index` (`values` eager-loaded TWICE — `with('values')` assigned then re-applied; harmless redundancy, code-verified; paginated with query string; per-URL cached; manual payload) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice: route-level `view-attributes|create-attribute|update-attribute|delete-attribute` pipe, then constructor `view-attributes` (net effect: `view-attributes` is mandatory — code-verified stacking) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `attributes.index` (apiResource default naming — LOAD-BEARING: the resource returns the locale-STRING name only on this route name; every other attribute endpoint returns the `{ar,en}` object) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale AND each item's `name` string |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | No validation; passed to `paginate($limit)` | Any positive integer | `15` | None | `20` | Page size (code-traced; no max cap is enforced) |
| `order` | query | string | Optional | No | Allowlisted: `id`, `name`, `slug`, `created_at`, `updated_at` — anything else keeps DATABASE-DEFAULT order (no sortable fallback here — code-verified difference from brands/sliders) | Allowlist values | Absent (DB-default order) | None | `slug` | Sort column |
| `sortedBy` | query | string | Optional | No | Only the exact string `'desc'` selects descending; EVERYTHING else sorts ascending (code-verified) | `'desc'` or anything | `'asc'` behavior | None | `desc` | Sort direction |

There is no active/inactive filter, no search, and no soft-delete exclusion at all — the `Attribute` model uses NO `SoftDeletes` trait (code-verified), so nothing is ever trashed here. There is no `422` contract — no validation runs.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/attributes?limit=20&order=slug&sortedBy=desc",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of attributes — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the roles-list-style manual pagination payload. Verified by `test_authenticated_user_can_list_attributes` (200 with 2 rows) and `test_list_attributes_returns_empty_data_when_none_exist` (200 with no rows; both use `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Manually built page payload | See `data` fields below |

`data` fields (code-verified; same contract as sibling lists):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.data[]` | array of objects | Always present (possibly empty — verified) | Attributes serialized by Marvel `AttributeResource` — with the `attributes.index` name check TRUE, so `name` is the LOCALE STRING (code-verified) | Each item: `id`, `name` (string), `slug`, `values` (array — always present here since eager-loaded; item keys per `AttributeValueResource` — exact shape **Unverified in source**, no item-level test found) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.path` / `data.per_page` / `data.total` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | mixed | Always present | Manual pagination with `?? ""` / `?? 0` fallbacks | Same semantics as sibling lists |

Caching (code-verified): per-URL `remember` under the `ATTRIBUTES` tag (up to 4h); mutations flush the tag. There is no cache-bypass parameter.

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
        "name": "Size",
        "slug": "size",
        "values": []
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/attributes",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/attributes?page=1",
    "first_page_url": "http://localhost/api/v1/attributes?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200 contracts are test-verified (both use `getJson`). Guest behavior is CONTRADICTED between suites — see the 401 row.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` group middleware runs before the route pipe): the verified middleware chain yields 401. CONTRADICTION FLAG: `AttributeApiTest::test_guest_cannot_list_attributes` asserts **403** for this exact call, while `AttributesProductionHardenTest` hedges `[401, 403]` on list/show but asserts strict 401 for guest create/update/delete through the IDENTICAL stack. Per the verified chain (group `auth:sanctum` first) plus the hardened strict assertions, 401 is the code-verified outcome and the two 403 guest assertions look STALE — flagged, not resolved (tests NOT RUN here) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `view-attributes` permission (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-attributes` (no list-specific 403 test exists; identical constructor-gate path test-verified on sibling attribute routes) | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/attributes` path (no such routes are registered; note `POST /attributes` IS the separate store endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `PUT`/`PATCH`/`DELETE` to the exact URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-attributes` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/attributes`):

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
