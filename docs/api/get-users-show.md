# Endpoint Name

Users – Show User (`GET /api/v1/users/{user}`)

# Description

Returns a single user's full details with roles and address for customer-type users.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/users/{user}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `users/{user}` from `Route::apiResource('users', ...)->except(['store', 'destroy'])` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group. `PUT`/`PATCH` on this URI route to the sibling update endpoint (see `put-users.md`); the custom PATCH actions (`block-user`, `unblock-user`, `make-admin`, `assign-role`, `remove-role`) are longer URIs registered before the resource and never collide with the single-segment `{user}` placeholder |
| Controller action | `Marvel\Http\Controllers\UserController@show` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-users` via controller constructor `permission:view-users` middleware (applies to `index`, `show`, `adminTrashedUsers`); the method itself performs no inline permission check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `users.show` (apiResource default naming) |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the lookup.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `user` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the raw value is passed to the repository `findOrFail`, which throws `ModelNotFoundException` for unknown ids. Crucially, `show` catches only `MarvelException` — so the `ModelNotFoundException` escapes to `Handler`'s `ModelNotFoundException` branch and renders 404 `"Resource Not Found"` (fixed string, not the translated notice) | Id of an existing non-trashed user | None | None | `27` | Identifies the user to return |

There are no query parameters, body fields, multipart fields, or file uploads for this endpoint. Because the lookup carries the `SoftDeletes` scope, a soft-deleted id yields 404 (code-traced). A non-numeric `{user}` (e.g. `GET /users/block-user`) likewise yields 404 through the same escaping path (code-traced). There is no `422` contract.

## Request examples

Valid request showing a user:

```json
{
  "method": "GET",
  "url": "/api/v1/users/27",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: user returned — HTTP 200

Returned via `ApiResponse::apiResponse(USER_FETCHED_SUCCESSFULLY, 200, true, UserResource::make($user))` after loading `roles` + `permissions` (and `address` when `type === 'user'`). Verified by the show test (`assertOk`, `data.id`/`data.name`/`data.email` paths) and `test_show_user_returns_all_resource_fields`. There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.USER_FETCHED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"User fetched successfully"`, `ar` → `"تم جلب المستخدم بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The user serialized by Marvel `UserResource` | See `data` fields below |

`data` fields (`UserResource::toArray`):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.id` | integer | Always present | User id | Echo of `{user}` |
| `data.name` | string | Always present | Stored name | As stored |
| `data.email` | string | Always present | Stored email | As stored |
| `data.email_verified_at` | string (datetime) or null | Always present | Verification timestamp | Null when unverified; exact format **Unverified in source** |
| `data.is_active` | boolean/integer | Always present | Active flag | Stored `is_active` |
| `data.image` | string or null | Always present | Avatar URL | First `user-image` media URL, else null |
| `data.type` | string | Always present | User discriminator | E.g. `"user"`, `"admin"` |
| `data.phone_number` | string or null | Always present | Phone | Stored phone |
| `data.created_at` | string (datetime) | Always present | Creation timestamp | Exact format **Unverified in source** |
| `data.updated_at` | string (datetime) | Always present | Last-update timestamp | Exact format **Unverified in source** |
| `data.roles` | array of objects | Always present for this endpoint (possibly empty) | Assigned roles (`RoleResource` collection; present because `show` loads the relation). Each item carries `id`, `name`, `display_name` (raw JSON string), timestamps — but no `guard_name` (removed from `RoleResource` on 2026-10-10) and no nested `permissions` (never eager-loaded) | E.g. `{"id": 3, "name": "editor", ...}` |
| `data.permissions` | array of objects | Always present for this endpoint | Permissions serialized by `PermissionResource` — note it uses `getPermissionsViaRoles()`, i.e. role-derived permissions only; direct grants are NOT surfaced here (code-verified serialization nuance) | Each item: `{id: integer, label: string}` |
| `data.address` | object or omitted | Present only when the user's `type` is exactly `'user'` (code-verified conditional load) | Customer address | Included via `whenLoaded('address')`; absent for non-`'user'` types |

Complete success example (`en` locale; timestamps illustrative, all other values code-verified):

```json
{
  "status": 200,
  "message": "User fetched successfully",
  "success": true,
  "data": {
    "id": 27,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "email_verified_at": null,
    "is_active": true,
    "image": null,
    "type": "user",
    "phone_number": null,
    "created_at": "2026-10-10T12:00:00.000000Z",
    "updated_at": "2026-10-10T12:00:00.000000Z",
    "roles": [],
    "permissions": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint. The 200/404/403/401 contracts are test-verified; all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_show_user_fails_for_unauthenticated_user` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-users` permission (Spatie `permission:view-users` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-users`; verified by `test_show_user_fails_for_unauthorized_user` expecting 403 | See 403 example |
| 404 | No non-trashed user matches `{user}`: the repository `findOrFail` throws `ModelNotFoundException`, which `show` does NOT catch (only `MarvelException` is caught), so `Handler` renders its `ModelNotFoundException` branch — fixed `"Resource Not Found"`, not the translated notice (code-verified difference from endpoints that convert to `MarvelException(NOT_FOUND)`) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Nonexistent id (verified by `test_show_user_fails_for_nonexistent_user` expecting 404 for id `99999`); soft-deleted id (code-traced via the `SoftDeletes` scope); non-numeric `{user}` such as `block-user` (code-traced through the same escaping path) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/users/{user}` (`store`/`destroy` remain excluded, so no such routes exist; note `PUT`/`PATCH` route to the sibling update endpoint and the custom PATCH actions live on longer URIs) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure other than the lookup miss (a `MarvelException` inside the `try` would be re-raised as `NOT_FOUND` → 404 translated, not 500), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-lookup failure (e.g. relation-load failure — code-traced, no test) | See 500 example |

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

404 example (nonexistent, soft-deleted, or non-numeric `{user}`):

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
