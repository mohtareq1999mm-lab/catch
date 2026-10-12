# Endpoint Name

Attributes – Update Attribute (`PUT|PATCH /api/v1/attributes/{attribute}`)

# Description

Partially updates an attribute with value sync and returns the bilingual object.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` or `PATCH` (both registered by `apiResource` `update`; tests use `putJson`) |
| Full path | `/api/v1/attributes/{attribute}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `attributes/{attribute}` from `Route::apiResource('attributes', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-attribute` gate decides) |
| Controller action | `Marvel\Http\Controllers\AttributeController@update` (merges the path id into the request for the unique-ignore, then private `updateAttribute` → `findOrFail` + `updateAttribute` in a transaction; the private helper converts ALL failures to `HttpException(404, NOT_FOUND)` with the RAW key, which escapes the outer `MarvelException`-only catch → `Handler` renders 404 with the literal `'ERROR.NOT_FOUND'` string — code-verified wart, same class as the slider changeStatus 400) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-attribute` via controller constructor `permission:update-attribute` middleware (applies only to `update` — note: NOT `exportAttributes`/`importAttributes`, which are unrouted here); the `AttributeUpdateRequest::authorize` returns `true` and performs no further check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `attributes.update` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`AttributeUpdateRequest` validation runs before the method body (`failedValidation` rethrows → 422). All fields are optional (`sometimes`) — an empty body passes validation and returns 200 with no changes (code-traced). The unique-ignore resolves `$this->route('id') ?? $this->route('category') ?? $this->input('id')`... wait — that was the CATEGORY request. HERE the ignore is `->ignore($id)` with `$id = $this->route('attribute')` — the RAW `{attribute}` segment (apiResource param, string id) — plus the controller merges path id into input. Correct self-exclusion by two paths (code-verified, unlike the users-update bug).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `attribute` | path | integer id (no route-level constraint; resolved by manual `findOrFail` — NO SoftDeletes scope exists on the model, so nothing is ever trashed) | Required | No | Raw segment (also merged into input as `id`); unknown ids throw inside the private helper → converted to the 404 RAW-KEY path below (code-verified) | Id of an existing attribute | None | None | `4` | Identifies the attribute to update |
| `name` | body | object (associative array of locale → string) | Optional | No | `sometimes\|array` | Any object whose locales each satisfy the rules below | Absent (unchanged) | None | `{"en": "Dimension"}` | Replacement names |
| `name.en` / `name.ar` | body (each locale) | string | Optional per locale | No | Each `sometimes\|string\|min:2\|max:50` + `UniqueTranslationRule::for('attributes')->ignore($id)` (self excluded; exact vendor message text **Unverified in source** — sibling suites stub this rule to always pass) | Strings 2–50 chars, unique across other attributes | Absent (unchanged) | None | `"Dimension"` | One locale's new name |
| `values` | body | array of objects | Optional | No | `sometimes\|array`, per-element `values.*`: `sometimes\|array` (note: the ELEMENT rule is also `sometimes` — an empty object element passes element validation; code-verified laxity, same as store) | Array of value objects | Absent (values untouched — the sync block runs ONLY `if (isset($request['values']) && count(...))`; passing `[]` SKIPS the sync entirely, so values can never be cleared to empty through this endpoint — code-verified) | None | `[{"value": {"en": "Small"}}]` | Complete replacement value set (by slug: existing slugs kept, missing slugs created, absent slugs DELETED) |
| `values.*.value` | body (each element) | object (associative array of locale → string) | Conditionally required | No | `required_with:values.*` + `array` | Non-empty object | None | None | `{"en": "Small"}` | One value's translations |
| `values.*.value.en` / `values.*.value.ar` | body | string | Conditionally required | No | `required_with:values.*.value` + `string` + `min:2` + `max:50` | Strings 2–50 chars | None | None | `"Small"` | One value locale |

Value sync mechanics (code-verified): incoming slugs derived from `value.en` (fallback: first locale value) via `Str::slug`; rows keyed by slug — new slugs INSERTED, missing slugs DELETED, matched slugs left UNTOUCHED (no update of changed translations on existing values — code-verified). There are no query parameters or multipart fields. Any extra body fields are ignored.

## Request examples

Valid request renaming an attribute (shape per sibling update tests):

```json
{
  "method": "PUT",
  "url": "/api/v1/attributes/4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Dimension" }
  }
}
```

# Responses

## Success: attribute updated — HTTP 200

Returned via `ApiResponse::apiResponse(ATTRIBUTE_UPDATED_SUCCESSFULLY, 200, true, AttributeResource::make($attributeUpdates))` with `values` loaded (re-read post-commit). No endpoint-specific success test exists in this pass (the suite covers 401/403/404 for update; all expectations below are code-verified). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ATTRIBUTE_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Attribute updated successfully"`, `ar` → `"تم تحديث السمة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated attribute serialized by Marvel `AttributeResource` (this route is not named `attributes.index`, so `name` is the `{ar,en}` OBJECT) | `id`, `name{ar, en}`, `slug` (UNCHANGED — no recomputation here; code-verified difference from brands), `values` (array — present since loaded; item keys **Unverified in source**) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Attribute updated successfully",
  "success": true,
  "data": {
    "id": 4,
    "name": { "ar": "الحجم", "en": "Dimension" },
    "slug": "size",
    "values": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 401/403/404 contracts are test-verified (all use `putJson`); all other rows are code-verified or code-traced as marked. Note the three failure shapes: validation 422s (Handler shape with `errors`), private-helper failures as 404 RAW-KEY (below), and everything else.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_update_attribute` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `update-attribute` permission (constructor Spatie middleware rejects before validation runs, so even an empty body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-attribute` (no update-specific 403 test found in this pass; identical constructor-gate path test-verified on the sibling store route) | See 403 example |
| 404 | No attribute matches `{attribute}` (manual `findOrFail` miss) — OR ANY failure inside the private helper (unique collision, value-row fault, transaction fault): the helper converts EVERYTHING to `HttpException(404, 'ERROR.NOT_FOUND')` with the RAW KEY, which escapes the outer `MarvelException`-only catch → `Handler` `HttpException` branch renders 404 with the literal key string (code-verified wart — same class as the slider changeStatus 400) | `{message: string, status: boolean}` | None | `"ERROR.NOT_FOUND"` (literal key, untranslated — verified against `Handler` L177-179) | None | Unknown id; any update failure (code-traced — no update-specific 404 test found in this pass) | See 404 example |
| 405 | `POST` or `DELETE` on `/api/v1/attributes/{attribute}` (no such routes are registered; note `GET` is the separate show endpoint) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`DELETE` with a segment (code-traced) | See 405 example |
| 422 | Any `AttributeUpdateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of field → message arrays (keys: `name`, `name.{locale}`, `values*`; exact messages **Unverified in source** — no update-specific 422 test exists) | Bad field values, duplicate locale name on ANOTHER attribute (self excluded) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on this action in practice (helper failures convert to the 404 raw-key path above — a REAL outage here still renders 404; code-verified consequence).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-attribute` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{attribute}` — note the RAW key):

```json
{
  "message": "ERROR.NOT_FOUND",
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

422 example (non-array `name`):

```json
{
  "message": "The name must be an array.",
  "status": false,
  "errors": {
    "name": [
      "The name must be an array."
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
