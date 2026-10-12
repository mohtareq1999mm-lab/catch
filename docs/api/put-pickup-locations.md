# Endpoint Name

Pickup Locations – Update (`PUT|PATCH /api/v1/pickup-locations/{pickup_location}`)

# Description

Partially updates a pickup location with single-default enforcement on writes.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`) |
| Full path | `/api/v1/pickup-locations/{pickup_location}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `pickup-locations/{pickup_location}` from `Route::apiResource('pickup-locations', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-pickup-location` gate decides) |
| Controller action | `Marvel\Http\Controllers\PickupLocationController@update` (manual `findOrFail` + `$pickupLocation->update($request->validated())` — the FULL validated payload, no `only()` whitelist; ANY exception funnels to `MarvelException(NOT_FOUND)` → 404 translated, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-pickup-location` via controller constructor `permission:update-pickup-location` middleware (applies only to `update`); the `UpdatePickupLocationRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `pickup-locations.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`UpdatePickupLocationRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional (`sometimes`/`nullable`) — an empty body passes validation and returns 200 with no changes (code-traced). Note the working-hours asymmetry vs store: update requires `day.ar`/`day.en` SCALAR strings per entry, while store requires `day` as an ARRAY of strings (code-verified rule difference — same payload shape fails one and passes the other).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `pickup_location` | path | integer id (no route-level constraint; resolved by manual `findOrFail` under the `SoftDeletes` scope) | Required | No | Raw segment; unknown/trashed ids throw `ModelNotFoundException`, caught by `catch (Exception)` → translated 404 (code-verified funnel) | Id of a non-trashed location | None | None | `2` | Identifies the location to update |
| `store_name` | body | string | Optional | No | `sometimes\|string\|max:255` | Any string ≤255 chars | Absent (unchanged) | None | `"Cairo Store 2"` | New display name |
| `address` | body | string | Optional | No | `sometimes\|string` (no max — code-verified absence) | Any string | Absent (unchanged) | None | `"456 Side St"` | New address |
| `phone` | body | string | Optional | No | `sometimes\|string\|max:50` | Any string ≤50 chars | Absent (unchanged) | None | `"01111111111"` | New phone |
| `email` / `latitude` / `longitude` | body | string | Optional | Yes (`nullable`) | Each `nullable` + `email`/`string` + `max` per store rules | Valid values or null | Absent (unchanged) | None | `"a@b.com"` | New contact/geo values |
| `working_hours` | body | array of objects | Optional | Yes (`nullable`) | `nullable\|array`, per-entry `day.ar` + `day.en` (`required_with:working_hours\|string` — SCALARS, unlike store's arrays), `open`/`close` (`required_with:working_hours\|string` — free text, no time-format check) | Entries with scalar bilingual day + open/close strings | Absent (unchanged) | None | `[{"day": {"ar": "الإثنين", "en": "Monday"}, "open": "09:00", "close": "22:00"}]` | Replacement schedule |
| `status` | body | integer 0/1 | Optional | No | `sometimes\|in:1,0` (strict — JSON booleans FAIL; code-traced) | `0`, `1` | Absent (unchanged) | None | `0` | New active flag |
| `display_order` | body | integer | Optional | No | `sometimes\|integer\|min:0` | Non-negative integers | Absent (unchanged) | None | `3` | New sort position |
| `is_default` | body | boolean | Optional | No | `sometimes\|boolean` (STRICT boolean rule — `0`/`1`/strings FAIL here, unlike the loose `in:` flags; code-verified) | JSON `true`/`false` only | Absent (unchanged) | Setting `true` DEMOTES every other default via the model hook (code-verified); setting `false` simply unmarks (code-traced — may leave ZERO defaults) | `true` | Default-location marker |

There are no query parameters or multipart fields. Any extra body fields are ignored (update runs on `$request->validated()` — validated-only; code-verified).

## Request examples

Valid request renaming a location:

```json
{
  "method": "PUT",
  "url": "/api/v1/pickup-locations/2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "store_name": "Cairo Store 2"
  }
}
```

# Responses

## Success: location updated — HTTP 200

Returned via `ApiResponse::apiResponse(PICKUP_LOCATION_UPDATED_SUCCESSFULLY, 200, true, PickupLocationResource::make($pickupLocation))`. No endpoint-specific success test exists (all expectations below are code-verified). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.PICKUP_LOCATION_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Pickup location updated successfully"`, `ar` → `"تم تحديث موقع الاستلام بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated row serialized by Marvel `PickupLocationResource` | Same 12 keys as store (no `updated_at` key) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Pickup location updated successfully",
  "success": true,
  "data": {
    "id": 2,
    "store_name": "Cairo Store 2",
    "address": "123 Main St",
    "phone": "01000000000",
    "email": null,
    "latitude": null,
    "longitude": null,
    "working_hours": null,
    "status": true,
    "display_order": 0,
    "is_default": true,
    "created_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. No test covers this endpoint: 401/403/422 expectations are verified against the shared middleware/validation paths exercised by named tests on sibling routes (cited per row); all other rows are code-verified or code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (identical rejection test-verified on sibling routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `update-pickup-location` permission (constructor Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-pickup-location` (code-traced — no endpoint-specific 403 test exists) | See 403 example |
| 404 | No non-trashed location matches `{pickup_location}` (manual `findOrFail` miss) — OR any update failure: the controller's `catch (Exception)` converts EVERYTHING to `MarvelException(NOT_FOUND)` → 404 translated (code-verified funnel) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified values; end-to-end rendering code-traced — no endpoint-specific 404 test exists) | None | Unknown/trashed id; any update failure (code-traced) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/pickup-locations/{pickup_location}` (no such routes are registered; note `GET` is the separate show endpoint) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`DELETE` with a segment (code-traced) | See 405 example |
| 422 | Any `UpdatePickupLocationRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys per the rule table above; exact messages **Unverified in source**) | Bad field values (note the `day.ar`/`day.en` scalar requirement vs store's array form) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on this action (all update exceptions funnel to the 404 above — a REAL outage here still renders 404; code-verified consequence).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-pickup-location` permission; `en` locale):

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

422 example (non-boolean `is_default`):

```json
{
  "message": "The is default field must be true or false.",
  "status": false,
  "errors": {
    "is_default": [
      "The is default field must be true or false."
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
