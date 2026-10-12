# Endpoint Name

Brands – Reorder (`PUT /api/v1/brands/reorder`)

# Description

Persists a new global brand display order and invalidates the storefront cache.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/brands/reorder` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `brands/reorder` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, with route-level `permission:update-brand` ON TOP of the constructor `update-brand` gate (aligned — both require the same permission). Registered BEFORE `apiResource('brands')`, so it is not captured by `PUT brands/{brand}` (update) — the static segment wins by order (same verified pattern as the coupons-rules route) |
| Controller action | `Marvel\Http\Controllers\BrandController@reorder` → `BrandRepository::reorder` (`setNewOrder` + tag flush; the method has NO try/catch — failures bubble to `Handler`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `update-brand` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route (the pasted line carries no `->name()`) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`BrandsReorderRequest` validation runs before the method body (`failedValidation` rethrows → 422). Only `brands` is read; any extra body fields are ignored. NOTE: unlike the sections reorder rules, there is NO `distinct` rule here — duplicates pass validation and reach `setNewOrder` (duplicate behavior code-traced through the Sortable trait, untested).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `brands` | body | array of integer ids, in desired display order (first = order 1) | Required | No | `required\|array` | Non-empty array of brand ids | None | None | `[9, 5]` | Complete new global ordering |
| `brands.*` | body (each element) | integer id reference | Required for every element | No | `required\|integer\|exists:brands,id` (table-level check WITHOUT Eloquent scopes — a trashed id PASSES validation and reaches `setNewOrder`, whose scoped query then skips it; net effect untested, marked code-traced) | Ids of existing non-trashed brands | None | None | `9` | One brand placed at its index position |

There are no query parameters, path parameters, multipart fields, or file uploads. Omitted brands keep their existing `order` values (partial lists do NOT reset the rest — `setNewOrder` positions listed ids only; code-traced trait semantics, untested here).

## Request examples

Valid request swapping two brands (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/brands/reorder",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "brands": [9, 5]
  }
}
```

# Responses

## Success: order persisted — HTTP 200

Returned via `ApiResponse::apiResponse(BRANDS_REORDERED_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_reorder_brands` (Alpha order 1 + Beta order 2, reversed input → refreshed rows read Beta 1 / Alpha 2; uses `putJson`, matching this route). There is exactly one success contract. Observable effect (code-verified): the `order` column is rewritten per the array positions AND the frontend `BRANDS` cache tag is flushed.

Complete success example:

```json
{
  "status": 200,
  "message": "Brands reordered successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BRANDS_REORDERED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Brands reordered successfully"`, `ar` → `"تمت إعادة ترتيب العلامات التجارية بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/422 contracts are test-verified (both use `putJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no reorder-specific 401 test exists; identical rejection test-verified on sibling brand routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `update-brand` permission (BOTH route and constructor gates require it; either rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-brand` (no reorder-specific 403 test exists; identical gates test-verified on sibling brand routes) | See 403 example |
| 405 | Any HTTP method other than `PUT` on `/api/v1/brands/reorder` (only `Route::put` is registered — no `PATCH` twin, unlike `apiResource` updates) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 422 | Any `BrandsReorderRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `brands` / `brands.{index}` → message arrays (key verified from rule targets; exact messages **Unverified in source**) | Missing/non-array `brands`; non-integer element; unknown id (verified by `test_reorder_with_invalid_brand_id_returns_422` for `[99999]`) or trashed id (excluded from `exists` — code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any exception inside `reorder` (e.g. `setNewOrder` failure), rendered by the generic branch of `Handler` (NEITHER the method NOR the repository catches around `setNewOrder` — the repository try/catch wraps only its own transaction, and `setNewOrder` is called... wait: `$this->setNewOrder($brands)` runs INSIDE the repository's try (lines 117-120), so its exceptions roll back and rethrow as `HttpException(500, live-message)` → `Handler` `HttpException` branch renders 500 with THAT message, not the generic one — code-verified path) | `{message: string, status: boolean}` | None | The live wrapped message when rethrown (`$e->getMessage()`), else `"Internal Server Error"` when `app.debug` is off and no HttpException message applies (code-traced; no test) | None | Reorder persistence failure | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-brand` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/brands/reorder` — and note: such a call would otherwise hit NOTHING, since `POST brands/{brand}` does not exist either):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (unknown brand id):

```json
{
  "message": "The selected brands.0 is invalid.",
  "status": false,
  "errors": {
    "brands.0": [
      "The selected brands.0 is invalid."
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

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```
