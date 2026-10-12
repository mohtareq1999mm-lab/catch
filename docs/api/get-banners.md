# Endpoint Name

Banners – List Banners (`GET /api/v1/banners`)

# Description

Returns a paginated list of banners with locale-string titles inside a custom pagination envelope.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/banners` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `banners` from `Route::apiResource('banners', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-banners` gate decides) |
| Controller action | `Marvel\Http\Controllers\BannerController@index` (takes NO `Request` param) → `BannerRepository::getBanners()` reading `request()->limit` (default `15`) and `request()->active`, with `products` eager-loaded, `active()` scope when truthy, `ordered()` (Sortable `order` column), `paginate($limit)`; soft-deleted rows excluded by the default `SoftDeletes` scope |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-banners` via controller constructor (applies to `index` and `show`); the route pipe lists `view-banners\|create-banners\|update-banners\|delete-banners` but the constructor gate is decisive |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; other values ignored, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `banners.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale and `title` locale string (verified by `test_banner_title_is_translatable`: `ar` → `"تخفيضات الصيف"`, `en` → `"Summer Sale"`) |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | Read as `request()->limit ?? 15`; NOT `per_page` — a `per_page` key is silently ignored (code-verified wart shared with the governorate index) | Any positive integer | `15` | None | `15` | Page size |
| `active` | query | boolean-ish | Optional | No | `request()->active ?? false`; truthy applies the `active()` (`status = true`) scope | `0`, `1`, `true`, `false` | `false` (no filter) | None | `1` | Restricts to active banners |

No body fields, path parameters, or file uploads. No `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/banners?limit=15&active=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with a CUSTOM envelope: `data` is an OBJECT (not an array) containing `data` (items) plus Laravel paginator meta flattened by the controller. Verified by `test_authenticated_user_can_list_banners` (`assertOk`, `data.data` count 2, full meta structure) and `test_list_banners_returns_empty_when_none_exist` (`data.data` count 0, `data.total` 0), both `getJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (NOT a plain array — code-verified) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by Marvel `BannerResource`; route is NOT `banners.show`, so `title` is the locale STRING | Item: `id`, `title` (string), `slug`, `description`, `image{desktop, mobile}`, `status` (bool), `products` (only when loaded — loaded here) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.total` | integers | Always present | Paginator meta (`page` duplicates `current_page` — code-verified redundancy) | Paginator values |
| `data.path` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | strings | Always present (possibly `""`) | Paginator links (`""` fallback when null — code-verified) | URLs or `""` |
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
        "title": "Summer Sale",
        "slug": "summer-sale",
        "description": null,
        "image": { "desktop": null, "mobile": null },
        "status": true
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/banners",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/banners?page=1",
    "first_page_url": "http://localhost/api/v1/banners?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation runs here, so no `422` contract.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (verified by `test_guest_gets_401_for_list_banners`, `getJson`) | See 401 example |
| 403 | Authenticated caller lacks `view-banners` (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token + user with no permissions (verified by `test_user_without_view_permission_gets_forbidden_for_index`) | See 403 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
