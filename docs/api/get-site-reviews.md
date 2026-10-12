# Endpoint Name

Site Reviews – List Site Reviews (`GET /api/v1/site-reviews`)

# Description

Returns a paginated list of website reviews with moderation state and a clamped page size.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/site-reviews` |
| Path composition | `api/v1` prefix + `site-reviews` custom `Route::get` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:view-site-reviews` (NOT a pipe — code-verified) |
| Controller action | `Marvel\Http\Controllers\SiteReviewController@index` → `SiteReviewService::getAllReviews($status, $limit)` with `user`+`moderator` eager-loaded (N+1-safe by design, code-verified), `latest()` ordering, `paginate($limit)`; rendered through the same manual meta-flattening envelope as banners (controller builds `data.data` + `page/current_page/from/to/last_page/path/per_page/total/*_url`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-site-reviews` via route middleware AND constructor `only: [index, show]` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `limit` | query | integer | Optional | No | Clamped `max(1, min((int) query('limit', 15), 100))` — `0`/negative → `1`, over `100` → `100` (code-verified) | `1`–`100` effective | `15` | None | `15` | Page size |
| `status` | query | string | Optional | No | Applied ONLY when non-null, not `'all'`, and one of `SiteReviewStatus::values()` (`pending/approved/rejected`); any other value is SILENTLY IGNORED (no filter, no 422 — code-verified laxity) | `pending`, `approved`, `rejected`, `all` | None (no filter) | None | `pending` | Filters by moderation state |

No body fields or path parameters. No `422` contract. No admin endpoint test found — all contracts code-traced, NOT test-verified (only the PUBLIC `general/site-reviews` sibling has tests, which is a different controller/route).

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/site-reviews?limit=15&status=pending",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the banner-style custom envelope (`data` is an OBJECT). Items use `AdminSiteReviewResource` (moderation fields exposed — unlike the public resource).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (same flattened shape as banners index — code-verified) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by `AdminSiteReviewResource` | Item: `id`, `user_id`, `customer{id,name,email}` (when `user` loaded — always here), `rating`, `title`, `comment`, `status` (enum string), `moderator{id,name}` or `null`, `moderated_at`, `created_at` |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.total` | integers | Always present | Paginator meta | Paginator values |
| `data.path` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | strings | Always present (possibly `''`) | Paginator links (`''` fallback when null) | URLs or `''` |
| `data.per_page` | integer | Always present | Effective (possibly clamped) page size | `1`–`100` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "user_id": 7,
        "customer": { "id": 7, "name": "Jane", "email": "jane@example.com" },
        "rating": 5,
        "title": "Great shop",
        "comment": "Loved it",
        "status": "pending",
        "moderator": null,
        "moderated_at": null,
        "created_at": "2026-01-01T00:00:00.000000Z"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/site-reviews",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/site-reviews?page=1",
    "first_page_url": "http://localhost/api/v1/site-reviews?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422` (an invalid `status` is ignored, not rejected).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced; no admin guest test — the public sibling is a different route) | See 401 example |
| 403 | Authenticated caller lacks `view-site-reviews` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-site-reviews` (code-traced double gate) | See 403 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

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
