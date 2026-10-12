# Endpoint Name

Categories – List (`GET /api/v1/categories`)

# Description

Returns a paginated, richly filterable category list with parent and product counts.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/categories` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories` from `Route::apiResource('categories', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-categories` gate decides) |
| Controller action | `Marvel\Http\Controllers\CategoryController@index` (eager `parent` + `products_count`; twelve query inputs below; paginated; per-URL cached) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice: route-level `view-categories|view-category|create-category|update-category|delete-category` pipe, then constructor `view-categories` (net effect: `view-categories` is mandatory — code-verified stacking, same as brands) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `categories.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale, each item's `name` string, AND the `search` locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `per_page` | query | integer | Optional | No | No validation; wins over `limit` when both present | Any positive integer | `15` (when neither given) | None | `20` | Page size (preferred key) |
| `limit` | query | integer | Optional | No | Cast to int; non-positive/zero/absent → `15`; capped at 100 (`min($limit, 100)` — code-verified ceiling, unlike brands/sliders which cap nothing) | Any integer | `15` | None | `50` | Page size (legacy key; effective ceiling 100) |
| `active` | query | boolean-ish | Optional | No | Truthy check (`if ($active)` — any truthy value including `"false"` — code-verified PHP truthiness) applies the `active()` scope | Truthy values | Absent (no filter) | Independent of `inactive` (both truthy applies BOTH scopes → empty result; code-traced) | `true` | Restricts to active categories |
| `inactive` | query | boolean-ish | Optional | No | Same truthiness semantics (note the `$Inactive` capital in code — no behavioral effect); applies the `inactive()` scope | Truthy values | Absent (no filter) | See `active` | `true` | Restricts to inactive categories |
| `status` | query | string/int/bool | Optional | No | STRICT allowlist matching (`in_array(..., true)`): `1/'1'/true/'true'/'True'/'TRUE'` → `1`; `0/'0'/false/'false'/'False'/'FALSE'` → `0`; ANYTHING else (including `'yes'`, `2`, `'active'`) is SILENTLY ignored (code-verified) | The twelve spellings above | Absent (no filter) | AND-combined with `active`/`inactive` (updates the total — code-verified comment) | `1` | Exact status filter |
| `search` | query | string | Optional | No | No validation; locale-aware `LIKE` on the translated `name` when truthy | Any string | Absent (no filter) | None | `Nike` | Substring filter on category names in the request locale |
| `order` | query | string | Optional | No | Allowlisted: `id`, `name`, `slug`, `products_count`, `created_at`, `updated_at`, `level` (note `products_count` + `level` here vs brands/sliders lists — code-verified); anything else keeps sortable... categories have NO sortable trait — unlisted values keep DATABASE-DEFAULT order (code-verified difference from brands/sliders `ordered()` fallback) | Allowlist values | Absent (DB-default order) | None | `slug` | Sort column |
| `sortedBy` | query | string | Optional | No | Only the exact string `'desc'` selects descending; EVERYTHING else sorts ascending (code-verified) | `'desc'` or anything | `'asc'` behavior | None | `desc` | Sort direction |
| `parent` | query | boolean-ish | Optional | No | Truthy → `whereNull('parent_id')` (roots only) | Truthy values | Absent (no filter) | None | `1` | Roots-only switch |
| `exceptSelf` | query | integer | Optional | No | Raw value injected as `where('id', '!=', $selfId)` when truthy (code-verified — building parent pickers that exclude the edited row) | Any id | Absent (no filter) | None | `9` | Excludes one id from results |
| `feature-category` | query | boolean-ish | Optional | No | Truthy → `where('is_featured', true)` (kebab-case key — code-verified literal) | Truthy values | Absent (no filter) | None | `1` | Featured-only switch |
| `level` | query | integer/string | Optional | No | Numeric strings/ints → `where('level', (int))`; non-numeric non-empty values SILENTLY ignored (code-verified) | Numeric values | Absent (no filter) | None | `1` | Depth-level filter |
| `parent_id` | query | integer/string | Optional | No | Numeric → `where('parent_id', (int))`; case-insensitive literal `'null'` → `whereNull('parent_id')`; anything else SILENTLY ignored (code-verified three-way) | Numeric ids or `'null'` | Absent (no filter) | None | `4` or `null` | Parent filter incl. explicit roots |

Soft-deleted rows are always excluded (model uses `SoftDeletes`; same pattern test-verified on sibling resources). There is no `422` contract — no validation runs.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/categories?search=shoes&active=true&per_page=20&order=slug&sortedBy=desc&parent_id=4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of categories — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the roles-list-style manual pagination payload. The category suites assert 200s on this URI across auth/authz/CRUD files (all use `getJson`, matching this route); filter-level value assertions live in the dedicated suites. There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Manually built page payload | See `data` fields below |

`data` fields (code-verified; same contract as sibling lists):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.data[]` | array of objects | Always present (possibly empty) | Categories serialized by Marvel `CategoryResource` | Each item: `id`, `name` (LOCALE STRING except on `categories.show` — see show doc), `slug`, `parent_id`, `parent{id, name, slug}` or `null`, `level`, `image{desktop, mobile}`, `is_featured` (bool), `products_count` (int — always present, eager-counted), `status` (bool); `details` OMITTED on this route (merged only when NOT `categories.index` — code-verified); `children`/`products` absent (relations not loaded here) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.path` / `data.per_page` / `data.total` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | mixed | Always present | Manual pagination with `?? ""` / `?? 0` fallbacks | Same semantics as sibling lists |

Caching (code-verified): per-URL `remember` under the `CATEGORIES` tag (up to 4h); mutations flush the tag. There is no cache-bypass parameter.

Complete success example (`en` locale; rows illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 4,
        "name": "Shoes",
        "slug": "shoes",
        "parent_id": null,
        "parent": null,
        "level": 0,
        "image": { "desktop": "", "mobile": "" },
        "is_featured": false,
        "products_count": 12,
        "status": true
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/categories",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/categories?page=1",
    "first_page_url": "http://localhost/api/v1/categories?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified through the auth/authz suites (all use `getJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_list_categories` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-categories` permission (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-categories`; verified by `test_user_with_no_category_permissions_gets_forbidden` expecting 403 on this exact URI | See 403 example |
| 405 | `POST`, `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/categories` path (only `Route::get`+`Route::post`... note `POST /categories` IS the separate store endpoint — only `PUT`/`PATCH`/`DELETE` 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `PUT`/`PATCH`/`DELETE` to the exact URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

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

405 example (wrong method, e.g. `PUT /api/v1/categories`):

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
