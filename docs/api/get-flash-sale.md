# Endpoint Name

Flash Sale – List Flash Sales (`GET /api/v1/flash-sale`)

# Description

Returns a paginated, cached list of flash sales with active/inactive/search/sort filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/flash-sale` (NOTE singular `flash-sale` — code-verified; the apiResource name is singular, so route names are `flash-sale.index`, etc.) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `flash-sale` from `Route::apiResource('flash-sale', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-flash-sale` gate decides; the constant is even spelled `VIEW_FlASH_SALE` with a capital L in source — value is the correct lowercase string, code-verified cosmetic wart) |
| Controller action | `Marvel\Http\Controllers\FlashSaleController@index` → `fetchFlashSales` query (SoftDeletes scope active): `active` → `valid()` scope, `inactive` → `invalid()` scope (both truthy = intersection, code-verified), `search` → locale `title` LIKE, `order` allowlist + `sortedBy`; paginated and cached per full URL (`remember(FLASH_SALES, md5(fullUrl))`); banner-style manual meta-flattening envelope |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-flash-sale` via controller constructor (applies to `index` and `show`); the route pipe lists all four but the constructor gate is decisive |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; also selects the `title` locale string and the `type` label language) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `flash-sale.index` (singular apiResource naming — and NOTE: `FlashSaleResource` checks `routeIs('flash-sale.index')`, TRUE here, so `title` is the locale STRING on index) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects message locale, `title`/`description` locale, and `type` label language |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `per_page` | query | integer | Optional | No | First precedence: `$request->per_page ?? $request->limit ?? 10` (BOTH keys accepted — unlike every other resource documented so far; code-verified) | Any positive integer | `10` (not 15) | None | `10` | Page size |
| `limit` | query | integer | Optional | No | Fallback when `per_page` absent | Any positive integer | `10` | None | `10` | Page size alias |
| `active` | query | truthy flag | Optional | No | When truthy → `valid()` scope (status + date window); NO validation — any truthy string works | Truthy values | None (no filter) | None | `1` | Restricts to currently-valid sales |
| `inactive` | query | truthy flag | Optional | No | When truthy → `invalid()` scope | Truthy values | None (no filter) | None | `1` | Restricts to expired/disabled sales |
| `search` | query | string | Optional | No | Locale `title` LIKE (+ raw-column fallback per `scopeSearch`) | Any string | None (no filter) | None | `summer` | Filters by title |
| `order` | query | string | Optional | No | Allowlist `['id', 'title', 'slug', 'type', 'discount', 'status', 'start_date', 'end_date', 'created_at', 'updated_at']` — anything else SILENTLY ignored (no ordering, no 422; code-verified) | Allowlisted columns | DB default order | None | `start_date` | Sort column |
| `sortedBy` | query | string | Optional | No | `'desc'` → desc, ANYTHING else (including garbage) → asc (code-verified) | `asc`, `desc` | `asc` | Only meaningful with `order` | `desc` | Sort direction |

No body fields, path parameters, or file uploads. No `422` contract. No endpoint test found — all contracts code-traced, NOT test-verified.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/flash-sale?per_page=10&active=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the banner-style custom envelope (`data` is an OBJECT). Results are cached per full URL.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (same flattened shape as banners) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by Marvel `FlashSaleResource`; route IS `flash-sale.index`, so `title` is the locale STRING; `products` omitted (relation not loaded on index) | Item: `id`, `title` (string), `slug`, `image{desktop, mobile}` (URLs, possibly `""`), `description` (locale string — ALWAYS locale-string, no show branch), `start_date`, `end_date`, `status` (bool), `is_valid` (bool, computed), `type` (LOCALIZED LABEL like `"Percentage discount"`, NOT the raw `percentage` — code-verified `typeByLang`), `discount`, `max_discount_amount` (2dp or null), `created_at` (`Y-m-d` format, NOT ISO — code-verified) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.total` | integers | Always present | Paginator meta (`page` duplicates `current_page`) | Paginator values |
| `data.path` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | strings | Always present (possibly `""`) | Paginator links | URLs or `""` |
| `data.per_page` | integer | Always present | Page size echoed back | The effective limit |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "title": "Summer Sale",
        "slug": "summer-sale",
        "image": { "desktop": "", "mobile": "" },
        "description": "Up to 50% off",
        "start_date": "2026-01-01",
        "end_date": "2026-02-01",
        "status": true,
        "is_valid": true,
        "type": "Percentage discount",
        "discount": "50.00",
        "max_discount_amount": 100.0,
        "created_at": "2026-01-01"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/flash-sale",
    "per_page": 10,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/flash-sale?page=1",
    "first_page_url": "http://localhost/api/v1/flash-sale?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the try/catch only converts `MarvelException`s — real query failures (QueryException, etc.) ESCAPE to `Handler`, so the translated 500 below is nearly dead code (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (code-traced; no flash-sale guest test found) | See 401 example |
| 403 | Authenticated caller lacks `view-flash-sale` (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without `view-flash-sale` (code-traced) | See 403 example |
| 500 | `MarvelException` inside the index path (nearly dead — only `MarvelException`s are caught; anything else escapes to the generic 500 with the same shape) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong"` | None | Post-auth failure (code-traced) | See 500 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example:

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

500 example:

```json
{
  "status": 500,
  "message": "Something went wrong",
  "success": false
}
```

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
