# Endpoint Name

Notifications – Unread (`GET /api/v1/admin/notifications/unread`)

# Description

Returns all of the caller's unread notifications with a count-only meta block.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/notifications/unread` |
| Path composition | `api` prefix + `v1/admin` prefix + `notifications/unread` from the notifications group in `routes/api.php`, inside the route-level `permission:view-notifications` + `lang` group (no route-level `auth:sanctum`; authentication comes from the controller constructor, which runs AFTER route middleware) |
| Controller action | `Marvel\Http\Controllers\NotificationController@unread` (unfiltered `get()` — no pagination; read rows excluded at query level) |
| Authentication | Required via constructor `auth:sanctum`, but the route-level Spatie gate runs first — guests receive the Spatie 401 below |
| Permission | Required: `view-notifications` via route middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read (no inputs of any kind are honored) |
| Rate limit | None on this group (no `throttle:*` middleware — code-verified) |
| Route name | None defined for these routes |

## Request inputs

No inputs of any kind are honored. There is no validation, so there is no `422` contract.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Must satisfy the `view-notifications` gate (which implies an authenticated user) | Any valid token of a permitted user | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale AND each notification's resolved `title`/`message` |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/notifications/unread",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: unread list — HTTP 200

Returned via `ApiResponse::apiResponse(UNREAD_NOTIFICATIONS_FETCHED, 200, true, ['data' => ..., 'meta' => ['total' => ...]])`. Verified by `test_admin_can_fetch_unread_notifications` (2 unread + 1 read → 200, `success: true`, `data.meta.total: 2`), `test_fetch_unread_returns_empty_when_all_read` (`total: 0`, `data.data: []`), and `test_unread_response_structure` (full item + meta keys; all use `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UNREAD_NOTIFICATIONS_FETCHED` (locale from `lang` header) | `en` → `"Unread notifications fetched successfully."`, `ar` → `"تم جلب الإشعارات غير المقروءة بنجاح."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `{data: [...], meta: {total}}` — note the meta carries ONLY `total` (no pagination keys — code-verified difference from the list endpoint) | Items share the list endpoint's item shape |
| `data.data[]` | array of objects | Always present (possibly empty) | Unread rows only, newest first | Same item keys as the list endpoint |

Complete success example (`en` locale; rows illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Unread notifications fetched successfully.",
  "success": true,
  "data": {
    "data": [],
    "meta": { "total": 0 }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (all use `getJson`); the 403 row is verified on the sibling list endpoint through the identical middleware (no unread-specific 403 test exists); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Guest call (no authenticated user): the route-level Spatie gate runs before constructor `auth:sanctum` and throws `notLoggedIn` → 401 with the translated message (NOT generic `"Unauthenticated"`) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | Translated `ERROR.USER_NOT_LOGGED_IN`: `en` → `"User is not logged in."`, `ar` → `"المستخدم غير مسجل الدخول."` (message code-verified via middleware order + Handler branch; tests assert status only) | None | Any request without an authenticated user; verified by `test_unauthenticated_user_cannot_access_unread` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-notifications` permission (same Spatie middleware) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-notifications` (identical middleware path test-verified on the sibling list endpoint) | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/admin/notifications/unread` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

There is no throttle on this group, hence no 429 contract.

401 example (guest — no authenticated user):

```json
{
  "message": "User is not logged in.",
  "status": false
}
```

403 example (authenticated without `view-notifications` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/admin/notifications/unread`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```
