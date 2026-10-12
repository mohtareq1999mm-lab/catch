# Endpoint Name

Tags – Create Tag (`POST /api/v1/tags`)

# Description

Creates one tag with products and media under split failure shapes, returning 201 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/tags` |
| Path composition | `api/v1` prefix + `tags` from bare `Route::apiResource('tags', ...)` (no route middleware; the in-controller `create-tags` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\TagController@store` → slug via `makeSlug`, `create` with ONLY `slug`+`name` (the `icon` string is NOT persisted as a column — icons/images attach as MEDIA; code-verified), `products` sync (ids coerced via `intval`, non-positive dropped), `image`/`icon` uploads into the SAME `tags` media collection (hence identical URLs — code-verified wart); then `load('products')` + `flushTag(TAGS)`. Failure split (code-verified): `MarvelException` → rethrown `COULD_NOT_CREATE_THE_RESOURCE` → `Handler` 500 translated; `HttpException(422, live-text)` from uploads ESCAPES (not a `MarvelException`) → 422 live text; any OTHER exception (e.g. DB `QueryException`) escapes → 409 generic |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-tags` via controller constructor (applies only to `store`); `TagCreateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` (or `multipart/form-data` when sending files) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` or `multipart/form-data` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `tags.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 409/422/500.

## Request inputs

`TagCreateRequest` runs before the body. TENSION (code-verified, documented not resolved): `icon` validates as `nullable|string`, but the controller treats a PRESENT `icon` as a media upload (`uploadSingleImage` expects a file) — a string icon reaches the upload path whose outcome is **Unverified in source**. `image` validates as `nullable|image` (file).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `name` | body | object | Required | No | `['array', 'required']` (array FIRST — style differs from siblings, behavior identical) | Object with locales | None | None | `{"en": "Organic"}` | Localized names |
| `name.*` | body | string | Required | No | `['string', 'max:150', 'required', UniqueTranslationRule::for('tags')]` (note `max:150`, not the usual 255/70; vendor text **Unverified in source**) | String ≤ 150 chars, unique | None | None | `"Organic"` | One locale's name (slug derives from it) |
| `products` | body | array of integers | Optional | Yes | `nullable\|array` (present-empty array DETACHES-nothing-on-create — syncs empty set; code-verified `sync` semantics) | Id arrays | Absent (no links) | None | `[3]` | Products to attach |
| `products.*` | body | integer | Optional | No | `integer\|exists:products,id` (+ runtime `> 0` filter) | Existing product ids | None | None | `3` | One product id |
| `icon` | body | string/file | Optional | Yes | `nullable\|string` (but a PRESENT value enters the media-upload path — see tension note) | String or file | Absent | None | `"star"` | Icon (media-backed; validation/execution tension documented) |
| `image` | body (file) | file (image) | Optional | Yes | `nullable\|image` (no mime/size rules — any image type passes validation; upload failures surface as 422 live text) | Any image file | Absent | None | `tag.jpg` | Tag image (media-backed) |

No path or query parameters. No endpoint test found for the ADMIN store — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/tags",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Organic" },
    "products": [3]
  }
}
```

# Responses

## Success: tag created — HTTP 201

Returned via `ApiResponse::apiResponse(TAG_CREATED_SUCCESSFULLY, 201, true, new TagResource($tag))` with `products` loaded (key present). Route is NOT `tags.show`, so `name` is the locale STRING.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.TAG_CREATED_SUCCESSFULLY` | `en` → `"Tag created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `TagResource` (`products` present — loaded) | `id`, `name` (string), `slug`, `image`, `icon` (same URL), `products[]` (`id`, `name`, `slug`, `status`, `image{thumbnail}`) |

```json
{
  "status": 201,
  "message": "Tag created successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Organic",
    "slug": "organic",
    "image": null,
    "icon": null,
    "products": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. NOTE the three-way failure split (unique in this batch).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `create-tags` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `create-tags` (code-traced) | See 403 example |
| 409 | DB failure during create (`QueryException` escapes the `MarvelException`-only catch → `Handler` `QueryException` branch) | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Constraint/DB failure (code-traced) | See 409 example |
| 422 | `TagCreateRequest` fails (missing/long/duplicate name, unknown `products.*`, non-image `image`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Image/icon UPLOAD fails (`HttpException(422, live-text)` escapes the catch → verbatim; code-verified) | `{message: string, status: boolean}` (NO `errors` map — upload outcome, not validation) | None | Live text: `'Image upload failed, please check the file format or size.'` / `'Icon upload failed, please check the file format or size.'` | None | Unstorable file (code-traced) | See 422-upload example |
| 500 | A `MarvelException` surfaces inside the try (only this class reaches the rethrow → translated 500; nearly dead in practice) | `{message: string, status: boolean}` | None | Translated `ERROR.COULD_NOT_CREATE_THE_RESOURCE`: `en` → `"Could not create the resource"` | None | `MarvelException`-sourced failure (code-traced) | See 500 example |
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

409 example (DB failure):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

422-validation example:

```json
{
  "message": "The name field is required.",
  "status": false,
  "errors": {
    "name": ["The name field is required."]
  }
}
```

422-upload example (live text, no map):

```json
{
  "message": "Image upload failed, please check the file format or size.",
  "status": false
}
```

500 example (MarvelException-sourced only):

```json
{
  "message": "Could not create the resource",
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
