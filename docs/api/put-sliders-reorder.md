# Endpoint Name

Sliders – Reorder (`PUT /api/v1/sliders/reorder`)

# Description

Persists a new global slider display order and invalidates the storefront cache.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/sliders/reorder` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sliders/reorder` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, with route-level `permission:update-slider` ON TOP of the constructor `update-slider` gate (aligned — both require the same permission). Registered BEFORE `apiResource('sliders')`, so it is not captured by `PUT sliders/{slider}` (update) — the static segment wins by order (same verified pattern as the coupons-rules route) |
| Controller action | `Marvel\Http\Controllers\SliderController@reorder` → `SliderRepository::reorder` (`setNewOrder` converting failures to `HttpException(500, live-message)`; the controller catch-all returns the 500 translated envelope, discarding the live text) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `update-slider` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route (the pasted line carries no `->name()`) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching validation.

## Request inputs

Inline `$request->validate()` runs before the try block (code-verified order): an invalid body yields 422 and never touches ordering. A `ValidationException` is NOT caught (the try starts after validation) and renders through `Handler` as 422. NOTE the weaker element rules here vs siblings: `required|exists:sliders,id` with NO `integer` and NO `distinct` (code-verified difference from sections/brands reorder).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `sliders` | body | array of ids, in desired display order (first = order 1) | Required | No | `required\|array` | Non-empty array of slider ids | None | None | `[9, 5]` | Complete new global ordering |
| `sliders.*` | body (each element) | id reference | Required for every element | No | `required\|exists:sliders,id` (table-level check WITHOUT Eloquent scopes — a trashed id PASSES validation and reaches `setNewOrder`, whose scoped query then skips it; net effect untested, marked code-traced. Duplicates also pass — no `distinct` rule) | Ids of existing sliders | None | None | `9` | One slider placed at its index position |

There are no query parameters, path parameters, multipart fields, or file uploads. Omitted sliders keep their existing `order` values (partial lists do NOT reset the rest — `setNewOrder` positions listed ids only; code-traced trait semantics, untested here).

## Request examples

Valid request swapping two sliders (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/sliders/reorder",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "sliders": [9, 5]
  }
}
```

# Responses

## Success: order persisted — HTTP 200

Returned via `ApiResponse::apiResponse(SLIDERS_REORDERED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_authenticated_admin_can_reorder_sliders` (`assertOk`, `success: true`; uses `putJson`, matching this route — the suite has no DB-order assertion for sliders, unlike brands). There is exactly one success contract. Observable effect (code-verified): the `order` column is rewritten per the array positions AND the frontend `SLIDERS` cache tag is flushed.

Complete success example:

```json
{
  "status": 200,
  "message": "Sliders reordered successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SLIDERS_REORDERED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Sliders reordered successfully"`, `ar` → `"تمت إعادة ترتيب السلايدرات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_reorder_sliders` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-slider` permission (BOTH route and constructor gates require it; either rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-slider`; verified by `test_user_without_update_permission_gets_forbidden_for_reorder` expecting 403 | See 403 example |
| 405 | Any HTTP method other than `PUT` on `/api/v1/sliders/reorder` (only `Route::put` is registered — no `PATCH` twin, unlike `apiResource` updates) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 422 | Inline validation failure (missing/non-array `sliders`, or any element failing `required|exists:sliders,id`), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `sliders` / `sliders.{index}` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no reorder-specific 422 test exists) | Malformed envelope or unknown element id | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any exception inside `reorder` (e.g. `setNewOrder` failure, already wrapped by the repository as `HttpException(500, live-message)`), caught by the method's own `catch (\Exception $e)` which returns `apiResponse(SOMETHING_WENT_WRONG, 500)` — the live text is DISCARDED in this second wrap (code-verified double-wrap) | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Reorder persistence failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-slider` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/sliders/reorder`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (unknown slider id):

```json
{
  "message": "The selected sliders.0 is invalid.",
  "status": false,
  "errors": {
    "sliders.0": [
      "The selected sliders.0 is invalid."
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

500 example (reorder persistence failure):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```
