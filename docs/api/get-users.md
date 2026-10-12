# Endpoint Name

Users – List Users (`GET /api/v1/users`)

# Description

Returns a paginated list of users with type, activity, trash, search, and ordering filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/users` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `users` from `Route::apiResource('users', ...)->except(['store', 'update', 'destroy'])` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\UserController@index` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-users` via controller constructor `permission:view-users` middleware (applies to `index`, `show`, `adminTrashedUsers`); the method itself performs no inline permission check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `users.index` (apiResource default naming) |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the query.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | No validation; `$request->limit ? $request->limit : 15` then `paginate($limit)` | Any positive integer | `15` | None | `20` | Page size (code-traced; no max cap is enforced) |
| `users` | query | boolean | Optional | No | Evaluated via `$request->boolean('users')`; when truthy adds `type = 'user'` | Truthy values | Absent (no filter) | Mutually exclusive with `admins` in effect (`if/elseif` — when both are truthy only `users` applies; code-verified) | `1` | Restricts to customer-type users |
| `admins` | query | boolean | Optional | No | Evaluated via `$request->boolean('admins')`; applies only when `users` is falsy; adds `type = 'admin'` | Truthy values | Absent (no filter) | See `users` row | `1` | Restricts to admin-type users |
| `trash` | query | boolean | Optional | No | When truthy applies `onlyTrashed()` (trashed rows only); otherwise the default scope excludes trashed rows | Truthy values | Absent (non-trashed only) | None | `1` | Switches the listing to soft-deleted users (same `view-users` gate — no extra permission required; code-verified) |
| `active` | query | boolean | Optional | No | When truthy adds `is_active = true` | Truthy values | Absent (no filter) | Independent of `in_active` (both truthy yields an empty result — code-traced) | `true` | Restricts to active users |
| `in_active` | query | boolean | Optional | No | When truthy adds `is_active = false` | Truthy values | Absent (no filter) | See `active` row | `true` | Restricts to inactive users |
| `type` | query | string | Optional | No | No validation; applied verbatim as `where('type', $value)` when the key is present | Any string | Absent (no filter) | None | `admin` | Exact-match type filter |
| `search` | query | string | Optional | No | No validation; applied only when truthy as grouped `(name LIKE %search% OR email LIKE %search%)` | Any string | Absent (no filter) | None | `John` | Substring filter across name and email |
| `order_by` | query | string | Optional | No | No validation; passed verbatim to `orderBy($orderBy, $sort)` — an unknown column fails at the database level (see 409) | Any string | `created_at` | None | `name` | Sort column |
| `sort` | query | string | Optional | No | No validation; passed verbatim as the direction | Any string | `desc` | None | `asc` | Sort direction |

There is no `422` contract — no validation runs on this endpoint. Pagination links preserve the query string (`withQueryString`).

## Request examples

Valid request listing active admins matching a search:

```json
{
  "method": "GET",
  "url": "/api/v1/users?search=John&admins=1&active=true&limit=20",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of users — HTTP 200

Returned via `ApiResponse::apiResponse(USERS_LISTED_SUCCESSFULLY, 200, true, [...])` with a manually built pagination payload. Verified by seven search/filter/pagination tests (`assertOk` on `data.data` contents, counts, and empty results). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.USERS_LISTED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Users listed successfully"`, `ar` → `"تم عرض قائمة المستخدمين بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Manually built page payload | See `data` fields below |

`data` fields (code-verified against the `apiResponse` payload; same key set as the roles list):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.data[]` | array of objects | Always present (possibly empty — verified: empty search yields `[]` with 200) | Users serialized by Marvel `UserResource` | `id`, `name`, `email`, `email_verified_at`, `is_active`, `image`, `type`, `phone_number`, `created_at`, `updated_at`, `permissions` (role-derived via `getPermissionsViaRoles()`); `roles` is ABSENT here — the query eager-loads only `with(['permissions'])` (the direct Spatie relation, unused by the resource), never `roles`, so `whenLoaded('roles')` is false (code-verified difference from show/assign-role); `address` absent (never loaded) |
| `data.page` | integer | Always present | Current page (duplicate of `current_page`) | `$users->currentPage()` |
| `data.current_page` | integer | Always present | Current page | Same value as `page` |
| `data.from` | integer | Always present | First item index (`0` on an empty page — code-verified `?? 0` fallback) | `$users->firstItem() ?? 0` |
| `data.to` | integer | Always present | Last item index (`0` on an empty page — code-verified `?? 0` fallback) | `$users->lastItem() ?? 0` |
| `data.last_page` | integer | Always present | Last page number | `$users->lastPage()` |
| `data.path` | string | Always present | Base paginator path | `$users->path()` |
| `data.per_page` | integer | Always present | Page size | `$users->perPage()` |
| `data.total` | integer | Always present | Total matching users | `$users->total()` |
| `data.next_page_url` | string | Always present (empty string when none — code-verified `?? ""` fallback) | Next page URL or `""` | `$users->nextPageUrl() ?? ""` |
| `data.prev_page_url` | string | Always present (empty string when none — code-verified `?? ""` fallback) | Previous page URL or `""` | `$users->previousPageUrl() ?? ""` |
| `data.last_page_url` | string | Always present | Last page URL | `$users->url($users->lastPage())` |
| `data.first_page_url` | string | Always present | First page URL | `$users->url(1)` |

Complete success example (`en` locale; pagination values illustrative):

```json
{
  "status": 200,
  "message": "Users listed successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 27,
        "name": "John Doe",
        "email": "john@example.com",
        "email_verified_at": null,
        "is_active": true,
        "image": null,
        "type": "user",
        "phone_number": null,
        "created_at": "2026-10-10T12:00:00.000000Z",
        "updated_at": "2026-10-10T12:00:00.000000Z",
        "permissions": []
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/users",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/users?page=1",
    "first_page_url": "http://localhost/api/v1/users?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint. The 200 contract is test-verified (seven tests); all error rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no list-specific 401 test exists; identical rejection test-verified on sibling user/role routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `view-users` permission (Spatie `permission:view-users` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` (no list-specific 403 test exists; the identical middleware/Handler path is test-verified on sibling endpoints) | None | Valid token + user without `view-users` | See 403 example |
| 405 | `POST`, `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/users` path (`store`/`destroy` are excluded and `update` requires a `{user}` segment, so no such bare-path routes exist) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 409 | Database query failure from unvalidated ordering input (e.g. unknown `order_by` column): `index` catches only `MarvelException`, so a `QueryException` escapes to `Handler`'s `QueryException` branch; conditional on input, not a validation contract | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Only when the generated query fails (code-traced, no test) | See 409 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any non-`MarvelException`, non-query exception), rendered by the generic branch of `Handler` (the method's `catch (MarvelException) → throw NOT_FOUND` path is effectively dead: nothing in the `try` block throws `MarvelException` — code-verified) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-users` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (excluded action, e.g. `POST /api/v1/users`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (unknown `order_by` column):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
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
