# Endpoint Name

Admin – Restore User (`PUT /api/v1/admin-users/restore/{id}`)

# Description

Restores a soft-deleted user back to active records, rejecting the request when the user is not trashed.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/admin-users/restore/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `admin-users/restore/{id}` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\UserController@adminRestoreUser` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required. `restore-user` via controller constructor `permission:restore-user` middleware (applies only to `adminRestoreUser`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read. No `Content-Type` is required. Any body or query string sent is ignored: the method signature takes only the `$id` route parameter and never reads the request object |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |
| Route constraint on `{id}` | None (no `whereNumber`): any value, including non-numeric strings, reaches the controller and resolves via `User::withTrashed()->findOrFail($id)` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the value is passed to `User::withTrashed()->findOrFail($id)`, which searches active and soft-deleted rows and throws `ModelNotFoundException` when nothing matches | Id of an existing user row; only a soft-deleted (trashed) row can be restored — an active row yields 400 | None | None | `27` | Identifies the soft-deleted user to restore |

There are no query parameters, JSON body fields, multipart fields, or file uploads for this endpoint. Unlike the delete endpoints, this method has no `super_admin`-role or self-ownership guard: any trashed row found by id is restored (code-verified absence of a guard).

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/admin-users/restore/27",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: user restored — HTTP 200

Returned via `ApiResponse::apiResponse(USER_RESTORED_SUCCESSFULLY, 200, true)` with no data payload, so the `data` key is absent (`apiResponse` adds `data` only when non-empty).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.USER_RESTORED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"User restored successfully"`, `ar` → `"تمت استعادة المستخدم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Observable effect (code-verified): the `users` row's `deleted_at` is cleared via `restore()`, verified by `assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null])`; an internal audit entry is recorded via `UserObserver::restored`, which has no externally observable HTTP effect.

Complete success example:

```json
{
  "status": 200,
  "message": "User restored successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no endpoint-specific validation (no FormRequest, no `$request->validate()` call), so there is no `422` contract and no `errors` map for this endpoint.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Target user exists but is not soft-deleted (`!$user->trashed()`), returned directly via `apiResponse(USER_CANNOT_BE_RESTORED, 400, false)` with HTTP 400 | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `ERROR.USER_CANNOT_BE_RESTORED`: `en` → `"User cannot be restored"`, `ar` → `"لا يمكن استعادة المستخدم"` | None | Caller authenticated with `restore-user` permission; `{id}` matches an active (non-trashed) row; verified by `test_restore_fails_for_non_trashed_user` expecting 400 | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success/400 envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_restore_fails_for_unauthenticated_user` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `restore-user` permission (Spatie `permission:restore-user` middleware rejects before the controller runs) | `{message: string, status: boolean}` | None | Spatie `UnauthorizedException` message rendered by `Handler::messageForSpatieUnauthorized` (locale-aware) | None | Valid token + user without `restore-user`; code-verified via middleware registration and Handler branch (no endpoint-specific 403 test exists) | See 403 example |
| 404 | No user row (active or soft-deleted) matches `{id}`: `User::withTrashed()->findOrFail($id)` throws `ModelNotFoundException`, which the controller does not catch (it catches only `MarvelException`), so `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string from the `ModelNotFoundException` branch; not the translated `ERROR.NOT_FOUND`) | None | Nonexistent id (verified by `test_restore_fails_for_nonexistent_user` expecting 404 for id `99999`); also any non-numeric `{id}`, since the route has no numeric constraint and the lookup then matches nothing | See 404 example |
| 405 | Any HTTP method other than `PUT` on `/api/v1/admin-users/restore/{id}` (only `Route::put` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PATCH`, `DELETE` to the same URI | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception not matched above), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-lookup failure (e.g. database failure during `restore()`); the controller's `catch (MarvelException)` → `throw new MarvelException(NOT_FOUND)` path (which would render 404 `"Not found"`/`"غير موجود"`) is present in code but effectively unreachable for the lookup path, since `findOrFail` throws `ModelNotFoundException`, not `MarvelException` | See 500 example |

400 example (active, non-trashed target; `en` locale):

```json
{
  "status": 400,
  "message": "User cannot be restored",
  "success": false
}
```

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `restore-user` permission):

```json
{
  "message": "User does not have the right permissions.",
  "status": false
}
```

404 example (nonexistent or non-matching `{id}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/admin-users/restore/27`):

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
