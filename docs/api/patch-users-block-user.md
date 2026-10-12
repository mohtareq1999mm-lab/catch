# Endpoint Name

User – Block User (`PATCH /api/v1/users/block-user`)

# Description

Deactivates a user account and revokes its API tokens, rejecting self-blocking with a forbidden response.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` |
| Full path | `/api/v1/users/block-user` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `users/block-user` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group. The route is registered before `apiResource('users')` so it is not captured by `PATCH users/{user}` (the `update` action is registered again as of 2026-10-10, making the pre-registration load-bearing once more) |
| Controller action | `Marvel\Http\Controllers\UserController@banUser` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice: `ban-user` via controller constructor `permission:ban-user` middleware (applies to `banUser`), plus an inline `$user->hasPermissionTo(Permission::BAN_USER)` check inside the method |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` (single body field; no file upload applies) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the inline checks.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `id` | body | integer | Effectively required (no validation rule exists, but a missing value reaches `User::findOrFail(null)` and yields 404) | No | No FormRequest or inline validation exists; the raw value is passed to `User::findOrFail($request->id)` under the `SoftDeletes` scope, and must additionally differ from the caller's own id (loose `!=` comparison) or the request is rejected with 403 | Id of an existing active (non-trashed) user row that is not the caller | None | None | `27` | Identifies the user to deactivate |

There are no path parameters, query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored: only `id` is read. There is no `422` contract — without validation, a missing, null, or non-numeric `id` flows into the lookup and produces 404 (code-traced).

## Request examples

Valid request blocking a user:

```json
{
  "method": "PATCH",
  "url": "/api/v1/users/block-user",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "id": 27
  }
}
```

# Responses

## Success: user deactivated — HTTP 200

Returned via `ApiResponse::apiResponse(USER_DEACTIVATED_SUCCESSFULLY, 200)` with default `success = true` and no data payload, so the `data` key is absent (`apiResponse` adds `data` only when non-empty). An already-inactive target is still processed and returns the same 200 (verified by `test_ban_user_already_banned_succeeds`); there is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.USER_DEACTIVATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"User deactivated successfully"`, `ar` → `"تم تعطيل المستخدم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Observable effect (code-verified): the target's API tokens are revoked (`$banUser->tokens()->delete()`), `is_active` is set to `false` and saved, verified by `assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false])`; an internal audit entry is recorded via `UserObserver::updated`, which has no externally observable HTTP effect.

Complete success example:

```json
{
  "status": 200,
  "message": "User deactivated successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint. The two 403 contracts below have different bodies and must not be conflated.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_ban_user_fails_for_unauthenticated_user` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `ban-user` permission (Spatie `permission:ban-user` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `ban-user`; verified by `test_ban_user_fails_for_unauthorized_user` expecting 403 | See 403 example (middleware) |
| 403 | Caller attempts to block their own account (`$user->id != $request->id` is false under loose comparison, so the `if` fails and the method throws `AuthorizationException(NOT_AUTHORIZED)`), rendered by the `AuthorizationException` branch of `Handler`, which ignores the exception message | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (fixed hardcoded string WITHOUT trailing period, per `Handler`, not the translated `ERROR.NOT_AUTHORIZED`) | None | Caller authenticated with `ban-user` permission; body `id` equals the caller's own user id (integer or equivalent numeric string, per loose `!=`) | See 403 example (self-block) |
| 404 | No active user row matches `id`: `User::findOrFail($request->id)` throws `ModelNotFoundException`, which the controller does not catch (it catches only `MarvelException`), so `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string from the `ModelNotFoundException` branch; not the translated `ERROR.NOT_FOUND`) | None | Nonexistent id (verified by `test_ban_user_fails_for_nonexistent_user` expecting 404 for id `99999`); also a soft-deleted id (excluded by the `SoftDeletes` scope), a missing/null/non-numeric `id` (no validation intercepts it — code-traced) | See 404 example |
| 405 | Any HTTP method other than `PATCH` on `/api/v1/users/block-user` (only `Route::patch` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `DELETE` to the same URI | See 405 example |
| 409 | Database constraint failure during `save()` (e.g. a constraint violation on the users row), rendered by the `QueryException` branch of `Handler`; conditional on database state, not on request input | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Only when persisting the deactivation violates a database constraint | See 409 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception not matched above), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-lookup failure (e.g. token-revocation failure); the controller's `catch (MarvelException)` → `throw new MarvelException(SOMETHING_WENT_WRONG)` path (which would render 500 `"Something went wrong."`/`"حدث خطأ ما"`) is present in code but effectively unreachable on the inspected path, since nothing in the `try` block throws `MarvelException` | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (middleware — authenticated without `ban-user` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403 example (self-block — caller with permission blocking their own id):

```json
{
  "message": "This action is unauthorized",
  "status": false
}
```

404 example (nonexistent, soft-deleted, or missing `id`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/users/block-user`):

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
