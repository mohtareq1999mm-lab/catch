# Endpoint Name

Users – Update User (`PUT|PATCH /api/v1/users/{user}`)

# Description

Updates a user's name, email, avatar, profile, and addresses under a super-admin-or-self rule.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`; tests use `putJson`) |
| Full path | `/api/v1/users/{user}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `users/{user}` from `Route::apiResource('users', ...)->except(['store', 'destroy'])` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group. The custom PATCH actions (`block-user`, `unblock-user`, `make-admin`, `assign-role`, `remove-role`) are longer URIs registered before the resource and never collide with the single-segment `{user}` placeholder |
| Controller action | `Marvel\Http\Controllers\UserController@update` → `UserRepository::updateUser` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `edit-user` via controller constructor `permission:edit-user` middleware (applies to `adminUpdateActivationUsers` and `update`). This gate applies IN ADDITION to the in-method super-admin-or-self rule below — a regular user without `edit-user` cannot update even themselves (test-verified 403) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json`, plus `multipart/form-data` when uploading `image` (the repository checks `hasFile('image')`; the validated field is named `avatar` — see quirk below) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `users.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching `UserUpdateRequest`.

## Request inputs

`UserUpdateRequest` validation runs before the method body (`authorize()` returns `true`; `failedValidation` rethrows → 422). Every field is optional — an empty body passes validation and returns 200 with no changes (code-traced).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json`, or `multipart/form-data` for uploads | None | None | `application/json` | Declares body encoding |
| `user` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No route-level constraint; super-admin callers resolve it via the repository `findOrFail` (SoftDeletes scope); self-update callers ignore it for the lookup (see rule below) and only compare against it | Id of an existing non-trashed user (super-admin path) or the caller's own id (self path) | None | None | `27` | Identifies the user to update |
| `name` | body | string | Optional | No | `string\|max:255` | Any string up to 255 chars | Absent (unchanged) | None | `"Updated By Admin"` | New display name (persisted — `dataArray` verified) |
| `email` | body | string | Optional | No | `email\|unique:users,email,<ignore>` | Any valid email unique across users | Absent (unchanged) | None | `"updated@example.com"` | New email (persisted). BUG (code-traced, flagged): the ignore id is read via `$this->route('id')` but the resource parameter is named `{user}`, so the ignore resolves to null and re-submitting the user's UNCHANGED email fails unique → 422. `test_update_user_with_same_email_works` expects 200 and contradicts this code path |
| `avatar` | body | file | Optional | Yes (`nullable`) | `nullable\|image\|mimes:jpeg,png,jpg,gif,svg\|max:2048` | Image file ≤2MB | Absent | None | Binary upload | Validated but IGNORED — `updateUser` checks `hasFile('image')`, not `avatar` (code-verified field-name mismatch): uploading `avatar` stores nothing, while uploading `image` stores via `updateSingleImage` without passing the `avatar` validation |
| `profile` | body | object/array | Optional | No | `array` | Any array | Absent | None | `{"bio": "..."}` | Accepted by validation but NOT persisted by `updateUser` (only `address` items, `image`, and `dataArray` fields are written — code-verified) |
| `address` | body | array of objects | Optional | No | `array` (elements unvalidated) | Array of address objects, each with or without an `id` | Absent (untouched) | None | `[{"id": 5, "city": "..."}]` | Address side effect (code-verified): items WITH `id` update that address row (`Address::findOrFail` — an unknown address id escapes as 404 `"Resource Not Found"`); items WITHOUT `id` create a new row with `customer_id` set to the user |

In-method authorization (code-verified order): (1) caller has the `super_admin` role (`Marvel\Enums\Role::SUPER_ADMIN` = `'super_admin'`) → any `{user}` may be updated; (2) else the caller's id loosely equals `{user}` (`==`) → self-update; (3) else `AuthorizationException(NOT_AUTHORIZED)` → 403. Note the ordering consequence: a non-super-admin requesting a nonexistent OTHER id gets 403, not 404 (the lookup never runs — code-traced).

## Request examples

Valid request (super-admin updating another user):

```json
{
  "method": "PUT",
  "url": "/api/v1/users/27",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": "Updated By Admin"
  }
}
```

# Responses

## Success: user updated — HTTP 200

Returned via `ApiResponse::apiResponse(USER_UPDATED_SUCCESSFULLY, 200, true, UserResource::make($updatedUser))`. Verified by `test_super_admin_can_update_any_user` (`assertOk`, `success: true`, `assertDatabaseHas` on the new name). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.USER_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"User updated successfully"`, `ar` → `"تم تحديث المستخدم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated user serialized by Marvel `UserResource` | `id`, `name`, `email`, `email_verified_at`, `is_active`, `image`, `type`, `phone_number`, `created_at`, `updated_at`, `permissions` (role-derived via `getPermissionsViaRoles()`); `roles` ABSENT (never loaded — only `address` is loaded); `address` present (loaded rows for the user) |

Complete success example (`en` locale; timestamps illustrative):

```json
{
  "status": 200,
  "message": "User updated successfully",
  "success": true,
  "data": {
    "id": 27,
    "name": "Updated By Admin",
    "email": "jane@example.com",
    "email_verified_at": null,
    "is_active": true,
    "image": null,
    "type": "user",
    "phone_number": null,
    "created_at": "2026-10-10T12:00:00.000000Z",
    "updated_at": "2026-10-10T12:00:00.000000Z",
    "permissions": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/403-self-without-permission/403-other/401 contracts are test-verified (all tests correctly use `putJson`); all other rows are code-verified or code-traced as marked. The two 403 contracts below have different bodies and must not be conflated.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_update_user_fails_for_unauthenticated_user` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `edit-user` permission (Spatie `permission:edit-user` middleware rejects before validation runs — so even a self-update with a valid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `edit-user`; verified by `test_user_cannot_update_self_without_permission` (self-update!) and `test_user_cannot_update_other_user`, both expecting 403 | See 403 example (middleware) |
| 403 | Caller passes the `edit-user` gate but is neither super-admin nor the target (`AuthorizationException(NOT_AUTHORIZED)` thrown in-method), rendered by the `AuthorizationException` branch of `Handler`, which ignores the exception message | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (fixed hardcoded string WITHOUT trailing period, per `Handler` — not the translated `ERROR.NOT_AUTHORIZED`) | None | Caller with `edit-user` but without the `super_admin` role requesting a different `{user}` (code-traced; the existing 403 tests trigger on the middleware gate instead) | See 403 example (in-method) |
| 404 | No non-trashed user matches `{user}` on the super-admin path: the repository `findOrFail` throws `ModelNotFoundException`, which `update` does NOT catch (only `MarvelException` is caught), so `Handler` renders its `ModelNotFoundException` branch — fixed `"Resource Not Found"`, not the translated notice | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Super-admin caller + nonexistent/soft-deleted `{user}` (code-traced — no endpoint-specific 404 test exists; note a non-super-admin gets 403 instead per the ordering rule above) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/users/{user}` (`store`/`destroy` remain excluded, so no such routes exist; note `GET` is the separate show endpoint and the custom PATCH actions live on longer URIs) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `POST`/`DELETE` on the exact URI (code-traced) | See 405 example |
| 422 | Any `UserUpdateRequest` validation failure (runs after middleware, before the method body), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `name`, `email`, `avatar`, `profile`, `address`; exact messages **Unverified in source** — no update-specific 422 test exists) | Non-string/over-255 `name`; invalid/duplicate `email` (including the unchanged-email resubmission per the flagged unique-ignore bug above); non-image/disallowed-mime/over-2MB `avatar`; non-array `profile`/`address` | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure other than a caught `MarvelException` (which `update` re-raises as `NOT_FOUND` → 404 translated, not 500), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (middleware — authenticated without `edit-user` permission, even for self-update; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403 example (in-method — caller with `edit-user` but neither super-admin nor target):

```json
{
  "message": "This action is unauthorized",
  "status": false
}
```

404 example (super-admin caller, nonexistent `{user}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (still-excluded action, e.g. `DELETE /api/v1/users/27`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (non-string `name`):

```json
{
  "message": "The name must be a string.",
  "status": false,
  "errors": {
    "name": [
      "The name must be a string."
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

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```
