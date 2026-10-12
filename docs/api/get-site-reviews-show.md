# Endpoint Name

Site Reviews – Show Site Review (`GET /api/v1/site-reviews/{id}`)

# Description

Returns one website review by numeric id with customer and moderator blocks.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/site-reviews/{id}` |
| Path composition | `api/v1` prefix + `site-reviews/{id}` custom `Route::get(...)->whereNumber('id')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:view-site-reviews` (the `whereNumber` constraint means non-numeric segments match NO route → 404 `NotFoundHttpException` shape, NOT the translated miss message — code-verified distinction) |
| Controller action | `Marvel\Http\Controllers\SiteReviewController@show(int $id)` → `SiteReviewService::findReview` (with `user`+`moderator`); null → `apiResponse(SITE_REVIEW_NOT_FOUND, 404, false)` (translated `ERROR.SITE_REVIEW_NOT_FOUND`, code-verified — NOT the fixed string) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-site-reviews` via route middleware AND constructor `only: [index, show]` (double gate, same permission) |
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
| `id` | path | integer | Required | No | `whereNumber('id')` at route level + `int $id` signature; non-numeric → no route match (404 `NotFoundHttpException` shape — see Errors) | Id of an existing site review | None | None | `1` | Identifies the review |

No query parameters, body fields, or `422` contract. No admin endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/site-reviews/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: review returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, AdminSiteReviewResource::make($review))` with `user`+`moderator` loaded.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `AdminSiteReviewResource` | `id`, `user_id`, `customer{id,name,email}`, `rating`, `title`, `comment`, `status`, `moderator{id,name}` or `null`, `moderated_at`, `created_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
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
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the two different 404 shapes (translated miss vs fixed no-route-match).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-site-reviews` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-site-reviews` (code-traced) | See 403 example |
| 404 | No site review with `{id}` (service miss → translated key) | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | Translated `ERROR.SITE_REVIEW_NOT_FOUND`: `en` → `"Site review not found"` | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` (e.g. `/site-reviews/abc`) matches NO route due to `whereNumber` → `NotFoundHttpException` branch (DIFFERENT shape/message from the miss row above — code-verified) | `{message: string, status: boolean}` (Handler shape) | None | `"Not Found"` (HttpException message or fallback — NOT the translated site-review string) | None | Non-numeric segment | See 404-constraint example |
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

404 example (unknown numeric id):

```json
{
  "status": 404,
  "message": "Site review not found",
  "success": false
}
```

404-constraint example (non-numeric id, no route match):

```json
{
  "message": "Not Found",
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
