# Endpoint Name

Order Statuses – List Statuses (`GET /api/v1/admin/order-statuses`)

# Description

Returns a paginated status catalog with escaped search and a clamped page size.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/order-statuses` |
| Path composition | `api/v1` prefix + `admin/order-statuses/` from the `Route::prefix('admin/order-statuses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-statuses.index`) in `packages/marvel/src/Rest/Routes.php`. No `lang` in group middleware (same as its order-flow siblings) |
| Controller action | `App\Http\Controllers\Api\Admin\OrderStatusCatalogController@index` (constructor `auth:sanctum` ONLY — no permission middleware; the route pipe alone decides): `search` over `code`/`name` with LIKE metacharacters ESCAPED (`addcslashes`, same as flows index — code-verified), `is_active` via `FILTER_VALIDATE_BOOLEAN` (same `abc`-means-false wart — code-verified), `id ASC` ordering, custom 3-key meta envelope (same shape as flows index) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `view-order-flows` / `view-orders` / `view-order` via the route pipe (no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests) |
| Route name | `api.admin.order-statuses.index` (explicit named route) |

## Request inputs

No `FormRequest` — NOTHING here 422s.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `search` | query | string | Optional | No | Applied only when `filled`; LIKE-escaped `code`/`name` match (`%`/`_` literal) | Any string | None (no filter) | None | `pend` | Free-text filter |
| `is_active` | query | boolean-ish | Optional | No | Applied only when `filled`; `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — unrecognized strings become `false` (code-verified wart) | `true`/`false`/`1`/`0` | None (no filter) | None | `true` | Active filter |
| `per_page` | query | integer | Optional | No | Clamped `max(1, min(200, (int) per_page))` inline — never rejected (code-verified; same 200 cap / 50 default as flows) | Effective `1`–`200` | `50` | None | `50` | Page size |

## Request examples

Valid request listing the catalog (test-compatible call):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/order-statuses",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: catalog returned — HTTP 200 (CUSTOM 3-key meta envelope)

Returned via `ApiResponse::apiResponse('Order statuses retrieved successfully.', 200, true, ['data' => ..., 'meta' => ...])` (literal message; same envelope shape as flows index). Verified by `test_catalog_lists_seeded_statuses` (`assertOk` via `adminGet`, `data.data` codes containing `pending/processing/packed/shipped/customs_clearance/delivered/cancelled`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Order statuses retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Custom envelope | `data` (status array), `meta` (`current_page`, `per_page`, `total` ONLY) |
| `data.data[]` | object | Always present (possibly empty) | `OrderStatusResource` rows in `id` order | `id`, `code` (stable business identifier — never the translated name), `name` (localized via `LocalizedName`), `description`, `is_active` (bool), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Order statuses retrieved successfully.",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "code": "pending",
        "name": { "en": "Pending" },
        "description": null,
        "is_active": true,
        "created_at": "2026-01-01T00:00:00+00:00",
        "updated_at": "2026-01-01T00:00:00+00:00"
      }
    ],
    "meta": {
      "current_page": 1,
      "per_page": 50,
      "total": 1
    }
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (code-traced; the cancel twin pins 401 strictly on this group stack) | See 401 example |
| 403 | Authenticated caller holds NONE of the three pipe permissions | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without any of the three (code-traced) | See 403 example |
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
