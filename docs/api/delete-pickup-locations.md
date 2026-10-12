# Endpoint Name

Pickup Locations – Delete (`DELETE /api/v1/pickup-locations/{pickup_location}`)

# Description

Soft-deletes a pickup location, promoting the oldest survivor when it was default.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/pickup-locations/{pickup_location}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `pickup-locations/{pickup_location}` from `Route::apiResource('pickup-locations', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-pickup-location` gate decides) |
| Controller action | `Marvel\Http\Controllers\PickupLocationController@destroy` (manual `findOrFail` → `delete()` → tag flush; the model uses `SoftDeletes`, so this is a SOFT delete. ANY exception funnels to `MarvelException(NOT_FOUND)` → 404 translated, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-pickup-location` via controller constructor `permission:delete-pickup-location` middleware (applies only to `destroy`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `pickup-locations.destroy` (apiResource default naming) |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the deletion.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `pickup_location` | path | integer id (no route-level constraint; resolved by manual `findOrFail` under the `SoftDeletes` scope) | Required | No | Raw segment; unknown/trashed ids throw `ModelNotFoundException`, caught by `catch (Exception)` → translated 404 (code-verified funnel) | Id of a non-trashed location | None | None | `2` | Identifies the location to soft-delete |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/pickup-locations/2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: location soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(PICKUP_LOCATION_DELETED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. No endpoint-specific success test exists (all expectations below are code-verified). There is exactly one success contract. Observable effects (code-verified): `deleted_at` set (row kept, excluded from listings); when the deleted row WAS the default, the OLDEST surviving row (lowest id) is promoted to `is_default = true` automatically (model `deleted` hook — survivable no-op when no rows remain); the `PICKUP_LOCATIONS` cache tag flushed.

Complete success example:

```json
{
  "status": 200,
  "message": "Pickup location deleted successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.PICKUP_LOCATION_DELETED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Pickup location deleted successfully"`, `ar` → `"تم حذف موقع الاستلام بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. No test covers this endpoint: 401/403 expectations are verified against the shared middleware paths exercised by named tests on sibling routes (cited per row); all other rows are code-verified or code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (identical rejection test-verified on sibling routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `delete-pickup-location` permission (constructor Spatie middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-pickup-location` (code-traced — no endpoint-specific 403 test exists) | See 403 example |
| 404 | No non-trashed location matches `{pickup_location}` (manual `findOrFail` miss) — OR any delete failure: the controller's `catch (Exception)` converts EVERYTHING to `MarvelException(NOT_FOUND)` → 404 translated (code-verified funnel) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — no endpoint-specific 404 test exists) | None | Unknown/trashed id; any delete failure (code-traced) | See 404 example |
| 405 | `POST` on `/api/v1/pickup-locations/{pickup_location}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `GET` to show) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on this action (all delete exceptions funnel to the 404 above — a REAL outage here still renders 404; code-verified consequence).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `delete-pickup-location` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{pickup_location}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/pickup-locations/2`):

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
