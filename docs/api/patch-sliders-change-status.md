# Endpoint Name

Sliders – Toggle Status (`PATCH /api/v1/sliders/change-status`)

# Description

Flips a slider's active flag by body id and returns the slider with products.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` |
| Full path | `/api/v1/sliders/change-status` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sliders/change-status` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, with route-level `permission:update-slider` ON TOP of the constructor `update-slider` gate (aligned — both require the same permission) |
| Controller action | `Marvel\Http\Controllers\SliderController@changeStatus` → `SliderRepository::changeStatus` (find-or-fail then boolean flip; a miss throws `HttpException(400, 'ERROR.NOT_FOUND')` — raw untranslated key — surfacing as 400, NOT 404; code-verified wart, see Errors) |
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

Inline `$request->validate()` runs before the repository call (code-verified order): an invalid body yields 422 even for a nonexistent id (test-verified: id `9999` → 422, NOT 404 — the `exists` rule fires first). A `ValidationException` renders through `Handler` as 422 (no try/catch wraps validation).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `id` | body | integer | Required | No | `required\|exists:sliders,id` (table-level check WITHOUT Eloquent scopes — the string-syntax `exists` rule queries the table directly, so a TRASHED id PASSES validation and then misses the scoped repository lookup, landing on the 400 raw-key path below; code-verified rule behavior) | Id of an existing non-trashed slider | None | None | `7` | Identifies the slider to flip |

There are no path parameters, query parameters, multipart fields, or file uploads. Any extra body fields are ignored: only `id` is read.

## Request examples

Valid request flipping an inactive slider (test-verified call):

```json
{
  "method": "PATCH",
  "url": "/api/v1/sliders/change-status",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "id": 7
  }
}
```

# Responses

## Success: status flipped — HTTP 200

Returned via `ApiResponse::apiResponse(SLIDER_STATUS_CHANGED, 200, true, SliderResource::make($slider))` after the flip with `products` loaded (a falsy repository return would 500 instead — unreachable when validation passes, since `exists` guarantees the row; code-verified dead branch). Verified by `test_authenticated_admin_can_toggle_slider_status` (`assertOk`, `success: true`, `data.status === true` after flipping a `false` slider; uses `patchJson`, matching this route). There is exactly one success contract. The operation is symmetric (code-traced).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SLIDER_STATUS_CHANGED` (locale from `lang` header) | `en` → `"Slider status changed successfully"`, `ar` → `"تم تغيير حالة السلايدر بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The flipped slider serialized by Marvel `SliderResource` | `id`, `title` (translations OBJECT here — this route is not named `sliders.index`), `slug`, `status` (bool — the NEW value), `order`, `image{desktop, mobile}` (with legacy-collection fallbacks), `products` (array — present since loaded) |

Complete success example (`en` locale):

```json
{
  "status": 200,
  "message": "Slider status changed successfully",
  "success": true,
  "data": {
    "id": 7,
    "title": { "ar": null, "en": "Summer Sale" },
    "slug": "summer-sale",
    "status": true,
    "order": 1,
    "image": { "desktop": "", "mobile": "" },
    "products": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/422 contracts are test-verified (all use `patchJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: validation 422s (Handler shape with `errors`), repository-miss 400 with a RAW KEY (below), and everything else.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Repository miss AFTER validation passes — concretely a TRASHED id (passes the scopeless `exists` rule, then misses the scoped `findOrFail`) or a row deleted in the TOCTOU window: `changeStatus` throws `HttpException(400, 'ERROR.NOT_FOUND')` — and the controller has NO try/catch here, so `Handler`'s `HttpException` branch renders status 400 with the RAW UNTRANSLATED key string (code-verified wart — the ONLY endpoint in this batch leaking a translation key to clients) | `{message: string, status: boolean}` | None | `"ERROR.NOT_FOUND"` (literal key, untranslated — verified against `Handler` L177-179 + `constants.php:22`) | None | Trashed id (deterministic trigger) or post-validation deletion race (code-traced) | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_change_slider_status` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-slider` permission (BOTH route and constructor gates require it; either rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-slider`; verified by `test_user_without_update_permission_gets_forbidden_for_change_status` expecting 403 | See 403 example |
| 405 | Any HTTP method other than `PATCH` on `/api/v1/sliders/change-status` (only `Route::patch` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `DELETE` to the same URI (code-traced) | See 405 example |
| 422 | Inline validation failure (missing/non-`exists` `id`): the `ValidationException` is NOT caught (no try wraps validation) and renders through `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `id` → message array (exact messages **Unverified in source**) | Missing `id` (verified by `test_change_status_returns_422_for_missing_id`) or unknown/trashed id (verified by `test_change_status_returns_422_for_nonexistent_id` for `9999` — proving validation precedes lookup) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Repository falsy return (unreachable when validation passes — `exists` guarantees the row; code-verified dead branch): `apiResponse(SOMETHING_WENT_WRONG, 500, false)` envelope | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Effectively unreachable (code-traced, no test) | See 500 example |

400 example (post-validation lookup miss — note the RAW key):

```json
{
  "message": "ERROR.NOT_FOUND",
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

403 example (authenticated without `update-slider` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/sliders/change-status`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (unknown `id`):

```json
{
  "message": "The selected id is invalid.",
  "status": false,
  "errors": {
    "id": [
      "The selected id is invalid."
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

500 example (unreachable falsy branch):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```
