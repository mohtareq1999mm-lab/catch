# Endpoint Name

Attributes – Delete Attribute (`DELETE /api/v1/attributes/{attribute}`)

# Description

Permanently hard-deletes an attribute with cascading values and returns a confirmation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/attributes/{attribute}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `attributes/{attribute}` from `Route::apiResource('attributes', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-attribute` gate decides) |
| Controller action | `Marvel\Http\Controllers\AttributeController@destroy` (manual `findOrFail` → `delete()`; the `Attribute` model uses NO `SoftDeletes` trait, so this is a HARD delete with values cascading — test-verified. ANY exception funnels to `MarvelException(NOT_FOUND)` → 404 translated, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-attribute` via controller constructor `permission:delete-attribute` middleware (applies only to `destroy`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `attributes.destroy` (apiResource default naming) |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the deletion.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `attribute` | path | integer id (no route-level constraint; resolved by manual `findOrFail` — no soft-delete scope exists) | Required | No | Raw segment; unknown ids throw `ModelNotFoundException`, caught by `catch (\Exception)` → translated 404 (code-verified funnel) | Id of an existing attribute | None | None | `4` | Identifies the attribute to delete |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/attributes/4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: attribute hard-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(ATTRIBUTE_DELETED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_authenticated_admin_can_delete_attribute` (`assertOk`, `success: true`, `assertDatabaseMissing('attributes', ...)` — proving HARD deletion) and `test_deleting_attribute_cascades_to_its_values` (values vanish with the parent; both use `deleteJson`, matching this route). There is exactly one success contract.

Complete success example:

```json
{
  "status": 200,
  "message": "Attribute deleted successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ATTRIBUTE_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Attribute deleted successfully"`, `ar` → `"تم حذف السمة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified (all use `deleteJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_delete_attribute` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `delete-attribute` permission (constructor Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-attribute` (no delete-specific 403 test found in this pass; identical constructor-gate path test-verified on sibling attribute routes) | See 403 example |
| 404 | No attribute matches `{attribute}` (manual `findOrFail` miss) — OR any delete failure: the controller's `catch (\Exception)` converts EVERYTHING to `MarvelException(NOT_FOUND)` → 404 translated (code-verified funnel) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — no delete-specific 404 test found in this pass) | None | Unknown id; any delete failure (code-traced) | See 404 example |
| 405 | `POST` on `/api/v1/attributes/{attribute}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `GET` to show) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on this action (all delete exceptions funnel to the 404 above — a REAL outage here still renders 404; code-verified consequence).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `delete-attribute` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{attribute}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/attributes/4`):

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
