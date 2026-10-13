# Endpoint Name

Notifications – Mark All Read (`PATCH /api/v1/admin/notifications/read-all`)

# Description

Marks all of the caller's unread notifications read and reports how many changed.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` |
| Full path | `/api/v1/admin/notifications/read-all` |
| Path composition | `api` prefix + `v1/admin` prefix + `notifications/read-all` from the notifications group in `routes/api.php`, inside the route-level `permission:manage-notifications` + `lang` group. REACHABLE despite following `PATCH notifications/{id}/read`: that sibling needs three URI segments while this one has two, so no capture occurs (segment-count verified — the feared shadowing does not apply) |
| Controller action | `Marvel\Http\Controllers\NotificationController@markAllAsRead` (counts unread FIRST, then mass-updates — the returned count is pre-update) |
| Authentication | Required via constructor `auth:sanctum`, but the route-level Spatie gate runs first — guests receive the Spatie 401 below |
| Permission | Required: `manage-notifications` via route middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | None on this group (no `throttle:*` middleware — code-verified) |
| Route name | None defined for these routes |

## Request inputs

No inputs of any kind are honored. There is no validation, so there is no `422` contract.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Must satisfy the `manage-notifications` gate (which implies an authenticated user) | Any valid token of a permitted user | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

## Request examples

```json
{
  "method": "PATCH",
  "url": "/api/v1/admin/notifications/read-all",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: all marked — HTTP 200

Returned via `ApiResponse::apiResponse(ALL_NOTIFICATIONS_MARKED_READ, 200, true, ['marked_count' => $count])`. Verified by `test_admin_can_mark_all_notifications_as_read` (3 unread → 200, `success: true`, `data.marked_count: 3`, unread count afterwards `0`), `test_mark_all_as_read_when_none_unread` (`marked_count: 0` with 200 — zero case is success, not an error), and `test_mark_all_as_read_response_structure` (all use `patchJson`, matching this route). There is exactly one success contract. Only the caller's own unread rows are touched (scoped relation — other users unaffected by construction).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ALL_NOTIFICATIONS_MARKED_READ` (locale from `lang` header) | `en` → `"All notifications marked as read successfully."`, `ar` → `"تم تعليم جميع الإشعارات كمقروءة بنجاح."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `{marked_count}` — counted BEFORE the mass update | Integer `>= 0` (test-verified `3` and `0` cases) |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "All notifications marked as read successfully.",
  "success": true,
  "data": { "marked_count": 3 }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified (all use `patchJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token: route-level `auth:sanctum` (working-tree addition) runs before the Spatie gate and throws `AuthenticationException` → 401 with the generic message (NOT the translated Spatie text) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via the `AuthenticationException` branch (message code-verified via middleware order + Handler branch; tests assert status only) | None | Any request without a valid token; verified by `test_unauthenticated_user_cannot_mark_all_as_read` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `manage-notifications` permission (same Spatie middleware) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `manage-notifications`; verified by `test_admin_with_view_only_cannot_mark_all_as_read` expecting 403 | See 403 example |
| 405 | Any HTTP method other than `PATCH` on `/api/v1/admin/notifications/read-all` (only `Route::patch` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `DELETE` to the same URI (code-traced; note the sibling E2E file's `postJson` read-all calls target a different prefix and are unrelated to this route) | See 405 example |
| 500 | Unexpected failure (any exception in the count/update), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

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

405 example (wrong method, e.g. `POST /api/v1/admin/notifications/read-all`):

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
