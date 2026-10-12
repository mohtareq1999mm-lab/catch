# Endpoint Name

Promotions – List Promotions (`GET /api/v1/promotions`)

# Description

Returns a paginated, cached list of promotions with unvalidated filters and raw ordering.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/promotions` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `promotions` from bare `Route::apiResource('promotions', ...)` (NO route middleware — the in-controller `view-promotion` gate decides; note singular `view-promotion`, unlike the plural `view-banners`) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\PromotionController@index` — NO `FormRequest` (unlike coupons): `limit` defaults 15 unvalidated; `search` groups name/code/type LIKE; `status` uses an EXACT `=== 'true'` string comparison (`?status=1` filters DISABLED — code-verified wart); `type`/`type_amount` exact matches; `order_by`/`sort` pass RAW into `orderBy` with NO allowlist (invalid column/direction → SQL error → Handler 409, code-verified edge); paginated + cached per full URL; banner-style manual meta-flattening envelope |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-promotion` via controller constructor (applies to `index` and `show`); no route-level permission exists |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; also selects the `name` locale string) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `promotions.index` (apiResource default naming — and NOTE: `PromotionResource` checks `routeIs('promotions.index')`, TRUE here, so `name` is the locale STRING on index) |

## Request inputs

No validation runs — NOTHING here 422s (bad `order_by` fails later as 409, not 422).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects message locale and `name` locale string |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | Falsy → `15`; unvalidated | Any positive integer | `15` | None | `15` | Page size |
| `search` | query | string | Optional | No | Grouped LIKE over `name`, `code`, `type` (unescaped LIKE — `%` acts as wildcard, code-verified edge) | Any string | None (no filter) | None | `promo` | Free-text filter |
| `status` | query | string | Optional | No | Applied only when the key is present; `=== 'true'` (EXACT string) → `true`, EVERYTHING else (`1`, `0`, `false`, `true`-boolean) → `false` (code-verified wart) | Only the literal string `"true"` selects enabled | None (no filter) | None | `true` | Enabled filter (use the exact string `true`) |
| `type` | query | string | Optional | No | Exact match when key present; no allowlist (unknown types yield empty page, not an error) | `price`, `quantity` | None (no filter) | None | `price` | Promotion-kind filter |
| `type_amount` | query | string | Optional | No | Exact match when key present | `fixed_rate`, `percentage`, `gift` | None (no filter) | None | `percentage` | Amount-kind filter |
| `order_by` | query | string | Optional | No | RAW column into `orderBy` — NO allowlist (invalid column → 409 edge, not 422; code-verified) | Any column (safe: `created_at`) | `created_at` | None | `created_at` | Sort column |
| `sort` | query | string | Optional | No | RAW direction into `orderBy` (invalid direction → 409 edge) | `asc`, `desc` | `desc` (NOTE: desc default — unlike most resources' asc) | None | `desc` | Sort direction |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/promotions?limit=15&status=true",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the banner-style custom envelope. Verified by `test_admin_can_list_promotions` (`assertOk`, structure `data.data.*: id, name, code, type, discount_type, status`, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (same flattened shape as banners) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by `PromotionResource`; route IS `promotions.index`, so `name` is the locale STRING | Item: `id`, `name` (string), `slug`, `type` (localized label via `typeByLang`), `discount_type` (RAW `type_amount`), `value`, `discount` (falls back to `value`), `code`, `minimum_order_amount` (2dp/null), `required_quantity`, `apply_to`, `products` (omitted — not loaded), `gift_products` (omitted), `image{desktop, mobile}`, `start_at`/`end_at` (ISO/null), `status` (bool), `is_valid` (computed), `created_at` (ISO) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.total` | integers | Always present | Paginator meta | Paginator values |
| `data.path` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | strings | Always present (possibly `""`) | Paginator links | URLs or `""` |
| `data.per_page` | integer | Always present | Page size echoed back | The effective `limit` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Promo A",
        "slug": "promo-a",
        "type": "Price",
        "discount_type": "fixed_rate",
        "value": 10,
        "discount": 10,
        "code": "A-ABC123",
        "minimum_order_amount": 0.0,
        "required_quantity": null,
        "apply_to": "all_products",
        "image": { "desktop": null, "mobile": null },
        "start_at": null,
        "end_at": null,
        "status": true,
        "is_valid": true,
        "created_at": "2026-01-01T00:00:00+00:00"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/promotions",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/promotions?page=1",
    "first_page_url": "http://localhost/api/v1/promotions?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. No try/catch on this action — query failures escape to `Handler`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (code-traced; no promotion guest test found) | See 401 example |
| 403 | Authenticated caller lacks `view-promotion` (constructor gate rejects; no route pipe exists) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without `view-promotion` (code-traced) | See 403 example |
| 409 | SQL failure from unvalidated `order_by`/`sort` (`QueryException` branch — the price of no allowlist) | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | e.g. `?order_by=nonexistent_column` (code-traced edge) | See 409 example |
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

409 example (bad `order_by`):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
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
