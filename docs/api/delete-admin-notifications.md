# Endpoint Name

Notifications – Delete One (`DELETE /api/v1/admin/notifications/{id}`)

# Description

Permanently deletes one of the caller's notifications and returns a confirmation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin/notifications/{id}` |
| Path composition | `api` prefix + `v1/admin` prefix + `notifications/{id}` from the notifications group in `routes/api.php`, inside the route-level `permission:manage-notifications` + `lang` group |
| Controller action | `Marvel\Http\Controllers\NotificationController@destroy` (lookup scoped to `$user->notifications()` — a foreign id 404s instead of 403, anti-leak by design; row hard-deleted, no `data` returned) |
| Authentication | Required via constructor `auth:sanctum`, but the route-level Spatie gate runs first — guests receive the Spatie 401 below |
| Permission | Required: `manage-notifications` via route middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | None on this group (no `throttle:*` middleware — code-verified) |
| Route name | None defined for these routes |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Must satisfy the `manage-notifications` gate (which implies an authenticated user) | Any valid token of a permitted user | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | UUID string (no route constraint; notification ids are UUIDs) | Required | No | No validation; passed to the caller-scoped `notifications()->findOrFail($id)`, which throws `ModelNotFoundException` for unknown OR foreign ids → `Handler` 404 (the method has no try/catch at all) | Id of one of the caller's own notifications | None | None | `9f3a2b1c-0000-4000-8000-000000000000` | Identifies the notification to delete |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin/notifications/9f3a2b1c-0000-4000-8000-000000000000",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: notification deleted — HTTP 200

Returned via `ApiResponse::apiResponse(NOTIFICATION_DELETED, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_admin_can_delete_single_notification` (`assertStatus(200)`, `success: true`, `DatabaseNotification::find($id)` null afterwards — proving hard deletion; uses `deleteJson`, matching this route). There is exactly one success contract.

Complete success example:

```json
{
  "status": 200,
  "message": "Notification deleted successfully.",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.NOTIFICATION_DELETED` (locale from `lang` header) | `en` → `"Notification deleted successfully."`, `ar` → `"تم حذف الإشعار بنجاح."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403/404 contracts are test-verified (all use `deleteJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token: route-level `auth:sanctum` (working-tree addition) runs before the Spatie gate and throws `AuthenticationException` → 401 with the generic message (NOT the translated Spatie text) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via the `AuthenticationException` branch (message code-verified via middleware order + Handler branch; tests assert status only) | None | Any request without a valid token; verified by `test_unauthenticated_user_cannot_delete_notification` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `manage-notifications` permission (same Spatie middleware) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `manage-notifications`; verified by `test_non_admin_cannot_delete` and `test_admin_with_view_only_cannot_delete`, both expecting 403 | See 403 example |
| 404 | No notification with this id among the CALLER's rows: the scoped `findOrFail` throws `ModelNotFoundException` → `Handler` renders its `ModelNotFoundException` branch. A foreign (another user's) id is indistinguishable from a nonexistent one (anti-leak by design — code-verified scope) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id (verified by `test_delete_returns_404_for_nonexistent_notification` for `'nonexistent-id'`) or another user's notification id (code-traced through the same scoped lookup) | See 404 example |
| 405 | Any HTTP method other than `DELETE` on this exact URI (only `Route::delete` is registered for the bare id path; note the suffixed PATCH/DELETE URIs are separate actions, and sibling GET/PUT/PATCH/POST on the bare path have no routes) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `PATCH` to the same URI (code-traced) | See 405 example |
| 500 | Unexpected failure (any exception in the lookup/delete), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

There is no throttle on this group, hence no 429 contract.

401 example (guest — no authenticated user):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `manage-notifications` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown or foreign `{id}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/admin/notifications/9f3a/read`):

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
