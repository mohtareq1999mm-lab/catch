# Endpoint Name

Roles – List Roles (`GET /api/v1/roles`)

# Description

Returns a paginated list of roles with manual pagination metadata and per-role permissions.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/roles` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/roles` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: roles use explicit routes, not `apiResource`) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@getAllRoles` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-roles` via controller constructor `permission:view-roles` middleware (applies to `getAllRoles`; note the plural form — distinct from the singular `view-role` on the show endpoint); the method itself performs no inline permission check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the query.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | No validation; passed to `paginate($limit)` via `request('limit', 10)` | Any positive integer | `10` (note: smaller default than the permissions list's `100`) | None | `20` | Page size (code-traced; no max cap is enforced) |
| `search` | query | string | Optional | No | No validation; applied only when truthy as `name LIKE %search% OR display_name LIKE %search%` (the `orWhere` is ungrouped — code-verified as written) | Any string | Absent (no filter) | None | `test` | Substring filter across machine name and display-name translations JSON |

There is no `422` contract — no validation runs on this endpoint.

## Request examples

Valid request listing roles:

```json
{
  "method": "GET",
  "url": "/api/v1/roles?limit=20&search=test",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of roles — HTTP 200

Returned via `ApiResponse::apiResponse(ROLES_FETCHED_SUCCESSFULLY, 200, true, [...])` with a manually built pagination payload. Verified by `test_super_admin_can_fetch_all_roles` (`assertOk`, `success: true`, full structure assertion) and `test_get_all_roles_supports_search` (`assertOk` with `?search=test`). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ROLES_FETCHED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Roles fetched successfully"`, `ar` → `"تم جلب الأدوار بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Manually built page payload | See `data` fields below |

`data` fields (code-verified against the `apiResponse` payload; key set verified by the structure test):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.data[]` | array of objects | Always present (possibly empty) | Roles serialized by `RoleResource` | Each item: `id`, `name`, `display_name` (raw JSON string), `created_at`, `updated_at`, plus `permissions` (`[{id, label}]`, present because the query eager-loads `with('permissions')` — code-verified difference from `addRole`, where the key is absent; the structure test does not name it but `assertJsonStructure` permits extra keys) |
| `data.page` | integer | Always present | Current page (duplicate of `current_page`) | `$roles->currentPage()` |
| `data.current_page` | integer | Always present | Current page | Same value as `page` |
| `data.from` | integer or null | Always present | First item index (`null` on an empty page — code-traced) | `$roles->firstItem() ?? 0` (note: `?? 0` yields `0`, not null, when the page is empty — code-verified) |
| `data.to` | integer or null | Always present | Last item index (`null` on an empty page — code-traced) | `$roles->lastItem() ?? 0` (same `0` fallback) |
| `data.last_page` | integer | Always present | Last page number | `$roles->lastPage()` |
| `data.path` | string | Always present | Base paginator path | `$roles->path()` |
| `data.per_page` | integer | Always present | Page size | `$roles->perPage()` |
| `data.total` | integer | Always present | Total matching roles | `$roles->total()` |
| `data.next_page_url` | string | Always present (empty string when none — code-verified `?? ""` fallback) | Next page URL or `""` | `$roles->nextPageUrl() ?? ""` |
| `data.prev_page_url` | string | Always present (empty string when none — code-verified `?? ""` fallback) | Previous page URL or `""` | `$roles->previousPageUrl() ?? ""` |
| `data.last_page_url` | string | Always present | Last page URL | `$roles->url($roles->lastPage())` |
| `data.first_page_url` | string | Always present | First page URL | `$roles->url(1)` |

Complete success example (`en` locale; pagination values illustrative):

```json
{
  "status": 200,
  "message": "Roles fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 3,
        "name": "editor_role",
        "display_name": "{\"en\":\"Editor Role\",\"ar\":\"دور المحرر\"}",
        "created_at": "2026-10-10T12:00:00.000000Z",
        "updated_at": "2026-10-10T12:00:00.000000Z",
        "permissions": [
          {
            "id": 5,
            "label": "edit-articles"
          }
        ]
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/roles",
    "per_page": 10,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/roles?page=1",
    "first_page_url": "http://localhost/api/v1/roles?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_roles` expecting 401 on this exact URI | See 401 example |
| 403 | Authenticated caller lacks the `view-roles` permission (Spatie `permission:view-roles` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-roles` (no list-specific 403 test exists; the identical middleware/Handler path is test-verified on `POST /users/{userId}/assign-role` expecting 403) | See 403 example |
| 405 | Any HTTP method other than `GET` on the bare `/api/v1/roles` path (only `Route::get('/roles')` is registered there) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `PUT`, `PATCH`, `DELETE` to `/api/v1/roles` (note: `POST /roles` is the separate create endpoint, not a 405) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any exception inside the `try` block, caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403 errors on this endpoint (the `catch (ValidationException)` rethrow is effectively dead code: nothing in the `try` block validates) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Query failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-roles` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `DELETE /api/v1/roles`):

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

500 example (query failure inside `getAllRoles`):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```
