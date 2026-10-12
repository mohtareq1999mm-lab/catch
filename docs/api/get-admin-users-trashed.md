# Endpoint Name

Admin – Trashed Users (`GET /api/v1/admin-users/trashed`)

# Description

Lists soft-deleted users with pagination for callers holding the view-users permission.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin-users/trashed` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `admin-users/trashed` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\UserController@adminTrashedUsers` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required. `view-users` via controller constructor `permission:view-users` middleware (applies to `index`, `show`, and `adminTrashedUsers`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only. No body is read and no `Content-Type` is required; any body sent is ignored |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer (sent as a query-string value) | Optional | No explicit `nullable` rule exists — there is no validation at all; the value is tested with PHP truthiness (`$request->limit ? $request->limit : 15`) | None (unvalidated; any truthy value is passed to `paginate()`) | Any positive integer in practice | `15` whenever `limit` is absent or falsy (`null`, `""`, `"0"`, `0`) | None | `15` | Number of trashed users per page |
| `page` | query | integer (sent as a query-string value) | Optional | No explicit `nullable` rule exists — there is no validation at all; consumed by the paginator | None (unvalidated; values below 1 or failing integer validation fall back to page 1 per `AbstractPaginator::isValidPageNumber`) | Any integer `>= 1` in practice | `1` | None | `2` | Page number to retrieve |

There are no path parameters, JSON body fields, multipart fields, or file uploads for this endpoint. Only soft-deleted rows are returned (`User::onlyTrashed()`); active users never appear. Results are ordered by `updated_at` descending via the `order` global scope in `User::boot`.

**Unverified in source:** a truthy but non-numeric `limit` (e.g. `?limit=abc` or an array value) is passed straight to `paginate()` with no covering test; the resulting HTTP response is marked **Unverified in source** (a framework-level failureerebbe rendered as 500, but this is not established from the inspected code path).

## Request examples

First page with default page size:

```json
{
  "method": "GET",
  "url": "/api/v1/admin-users/trashed",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

Second page with an explicit page size:

```json
{
  "method": "GET",
  "url": "/api/v1/admin-users/trashed?limit=10&page=2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: trashed users listed — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])`. There is exactly one success contract; an empty result uses the same structure with `"data": []`, `"total": 0`, `"from": 0`, and `"to": 0`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope built by the controller from the paginated `UserResource` collection (note: the items array is `data.data`, not `data`) | Object described below |
| `data.data` | array of objects | Always present; may be empty (`[]` when no soft-deleted users exist or the requested page is out of range) | Soft-deleted users on the current page, each a Marvel `UserResource` | Items described below |
| `data.data[].id` | integer | Always present per item | User primary key | E.g. `27` |
| `data.data[].name` | string | Always present per item | Stored display name | E.g. `"Regular User"` |
| `data.data[].email` | string | Always present per item | Stored email | E.g. `"regular@example.com"` |
| `data.data[].email_verified_at` | string (datetime) or null | Always present per item | Verification timestamp as stored | Timestamp or `null` |
| `data.data[].is_active` | integer/boolean | Always present per item | Stored activation flag | `0` or `1` |
| `data.data[].image` | string (URL) or null | Always present per item | Public URL of the first `user-image` media item | URL string or `null` when no media is attached |
| `data.data[].type` | string | Always present per item | User discriminator as stored | `"admin"`, `"user"`, or `"store_owner"` depending on the deleted row |
| `data.data[].phone_number` | string or null | Always present per item | Stored phone number | Value or `null` |
| `data.data[].created_at` | string (datetime) | Always present per item | Creation timestamp | E.g. `"2026-10-09T10:00:00.000000Z"` |
| `data.data[].updated_at` | string (datetime) | Always present per item | Last-update timestamp | E.g. `"2026-10-10T11:00:00.000000Z"` |
| `data.data[].roles` | omitted | Always absent for this endpoint | Assigned roles | `UserResource` includes `roles` only `whenLoaded('roles')`; the controller eager-loads only `['permissions']`, so this key is omitted even though `getPermissionsViaRoles()` lazy-loads roles afterwards (key evaluation order keeps the omission) |
| `data.data[].permissions` | array of objects | Always present; may be empty when the user's roles grant no permissions | Effective permissions via `getPermissionsViaRoles()` (roles and their permissions are lazy-loaded per user) | Each item is a `PermissionResource`: `{id: integer, label: string}` where `label` is the `permissions.{name}` translation when it exists, else the raw permission name |
| `data.data[].address` | omitted | Always absent for this endpoint | Customer addresses | `whenLoaded('address')` and `address` is never loaded here, so the key is omitted |
| `data.page` | integer | Always present | Current page (duplicate of `current_page`) | `meta.current_page` from the paginator, e.g. `1` |
| `data.current_page` | integer | Always present | Current page | Requested valid `page`, else `1` |
| `data.from` | integer | Always present | 1-based index of the first item on the page | Numerical when items exist; `0` when empty (`firstItem()` returns `null` and the `?? 0` fallback applies) |
| `data.to` | integer | Always present | 1-based index of the last item on the page | Numerical when items exist; `0` when empty (`lastItem()` returns `null` and the `?? 0` fallback applies) |
| `data.last_page` | integer | Always present | Last available page (`max(ceil(total / per_page), 1)`) | `1` when the result is empty |
| `data.path` | string | Always present | Paginator base path used to build page URLs | Resolved request path, e.g. `"http://localhost/api/v1/admin-users/trashed"` |
| `data.per_page` | integer | Always present | Applied page size | Effective `limit`, or `15` by default |
| `data.total` | integer | Always present | Total number of soft-deleted users | `0` when none exist |
| `data.next_page_url` | string | Always present; empty string when there is no next page (`null ?? ""`) | URL of the next page | URL string or `""` |
| `data.prev_page_url` | string | Always present; empty string on the first page (`null ?? ""`) | URL of the previous page | URL string or `""` |
| `data.last_page_url` | string | Always present | URL of the last page | URL string (falls back to `""` only if the paginator omits it) |
| `data.first_page_url` | string | Always present | URL of the first page | URL string (falls back to `""` only if the paginator omits it) |

Fields never exposed by this endpoint even though they exist on the model/table: `password` and `remember_token` (hidden via `User::$hidden`); `deleted_at` is not part of `UserResource` and is therefore not returned even though every item is soft-deleted.

Complete success example (one trashed user, first of two pages):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 27,
        "name": "Regular User",
        "email": "regular@example.com",
        "email_verified_at": "2026-10-09T10:00:00.000000Z",
        "is_active": 1,
        "image": null,
        "type": "user",
        "phone_number": "01000000011",
        "created_at": "2026-10-09T10:00:00.000000Z",
        "updated_at": "2026-10-10T11:00:00.000000Z",
        "permissions": [
          {
            "id": 5,
            "label": "create-user"
          }
        ]
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 2,
    "path": "http://localhost/api/v1/admin-users/trashed",
    "per_page": 1,
    "total": 2,
    "next_page_url": "http://localhost/api/v1/admin-users/trashed?page=2",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/admin-users/trashed?page=2",
    "first_page_url": "http://localhost/api/v1/admin-users/trashed?page=1"
  }
}
```

Success example variant with no trashed users (same structure, empty items):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [],
    "page": 1,
    "current_page": 1,
    "from": 0,
    "to": 0,
    "last_page": 1,
    "path": "http://localhost/api/v1/admin-users/trashed",
    "per_page": 15,
    "total": 0,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/admin-users/trashed?page=1",
    "first_page_url": "http://localhost/api/v1/admin-users/trashed?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no endpoint-specific validation (no FormRequest, no `$request->validate()` call), so there is no `422` contract. There is no per-id lookup, so there is no `404` contract.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `view-users` permission (Spatie `permission:view-users` middleware rejects before the controller runs) | `{message: string, status: boolean}` | None | Spatie `UnauthorizedException` message rendered by `Handler::messageForSpatieUnauthorized` (locale-aware) | None | Valid token + user without `view-users`; code-verified via middleware registration and Handler branch (no endpoint-specific 403 test exists) | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/admin-users/trashed` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception not matched above), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query or pagination failure (e.g. an unprocessable truthy `limit` value — see the **Unverified in source** note under Request inputs) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-users` permission):

```json
{
  "message": "User does not have the right permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/admin-users/trashed`):

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
