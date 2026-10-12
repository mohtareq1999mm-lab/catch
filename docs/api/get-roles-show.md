# Endpoint Name

Roles – Show Role (`GET /api/v1/roles/{id}`)

# Description

Returns a single role with its assigned permissions.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/roles/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/roles/{id}` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: roles use explicit routes, not `apiResource`) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@showRole` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-role` via controller constructor `permission:view-role` middleware (applies to `showRole`); the method itself performs no inline permission check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the lookup.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the raw value is passed to `Role::findOrFail($id)`, which throws `ModelNotFoundException` for unknown ids (converted to `MarvelException(NOT_FOUND)` → 404) | Id of an existing role | None | None | `9` | Identifies the role to return |

There are no query parameters, body fields, multipart fields, or file uploads for this endpoint. Note the lookup is `findOrFail($id)` with no guard scope — unlike `updateRole`/`destroyRole`, which use the `api`-guard-scoped `findById` (code-verified difference). A non-numeric `{id}` fails the lookup and yields 404 (code-traced). There is no `422` contract.

## Request examples

Valid request fetching a role:

```json
{
  "method": "GET",
  "url": "/api/v1/roles/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: role returned — HTTP 200

Returned via `ApiResponse::apiResponse(ROLE_FETCHED_SUCCESSFULLY, 200, true, RoleResource::make($role))` after `$role->load('permissions')`, so the `permissions` key is always present (possibly an empty array). Verified by `test_super_admin_can_fetch_role_by_id` (`assertOk`, `success: true`, structure `data: [id, display_name, permissions]`, `data.id` match). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ROLE_FETCHED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Role fetched successfully"`, `ar` → `"تم جلب الدور بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The role serialized by `Marvel\Http\Resources\RoleResource` with permissions loaded | See `data` fields below |

`data` fields:

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.id` | integer | Always present | Role id | Auto-incremented id |
| `data.name` | string | Always present | Machine name | E.g. `"visible_role"` |
| `data.display_name` | string | Always present | Raw stored translations JSON (note: string, not object — the resource uses `getRawOriginal('display_name')`) | E.g. `'{"en":"Visible Role","ar":"دور مرئي"}'` |
| `data.created_at` | string (datetime) | Always present | Creation timestamp | Exact format **Unverified in source** |
| `data.updated_at` | string (datetime) | Always present | Last-update timestamp | Exact format **Unverified in source** |
| `data.permissions` | array of objects | Always present for this endpoint (possibly empty) | Assigned permissions serialized by `PermissionResource` — present because `showRole` always loads the relation (code-verified difference from `addRole`, where the key is absent) | Each item: `{id: integer, label: string}` where `label` is the `permissions.{name}` translation when one exists, else the permission name itself |

Complete success example (`en` locale; `created_at`/`updated_at` illustrative, all other values verified):

```json
{
  "status": 200,
  "message": "Role fetched successfully",
  "success": true,
  "data": {
    "id": 9,
    "name": "visible_role",
    "display_name": "{\"en\":\"Visible Role\",\"ar\":\"دور مرئي\"}",
    "created_at": "2026-10-10T12:00:00.000000Z",
    "updated_at": "2026-10-10T12:00:00.000000Z",
    "permissions": [
      {
        "id": 4,
        "label": "visible-perm"
      }
    ]
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token (no show-specific 401 test exists; the identical `auth:sanctum` rejection is test-verified on sibling roles routes, e.g. `test_unauthenticated_user_cannot_access_roles` expecting 401 on `GET /roles`) | See 401 example |
| 403 | Authenticated caller lacks the `view-role` permission (Spatie `permission:view-role` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-role` (no show-specific 403 test exists; the identical middleware/Handler path is test-verified on `POST /users/{userId}/assign-role` expecting 403) | See 403 example |
| 404 | No role matches `{id}`: `Role::findOrFail($id)` throws `ModelNotFoundException`, which `showRole` converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced — the test asserts status only) | None | Nonexistent id (verified by `test_show_nonexistent_role_returns_404` expecting 404 for id `99999`); non-numeric `{id}` (code-traced through the same lookup) | See 404 example |
| 405 | `POST` or `PATCH` on `/api/v1/roles/{id}` (no such routes are registered; note `PUT /roles/{id}` is the separate update endpoint and `DELETE /roles/{id}` is the separate destroy endpoint, so those do not 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`PATCH` with an id segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any other exception inside the `try` block (e.g. failure while loading permissions), caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403/404 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Post-lookup internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-role` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (nonexistent or non-numeric `{id}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/roles/9`):

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

500 example (post-lookup internal failure):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```
