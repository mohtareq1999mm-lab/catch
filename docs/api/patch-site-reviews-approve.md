# Endpoint Name

Site Reviews – Approve Site Review (`PATCH /api/v1/site-reviews/{id}/approve`)

# Description

Approves one pending website review, recording the moderator, and returns 200 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` (only `PATCH` is registered — `PUT`/`POST` on this path → 405) |
| Full path | `/api/v1/site-reviews/{id}/approve` |
| Path composition | `api/v1` prefix + `site-reviews/{id}/approve` custom `Route::patch(...)->whereNumber('id')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:approve-site-reviews` (non-numeric `{id}` matches NO route → 404 `NotFoundHttpException` shape, same distinction as show) |
| Controller action | `Marvel\Http\Controllers\SiteReviewController@approve(Request $request, int $id)` → `SiteReviewService::approveReview($id, $request->user())` in a DB transaction: ONLY the `pending → approved` transition is applied (`moderated_by` = caller id, `moderated_at` = now); missing OR already-moderated (approved/rejected) → `null` → `apiResponse(SITE_REVIEW_NOT_FOUND, 404, false)` (CONFLATION WART code-verified: an already-approved review is indistinguishable from a missing one); success flushes the `site-reviews` frontend cache tag |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (the moderator identity is `$request->user()` — the caller's own id is recorded, code-verified) |
| Permission | Required once: `approve-site-reviews` via route middleware AND constructor `only: [approve]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also recorded as moderator) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` signature | Id of a PENDING site review | None | None | `1` | Identifies the review to approve |

No query parameters, body fields, or `422` contract. No admin endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "PATCH",
  "url": "/api/v1/site-reviews/1/approve",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: review approved — HTTP 200

Returned via `ApiResponse::apiResponse(SITE_REVIEW_APPROVED_SUCCESSFULLY, 200, true, AdminSiteReviewResource::make($review))` with `user`+`moderator` loaded (the `moderator` block is the approving admin).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SITE_REVIEW_APPROVED_SUCCESSFULLY` | `en` → `"Site review approved successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Approved `AdminSiteReviewResource` | `id`, `user_id`, `customer`, `rating`, `title`, `comment`, `status: "approved"`, `moderator{id,name}`, `moderated_at`, `created_at` |

```json
{
  "status": 200,
  "message": "Site review approved successfully",
  "success": true,
  "data": {
    "id": 1,
    "user_id": 7,
    "customer": { "id": 7, "name": "Jane", "email": "jane@example.com" },
    "rating": 5,
    "title": "Great shop",
    "comment": "Loved it",
    "status": "approved",
    "moderator": { "id": 2, "name": "Admin" },
    "moderated_at": "2026-01-02T00:00:00.000000Z",
    "created_at": "2026-01-01T00:00:00.000000Z"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `approve-site-reviews` (note: `view-site-reviews` alone is NOT enough — code-verified permission split; the two site-review moderation permissions are distinct) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `approve-site-reviews` (code-traced) | See 403 example |
| 404 | No PENDING review with `{id}` — unknown id, OR already approved, OR already rejected (all three render IDENTICALLY — conflation wart, code-verified) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SITE_REVIEW_NOT_FOUND`: `en` → `"Site review not found"` | None | Unknown numeric id or re-approving a moderated review | See 404 example |
| 404 | Non-numeric `{id}` matches NO route (`whereNumber`) | `{message: string, status: boolean}` | None | `"Not Found"` (NOT the translated site-review string) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`POST` on `/api/v1/site-reviews/{id}/approve` (only `PATCH` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
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

404 example (unknown OR already-moderated id):

```json
{
  "status": 404,
  "message": "Site review not found",
  "success": false
}
```

404-constraint example (non-numeric id):

```json
{
  "message": "Not Found",
  "status": false
}
```

405 example:

```json
{
  "message": "Method Not Allowed",
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
