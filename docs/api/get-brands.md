# Endpoint Name

Brands – List (`GET /api/v1/brands`)

# Description

Returns a paginated, searchable brand list with per-locale names and promotion-aware totals.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/brands` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands` from `Route::apiResource('brands', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level `permission:view-brands|view-brand|create-brand|update-brand|delete-brand|import-brand|export-brand` (ANY-of pipe — nearly every brand-privileged caller passes; the constructor `view-brands` gate then decides) |
| Controller action | `Marvel\Http\Controllers\BrandController@index` (per_page/limit, active/inactive scopes, locale-aware name search, allowlisted ordering, sortable default order, paginated, per-URL cached) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice: route-level ANY-of pipe, then constructor `view-brands` (net effect: `view-brands` is mandatory — a caller holding ONLY e.g. `create-brand` passes the route gate but fails the constructor gate with 403; code-verified stacking) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `brands.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale, each item's `name` string, AND the `search` locale (test-verified: `lang: ar` returns `'نايك'`, `en` returns `'Nike'`) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `per_page` | query | integer | Optional | No | No validation; wins over `limit` when both present (`$request->per_page ?? $request->limit ?? 15`) | Any positive integer | `15` (when neither given) | None | `2` (test-verified: 5 rows → `per_page: 2`, 2 items, `total: 5`, `last_page: 3`) | Page size (preferred key) |
| `limit` | query | integer | Optional | No | No validation; fallback when `per_page` absent | Any positive integer | `15` | None | `20` | Page size (legacy key) |
| `active` | query | boolean-ish | Optional | No | Truthy check (`if ($active)` — any truthy value including `"false"` — code-verified PHP truthiness) applies the `active()` scope | Truthy values | Absent (no filter) | Independent of `inactive` (both truthy applies BOTH scopes → empty result; code-traced) | `true` | Restricts to active brands (test-verified) |
| `inactive` | query | boolean-ish | Optional | No | Same truthiness semantics; applies the `inactive()` scope | Truthy values | Absent (no filter) | See `active` | `true` | Restricts to inactive brands (test-verified) |
| `search` | query | string | Optional | No | No validation; locale-aware `LIKE` on the translated `name` when truthy (empty string skips — test-verified `?search=` returns all) | Any string | Absent (no filter) | None | `Nike` (test-verified name + partial-match hits) | Substring filter on brand names in the request locale |
| `order` | query | string | Optional | No | Allowlisted: `id`, `name`, `slug`, `status`, `created_at`, `updated_at` — anything else is SILENTLY ignored, keeping sortable default order (code-verified) | Allowlist values | Absent (sortable `ordered()` scope) | None | `slug` (test-verified with `sortedBy=desc`) | Sort column |
| `sortedBy` | query | string | Optional | No | Only the exact string `'desc'` selects descending; EVERYTHING else (including garbage) sorts ascending (code-verified) | `'desc'` or anything | `'asc'` behavior | None | `desc` | Sort direction |

Soft-deleted rows are always excluded (model uses `SoftDeletes`; test-verified: trashed brand absent from `data.data` count). There is no `422` contract — no validation runs.

## Request examples

Valid request listing active brands matching a search (test-verified shapes):

```json
{
  "method": "GET",
  "url": "/api/v1/brands?search=Nike&active=true&per_page=2&order=slug&sortedBy=desc",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of brands — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the roles-list-style manual pagination payload (same key set incl. `""`/`0` fallbacks). Verified by seven list tests (200s, structure, counts, filters, ordering, empty-DB 200; all use `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Manually built page payload | See `data` fields below |

`data` fields (code-verified against the payload; same contract as the users/roles lists):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.data[]` | array of objects | Always present (possibly empty — verified: empty DB yields 200) | Brands serialized by Marvel `BrandResource` (products relation NOT loaded here → `products` key absent) | Each item: `id`, `name` (LOCALE STRING except on `brands.show` — see show doc), `slug`, `image{desktop, mobile}` (URLs or `""` — test-verified empty strings when no media), `details` (locale string), `status` (bool) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.path` / `data.per_page` / `data.total` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | mixed | Always present | Manual pagination with `?? ""` / `?? 0` fallbacks | Same semantics as the users/roles lists |

Caching (code-verified): per-URL `remember` under the `BRANDS` tag (up to 4h); mutations flush the tag. There is no cache-bypass parameter.

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
        "name": "Nike",
        "slug": "nike",
        "image": { "desktop": "", "mobile": "" },
        "details": "Sportswear",
        "status": true
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/brands",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/brands?page=1",
    "first_page_url": "http://localhost/api/v1/brands?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (all use `getJson`); the 403 row has no list-specific test (the suite covers 403s on store/update/destroy through the identical constructor gates — cited); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_list_brands` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-brands` permission (constructor gate rejects after the route pipe; a caller holding ONLY other pipe permissions still fails here — code-verified stacking) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-brands` (no list-specific 403 test exists; identical constructor-gate path test-verified on sibling brand routes) | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/brands` path (no such routes are registered; note `POST /brands` is the separate store endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

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

405 example (wrong method, e.g. `PUT /api/v1/brands`):

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
