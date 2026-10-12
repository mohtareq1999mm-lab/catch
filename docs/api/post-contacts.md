# Endpoint Name

Contacts – Create Contact (`POST /api/v1/contacts`)

# Description

Creates a contact message from visitor input without requiring authentication.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/contacts` (alias: `/api/v1/contact-us` maps to the same `store` action) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `contacts` from `Route::apiResource('contacts', ...)` (alias `contact-us` is an explicit `Route::post` in the same group) in `packages/marvel/src/Rest/Routes.php` inside the `["throttle:sensitive", "lang"]` group |
| Controller action | `Marvel\Http\Controllers\ContactController@store` → `ContactRepository::saveContact` |
| Authentication | Not required. No permission middleware applies to `store` (the constructor gates only `index`, `show`/`sendReply`, `destroy`/`deleteAll`, `deleteAllReadContacts`), and the `ContactCreateRequest::authorize` returns `true` |
| Permission | None required |
| Required headers | `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:sensitive`: 5 requests/minute per IP (not per user), per `App\Providers\AppServiceProvider::boot` |
| Route name | `contacts.store` (apiResource default naming; the alias has none) |

## Request inputs

Validation runs via `ContactCreateRequest` before the method body: any rule failure throws `ValidationException` (re-raised by `failedValidation`), which `Handler` renders as 422. Only `name`/`email`/`subject`/`message` are persisted (`$data->only($this->dataArray)`); any extra body fields are silently ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `name` | body | string | Required | No | `required\|string\|max:255` | Any string up to 255 chars | None | None | `"Public User"` | Sender name |
| `email` | body | string | Required | No | `required\|email\|max:255` | Any valid email up to 255 chars | None | None | `"public@example.com"` | Sender email (reply target) |
| `subject` | body | string | Required | No | `required\|string\|max:255` | Any string up to 255 chars | None | None | `"Public Inquiry"` | Message subject |
| `message` | body | string | Required | No | `required\|string\|min:3\|max:5000` | Any string of 3–5000 chars | None | None | `"This is a public message."` | Message body |

There are no path parameters, query parameters, multipart fields, or file uploads for this endpoint.

## Request examples

Valid guest request creating a contact:

```json
{
  "method": "POST",
  "url": "/api/v1/contacts",
  "headers": {
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": "Public User",
    "email": "public@example.com",
    "subject": "Public Inquiry",
    "message": "This is a public message."
  }
}
```

# Responses

## Success: contact created — HTTP 201

Returned via `ApiResponse::apiResponse(CONTACT_CREATED_SUCCESSFULLY, 201, true, ContactResource::make($contact))`. There is exactly one success contract. Verified by `anyone_can_create_contact_without_permissions` and `unauthenticated_user_can_create_contact` (both `assertStatus(201)`), plus resource-structure assertions (`id`, `name`, `email`, `subject`, `message`, `is_read`, `is_replay`, `created_at` with `is_read`/`is_replay` false).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CONTACT_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Contact created successfully"`, `ar` → `"تم إنشاء جهة الاتصال بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created contact serialized by `ContactResource` | `id`, `name`, `email`, `subject`, `message`, `is_read` (bool, `false` on create), `is_replay` (bool, `false` on create), `created_at` (note: no `updated_at` key) |

Observable effects (code-verified): the row is persisted with only the four whitelisted fields, and `ContactMessageReceived` is dispatched for the new contact (downstream listener effects **Unverified in source**).

Complete success example (`en` locale; `id`/`created_at` illustrative):

```json
{
  "status": 201,
  "message": "Contact created successfully",
  "success": true,
  "data": {
    "id": 7,
    "name": "Public User",
    "email": "public@example.com",
    "subject": "Public Inquiry",
    "message": "This is a public message.",
    "is_read": false,
    "is_replay": false,
    "created_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/contacts` path (no such routes are registered; note `GET /contacts` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 409 | Database constraint failure during `create()` (e.g. a column constraint violation): `store` catches only `MarvelException`, so a `QueryException` escapes to `Handler`'s `QueryException` branch; conditional on database state, not on request input | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Only when persisting violates a database constraint (code-traced, no test) | See 409 example |
| 422 | Any `ContactCreateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`: first failure message, plus `" (and N more errors)"` when multiple fail) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `name`, `email`, `subject`, `message`; full multi-failure map **Unverified in source**) | Missing/short/invalid fields — verified triggers: missing `email`, invalid `email`, missing `subject`, missing `message`, `message` shorter than 3 chars, missing `name` (six tests, each `assertStatus(422)`) | See 422 example |
| 429 | Exceeding `throttle:sensitive` (5/min per IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller IP | See 429 example |
| 500 | A `MarvelException` raised inside the `try` block, rethrown by `store` as `MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` → `Handler`'s `MarvelException` branch (no `NOT_FOUND`/`NOT_AUTHORIZED` marker → 500 with translated notice) | `{message: string, status: boolean}` | None | Translated `ERROR.COULD_NOT_CREATE_THE_RESOURCE`: `en` → `"Could not create the resource"`, `ar` → `"تعذر إنشاء المورد"` | None | Post-validation internal failure (code-traced; no test — nothing on the inspected path ordinarily throws `MarvelException`) | See 500 example |

405 example (wrong method, e.g. `PUT /api/v1/contacts`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (database constraint failure during `create()`):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

422 example (missing `email` field):

```json
{
  "message": "The email field is required.",
  "status": false,
  "errors": {
    "email": [
      "The email field is required."
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

500 example (post-validation internal failure; `en` locale):

```json
{
  "message": "Could not create the resource",
  "status": false
}
```
