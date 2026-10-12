# Endpoint Name

Admin – Add User (`POST /api/v1/admin-users/add`)

# Description

Creates an admin-type user with optional roles and returns the created user.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin-users/add` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `admin-users/add` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\UserController@adminAddUsers` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required. `create-user` via controller constructor `permission:create-user` middleware on `adminAddUsers` only |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`; tests use `postJson` which sends `Accept: application/json`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` (fields only); `multipart/form-data` (required when sending `image`) |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale (`message.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required | No | Must match body format | `application/json`, `multipart/form-data` | None | `multipart/form-data` is required when `image` is sent | `application/json` | Declares body encoding |
| `name` | body | string | Required | No | `required` (no min/max/format rule in `AdminCreateUserRequest`; global `TrimStrings` + `ConvertEmptyStringsToNull` apply, so `""` becomes `null` and fails `required`) | None | None | None | `"New Admin"` | Display name of the new admin user |
| `email` | body | string | Required | No | `required\|email\|unique:users,email` | Any RFC-valid email not already in `users.email` | None | None | `"newadmin@example.com"` | Login/email address of the new user |
| `password` | body | string | Required | No | `required\|min:6\|confirmed\|max:50` (`confirmed` requires a matching `password_confirmation` field) | Any string of 6–50 characters | None | None | `"password123"` | Initial password (stored only as `Hash::make` output; never returned) |
| `password_confirmation` | body | string | Conditionally required | No | No explicit rule in the FormRequest; required and must equal `password` whenever `password` is present, via the `confirmed` rule on `password` | Must exactly match `password` | None | Required when `password` is present | `"password123"` | Confirms the intended password |
| `phone_number` | body | string | Required | No | `required\|unique:users,phone_number` (no format rule in the FormRequest; any non-empty value passes validation; column is `string unique nullable` at DB level per `2014_10_12_000000_create_users_table`) | Any value not already in `users.phone_number` | None | None | `"0100000201"` | Contact phone number of the new user |
| `roles` | body | array of integer | Optional | No (`sometimes\|array`, not `nullable`; an explicit `null` fails the `array` rule) | `sometimes\|array` (key may be omitted; when present it must be an array) | Array (possibly empty) of role ids | None (no application default; when omitted the repository reads `null` — see Unverified note) | Each element is validated by `roles.*` when the array is present and non-empty | `[1]` | Role ids to assign to the new user |
| `roles.*` | body | integer | Conditionally required | No | `integer\|exists:roles,id` | Ids of existing rows in `roles.id` | None | Applies to every element of `roles` when `roles` is present and the element is present | `1` | One role id to assign |
| `image` | file (multipart field `image`) | file (binary) | Optional | Yes (`nullable`) | `nullable\|image\|mimes:jpeg,png,jpg,webp,gif` (no `max` size rule in the FormRequest; PHP/post-size and Media Library limits still apply — **Unverified in source** for exact byte limit) | Files detected as images with extension/MIME `jpeg`, `png`, `jpg`, `webp`, `gif` | None (no media attached; response `image` is `null`) | Validated only when present and non-null | Binary upload with filename `avatar.jpg` | Profile image stored in Media Library collection `user-image` on disk `users` |
| `is_active` | body | string, integer, or boolean (`in:0,1` uses loose comparison; verified test passes boolean `true` and gets HTTP 200) | Optional | Yes (`nullable`) | `nullable\|in:0,1` | `0`, `1`, `"0"`, `"1"` (boolean `true` also passes validation per verified test and is stored as-is) | `0` when the key is absent, applied by `UserRepository::addUserWithRole` via `isset($request->is_active) ? $request->is_active : 0` | None | `1` | Whether the new user is active |

Server-forced attributes (not client inputs, documented to prevent misuse): `type` is always set to `"admin"`; `email_verified_at` is always set to `now()`; `password` is always stored via `Hash::make()`; `name`, `email`, `phone_number`, `is_active` are mass-assigned from the request; `image` is attached via `uploadSingleImage` only when `hasFile('image')`; roles are assigned via `assignRole()` only when the looked-up role collection is non-empty.

**Unverified in source:** the exact behavior when `roles` is omitted entirely. Validation permits omission (`sometimes`), but `UserRepository::addUserWithRole` then evaluates `Role::whereIn('id', null)`, which has no covering test (all success tests send `roles`, including `[]`). The resulting HTTP response for an omitted `roles` key is therefore marked **Unverified in source** and not claimed as 200.

## Request examples

Valid JSON request without file upload:

```json
{
  "name": "New Admin",
  "email": "newadmin@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "phone_number": "0100000201",
  "roles": [1],
  "is_active": 1
}
```

Valid JSON request with an empty role list (verified to succeed; created user has `"roles": []`):

```json
{
  "name": "No Role Admin",
  "email": "norole@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "phone_number": "0100000202",
  "roles": []
}
```

Valid multipart request with profile image and activation flag:

```json
{
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en",
    "Content-Type": "multipart/form-data"
  },
  "parts": {
    "name": "Photo Admin",
    "email": "photoadmin@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "phone_number": "0100000214",
    "roles": [1],
    "is_active": "1",
    "image": "binary file: avatar.jpg (image/jpeg)"
  }
}
```

Corresponding headers for the JSON variants:

```json
{
  "Authorization": "Bearer 1|abcdef123456",
  "Accept": "application/json",
  "Content-Type": "application/json",
  "lang": "en"
}
```

# Responses

## Success: admin user created — HTTP 200

Returned by `UserController::adminAddUsers` via `ApiResponse::apiResponse(USER_ADDED_SUCCESSFULLY, 200, true, UserResource::make($user))` after `$user->load(['roles'])`. There is exactly one success contract; role assignment only changes the contents of the `roles`/`permissions` arrays, not the structure.

| Property | Value |
| --- | --- |
| HTTP status | `200` |
| Content-Type | `application/json` |
| Envelope | `{status: number, message: string, success: boolean, data: object}` built by `ApiResponse::apiResponse`; note success and error envelopes differ (errors use `{message, status: false}` with boolean `status`) |
| `status` | Always present. Integer, always `200` on success (mirrors the HTTP status) |
| `message` | Always present. String. Translated `MESSAGE.USER_ADDED_SUCCESSFULLY`: `en` → `"User added successfully"`, `ar` → `"تمت إضافة المستخدم بنجاح"` (locale from `lang` header) |
| `success` | Always present. Boolean, always `true` on success |
| `data` | Always present on success (`apiResponse` adds `data` only when non-empty; here it is a `UserResource`). Object described below |

`data` (Marvel `UserResource`) fields:

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `id` | integer | Always present | Primary key of the created user | Auto-increment id, e.g. `42` |
| `name` | string | Always present | Stored display name | Echo of the request `name` |
| `email` | string | Always present | Stored email | Echo of the request `email` |
| `email_verified_at` | string (datetime) or null | Always present, non-null for this endpoint | Verification timestamp | Set server-side to `now()` (e.g. `"2026-10-10T11:00:00.000000Z"`); never client-supplied |
| `is_active` | integer/boolean | Always present | Stored activation flag | Request `is_active` when supplied, else `0` (repository default) |
| `image` | string (URL) or null | Always present (null when no image) | Public URL of the first `user-image` media item | `getFirstMediaUrl('user-image') ?: null`; `null` when no file was uploaded |
| `type` | string | Always present | User discriminator | Always `"admin"` for this endpoint (forced server-side) |
| `phone_number` | string | Always present | Stored phone number | Echo of the request `phone_number` |
| `created_at` | string (datetime) | Always present | Creation timestamp | E.g. `"2026-10-10T11:00:00.000000Z"` |
| `updated_at` | string (datetime) | Always present | Last-update timestamp | E.g. `"2026-10-10T11:00:00.000000Z"` |
| `roles` | array of objects | Always present; may be empty (`[]` verified when `roles: []` is sent) | Assigned roles (only `roles` relation is eager-loaded by the controller) | Each item is a `RoleResource` (see below) |
| `roles[].id` | integer | Always present per item | Role id | E.g. `1` |
| `roles[].name` | string | Always present per item | Role name | E.g. `"super_admin"` |
| `roles[].display_name` | string or null | Always present per item (raw value) | Raw display name column | Raw `display_name` value |
| `roles[]` items | objects | Always present (possibly empty) | Assigned roles as `RoleResource` items | Each item: `id`, `name`, `display_name`, `created_at`, `updated_at` — note: no `guard_name` key since 2026-10-10 |
| `roles[].created_at` | string (datetime) or null | Always present per item | Role creation timestamp | Timestamp value |
| `roles[].updated_at` | string (datetime) or null | Always present per item | Role update timestamp | Timestamp value |
| `roles[].permissions` | omitted | Always absent for this endpoint | Nested role permissions | `RoleResource` includes `permissions` only `whenLoaded('permissions')`; the controller loads only `['roles']`, so this key is omitted |
| `permissions` | array of objects | Always present; may be empty when the assigned roles grant no permissions | Effective permissions via `getPermissionsViaRoles()` | Each item is a `PermissionResource`: `{id: integer, label: string}` where `label` is the `permissions.{name}` translation when it exists, else the raw permission name |
| `address` | omitted | Always absent for this endpoint | Customer addresses | `whenLoaded('address')` and `address` is never loaded here, so the key is omitted (not `null`, not `[]`) |

Fields never exposed by this endpoint even though they exist on the model/table: `password` (hidden via `User::$hidden`), `remember_token` (hidden). `password_confirmation` is never stored or returned.

Complete success example (one assigned role; `address` absent; nested role `permissions` absent because not loaded):

```json
{
  "status": 200,
  "message": "User added successfully",
  "success": true,
  "data": {
    "id": 42,
    "name": "New Admin",
    "email": "newadmin@example.com",
    "email_verified_at": "2026-10-10T11:00:00.000000Z",
    "is_active": 1,
    "image": null,
    "type": "admin",
    "phone_number": "0100000201",
    "created_at": "2026-10-10T11:00:00.000000Z",
    "updated_at": "2026-10-10T11:00:00.000000Z",
    "roles": [
      {
        "id": 1,
        "name": "super_admin",
        "display_name": "Super Admin",
        "created_at": "2026-10-09T10:00:00.000000Z",
        "updated_at": "2026-10-09T10:00:00.000000Z"
      }
    ],
    "permissions": [
      {
        "id": 5,
        "label": "create-user"
      }
    ]
  }
}
```

Success example variant with no roles (`"roles": []` sent; `permissions` empty):

```json
{
  "status": 200,
  "message": "User added successfully",
  "success": true,
  "data": {
    "id": 43,
    "name": "No Role Admin",
    "email": "norole@example.com",
    "email_verified_at": "2026-10-10T11:05:00.000000Z",
    "is_active": 0,
    "image": null,
    "type": "admin",
    "phone_number": "0100000202",
    "created_at": "2026-10-10T11:05:00.000000Z",
    "updated_at": "2026-10-10T11:05:00.000000Z",
    "roles": [],
    "permissions": []
  }
}
```

# Errors

This endpoint has no path or query parameters, performs no model lookup by id, and returns no pagination, so there is no endpoint-specific `404` contract. The implementation has no machine-readable error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `create-user` permission (Spatie `permission:create-user` middleware rejects before validation) | `{message: string, status: boolean}` | None | Spatie `UnauthorizedException` message rendered by `Handler::messageForSpatieUnauthorized` (locale-aware) | None | Valid token + user without `create-user`; verified by `test_non_admin_cannot_create_admin_user` expecting 403 | See 403 example |
| 405 | Any HTTP method other than `POST` on `/api/v1/admin-users/add` (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `PUT`, `PATCH`, `DELETE` to the same URI | See 405 example |
| 422 | Request validation failure in `AdminCreateUserRequest` (`failedValidation` throws `ValidationException`; rendered with `errors` map) | `{message: string, status: boolean, errors: object}` where `errors` maps field → string array | None | Validator message (e.g. `"The name field is required."`; wording follows Laravel defaults in the active `lang` locale) | Exact structure `{field: [message, ...]}`; multiple fields and multiple messages per field are possible; validation accumulates all failures (verified tests assert 422 for each independent field) | Any rule below violated. Failing fields/rules: `name` (`required`); `email` (`required`, `email`, `unique:users,email`); `password` (`required`, `min:6`, `max:50`, `confirmed`); `password_confirmation` (must be present and equal when `password` present); `phone_number` (`required`, `unique:users,phone_number`); `roles` (`array` when present); `roles.*` (`integer`, `exists:roles,id`); `image` (`image`, `mimes:jpeg,png,jpg,webp,gif`); `is_active` (`in:0,1` when present and non-null) | See 422 examples |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status`/`errors` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 409 | Race-condition unique-constraint violation at the database (`users.email` unique, `users.phone_number` unique) when validation passed but the row was taken concurrently (`QueryException` branch of `Handler`); normal duplicates are returned as 422 by validation instead | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Concurrent insert of the same `email` or `phone_number` after validation passed | See 409 example |
| 500 | Repository/application failure after validation: `UserRepository::addUserWithRole` catches only `ValidatorException` and rethrows `MarvelException(SOMETHING_WENT_WRONG)`, which the controller converts to `MarvelException(ERROR.SOMETHING_WENT_WRONG)` and `Handler` renders as 500; also any uncaught exception (e.g. media-storage failure) renders as 500 (`config('app.debug')` controls whether the raw message is exposed) | `{message: string, status: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` (for `MarvelException`); for non-Marvel exceptions the message is the exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-validation persistence, media upload, or role-assignment failure | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-user` permission):

```json
{
  "message": "User does not have the right permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/admin-users/add`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `name`, duplicate `email`, missing confirmation):

```json
{
  "message": "The name field is required. (and 2 more errors)",
  "status": false,
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email has already been taken."],
    "password": ["The password field confirmation does not match."]
  }
}
```

422 example (invalid `roles.*` and `is_active`):

```json
{
  "message": "The selected roles.0 is invalid. (and 1 more error)",
  "status": false,
  "errors": {
    "roles.0": ["The selected roles.0 is invalid."],
    "is_active": ["The selected is active is invalid."]
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

409 example (concurrent duplicate passing validation but violating the DB unique index):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

500 example (`en` locale; `ar` locale returns `"حدث خطأ ما"`):

```json
{
  "message": "Something went wrong.",
  "status": false
}
```
