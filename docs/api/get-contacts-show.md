# Endpoint Name

Contacts – Show Contact (`GET /api/v1/contacts/{contact}`)

# Description

Returns a single contact and marks it as read as a side effect.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/contacts/{contact}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `contacts/{contact}` from `Route::apiResource('contacts', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `["throttle:sensitive", "lang"]` group |
| Controller action | `Marvel\Http\Controllers\ContactController@show` → `ContactRepository::markAsRead` |
| Authentication | Effectively required, enforced by the Spatie `permission:update-contact` constructor middleware — not by `auth:sanctum` (this route group has no `auth:sanctum` middleware). A guest fails the permission middleware before the method body runs |
| Permission | Required: `update-contact` via controller constructor `permission:update-contact` middleware (applies to `show` and `sendReply`); the method itself performs no inline permission check |
| Required headers | None at the route level (in practice a valid Sanctum token belonging to a user with `update-contact` is needed to get past the permission middleware) |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:sensitive`: 5 requests/minute per IP (not per user), per `App\Providers\AppServiceProvider::boot` |
| Route name | `contacts.show` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Effectively required | No | Must satisfy the `update-contact` permission middleware | Any valid token of a permitted user | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `contact` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest or inline validation exists; the raw value is passed to the repository `findOrFail`, which throws `ModelNotFoundException` for unknown ids (converted to `MarvelException(NOT_FOUND)` → 404) | Id of an existing non-trashed contact | None | None | `7` | Identifies the contact to return |

There are no query parameters, body fields, multipart fields, or file uploads for this endpoint. Because the lookup carries the `SoftDeletes` scope, a soft-deleted id yields 404 (verified: fetching a trashed contact returns 404). A non-numeric `{contact}` (e.g. `delete-all` via `GET`) likewise yields 404 through the same path (code-traced). There is no `422` contract.

## Request examples

Valid request showing a contact:

```json
{
  "method": "GET",
  "url": "/api/v1/contacts/7",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: contact returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ContactResource::make($contact))`. Verified by `can_show_contact` (`assertOk` + `assertJsonPath('data.id', ...)`). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The contact serialized by `ContactResource` (after being marked read) | `id`, `name`, `email`, `subject`, `message`, `is_read` (bool — always `true` here, since `show` sets it before serializing), `is_replay` (bool), `created_at` (note: no `updated_at` key) |

Observable effect (code- and test-verified): the contact's `is_read` is set to `true` and saved before serialization (verified by `show_marks_contact_as_read`: `is_read` false before, true after refresh).

Complete success example (`en` locale; `created_at` illustrative):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 7,
    "name": "Alice",
    "email": "alice@example.com",
    "subject": "Help",
    "message": "Need assistance.",
    "is_read": true,
    "is_replay": false,
    "created_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint. The 401 and 403 contracts below have different bodies and must not be conflated.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Guest call (no authenticated user): the Spatie `permission:update-contact` middleware throws `UnauthorizedException::notLoggedIn()`, which `Handler` renders with 401 — this is why the endpoint returns 401 despite having no `auth:sanctum` middleware | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | Translated `ERROR.USER_NOT_LOGGED_IN` via `Handler::messageForSpatieUnauthorized`: `en` → `"User is not logged in."`, `ar` → `"المستخدم غير مسجل الدخول."` | None | Any request without an authenticated user; verified by `unauthenticated_user_cannot_show_contact` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-contact` permission (same Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-contact`; verified by `user_without_update_contact_cannot_show` expecting 403 | See 403 example |
| 404 | No non-trashed contact row matches `{contact}`: the repository `findOrFail` throws `ModelNotFoundException`, which `show` converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced — tests assert status only) | None | Nonexistent id (verified by `show_returns_404_for_nonexistent_contact` for id `99999`); soft-deleted id (verified: trashed contact fetch returns 404); non-numeric `{contact}` (code-traced) | See 404 example |
| 405 | `PUT` or `PATCH` on `/api/v1/contacts/{contact}` (`update` is excluded from the resource registration, so no such route exists; note `POST /contacts/{contact}/reply` is a separate URI, so a bare-path `POST` also yields 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 429 | Exceeding `throttle:sensitive` (5/min per IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller IP | See 429 example |
| 500 | Unexpected failure (any exception other than the caught `ModelNotFoundException`), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-lookup failure (e.g. `save()` failure while marking read — code-traced, no test) | See 500 example |

401 example (guest — no authenticated user):

```json
{
  "message": "User is not logged in.",
  "status": false
}
```

403 example (authenticated without `update-contact` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (nonexistent, soft-deleted, or non-numeric `{contact}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (excluded action, e.g. `PUT /api/v1/contacts/7`):

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
