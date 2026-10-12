# Endpoint Name

Contacts – Delete All Contacts (`DELETE /api/v1/contacts/delete-all`)

# Description

Soft-deletes every contact row in a single mass update, keeping all records in the database.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/contacts/delete-all` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `contacts/delete-all` from `packages/marvel/src/Rest/Routes.php` inside the `["throttle:sensitive", "lang"]` group. The route is registered before `apiResource('contacts')`, so it is not captured by `DELETE contacts/{contact}` |
| Controller action | `Marvel\Http\Controllers\ContactController@deleteAll` → `ContactRepository::deleteAllContacts` |
| Authentication | Effectively required, but enforced by the Spatie `permission:delete-contact` constructor middleware — not by `auth:sanctum` (this route group has no `auth:sanctum` middleware). A guest fails the permission middleware before the method body runs |
| Permission | Required: `delete-contact` via controller constructor `permission:delete-contact` middleware (applies to `destroy` and `deleteAll`); the method itself performs no inline permission check |
| Required headers | None at the route level (no `Authorization` requirement is registered; in practice a valid Sanctum token belonging to a user with `delete-contact` is needed to get past the permission middleware) |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:sensitive`: 5 requests/minute per IP (not per user), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: a guest or unauthorized call fails with 401/403 without reaching the repository.

## Request inputs

There are no inputs of any kind: no path parameters, no query parameters, no body fields, no multipart fields, no file uploads. The method signature is `deleteAll()` and the repository call is argument-free (`Contact::query()->update(['deleted_at' => now()])`). Any sent body is ignored. There is no validation, so there is no `422` contract.

## Request examples

Valid request deleting all contacts:

```json
{
  "method": "DELETE",
  "url": "/api/v1/contacts/delete-all",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: all contacts soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(ALL_CONTACTS_DELETED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent (`apiResponse` adds `data` only when non-empty). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ALL_CONTACTS_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"All contacts deleted successfully"`, `ar` → `"تم حذف جميع جهات الاتصال بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Observable effect (code- and test-verified): every row in `contacts` gets `deleted_at` set to now via a single mass update — including already-trashed rows, whose timestamps are overwritten — and all rows remain in the database (verified by `assertSoftDeleted` on both records plus `assertDatabaseHas('contacts', ['id' => ...])` for both). The mass update bypasses per-model Eloquent events (single `UPDATE` query, no model hydration). Success is returned regardless of row count (code-traced: a zero-row update still returns normally, so an empty table also yields 200 — no test covers this).

Complete success example:

```json
{
  "status": 200,
  "message": "All contacts deleted successfully",
  "success": true
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint. The 401 and 403 contracts below have different bodies and must not be conflated.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Guest call (no authenticated user): the Spatie `permission:delete-contact` middleware throws `UnauthorizedException::notLoggedIn()` (`"User is not logged in."`), which `Handler` renders with 401 — this is why the endpoint returns 401 despite having no `auth:sanctum` middleware | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | Translated `ERROR.USER_NOT_LOGGED_IN` via `Handler::messageForSpatieUnauthorized`: `en` → `"User is not logged in."`, `ar` → `"المستخدم غير مسجل الدخول."` | None | Any request without an authenticated user; verified by `unauthenticated_user_cannot_delete_all_contacts` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `delete-contact` permission (same Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-contact` (code-traced through the identical middleware/Handler path as every other permission-gated endpoint; no delete-all-specific 403 test exists) | See 403 example |
| 404 | `GET /api/v1/contacts/delete-all` (wrong method, code-traced): no `GET` route exists for this URI, so the request falls through to `GET contacts/{contact}` (`show`) with `{contact} = "delete-all"`; `markAsRead('delete-all')` → `findOrFail` throws `ModelNotFoundException`, which `show` converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced, no test) | None | `GET` to the exact URI | See 404 example |
| 405 | Any HTTP method other than `DELETE` on `/api/v1/contacts/delete-all` that matches no other route (e.g. `POST`, `PUT`, `PATCH` to the exact URI), rendered by the `MethodNotAllowedHttpException` branch of `Handler` | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method with no competing route match (code-traced; note the `GET` exception in the 404 row above) | See 405 example |
| 429 | Exceeding `throttle:sensitive` (5/min per IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller IP | See 429 example |
| 500 | Any exception inside the `try` block (e.g. database failure during the mass update), caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike every other error on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `ERROR.COULD_NOT_DELETE_THE_RESOURCE`: `en` → `"Could not delete the resource"`, `ar` → `"تعذر حذف المورد"` | None | Repository/database failure (code-traced, no test) | See 500 example |

401 example (guest — no authenticated user):

```json
{
  "message": "User is not logged in.",
  "status": false
}
```

403 example (authenticated without `delete-contact` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (`GET /api/v1/contacts/delete-all` falling through to `show`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/contacts/delete-all`):

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

500 example (repository failure inside `deleteAll`; `en` locale):

```json
{
  "status": 500,
  "message": "Could not delete the resource",
  "success": false
}
```
