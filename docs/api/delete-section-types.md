# Endpoint Name

Section Types – Delete Type (`DELETE /api/v1/section-types/{section_type}`)

# Description

Permanently hard-deletes a section type and returns a confirmation without data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/section-types/{section_type}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `section-types/{section_type}` from `Route::apiResource('section-types', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\SectionTypeController@destroy` → `SectionTypeService::deleteType` (find-by-ID then delete; the bound model's id is forwarded) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-section-types` via controller constructor `permission:delete-section-types` middleware (applies only to `destroy`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `section-types.destroy` (apiResource default naming) |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the deletion. Implicit binding resolves `{section_type}` by the `type` column before the method runs.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | None | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `section_type` | path | string (implicit route-model binding via `getRouteKeyName() = 'type'`) | Required | No | Resolved by Laravel before the method runs; unknown type strings throw `ModelNotFoundException` → `Handler` 404 (the method has no try/catch at all) | An existing `section_types.type` value | None | None | `banner` | Identifies the row to delete, by type string |

There are no query parameters, body fields, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/section-types/banner",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: type hard-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(TYPE_DELETED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_admin_can_delete_section_type` (`assertOk`, `success: true`, `assertDatabaseMissing('section_types', ['type' => 'banner'])` — proving HARD deletion: the model uses no `SoftDeletes` trait; uses `deleteJson`, matching this route). There is exactly one success contract. Dependent rows: `section_type_settings` carry `cascadeOnDelete` (migration-verified) and vanish with the row; `sections.type` is a plain string with NO foreign key (code-verified — sections keep referencing the deleted type name, and their resource falls back to empty settings).

Complete success example:

```json
{
  "status": 200,
  "message": "Type deleted successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.TYPE_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Type deleted successfully"`, `ar` → `"تم حذف النوع بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401/403 contracts are test-verified (all use `deleteJson`); the 404 path is binding-verified by convention (no delete-specific 404 test exists); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_delete_section_type` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `delete-section-types` permission (Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-section-types`; verified by `test_user_without_delete_section_types_gets_forbidden` expecting 403 | See 403 example |
| 404 | No `section_types` row has `type = {section_type}`: implicit binding throws `ModelNotFoundException` before the method runs → `Handler` renders its `ModelNotFoundException` branch | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown type string (code-traced — no delete-specific 404 test exists) | See 404 example |
| 405 | `POST` on `/api/v1/section-types/{type}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `GET` to show/settings) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a type segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in the delete), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `delete-section-types` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{section_type}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/section-types/banner`):

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
