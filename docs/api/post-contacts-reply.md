# Endpoint Name

Contacts – Reply To Contact (`POST /api/v1/contacts/{id}/reply`)

# Description

Stores a reply as a new contact record linked by email, leaving the original untouched.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/contacts/{id}/reply` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `contacts/{id}/reply` from `packages/marvel/src/Rest/Routes.php` inside the `["throttle:sensitive", "lang"]` group. The explicit route is registered before `apiResource('contacts')`, so the URI is not captured by `GET/PUT/DELETE contacts/{contact}` (and `POST` has no competing resource route) |
| Controller action | `Marvel\Http\Controllers\ContactController@sendReply` → `ContactRepository::ReplayContact` |
| Authentication | Effectively required, enforced by the Spatie `permission:update-contact` constructor middleware — not by `auth:sanctum` (this route group has no `auth:sanctum` middleware). A guest fails the permission middleware before validation runs |
| Permission | Required: `update-contact` via controller constructor `permission:update-contact` middleware (applies to `show` and `sendReply`); the `ContactCreateReplayRequest::authorize` returns `true` and performs no further check |
| Required headers | None at the route level (in practice a valid Sanctum token belonging to a user with `update-contact` is needed to get past the permission middleware) |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:sensitive`: 5 requests/minute per IP (not per user), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before validation: a guest or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`ContactCreateReplayRequest` validation runs before the method body (code-verified order): an invalid body yields 422 even when `{id}` does not exist. Only `subject`/`message` are read from the request; the reply's `name`/`email` are copied from the original contact.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Effectively required | No | Must satisfy the `update-contact` permission middleware | Any valid token of a permitted user | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `id` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No route-level constraint; the raw value reaches the repository `findOrFail`, which throws `ModelNotFoundException` for unknown ids (converted to `MarvelException(NOT_FOUND)` → 404) | Id of an existing non-trashed contact | None | None | `7` | Identifies the original contact being replied to |
| `subject` | body | string | Required | No | `required\|string\|max:255` | Any string up to 255 chars | None | None | `"RE: Original Subject"` | Reply subject (stored on the new record) |
| `message` | body | string | Required | No | `required\|string\|min:3\|max:5000` | Any string of 3–5000 chars | None | None | `"Thank you for your inquiry."` | Reply body (stored on the new record) |

There are no query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored.

## Request examples

Valid request replying to a contact:

```json
{
  "method": "POST",
  "url": "/api/v1/contacts/7/reply",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "subject": "RE: Original Subject",
    "message": "Thank you for your inquiry."
  }
}
```

# Responses

## Success: reply stored — HTTP 200

Returned via `ApiResponse::apiResponse(REPLAY_SENT_SUCCESSFULLY, 200, true, ContactResource::make($contact))` where `$contact` is the newly created reply record. Verified by `can_send_reply` (`assertOk`, `success: true`), `reply_creates_new_contact_record_with_same_email` (2 rows share the email), `reply_record_is_marked_as_read_and_replayed` (`data.is_read`/`data.is_replay` true), and `original_contact_is_not_marked_as_read_or_replayed`. There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.REPLAY_SENT_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Reply sent successfully"`, `ar` → `"تم إرسال الرد بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The new reply record serialized by `ContactResource` (not the original) | `id` (new row id), `name` + `email` (copied from the original), `subject` + `message` (from the request), `is_read: true`, `is_replay: true`, `created_at` (note: no `updated_at` key) |

Observable effects (code- and test-verified): a second `contacts` row is inserted with the original's `name`/`email`, the request's `subject`/`message`, and `is_read = is_replay = true`; the original row is left completely untouched (not marked read or replayed). No actual email is sent by this endpoint despite the code comment mentioning mail (code-verified: the method only inserts the record; mail-sending is **Unverified in source**).

Complete success example (`en` locale; `id`/`created_at` illustrative):

```json
{
  "status": 200,
  "message": "Reply sent successfully",
  "success": true,
  "data": {
    "id": 8,
    "name": "Original",
    "email": "original@example.com",
    "subject": "RE: Original Subject",
    "message": "Thank you for your inquiry.",
    "is_read": true,
    "is_replay": true,
    "created_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/404 contracts are test-verified; the 422 path is verified by rule code plus 422 tests on the sibling contact-create endpoint (no reply-specific 422 test exists).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Guest call (no authenticated user): the Spatie `permission:update-contact` middleware throws `UnauthorizedException::notLoggedIn()`, which `Handler` renders with 401 — this is why the endpoint returns 401 despite having no `auth:sanctum` middleware | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | Translated `ERROR.USER_NOT_LOGGED_IN` via `Handler::messageForSpatieUnauthorized`: `en` → `"User is not logged in."`, `ar` → `"المستخدم غير مسجل الدخول."` | None | Any request without an authenticated user; verified by `unauthenticated_user_cannot_send_replay` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-contact` permission (same Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-contact`; verified by `user_without_update_contact_cannot_send_replay` expecting 403 | See 403 example |
| 404 | No non-trashed contact row matches `{id}` (with a valid body): the repository `findOrFail` throws `ModelNotFoundException`, which `sendReply` converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced — the test asserts status only) | None | Nonexistent id (verified by `reply_returns_404_for_nonexistent_contact` for id `99999`); soft-deleted id (code-traced via the `SoftDeletes` scope) | See 404 example |
| 405 | Any HTTP method other than `POST` on `/api/v1/contacts/{id}/reply` (only `Route::post` is registered; note the sibling resource routes `GET/PUT/DELETE contacts/{contact}` do not match this two-segment URI) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 422 | Any `ContactCreateReplayRequest` validation failure (runs before the lookup, so it takes precedence over 404), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `subject` / `message` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no reply-specific 422 test exists; the same rules are 422-test-verified on contact-create) | Missing/non-string/over-255 `subject`; missing/non-string/under-3/over-5000 `message` | See 422 example |
| 429 | Exceeding `throttle:sensitive` (5/min per IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller IP | See 429 example |
| 500 | Unexpected failure (any exception other than the caught `ModelNotFoundException`), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Post-lookup failure (e.g. reply-insert failure — code-traced, no test) | See 500 example |

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

404 example (nonexistent `{id}` with a valid body; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/contacts/7/reply`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `subject` field):

```json
{
  "message": "The subject field is required.",
  "status": false,
  "errors": {
    "subject": [
      "The subject field is required."
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
