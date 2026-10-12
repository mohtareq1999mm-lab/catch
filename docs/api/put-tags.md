# Endpoint Name

Tags – Update Tag (`PUT /api/v1/tags/{tag}`)

# Description

Partially updates one tag by id with conditional slug recompute and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action) |
| Full path | `/api/v1/tags/{tag}` |
| Path composition | `api/v1` prefix + `tags/{tag}` from bare `Route::apiResource('tags', ...)` (no route middleware; the in-controller `update-tags` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\TagController@update(TagUpdateRequest $request, $id)` → stamps path `id` into the request → `tagUpdate` helper → `findOrFail(request id)` FIRST (miss throws `ModelNotFoundException`, which ESCAPES both `MarvelException`-only catches → `Handler` 404 fixed string — code-verified), then `TagRepository::updateTag`: `only(dataArray)` update, slug recomputed ONLY when `name` is present (`isset` guard — other-only updates keep the old slug even if the name locale changed elsewhere... precisely: no name → no slug touch; code-verified), `products` sync with `> 0` filter, image/icon replacement, fresh re-read; non-miss `MarvelException`s → rethrown `COULD_NOT_UPDATE_THE_RESOURCE` → `Handler` 500 translated; upload `HttpException(422, live-text)` escapes → 422 live text (same three-way split as store, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-tags` via controller constructor (applies only to `update`); `TagUpdateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` (or `multipart/form-data` when replacing files) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` for field-only updates; `multipart/form-data` when sending `image`/`icon` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `tags.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 404/422/500. Validation precedes the miss lookup, so 422 precedes 404 when both apply. `UniqueTranslationRule::ignore($this->route('tag'))` matches the `{tag}` placeholder (code-verified).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `tag` | path | mixed id | Required | No | No route constraint; stamped into the request as `id` for `findOrFail` (soft-deleted ids miss) | Id of a non-deleted tag | None | None | `1` | Identifies the tag |
| `name` | body | object | Optional | No | `['array', 'sometimes']` | Object with locales | Unchanged (and slug untouched when absent) | None | `{"en": "Organic Plus"}` | Localized names wrapper |
| `name.*` | body | string | Optional | No | `['string', 'max:150', 'sometimes', UniqueTranslationRule::for('tags', 'name')->ignore(route('tag'))]` (vendor text **Unverified in source**) | String ≤ 150 chars, unique except self | Unchanged | None | `"Organic Plus"` | One locale's name (recomputes slug when present) |
| `products` | body | array of integers | Optional | Yes | `nullable\|array` (present-empty array DETACHES all products via `sync([])` — code-verified; absent key leaves links untouched) | Id arrays | Unchanged | None | `[3]` | Replacement product links |
| `products.*` | body | integer | Optional | No | `integer\|exists:products,id` (+ runtime `> 0` filter) | Existing product ids | None | None | `3` | One product id |
| `icon` | body | string/file | Optional | Yes | `nullable\|string` (same store-time tension: a PRESENT value enters the media-upload path) | String or file | Unchanged | None | `"star"` | Replacement icon |
| `image` | body (file) | file (image) | Optional | Yes | `nullable\|image` (absent = image kept) | Any image file | Unchanged | None | `tag2.jpg` | Replacement image |

No endpoint test found for the ADMIN update — contracts code-traced.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/tags/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Organic Plus" }
  }
}
```

# Responses

## Success: tag updated — HTTP 200

Returned via `ApiResponse::apiResponse(TAG_UPDATED_SUCCESSFULLY, 200, true, new TagResource($updatedTag))` (fresh re-read + `products` loaded). Route is NOT `tags.show`, so `name` is the locale STRING.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.TAG_UPDATED_SUCCESSFULLY` | `en` → `"Tag updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `TagResource` with mapped `products` | `id`, `name` (string), `slug`, `image`, `icon`, `products[]` (slim rows) |

```json
{
  "status": 200,
  "message": "Tag updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Organic Plus",
    "slug": "organic-plus",
    "image": null,
    "icon": null,
    "products": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Same three-way split as store (plus the miss row).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `update-tags` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-tags` (code-traced) | See 403 example |
| 404 | No tag with id (miss escapes as `ModelNotFoundException` → fixed string) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown id with valid body (code-traced) | See 404 example |
| 409 | DB failure during update (`QueryException` escapes → `Handler` branch) | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` | None | Constraint/DB failure (code-traced) | See 409 example |
| 422 | `TagUpdateRequest` fails (long/duplicate name, unknown `products.*`, non-image `image`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Image/icon replacement fails (`HttpException(422, live-text)` escapes → verbatim) | `{message: string, status: boolean}` (NO `errors` map) | None | Live text (`'Logo upload failed, please check the file format or size.'` — note LOGO wording for tags, unlike IMAGE wording for banners) | None | Unstorable file (code-traced) | See 422-upload example |
| 500 | A `MarvelException` surfaces (rethrow → translated 500; nearly dead in practice) | `{message: string, status: boolean}` | None | Translated `ERROR.COULD_NOT_UPDATE_THE_RESOURCE`: `en` → `"Could not update the resource"` | None | `MarvelException`-sourced failure (code-traced) | See 500 example |
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

409 example:

```json
{
  "message": "Database error occurred. Please check your request and try again.",
  "status": false
}
```

422-validation example:

```json
{
  "message": "The name.en field must not be greater than 150 characters.",
  "status": false,
  "errors": {
    "name.en": ["The name.en field must not be greater than 150 characters."]
  }
}
```

422-upload example (live text, no map):

```json
{
  "message": "Logo upload failed, please check the file format or size.",
  "status": false
}
```

500 example:

```json
{
  "message": "Could not update the resource",
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
