# Endpoint Name

Attributes – Show Attribute (`GET /api/v1/attributes/{attribute}`)

# Description

Returns one attribute by id or slug with values and bilingual names.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/attributes/{attribute}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `attributes/{attribute}` from `Route::apiResource('attributes', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-attributes` gate decides) |
| Controller action | `Marvel\Http\Controllers\AttributeController@show` (numeric segment → id lookup, else slug lookup, both with `values` eager-loaded; the `catch (MarvelException)` converts ONLY `MarvelException`s — the `firstOrFail` miss throws `ModelNotFoundException`, which ESCAPES to `Handler`'s `ModelNotFoundException` branch → 404 FIXED string; code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-attributes` via controller constructor `permission:view-attributes` middleware (applies to `index` and `show`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `attributes.show` (apiResource default naming — but NOTE: the resource's bilingual branch checks `routeIs('attributes.index')`, which is FALSE here, so `name` is the `{ar,en}` OBJECT on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale (`name` is bilingual here regardless) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `attribute` | path | integer id OR slug string (no route-level constraint; `is_numeric` dispatch) | Required | No | Numeric → `where('id', (int))`; otherwise → `where('slug', ...)`; both with `values` eager-loaded and NO SoftDeletes scope (model has none — nothing is ever trashed). Misses throw `ModelNotFoundException`, which ESCAPES the `MarvelException`-only catch → `Handler` 404 fixed string (code-verified) | Id or slug of an existing attribute | None | None | `4` or `size` | Identifies the attribute to return |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

Valid request showing an attribute by id (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/attributes/4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: attribute returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, AttributeResource::make($attribute))` with `values` loaded. Verified by `test_authenticated_user_can_show_attribute_by_id` (`assertOk`, `data.id` path; uses `getJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The attribute serialized by Marvel `AttributeResource` — with the route NOT named `attributes.index`, `name` is the `{ar,en}` OBJECT (code-verified) | `id`, `name{ar, en}`, `slug`, `values` (array — present since loaded; item keys per `AttributeValueResource` — exact shape **Unverified in source**, no item-level test found) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 4,
    "name": { "ar": "الحجم", "en": "Size" },
    "slug": "size",
    "values": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/404 contracts are test-verified (both use `getJson`); the 403 row is verified on the create sibling through the identical constructor-gate family (no show-specific 403 test found in this pass); guest behavior is CONTRADICTED between suites — see the 401 row.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` group middleware runs before the route pipe): the verified middleware chain yields 401. CONTRADICTION FLAG: `AttributeApiTest::test_guest_gets_403_for_attribute_show` asserts **403** for this exact call, while `AttributesProductionHardenTest` hedges `[401, 403]` on show but asserts strict 401 for guest create/update/delete through the IDENTICAL stack. Per the verified chain plus the hardened strict assertions, 401 is the code-verified outcome and the 403 guest assertion looks STALE — flagged, not resolved (tests NOT RUN here) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `view-attributes` permission (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `view-attributes` (no show-specific 403 test found in this pass; identical constructor-gate path test-verified on sibling attribute routes) | See 403 example |
| 404 | No attribute matches `{attribute}` (by id or slug): the manual `firstOrFail` throws `ModelNotFoundException`, which ESCAPES the `MarvelException`-only catch → `Handler` renders its `ModelNotFoundException` branch (code-verified — fixed string, NOT the translated notice) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id (verified by `test_authenticated_user_gets_404_for_nonexistent_attribute_id` for `9999`) | See 404 example |
| 405 | `POST` on `/api/v1/attributes/{attribute}` (no such route is registered; note `PUT`/`PATCH` route to the sibling update endpoint, `DELETE` to destroy) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST` with a segment (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 500 contract on the lookup path as such (misses escape as 404 fixed; anything else would need a catch-all that doesn't exist — serialization failures bubble to generic 500, code-traced edge).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-attributes` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (unknown `{attribute}`):

```json
{
  "message": "Resource Not Found",
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

429 example (rate limit exceeded):

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
