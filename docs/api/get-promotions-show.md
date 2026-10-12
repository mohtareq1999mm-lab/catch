# Endpoint Name

Promotions – Show Promotion (`GET /api/v1/promotions/{promotion}`)

# Description

Returns one promotion by id with unloaded relations and raw stored name.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/promotions/{promotion}` |
| Path composition | `api/v1` prefix + `promotions/{promotion}` from bare `Route::apiResource('promotions', ...)` (no route middleware; the in-controller `view-promotion` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\PromotionController@show(Request $request, $id)` (untyped — no implicit binding) → `PromotionRepository::findOrFail($id)` (Prettus → Eloquent miss throws `ModelNotFoundException`, a `Throwable` but NOT a `MarvelException`); the `catch (Throwable)` converts EVERYTHING into `apiResponse(NOT_FOUND, 404, false)` (translated `ERROR.NOT_FOUND` — the broadest catch in this controller, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-promotion` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `promotions.show` (apiResource default naming — and NOTE: the resource's locale-string branch checks `routeIs('promotions.index')`, FALSE here, so `name` is the RAW stored value, not even the `{ar,en}` object) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `promotion` | path | mixed id | Required | No | No route-level constraint; passed to `findOrFail` (slug lookup NOT supported here, unlike flash-sale/tag show — code-verified asymmetry) | Id of an existing promotion | None | None | `1` | Identifies the promotion |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

Valid request showing a promotion (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/promotions/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: promotion returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PromotionResource::make($promotion))` WITHOUT pre-loaded relations (`products`/`gift_products` omitted via `whenLoaded`). Verified by `test_admin_can_show_promotion` (`assertOk`, `data.id`, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `PromotionResource` with RAW `name` (`getRawOriginal` — code-verified third shape: neither locale string nor `{ar,en}` object) | Same key set as create, minus loaded relations |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "{\"en\": \"Promo A\"}",
    "slug": "promo-a",
    "type": "Price",
    "discount_type": "fixed_rate",
    "value": 10,
    "discount": 10,
    "code": "A-ABC123",
    "minimum_order_amount": 0.0,
    "required_quantity": null,
    "apply_to": "all_products",
    "image": { "desktop": null, "mobile": null },
    "start_at": null,
    "end_at": null,
    "status": true,
    "is_valid": true,
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (code-traced; no promotion guest-show test found) | See 401 example |
| 403 | Authenticated caller lacks `view-promotion` (constructor gate rejects; no route pipe exists) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without `view-promotion` (code-traced) | See 403 example |
| 404 | Unknown `{promotion}` (OR any other `Throwable` — the catch is total; code-verified) | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"` | None | Unknown id (code-traced; no show-miss test found) | See 404 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example:

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example:

```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
