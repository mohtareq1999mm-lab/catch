# Endpoint Name

FAQs – Show FAQ (`GET /api/v1/faqs/{faq}`)

# Description

Returns one FAQ by id with bilingual title and description objects.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/faqs/{faq}` |
| Path composition | `api/v1` prefix + `faqs/{faq}` from `Route::apiResource('faqs', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-faqs` gate decides) |
| Controller action | `Marvel\Http\Controllers\FaqsController@show($id)` (untyped — no implicit binding) → `FaqsRepository::findOrFail($id)` → Eloquent miss throws `ModelNotFoundException`, which is NOT a `MarvelException`, so it ESCAPES the `catch (MarvelException)` into `Handler` → 404 FIXED string (code-verified — the in-controller translated 404 only fires for `MarvelException`-sourced failures) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-faqs` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `faqs.show` (apiResource default naming — and NOTE: the resource's locale-string branch checks `routeIs('faqs.index')`, FALSE here, so both fields are `{ar,en}` OBJECTS on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale (fields are bilingual here regardless) |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `faq` | path | mixed id | Required | No | No route-level constraint; passed to `findOrFail` (soft-deleted ids miss — `Faqs` uses `SoftDeletes`, code-verified) | Id of a non-deleted FAQ | None | None | `1` | Identifies the FAQ |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

Valid request showing an FAQ (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/faqs/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: FAQ returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, FaqResource::make($faq))`. Verified by `can_show_faq` (`assertOk`, `data.id`, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `FaqResource` with bilingual OBJECTS (code-verified show branch) | `id`, `faq_title{ar, en}`, `faq_description{ar, en}`, `status` (int), `order` (int) |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "faq_title": { "ar": null, "en": "Specific FAQ" },
    "faq_description": { "ar": null, "en": "Specific description" },
    "status": 1,
    "order": 1
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (verified by `unauthenticated_user_cannot_show_faq` → 401) | See 401 example |
| 403 | Authenticated caller lacks `view-faqs` (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | User with an unrelated permission (same constructor gate as index, test-verified on the index sibling) | See 403 example |
| 404 | No FAQ with id (miss escapes as `ModelNotFoundException` → fixed string, NOT the translated in-controller message) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown id (verified by `show_returns_404_for_nonexistent_faq` for `99999` — status-only assertion, consistent with this shape) | See 404 example |
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
  "message": "Resource Not Found",
  "status": false
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
