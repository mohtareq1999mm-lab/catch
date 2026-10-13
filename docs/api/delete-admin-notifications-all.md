# Endpoint Name

Notifications – Delete All (`DELETE /api/v1/admin/notifications`)

# Description

Permanently deletes only the caller's notifications and reports how many were removed.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin/notifications` |
| Path composition | `api` prefix + `v1/admin` prefix + `notifications` from the notifications group in `routes/api.php`, inside the route-level `permission:manage-notifications` + `lang` group |
| Controller action | `Marvel\Http\Controllers\NotificationController@destroyAll` (counts FIRST, then mass-deletes — the returned count is pre-delete; strictly caller-scoped) |
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
  "method": "DELETE",
  "url": "/api/v1/admin/notifications",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: all deleted — HTTP 200

Returned via `ApiResponse::apiResponse(ALL_NOTIFICATIONS_DELETED, 200, true, ['deleted_count' => $count])`. Verified by `test_admin_can_delete_all_notifications` (3 rows → 200, `success: true`, `data.deleted_count: 3`, caller count afterwards `0`), `test_delete_all_when_no_notifications` (`deleted_count: 0` with 200 — zero case is success, not an error), `test_delete_all_only_deletes_own_notifications` (caller with 2 + other admin with 1 → `deleted_count: 2`, caller `0`, other still `1` — proving strict caller scope), and `test_delete_all_response_structure` (all use `deleteJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ALL_NOTIFICATIONS_DELETED` (locale from `lang` header) | `en` → `"All notifications deleted successfully."`, `ar` → `"تم حذف جميع الإشعارات بنجاح."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `{deleted_count}` — counted BEFORE the mass delete | Integer `>= 0` (test-verified `3`, `2`, and `0` cases) |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "All notifications deleted successfully.",
  "success": true,
  "data": { "deleted_count": 3 }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (all use `deleteJson`); the 403 row is verified on sibling manage-gated routes through the identical middleware (no destroy-all-specific 403 test exists); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token: route-level `auth:sanctum` (working-tree addition) runs before the Spatie gate and throws `AuthenticationException` → 401 with the generic message (NOT the translated Spatie text) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via the `AuthenticationException` branch (message code-verified via middleware order + Handler branch; tests assert status only) | None | Any request without a valid token; verified by `test_unauthenticated_user_cannot_delete_all_notifications` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `manage-notifications` permission (same Spatie middleware) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `manage-notifications` (identical middleware path test-verified on sibling manage-gated routes) | See 403 example |
| 405 | Any HTTP method other than `DELETE` on the bare `/api/v1/admin/notifications` path (`GET` routes to the sibling list endpoint, so only `POST`/`PUT`/`PATCH` 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `POST`/`PUT`/`PATCH` to the exact URI (code-traced) | See 405 example |
| 500 | Unexpected failure (any exception in the count/delete), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

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
