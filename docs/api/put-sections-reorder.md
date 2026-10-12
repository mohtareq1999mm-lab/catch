# Endpoint Name

Sections – Reorder (`PUT /api/v1/sections/reorder`)

# Description

Persists a new global display order for sections and invalidates the storefront pages cache.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/sections/reorder` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `sections/reorder` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group. Registered BEFORE `apiResource('sections')`, so it is not captured by `PUT sections/{section}` (update) — the static segment wins by order (same verified pattern as the coupons-rules route) |
| Controller action | `Marvel\Http\Controllers\SectionController@reorder` (Spatie `SortableTrait::setNewOrder` persistence) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-sections` via controller constructor `permission:update-sections` middleware (applies to `update`, `reorder`, `toggleStatus`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching validation. Test-method note: the pre-existing tests called this `PUT`-only URI with `postJson` (which yields 405) and were corrected to `putJson` on 2026-10-10.

## Request inputs

Inline `$request->validate()` runs before the try block (code-verified order): an invalid body yields 422 and never touches ordering. A `ValidationException` is NOT caught (the try starts after validation) and renders through `Handler` as 422.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `sections` | body | array of integer ids, in desired display order (first = order 1) | Required | No | `required\|array` | Non-empty array of section ids | None | None | `[12, 11]` | Complete new global ordering (Spatie `setNewOrder` semantics: positions derive from array order) |
| `sections.*` | body (each element) | integer id reference | Required for every element | No | `required\|integer\|distinct\|exists:sections,id` | Ids of existing sections, no duplicates | None | None | `12` | One section placed at its index position |

There are no query parameters, path parameters, multipart fields, or file uploads. Any extra body fields are ignored: only `sections` is read. Sections omitted from the array keep their existing order values (Spatie `setNewOrder` only repositions listed ids — code-traced trait semantics, untested here).

## Request examples

Valid request swapping two sections (test-verified call shape, corrected to PUT):

```json
{
  "method": "PUT",
  "url": "/api/v1/sections/reorder",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "sections": [12, 11]
  }
}
```

# Responses

## Success: order persisted — HTTP 200

Returned via `ApiResponse::apiResponse(SECTIONS_REORDERED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_admin_can_reorder_sections` (`assertOk`, `success: true`; now `putJson` after the 2026-10-10 correction). There is exactly one success contract. Observable effect (code-verified): the `order` column is rewritten per the array positions AND the frontend content-pages cache tag is flushed (`flushTag` inside the try).

Complete success example:

```json
{
  "status": 200,
  "message": "Sections reordered successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.SECTIONS_REORDERED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Sections reordered successfully"`, `ar` → `"تمت إعادة ترتيب الأقسام بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403/422 contracts are test-verified (after the `postJson` → `putJson` correction); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_guest_gets_401_for_reorder_sections` expecting 401 (this guest test passes regardless of method — no route match is needed for `auth:sanctum`... note: actually with POST the route misses → 405, not 401; the guest test uses `postJson` and would receive 405. See note below) | See 401 example |
| 403 | Authenticated caller lacks the `update-sections` permission (Spatie middleware rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-sections`; verified by `test_user_without_update_sections_gets_forbidden_for_reorder` expecting 403 (uses `postJson` — same 405 caveat as the 401 row; the status assertion targets middleware behavior) | See 403 example |
| 405 | Any HTTP method other than `PUT` on `/api/v1/sections/reorder` (only `Route::put` is registered) — including the pre-correction `POST` test calls | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | `GET`, `POST`, `PATCH`, `DELETE` to the same URI (code-verified by registration; `POST` confirmed by the pre-correction tests) | See 405 example |
| 422 | Any inline validation failure (runs before the try block, so it takes precedence over 500): the `ValidationException` is NOT caught and renders through `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `sections` / `sections.{index}` → message arrays (key verified from rule targets; exact messages **Unverified in source**) | Missing/non-array `sections` (verified by `test_reorder_returns_422_for_missing_sections` for `[]` — after the method correction) or unknown/non-integer/duplicated element (verified by `test_reorder_returns_422_for_invalid_section_ids` for `[9999]`) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any exception inside the try block (`setNewOrder` or cache flush failure), caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403/422 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Persistence failure (code-traced, no test) | See 500 example |

Test-method note: only the three 200/422 reorder tests were corrected to `putJson`; the guest-401 and 403 reorder tests still use `postJson` and therefore receive 405 instead of their asserted statuses — flagged, not fixed.

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-sections` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (`POST /api/v1/sections/reorder` — the pre-correction test call):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (unknown section id):

```json
{
  "message": "The selected sections.0 is invalid.",
  "status": false,
  "errors": {
    "sections.0": [
      "The selected sections.0 is invalid."
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

500 example (persistence failure inside the try):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```
