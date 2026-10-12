# Endpoint Name

Tags – List Tags (`GET /api/v1/tags`)

# Description

Returns a paginated, cached list of tags with id/slug bulk filters and locale-string names.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/tags` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `tags` from bare `Route::apiResource('tags', ...)` (NO route middleware — the in-controller `view-tags` gate decides; note plural `view-tags`, unlike singular `view-promotion`) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\TagController@index` — NO `FormRequest`: `limit` defaults 15 unvalidated; `ids`/`slugs` accept an ARRAY or a COMMA-SEPARATED string (`filled` guard — empty values ignored, code-verified); NO SoftDeletes exclusion issue (`Tag` uses `SoftDeletes` — trashed excluded by default scope, code-verified); paginated (no `withQueryString`) + cached per full URL; banner-style manual meta-flattening envelope |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-tags` via controller constructor (applies to `index` and `show`); no route-level permission exists |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; also selects the `name` locale string) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `tags.index` (apiResource default naming) |

## Request inputs

No validation runs — NOTHING here 422s.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects message locale and `name` locale string |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | Falsy → `15`; unvalidated | Any positive integer | `15` | None | `15` | Page size |
| `ids` | query | array OR comma string | Optional | No | Applied only when `filled`; array used as-is, otherwise `explode(',', ...)` (e.g. `?ids=1,2,3`; code-verified dual shape) | Id arrays or CSV | None (no filter) | None | `1,2,3` | Bulk id filter |
| `slugs` | query | array OR comma string | Optional | No | Same dual shape as `ids`, matched against `slug` | Slug arrays or CSV | None (no filter) | None | `organic,sale` | Bulk slug filter |

No body fields, path parameters, or file uploads. No endpoint test found for the ADMIN index (only the PUBLIC `general/tags` sibling has tests — different route/controller) — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/tags?limit=15&ids=1,2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the banner-style custom envelope. `products` never loaded on index (key omitted via `mergeWhen`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (same flattened shape as banners) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by `TagResource`; route is NOT `tags.show`, so `name` is the locale STRING | Item: `id`, `name` (string), `slug`, `image` (media URL or null), `icon` (SAME `tags` collection URL as `image` — both keys always equal, code-verified wart), NO `products` key when unloaded |
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
        "name": "Organic",
        "slug": "organic",
        "image": null,
        "icon": null
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/tags",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/tags?page=1",
    "first_page_url": "http://localhost/api/v1/tags?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. No try/catch — failures escape to `Handler`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-tags` (constructor gate rejects; no route pipe exists) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without `view-tags` (code-traced) | See 403 example |
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
