# Endpoint Name

Attributes – Create Attribute (`POST /api/v1/attributes`)

# Description

Creates an attribute with translated values and returns 201 with the bilingual object.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/attributes` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `attributes` from `Route::apiResource('attributes', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-attribute` gate decides) |
| Controller action | `Marvel\Http\Controllers\AttributeController@store` → `AttributeRepository::storeAttribute` (transaction: row + value rows; ANY failure rolls back and rethrows as `HttpException(400, live-message)`; the controller catch-all converts `MarvelException`→403 and lets everything else escape — see Errors for the resulting shape split) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-attribute` via controller constructor `permission:create-attribute` middleware (applies only to `store`); the `AttributeCreateRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `attributes.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`AttributeCreateRequest` validation runs before the method body (`failedValidation` rethrows → 422). Persisted fields are `name` + `slug` (via `dataArray`); `values[]` create `AttributeValue` rows. Any other body fields are ignored.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `name` | body | object (associative array of locale → string) | Required | No | `required\|array` | Any object whose locales each satisfy the rules below | None | None | `{"en": "Size", "ar": "حجم"}` | Localized names (test-verified round trip) |
| `name.en` | body | string | Required | No | `required\|string\|min:2\|max:50` + `UniqueTranslationRule::for('attributes')` (unique across attributes; exact vendor message text **Unverified in source** — sibling suites stub this rule to always pass) | String 2–50 chars, unique | None | None | `"Size"` | English name |
| `name.ar` | body | string | Required | No | Same rule set as `name.en` (BOTH locales mandatory on create — code-verified difference from update, where each is `sometimes`) | String 2–50 chars, unique | None | None | `"حجم"` | Arabic name |
| `values` | body | array of objects | Optional | No | `sometimes\|array`, per-element `values.*`: `sometimes\|array` (note: the ELEMENT rule is also `sometimes` — an empty object element passes element validation; code-verified laxity) | Array of value objects | Absent (no values) | None | `[{"value": {"en": "Small"}}]` | Value rows to create |
| `values.*.value` | body (each element) | object (associative array of locale → string) | Conditionally required | No | `required` (UNCONDITIONAL — fires whenever the element is present, even though the element itself is `sometimes`; code-verified strictness inside laxity) | Non-empty object | None | None | `{"en": "Small"}` | One value's translations |
| `values.*.value.en` | body | string | Required (whenever the element is present) | No | `required\|string\|min:2\|max:50` | String 2–50 chars | None | None | `"Small"` | English value |
| `values.*.value.ar` | body | string | Required (whenever the element is present) | No | Same rule set as `.en` | String 2–50 chars | None | None | `"صغير"` | Arabic value |

There are no path parameters or query parameters. Slug derivation: the request carries NO slug rule and the repository passes `$request->only(dataArray)` — slug comes from... `dataArray = ['name', 'slug']`, so a client-supplied `slug` passes through unvalidated (!), otherwise the MODEL must default it (model `booted`/Sluggable mechanics **Unverified in source** — the test creates via HTTP without slug and asserts 201, not the slug value).

## Request examples

Valid request creating an attribute (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/attributes",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Size", "ar": "حجم" }
  }
}
```

# Responses

## Success: attribute created — HTTP 201

Returned via `ApiResponse::apiResponse(ATTRIBUTE_CREATED_SUCCESSFULLY, 201, true, AttributeResource::make($attribute))` with `values` loaded. Verified by `test_authenticated_admin_can_create_attribute` (`assertCreated`, DB row `slug: 'size'`; `postJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.ATTRIBUTE_CREATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Attribute created successfully"`, `ar` → `"تم إنشاء السمة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The created attribute serialized by Marvel `AttributeResource` (this route is not named `attributes.index`, so `name` is the `{ar,en}` OBJECT) | `id`, `name{ar, en}`, `slug`, `values` (array — present since loaded; item keys per `AttributeValueResource` — exact shape **Unverified in source**, no item-level test found) |

Complete success example (`en` locale; `id` illustrative, all other values verified):

```json
{
  "status": 201,
  "message": "Attribute created successfully",
  "success": true,
  "data": {
    "id": 4,
    "name": { "ar": "حجم", "en": "Size" },
    "slug": "size",
    "values": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 201/401/403 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: validation 422s (Handler shape with `errors`), repository `HttpException(400, live-text)` failures (Handler 400 shape), and `MarvelException`s converted to 403 (below).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Repository failure wrapped as `HttpException(400, live-message)` (unique collisions, value-row faults, transaction faults): escapes the controller's `MarvelException`-only catch → `Handler` `HttpException` branch renders 400 with the LIVE text (code-verified) | `{message: string, status: boolean}` | None | Live exception/operation text (dynamic, **Unverified in source** per case) | None | Post-validation persistence failure (code-traced, no test) | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_create_attribute` expecting 401 | See 401 example |
| 403 | A `MarvelException` inside `storeAttribute` (converted by the controller catch-all to `AuthorizationException(NOT_AUTHORIZED)`), rendered by the `AuthorizationException` branch of `Handler`, which ignores the exception message | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (fixed hardcoded string WITHOUT trailing period, per `Handler`) | None | Repository `MarvelException` (code-traced, no test) | See 403 example (in-method) |
| 403 | Authenticated caller lacks the `create-attribute` permission (constructor Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `create-attribute`; verified by `test_user_without_required_permission_gets_forbidden_for_create` expecting 403 | See 403 example (gate) |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/attributes` path (no such routes are registered; note `GET /attributes` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `AttributeCreateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `name`, `name.{locale}`, `values*`; exact messages **Unverified in source** — no store-specific 422 test exists) | Missing/non-array `name`, short/non-string/duplicate locale value, malformed `values` entries | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

400 example (repository failure; exact text dynamic):

```json
{
  "message": "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'size' for key 'attributes.slug'",
  "status": false
}
```

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (in-method conversion path; default locale):

```json
{
  "message": "This action is unauthorized",
  "status": false
}
```

403 example (constructor gate — authenticated without `create-attribute`; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `PUT /api/v1/attributes`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `name`):

```json
{
  "message": "The name field is required.",
  "status": false,
  "errors": {
    "name": [
      "The name field is required."
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
