# Endpoint Name

Users – Remove Roles (`PATCH /api/v1/users/{userId}/remove-role`)

# Description

Detaches roles from a user without deleting the roles or the user.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` |
| Full path | `/api/v1/users/{userId}/remove-role` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/users/{userId}/remove-role` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: no `apiResource` competes for this URI) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@removeRoleFromUser` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `remove-role` via controller constructor `permission:remove-role` middleware (applies to `removeRoleFromUser`); the method itself performs no inline permission check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching validation. Test-method note: the endpoint is `PATCH`-only — the pre-existing tests called it with `postJson` (which yields 405 against this route) and were corrected to `patchJson` on 2026-10-10.

## Request inputs

Validation runs before the user lookup (code-verified order): an invalid body yields 422 even when `{userId}` does not exist. A `ValidationException` is rethrown untouched and rendered by `Handler` as 422. Note the per-element rules carry neither `integer` nor `distinct` (code-verified difference from the permissions endpoints); non-integer or duplicate ids still fail the `exists` check.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `userId` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the raw value is passed to `User::findOrFail($userId)` under the `SoftDeletes` scope, throwing `ModelNotFoundException` for unknown ids (converted to `MarvelException(NOT_FOUND)` → 404) | Id of an existing non-trashed user | None | None | `27` | Identifies the user losing the roles |
| `role_ids` | body | array of integer ids | Required | No | `required\|array` (`required` also rejects an empty array per framework rule semantics) | Array of `api`-guard role ids | None | None | `[3]` | Roles to detach |
| `role_ids.*` | body (each element) | integer id reference | Required for every element | No | `Rule::exists('roles', 'id')->where(guard_name = 'api')` | Id of an existing `api`-guard role | None | None | `3` | One role to detach |

There are no query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored: only `role_ids` is read.

## Request examples

Valid request detaching a role (idempotent — detaching a role the user does not hold still returns 200; code-traced):

```json
{
  "method": "PATCH",
  "url": "/api/v1/users/27/remove-role",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "role_ids": [3]
  }
}
```

# Responses

## Success: roles detached — HTTP 200

Returned via `ApiResponse::apiResponse(ROLE_REMOVED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent (note: unlike assign-role/give-permission, the loaded user is NOT returned). Verified by `test_remove_role_from_user` (`assertOk`, `success: true`, plus `assertDatabaseMissing('model_has_roles', ...)` proving the detachment). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ROLE_REMOVED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Role removed successfully"`, `ar` → `"تم إزالة الدور بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Complete success example:

```json
{
  "status": 200,
  "message": "Role removed successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/404/403 contracts are test-verified (after the `postJson` → `patchJson` test correction); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no endpoint-specific 401 test exists; identical rejection test-verified on sibling roles routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `remove-role` permission (Spatie `permission:remove-role` middleware rejects before validation runs, so even a malformed body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `remove-role`; verified by `test_remove_role_without_permission_returns_403` expecting 403 | See 403 example |
| 404 | No non-trashed user matches `{userId}` (with a valid body): `User::findOrFail($userId)` throws `ModelNotFoundException`, which the method converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced — the test asserts status only) | None | Nonexistent or soft-deleted `{userId}` with a valid `role_ids` body; verified by `test_remove_role_from_nonexistent_user_returns_error` expecting 404 for id `99999` | See 404 example |
| 405 | `POST` on `/api/v1/users/{userId}/remove-role` (only `Route::patch` is registered — this is exactly what the uncorrected tests hit) or any other method with no registered route on this URI (note `GET`/`PUT`/`PATCH`/`DELETE /users/{userId}` fall through to the resource show/update/destroy routes, not to this action) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Unregistered method on the exact URI (code-traced; `POST` confirmed by route registration) | See 405 example |
| 422 | Any `role_ids` validation failure (runs before the user lookup, so it takes precedence over 404): the `ValidationException` is rethrown untouched and rendered by `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `role_ids` / `role_ids.{index}` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no test exercises this endpoint's 422 path) | Missing or non-array `role_ids`, empty array, or id of a nonexistent / non-`api`-guard role | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any other exception inside the `try` block, caught by the method's own `catch (\Exception $e)` — which additionally writes `Log::error('removeRoleFromUser failed: ...', ['userId' => $userId])` (logs only, no HTTP effect) — and returns the `apiResponse` envelope, unlike the 401/403/404 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Post-validation internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `remove-role` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (nonexistent `{userId}` with a valid body; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (`POST /api/v1/users/27/remove-role` — the pre-correction test call):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (unknown role id):

```json
{
  "message": "The selected role_ids.0 is invalid.",
  "status": false,
  "errors": {
    "role_ids.0": [
      "The selected role_ids.0 is invalid."
    ]
  }
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

500 example (post-validation internal failure):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```
