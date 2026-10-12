# Endpoint Name

Pickup Locations – Create (`POST /api/v1/pickup-locations`)

# Description

Creates a pickup location with single-default enforcement and returns it.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/pickup-locations` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `pickup-locations` from `Route::apiResource('pickup-locations', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-pickup-location` gate decides) |
| Controller action | `Marvel\Http\Controllers\PickupLocationController@store` (`repository->create($request->validated())` — the FULL validated payload, no `only()` whitelist; ANY failure → `MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` → 500 translated, code-verified funnel) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-pickup-location` via controller constructor `permission:create-pickup-location` middleware (applies only to `store`); the `StorePickupLocationRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `pickup-locations.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`StorePickupLocationRequest` validation runs before the method body (`failedValidation` rethrows → 422). Note the working-hours asymmetry: create requires `working_hours.*.day` as an ARRAY of strings, while update requires `day.ar`/`day.en` scalar strings (code-verified rule difference — same payload shape fails one and passes the other).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `store_name` | body | string | Required | No | `required\|string\|max:255` | Any string ≤255 chars | None | None | `"Cairo Store"` | Display name (plain column, untranslated) |
| `address` | body | string | Required | No | `required\|string` (no max — code-verified absence) | Any string | None | None | `"123 Main St"` | Street address |
| `phone` | body | string | Required | No | `required\|string\|max:50` | Any string ≤50 chars | None | None | `"01000000000"` | Contact phone |
| `email` | body | string | Optional | Yes (`nullable`) | `nullable\|email\|max:255` | Valid email or null | Absent | None | `"store@example.com"` | Contact email |
| `latitude` / `longitude` | body | string | Optional | Yes (`nullable`) | Each `nullable\|string\|max:50` (strings, NOT numeric — code-verified) | Any string ≤50 chars or null | Absent | None | `"30.0444"` | Coordinates as text |
| `working_hours` | body | array of objects | Optional | Yes (`nullable`) | `nullable\|array`, with per-entry `working_hours.*.day` (`required_with:working_hours\|array`), `day.*` (`required_with:working_hours\|string`), `open`/`close` (`required_with:working_hours\|string` — free text, no time-format check; code-verified) | Array of `{day: string[], open, close}` | Absent | `required_with` triggers ONLY when the `working_hours` key is present (sending the key with `[]`... an empty array present STILL triggers `required_with` on zero elements? — with no elements the nested rules never evaluate, so `[]` passes; code-traced) | `[{"day": ["Mon"], "open": "09:00", "close": "22:00"}]` | Weekly schedule (array-cast JSON) |
| `status` | body | integer 0/1 | Optional | No | `sometimes\|in:1,0` (strict — JSON booleans FAIL; code-traced) | `0`, `1` | DB default (code-traced column default) | None | `1` | Active flag |
| `display_order` | body | integer | Optional | No | `sometimes\|integer\|min:0` | Non-negative integers | DB default (code-traced) | None | `0` | Manual sort position |
| `is_default` | body | boolean | Optional | No | `sometimes\|boolean` (STRICT boolean rule — `0`/`1`/strings FAIL here, unlike the loose `in:` flags; code-verified) | JSON `true`/`false` only | Absent | Setting `true` DEMOTES every other default (model `saving` hook clears all other `is_default` rows including trashed — code-verified) | `true` | Default-location marker (single) |

There are no path parameters or query parameters. Any extra body fields are ignored (the repository creates from `$request->validated()` — validated-only, so unknown keys never persist; code-verified).

## Request examples

Valid request creating a location:

```json
{
  "method": "POST",
  "url": "/api/v1/pickup-locations",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "store_name": "Cairo Store",
    "address": "123 Main St",
    "phone": "01000000000",
    "is_default": true
  }
}
```

# Responses

## Success: location created — HTTP 200

Returned via `ApiResponse::apiResponse(PICKUP_LOCATION_CREATED_SUCCESSFULLY, 200, true, PickupLocationResource::make($pickupLocation))` — note 200, NOT 201 (code-verified). No endpoint-specific success test exists (all expectations below are code-verified). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (not 201) |
| `message` | string | Always present | Translated `MESSAGE.PICKUP_LOCATION_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Pickup location created successfully"`, `ar` → `"تم إنشاء موقع الاستلام بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created row serialized by Marvel `PickupLocationResource` | `id`, `store_name`, `address`, `phone`, `email`, `latitude`, `longitude`, `working_hours`, `status` (bool), `display_order`, `is_default` (bool), `created_at` (note: NO `updated_at` key — code-verified) |

Complete success example (`en` locale; `id`/timestamps illustrative, all other values verified):

```json
{
  "status": 200,
  "message": "Pickup location created successfully",
  "success": true,
  "data": {
    "id": 2,
    "store_name": "Cairo Store",
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
| 403 | Authenticated caller lacks the `create-pickup-location` permission (constructor Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-pickup-location` (code-traced — no endpoint-specific 403 test exists) | See 403 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/pickup-locations` path (no such routes are registered; note `GET /pickup-locations` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `StorePickupLocationRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `store_name`, `address`, `phone`, `email`, `latitude`, `longitude`, `working_hours*`, `status`, `display_order`, `is_default`; exact messages **Unverified in source**) | Missing/invalid required fields, bad emails, non-0/1 `status`, negative `display_order`, non-boolean `is_default`, malformed hours entries | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | ANY repository failure (unique collisions, DB faults): the controller's `catch (Exception)` converts EVERYTHING to `MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` → 500 translated (code-verified funnel — even `QueryException`s land here, never 409) | `{message: string, status: boolean}` | None | Translated `ERROR.COULD_NOT_CREATE_THE_RESOURCE`: `en` → `"Could not create the resource"`, `ar` → `"تعذر إنشاء المورد"` | None | Post-validation persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `create-pickup-location` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/pickup-locations`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `store_name`):

```json
{
  "message": "The store name field is required.",
  "status": false,
  "errors": {
    "store_name": [
      "The store name field is required."
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

500 example (post-validation persistence failure; `en` locale):

```json
{
  "message": "Could not create the resource",
  "status": false
}
```
