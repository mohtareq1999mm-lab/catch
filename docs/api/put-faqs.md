# Endpoint Name

FAQs – Update FAQ (`PUT /api/v1/faqs/{faq}`)

# Description

Partially or fully updates one FAQ by id with uniqueness-checked translations and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action; tests use `putJson`) |
| Full path | `/api/v1/faqs/{faq}` |
| Path composition | `api/v1` prefix + `faqs/{faq}` from `Route::apiResource('faqs', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-faq` gate decides; the auth test even asserts the route gathers `permission:update-faq` middleware) |
| Controller action | `Marvel\Http\Controllers\FaqsController@update(UpdateFaqsRequest $request, $id)` → merges path `id` → `updateFaqs` helper → `findOrFail(request id)` FIRST (miss throws `ModelNotFoundException`, which ESCAPES the `MarvelException`-only catch → `Handler` 404 fixed string — code-verified), then `FaqsRepository::updateFaqs` (plain `update` from `dataArray`); repository failures throw a PLAIN `Exception` → also escapes → generic 500 (the translated catch-block 500 is dead for both paths; code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-faq` via controller constructor (applies to `update` and `reorder`); `UpdateFaqsRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `faqs.update` (apiResource default naming — existence asserted in-test via `getByName('faqs.update')`) |

Middleware runs before validation: 401/403 precede 404/422/500. Validation precedes the miss lookup, so 422 precedes 404 when both apply. `UniqueTranslationRule::ignore($this->route("faq"))` matches the `{faq}` placeholder, so keeping the FAQ's own title passes (code-verified).

## Request inputs

NOTE the create/update asymmetry (code-verified): update ADDS `UniqueTranslationRule` (duplicates rejected here but accepted on create) and relaxes both wrappers to `sometimes`.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `faq` | path | mixed id | Required | No | No route constraint; merged into the request as `id` for `findOrFail` (soft-deleted ids miss) | Id of a non-deleted FAQ | None | None | `1` | Identifies the FAQ |
| `faq_title` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "Updated Title"}` | Localized titles wrapper |
| `faq_title.*` | body | string | Optional | No | `sometimes\|string\|min:3\|max:1000` + `UniqueTranslationRule::for('faqs', 'faq_title')->ignore(route('faq'))` (vendor text **Unverified in source**) | String 3–1000 chars, unique except self | Unchanged | None | `"Updated Title"` | One locale's title |
| `faq_description` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "Updated description"}` | Localized descriptions wrapper |
| `faq_description.*` | body | string | Optional | No | `sometimes\|string\|min:3\|max:1000` + `UniqueTranslationRule::for('faqs', 'faq_description')->ignore(route('faq'))` | String 3–1000 chars, unique except self | Unchanged | None | `"Updated description"` | One locale's description |
| `status` | body | string/integer | Optional | No | `sometimes\|in:0,1` | `0`, `1` | Unchanged | None | `1` | Active flag |

Any other body fields are ignored (`only(dataArray)`).

## Request examples

Field-only update (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/faqs/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "faq_title": { "en": "Updated Title" }
  }
}
```

# Responses

## Success: FAQ updated — HTTP 200

Returned via `ApiResponse::apiResponse(FAQ_UPDATED_SUCCESSFULLY, 200, true, FaqResource::make($faq))`. Verified by `can_update_faq` (`assertOk`, `success: true`, DB row asserted) and `user_with_update_only_can_update` (`assertOk`), both `putJson`. Route is NOT `faqs.index`, so both fields are `{ar,en}` OBJECTS.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FAQ_UPDATED_SUCCESSFULLY` | `en` → `"FAQ updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `FaqResource` with bilingual objects | `id`, `faq_title{ar, en}`, `faq_description{ar, en}`, `status` (int), `order` (int) |

```json
{
  "status": 200,
  "message": "FAQ updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "faq_title": { "ar": null, "en": "Updated Title" },
    "faq_description": { "ar": null, "en": "Original description" },
    "status": 1,
    "order": 1
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `unauthenticated_user_cannot_update_faq` → 401) | See 401 example |
| 403 | Authenticated caller lacks `update-faq` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only user (verified by `user_with_view_only_cannot_update` → 403) | See 403 example |
| 404 | No FAQ with id (miss escapes as `ModelNotFoundException` → fixed string) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown id with valid body (code-traced; no update-miss test found — the show/delete miss tests pin the same shape family) | See 404 example |
| 422 | `UpdateFaqsRequest` fails (short/duplicate title or description) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; shared rule shape with create) | See 422 example |
| 500 | Repository failure AFTER the lookup (plain `Exception` escapes → generic 500) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or the live message when debug is on) | None | Post-lookup failure (code-traced) | See 500 example |
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

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The faq title.en field must be at least 3 characters.",
  "status": false,
  "errors": {
    "faq_title.en": ["The faq title.en field must be at least 3 characters."]
  }
}
```

500 example (debug off):

```json
{
  "message": "Internal Server Error",
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
