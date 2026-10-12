# Endpoint Name

FAQs – Reorder FAQs (`PUT /api/v1/faqs/reorder`)

# Description

Replaces the display order of FAQs from an ordered id list and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `POST`/`PATCH` on this path → 405; note the sibling `banner/reorder` is POST-only — code-verified method asymmetry) |
| Full path | `/api/v1/faqs/reorder` |
| Path composition | `api/v1` prefix + `faqs/reorder` custom `Route::put` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:update-faq`. Registered BEFORE `apiResource('faqs', ...)`, so `reorder` is matched here and NOT captured as `faqs/{faq}` (code-verified ordering safety — same pattern as `flash-sale/reorder`) |
| Controller action | `Marvel\Http\Controllers\FaqsController@reorder` → inline `$request->validate(['faqs' => 'required\|array', 'faqs.*' => 'required\|exists:faqs,id'])` (→ 422); then `FaqsRepository::reorder` → `setNewOrder` (SortableTrait `order` column); repository failures throw `HttpException(500, live-message)` which is NOT a `MarvelException`, so it ESCAPES the controller catch-all into `Handler`'s `HttpException` branch → 500 with the LIVE message (code-verified wart — the translated catch-block 500 is dead for repository failures, same class as the flash-sale store/update wart) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-faq` via route middleware AND constructor `only: [update, reorder]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Order matters: 401/403 → 422 (validation) → 200/500. Duplicate ids are NOT rejected (no `distinct` rule — code-verified laxity).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `faqs` | body | array of integers | Required | No | `required\|array` (empty array passes validation, then `setNewOrder([])` is a no-op success — code-verified edge) | Array of FAQ ids in desired display order | None | None | `[2, 1]` | The new order (first element = position 1) |
| `faqs.*` | body | integer | Required per element | No | `required\|exists:faqs,id` (scopeless) | Existing FAQ ids | None | None | `2` | One FAQ id in sequence |

No path or query parameters.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/faqs/reorder",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "faqs": [2, 1]
  }
}
```

# Responses

## Success: FAQs reordered — HTTP 200

Returned via `ApiResponse::apiResponse(FAQS_REORDERED_SUCCESSFULLY, 200, true)` with NO data key. Verified by `user_with_update_only_can_reorder` (`assertOk`, `putJson` with real ids, matching this route and method). (No cache flush in code — unlike CRUD actions, reorder does NOT call `flushTag`; the cached index may serve stale order until expiry, code-verified asymmetry shared with flash-sale reorder.)

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FAQS_REORDERED_SUCCESSFULLY` | `en` → `"FAQs reordered successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "FAQs reordered successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `unauthenticated_user_cannot_reorder_faqs` → 401, `putJson` with `[1, 2, 3]`) | See 401 example |
| 403 | Authenticated caller lacks `update-faq` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only user (verified by `user_with_view_only_cannot_reorder` → 403) | See 403 example |
| 405 | `POST`/`PATCH` on `/api/v1/faqs/reorder` (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Inline validation fails (missing `faqs` key, non-array, unknown id) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; same rule shape as the banner/flash-sale reorder family) | See 422 example |
| 500 | `setNewOrder` throws AFTER validation → `HttpException(500, live-message)` escapes the `MarvelException`-only catch → LIVE message (NOT the translated key; code-verified) | `{message: string, status: boolean}` | None | The live vendor message (leaks regardless of debug — `Handler` renders `HttpException` messages verbatim) | None | Post-validation failure | See 500 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example:

```json
{
  "message": "The faqs field is required.",
  "status": false,
  "errors": {
    "faqs": ["The faqs field is required."]
  }
}
```

500 example (post-validation failure — live vendor text, illustrative):

```json
{
  "message": "Live vendor message (exact text dynamic)",
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
