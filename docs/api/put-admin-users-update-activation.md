# Endpoint Name

Admin – Update User Activation (`PUT /api/v1/admin-users/update-activation`)

# Description

Toggles a user's activation flag, rejecting the request for the caller's own super-admin account or an inactive super-admin account.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/admin-users/update-activation` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `admin-users/update-activation` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\UserController@adminUpdateActivationUsers` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required. `edit-user` via controller constructor `permission:edit-user` middleware (applies to `adminUpdateActivationUsers` and `update`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` (single body field; no file upload applies) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching validation (verified by tests sending an empty body and receiving 403 for a regular user rather than 422).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `user_id` | body | integer | Required | No | `required\|integer\|exists:users,id` via inline `$request->validate()` (a `ValidationException` renders 422 with an `errors` map) | Id of an existing row in `users.id`, except the guarded super-admin cases below (those yield 400, not 422) | None | None | `27` | Identifies the user whose `is_active` flag is toggled |

There are no path parameters, query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored: only `user_id` is read. The `exists` rule queries the `users` table directly (query-builder level, without the Eloquent `SoftDeletes` scope), so a soft-deleted id passes validation and then fails at the repository lookup with 404 (code-traced; see Errors).

## Request examples

Deactivate (or reactivate — the endpoint toggles) a user:

```json
{
  "method": "PUT",
  "url": "/api/v1/admin-users/update-activation",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "user_id": 27
  }
}
```

# Responses

## Success: activation toggled — HTTP 200

Returned via `ApiResponse::apiResponse(USER_UPDATED_SUCCESSFULLY, 200, true)` with no data payload, so the `data` key is absent (`apiResponse` adds `data` only when non-empty). The endpoint flips the stored value (`$user->is_active = !$user->is_active; $user->save();`), so the same contract covers both activation and deactivation; there is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.USER_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"User updated successfully"`, `ar` → `"تم تحديث المستخدم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Observable effect (code-verified): the `users.is_active` value is inverted and persisted, verified by tests asserting `is_active => false` after deactivation and `is_active => true` after reactivation across two consecutive calls; an internal audit entry is recorded via `UserObserver::updated`, which has no externally observable HTTP effect.

Complete success example:

```json
{
  "status": 200,
  "message": "User updated successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Target has the `super_admin` role and is the caller (`$user->id === auth()->id()`), returned directly via `apiResponse(USER_CANNOT_BE_UPDATED, 400, false)` with HTTP 400 | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `ERROR.USER_CANNOT_BE_UPDATED`: `en` → `"User cannot be updated"`, `ar` → `"لا يمكن تحديث المستخدم"` | None | Caller authenticated with `edit-user` permission; `user_id` resolves to a super-admin who is the caller | See 400 example |
| 400 | Target has the `super_admin` role and `$user->is_active === false` (strict comparison), same response contract as above | `{status: integer, message: string, success: boolean}`, no `data` key | None | Same `ERROR.USER_CANNOT_BE_UPDATED` message as above | None | Caller authenticated with `edit-user` permission; `user_id` resolves to an inactive super-admin. Note the comparison is strict (`===`): only boolean `false` satisfies it, and `is_active` has no Eloquent cast in `User::$casts`, so an integer `0` does not satisfy it — the practically observed trigger is the self-deletion case above | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success/400 envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_admin_endpoints` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `edit-user` permission (Spatie `permission:edit-user` middleware rejects before validation runs) | `{message: string, status: boolean}` | None | Spatie `UnauthorizedException` message rendered by `Handler::messageForSpatieUnauthorized` (locale-aware) | None | Valid token + user without `edit-user`; verified by `test_regular_user_cannot_access_admin_endpoints` expecting 403 for an empty body (confirming the permission check precedes validation) | See 403 example |
| 404 | `user_id` passes validation but the repository lookup finds nothing: `$this->repository->findOrFail()` forwards to the Eloquent model (Prettus `__call`), whose `SoftDeletes` scope excludes trashed rows, throwing `ModelNotFoundException`, which the controller does not catch (it catches only `MarvelException`), so `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string from the `ModelNotFoundException` branch; not the translated `ERROR.NOT_FOUND`) | None | `user_id` of a soft-deleted row (it satisfies the table-level `exists` rule, then misses the scoped lookup); code-traced, with no endpoint-specific 404 test — a fully nonexistent id instead fails the `exists` rule with 422 (verified by `test_update_activation_fails_for_nonexistent_user`) | See 404 example |
| 405 | Any HTTP method other than `PUT` on `/api/v1/admin-users/update-activation` (only `Route::put` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PATCH`, `DELETE` to the same URI | See 405 example |
| 422 | Inline validation failure (`required\|integer\|exists:users,id` on `user_id`; `failedValidation` path throws `ValidationException`, rendered with an `errors` map) | `{message: string, status: boolean, errors: object}` where `errors` maps field → string array | None | Validator message (e.g. `"The user id field is required."`; wording follows Laravel defaults in the active `lang` locale) | Exact structure `{user_id: [message, ...]}`; multiple messages for the field are possible | Missing `user_id` (`required`; verified by `test_update_activation_fails_without_user_id`), non-integer `user_id` (`integer`), or an id with no row in `users.id` (`exists`; verified by `test_update_activation_fails_for_nonexistent_user` expecting 422 for id `99999`) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception not matched above), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-lookup failure (e.g. database failure during `save()`); the controller's `catch (MarvelException)` → `throw new MarvelException(NOT_FOUND)` path (which would render 404 `"Not found"`/`"غير موجود"`) is present in code but effectively unreachable on the inspected path, since neither validation, the lookup, nor the toggle throws `MarvelException` | See 500 example |

400 example (guarded super-admin case; `en` locale):

```json
{
  "status": 400,
  "message": "User cannot be updated",
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

403 example (authenticated without `edit-user` permission):

```json
{
  "message": "User does not have the right permissions.",
  "status": false
}
```

404 example (soft-deleted `user_id` passing validation but missing the scoped lookup):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/admin-users/update-activation`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `user_id`):

```json
{
  "message": "The user id field is required.",
  "status": false,
  "errors": {
    "user_id": ["The user id field is required."]
  }
}
```

422 example (nonexistent `user_id` 99999):

```json
{
  "message": "The selected user id is invalid.",
  "status": false,
  "errors": {
    "user_id": ["The selected user id is invalid."]
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

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```
