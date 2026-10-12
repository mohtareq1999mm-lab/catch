# Endpoint Name

Contacts – List Contacts (`GET /api/v1/contacts`)

# Description

Returns a paginated list of non-trashed contacts with optional read-state filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/contacts` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `contacts` from `Route::apiResource('contacts', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `["throttle:sensitive", "lang"]` group |
| Controller action | `Marvel\Http\Controllers\ContactController@index` |
| Authentication | Effectively required, enforced by the Spatie `permission:view-contacts` constructor middleware — not by `auth:sanctum` (this route group has no `auth:sanctum` middleware). A guest fails the permission middleware before the method body runs |
| Permission | Required: `view-contacts` via controller constructor `permission:view-contacts` middleware (applies only to `index`); the method itself performs no inline permission check |
| Required headers | None at the route level (in practice a valid Sanctum token belonging to a user with `view-contacts` is needed to get past the permission middleware) |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:sensitive`: 5 requests/minute per IP (not per user), per `App\Providers\AppServiceProvider::boot` |
| Route name | `contacts.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Effectively required | No | Must satisfy the `view-contacts` permission middleware | Any valid token of a permitted user | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `limit` | query | integer | Optional | No | No validation; passed to `paginate($limit)` | Any positive integer | `15` | None | `20` | Page size (code-traced; no max cap is enforced) |
| `read` | query | boolean | Optional | No | Evaluated via `$request->boolean('read')` | Truthy values (`1`, `true`, `"1"`, `"true"`) | Absent (no filter) | None | `1` | Restricts to read contacts |
| `unread` | query | boolean | Optional | No | Evaluated via `$request->boolean('unread')` | Truthy values | Absent (no filter) | None | `1` | Restricts to unread contacts |
| `replay` | query | boolean | Optional | No | Evaluated via `$request->boolean('replay')` | Truthy values | Absent (no filter) | None | `1` | Restricts to reply records (code-traced flag name as written in source) |

Soft-deleted rows are always excluded (model uses `SoftDeletes`; no `withTrashed` applies). The repository pushes Prettus `RequestCriteria`, so `search` across `email`/`subject`/`message` (like-match) plus standard filter/order parameters are also accepted (code-traced: `fieldSearchable` verified, exact query grammar **Unverified in source**). There is no `422` contract — no validation runs on this endpoint.

## Request examples

Valid request listing contacts:

```json
{
  "method": "GET",
  "url": "/api/v1/contacts?limit=20&unread=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of contacts — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data)` where `$data` is a `ContactCollection`, so `data` is always present with `data` + `links` keys. Verified by `can_list_contacts` (`assertOk` + `assertJsonCount(2, 'data.data')`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Paginated collection | `{data: [...], links: {...}}` — see below |
| `data.data[]` | array of objects | Always present (possibly empty) | Contacts serialized by `ContactResource` | `id`, `name`, `email`, `subject`, `message`, `is_read` (bool), `is_replay` (bool), `created_at` (note: no `updated_at` key) |
| `data.links` | object | Always present | Pagination metadata (`ContactCollection::toArray`) | `current_page`, `from`, `to`, `last_page`, `path`, `per_page`, `total`, `next_page_url`, `prev_page_url`, `last_page_url`, `first_page_url` (`from`/`to` are `null` on an empty page — code-traced) |

Complete success example (`en` locale; pagination values illustrative):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 3,
        "name": "A",
        "email": "a@test.com",
        "subject": "S1",
        "message": "M1",
        "is_read": false,
        "is_replay": false,
        "created_at": "2026-10-10T12:00:00.000000Z"
      }
    ],
    "links": {
      "current_page": 1,
      "from": 1,
      "to": 1,
      "last_page": 1,
      "path": "http://localhost/api/v1/contacts",
      "per_page": 15,
      "total": 1,
      "next_page_url": null,
      "prev_page_url": null,
      "last_page_url": "http://localhost/api/v1/contacts?page=1",
      "first_page_url": "http://localhost/api/v1/contacts?page=1"
    }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map for this endpoint. The 401 and 403 contracts below have different bodies and must not be conflated.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Guest call (no authenticated user): the Spatie `permission:view-contacts` middleware throws `UnauthorizedException::notLoggedIn()`, which `Handler` renders with 401 — this is why the endpoint returns 401 despite having no `auth:sanctum` middleware | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | Translated `ERROR.USER_NOT_LOGGED_IN` via `Handler::messageForSpatieUnauthorized`: `en` → `"User is not logged in."`, `ar` → `"المستخدم غير مسجل الدخول."` | None | Any request without an authenticated user; verified by `unauthenticated_user_cannot_index_contacts` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-contacts` permission (same Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-contacts`; verified by `user_without_view_contacts_cannot_index` expecting 403 | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/contacts` path (no such routes are registered; note `POST /contacts` is the separate store endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 429 | Exceeding `throttle:sensitive` (5/min per IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller IP | See 429 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (`index` has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Database/query failure (code-traced, no test) | See 500 example |

401 example (guest — no authenticated user):

```json
{
  "message": "User is not logged in.",
  "status": false
}
```

403 example (authenticated without `view-contacts` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `DELETE /api/v1/contacts`):

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
