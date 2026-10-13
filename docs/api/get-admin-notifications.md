# Endpoint Name

Notifications – List (`GET /api/v1/admin/notifications`)

# Description

Returns the caller's paginated notifications, newest first, with locale-resolved titles.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/notifications` |
| Path composition | `api` prefix (from `RouteServiceProvider` mapping of `routes/api.php`) + `v1/admin` prefix + `notifications` from the `Route::group(['prefix' => 'admin', 'controller' => NotificationController::class])` block in `routes/api.php`, inside the route-level `auth:sanctum` + `permission:view-notifications` + `lang` group (`auth:sanctum` added at route level in the working tree — guests now hit it first and get plain `"Unauthenticated"`, not the translated Spatie message) |
| Controller action | `Marvel\Http\Controllers\NotificationController@index` (queries the caller's own `notifications()` morph relation — other users' rows are unreachable by construction) |
| Authentication | Required via constructor `auth:sanctum`, but the route-level Spatie `permission:view-notifications` middleware runs FIRST, so a guest receives the Spatie 401 below, not the generic one |
| Permission | Required: `view-notifications` via route middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | None on this group (no `throttle:*` middleware is registered for the notifications routes — code-verified) |
| Route name | None defined for these routes (the group uses `controller` string syntax without names) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Must satisfy the `view-notifications` gate (which implies an authenticated user) | Any valid token of a permitted user | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale AND each notification's resolved `title`/`message` (locale with `en` fallback per item — code-verified `formatNotification`) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `per_page` | query | integer | Optional | No | No validation; passed to `paginate($perPage)` | Any positive integer | `15` | None | `2` | Page size (test-verified: `per_page=2` → `meta.per_page: 2`, 2 items, `total: 5`, `last_page: 3`) |

There is no `422` contract — no validation runs on this endpoint.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/notifications?per_page=2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of notifications — HTTP 200

Returned via `ApiResponse::apiResponse(NOTIFICATIONS_FETCHED, 200, true, ['data' => ..., 'meta' => ...])`. Verified by `test_fetch_notifications_supports_pagination` (5 rows, `per_page=2` → meta + 2 items) and `test_notification_response_structure` (full item + meta keys; both use `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.NOTIFICATIONS_FETCHED` (locale from `lang` header) | `en` → `"Notifications fetched successfully."`, `ar` → `"تم جلب الإشعارات بنجاح."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `{data: [...], meta: {...}}` | See below |
| `data.data[]` | array of objects | Always present (possibly empty) | Caller's notifications, newest first, via `formatNotification` | Each item: `id` (UUID string), `type` (notification class), `title` (locale string, `en` fallback, `''` default), `message` (same resolution), `icon` (default `'bell'`), `resource_type` (default `''`), `resource_id` (default `null`), `action_url` (default `''`), `created_at` (ISO8601), `read_at` (ISO8601 or `null`) |
| `data.meta` | object | Always present | `{current_page, per_page, total, last_page, from, to}` (`from`/`to` null on an empty page — code-traced paginator semantics) | Test-verified keys |

Complete success example (`en` locale; rows illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Notifications fetched successfully.",
  "success": true,
  "data": {
    "data": [
      {
        "id": "9f3a2b1c-0000-4000-8000-000000000000",
        "type": "App\\Notifications\\NewOrderNotification",
        "title": "Test Notification",
        "message": "Test message.",
        "icon": "bell",
        "resource_type": "test",
        "resource_id": 1,
        "action_url": "/admin/test",
        "created_at": "2026-10-10T12:00:00+00:00",
        "read_at": null
      }
    ],
    "meta": {
      "current_page": 1,
      "per_page": 15,
      "total": 1,
      "last_page": 1,
      "from": 1,
      "to": 1
    }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified (all use `getJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token: route-level `auth:sanctum` (working-tree addition) runs before the Spatie gate and throws `AuthenticationException` → 401 with the generic message (NOT the translated Spatie text) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via the `AuthenticationException` branch (message code-verified via middleware order + Handler branch; tests assert status only) | None | Any request without a valid token; verified by `test_unauthenticated_user_cannot_access_notifications` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `view-notifications` permission (same Spatie middleware) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-notifications`; verified by `test_non_admin_cannot_access_notifications` and `test_admin_without_view_permission_cannot_fetch_notifications`, both expecting 403 | See 403 example |
| 405 | `POST`, `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/admin/notifications` path (`DELETE` routes to the sibling destroy-all endpoint, so only `POST`/`PUT`/`PATCH` 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `POST`/`PUT`/`PATCH` to the exact URI (code-traced) | See 405 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

There is no throttle on this group, hence no 429 contract.

401 example (guest — no authenticated user):

```json
{
  "message": "Unauthenticated",
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

405 example (wrong method, e.g. `POST /api/v1/admin/notifications`):

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
