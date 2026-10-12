# Endpoint Name

Permissions – List Permissions (`GET /api/v1/permissions`)

# Description

Returns the current page of permissions as a flat array, restricted to super-admin callers.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/permissions` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/permissions` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: permissions use explicit routes, not `apiResource`) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@getAllPermissions` (Spatie `Permission` model queried directly — no guard scope is applied to the listing) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Restricted once: `super-admin` role via controller constructor `role:super-admin` middleware (applies to `getAllPermissions` among others) — note this is a role gate, not a permission gate; the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or non-super-admin call fails with 401/403 without reaching the query.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | No validation; passed to `paginate($limit)` | Any positive integer | `100` (note: larger default than the roles list's `10`) | None | `50` | Page size (code-traced; no max cap is enforced) |
| `search` | query | string | Optional | No | No validation; applied as `name LIKE %search%` only when truthy | Any string | Absent (no filter) | None | `test-permission` | Substring filter on the permission name |

There is no `422` contract — no validation runs on this endpoint.

## Request examples

Valid request listing permissions:

```json
{
  "method": "GET",
  "url": "/api/v1/permissions?limit=50&search=edit",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of permissions — HTTP 200

Returned via `ApiResponse::apiResponse(PERMISSIONS_FETCHED_SUCCESSFULLY, 200, true, PermissionResource::collection($permissions))`. Verified by `test_super_admin_can_fetch_all_permissions` (`assertOk`, `success: true`, structure `data: ['*' => ['id', 'label']]`) and `test_get_permissions_supports_search` (`assertOk`). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.PERMISSIONS_FETCHED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Permissions fetched successfully"`, `ar` → `"تم جلب الصلاحيات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array of objects | Always present on success (possibly empty) | Current page serialized by `PermissionResource` | Each item: `{id: integer, label: string}` where `label` is the `permissions.{name}` translation when one exists, else the permission name itself |

Pagination quirk (code-verified, test-consistent): although the query paginates, the collection is nested inside the `apiResponse` envelope via plain JSON serialization, so `data` is a flat array of the current page's items — no `links`/`meta`/total metadata is returned (unlike the roles list, which builds pagination fields manually). Only the current page is observable; total page count is not.

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Permissions fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "label": "edit-articles"
    },
    {
      "id": 2,
      "label": "publish-articles"
    }
  ]
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_permissions` expecting 401 | See 401 example |
| 403 | Authenticated caller without the `super-admin` role (Spatie `role:super-admin` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_ROLES` via `Handler::messageForSpatieUnauthorized` (role branch; no role-name suffix: `display_role_in_exception` is `false`): `en` → `"User does not have the required roles."`, `ar` → `"لا يمتلك المستخدم الأدوار المطلوبة."` (note: "roles", not "permissions" — this gate checks the caller's role; no list-specific 403 test exists — code-traced through the shared path) | None | Valid token + caller lacking the `super-admin` role | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/permissions` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any exception inside the `try` block, caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403 errors on this endpoint (the `catch (ValidationException)` rethrow is effectively dead code: nothing in the `try` block validates) | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Query failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated caller without the `super-admin` role; `en` locale):

```json
{
  "message": "User does not have the required roles.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/permissions`):

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

500 example (query failure inside `getAllPermissions`):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```
