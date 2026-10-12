# Endpoint Name

FAQs – Create FAQ (`POST /api/v1/faqs`)

# Description

Creates one FAQ with translated title and description and returns 201 with bilingual objects.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/faqs` |
| Path composition | `api/v1` prefix + `faqs` from `Route::apiResource('faqs', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-faq` gate decides; the auth test even asserts the route gathers `permission:create-faq` middleware) |
| Controller action | `Marvel\Http\Controllers\FaqsController@store` → `FaqsRepository::storeFaqs` (plain `create` from `dataArray` — no slug, no images, no relations; `order` auto-assigned by `SortableTrait`) + `flushTag(FAQS)`; repository failures throw a PLAIN `Exception` (not `MarvelException`) which ESCAPES the controller catch-all into `Handler`'s generic 500 (code-verified — the translated catch-block 500 is dead for repository failures) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-faq` via controller constructor (applies only to `store`); `CreateFaqsRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `faqs.store` (apiResource default naming — existence asserted in-test via `getByName('faqs.store')`) |

Middleware runs before validation: 401/403 precede 422. NOTE success status is **201** here (verified `assertStatus(201)`) — unlike flash-sale/banners/currencies store endpoints which return 200.

## Request inputs

`CreateFaqsRequest` runs before the body. NOTE (code-verified asymmetry vs update): create has NO `UniqueTranslationRule` — duplicate titles are ACCEPTED on create but rejected on update.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `faq_title` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "How to return a product?"}` | Localized titles wrapper |
| `faq_title.*` | body | string | Required per locale | No | `required\|string\|min:3\|max:1000` (NO uniqueness on create) | String 3–1000 chars | None | None | `"How to return a product?"` | One locale's title |
| `faq_description` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "You can return any product within 30 days."}` | Localized descriptions wrapper |
| `faq_description.*` | body | string | Required per locale | No | `required\|string\|min:3\|max:1000` (NO uniqueness on create) | String 3–1000 chars | None | None | `"You can return any product within 30 days."` | One locale's description |
| `status` | body | string/integer | Optional | No | `sometimes\|in:1,0` | `1`, `0` | Model default | None | `1` | Active flag |

No path or query parameters. Any other body fields are ignored (`only(dataArray)`).

## Request examples

Valid request creating an FAQ (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/faqs",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "faq_title": { "en": "How to return a product?" },
    "faq_description": { "en": "You can return any product within 30 days." }
  }
}
```

# Responses

## Success: FAQ created — HTTP 201

Returned via `ApiResponse::apiResponse(FAQ_CREATED_SUCCESSFULLY, 201, true, FaqResource::make($faq))`. Verified by `can_create_faq` (`assertStatus(201)`, `success: true`, DB row asserted) and `user_with_create_only_can_create` (`assertStatus(201)`), both `postJson`. Route is NOT `faqs.index`, so both fields are `{ar,en}` OBJECTS.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success (NOT 200 — code- and test-verified) |
| `message` | string | Always present | Translated `MESSAGE.FAQ_CREATED_SUCCESSFULLY` | `en` → `"FAQ created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `FaqResource` with bilingual objects | `id`, `faq_title{ar, en}`, `faq_description{ar, en}`, `status` (int), `order` (int) |

```json
{
  "status": 201,
  "message": "FAQ created successfully",
  "success": true,
  "data": {
    "id": 1,
    "faq_title": { "ar": null, "en": "How to return a product?" },
    "faq_description": { "ar": null, "en": "You can return any product within 30 days." },
    "status": 1,
    "order": 1
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `unauthenticated_user_cannot_create_faq` → 401) | See 401 example |
| 403 | Authenticated caller lacks `create-faq` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only user (verified by `user_with_view_only_cannot_create` → 403) | See 403 example |
| 422 | `CreateFaqsRequest` fails (missing/short title or description) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; rule shape mirrors the update family) | See 422 example |
| 500 | Repository failure AFTER validation (plain `Exception` escapes the `MarvelException`-only catch → generic 500) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or the live message when debug is on) | None | Post-validation failure (code-traced) | See 500 example |
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

422 example:

```json
{
  "message": "The faq title.en field is required.",
  "status": false,
  "errors": {
    "faq_title.en": ["The faq title.en field is required."]
  }
}
```

500 example (post-validation failure, debug off):

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
