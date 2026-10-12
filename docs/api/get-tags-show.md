# Endpoint Name

Tags – Show Tag (`GET /api/v1/tags/{tag}`)

# Description

Returns one tag by id or slug with mapped products and raw translation names.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/tags/{tag}` |
| Path composition | `api/v1` prefix + `tags/{tag}` from bare `Route::apiResource('tags', ...)` (no route middleware; the in-controller `view-tags` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\TagController@show(Request $request, $params)` (untyped — numeric segment → `where('id')` lookup, else `where('slug')` lookup, both with `products` eager-loaded — same dispatch pattern as flash-sale/attribute show, code-verified); the Eloquent miss (`ModelNotFoundException`) is NOT a `MarvelException`, so it ESCAPES the `catch (MarvelException)` (whose `throw new MarvelException(NOT_FOUND)` rethrow is dead for real misses) into `Handler` → 404 FIXED string (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-tags` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `tags.show` (apiResource default naming — and NOTE: the resource's branch checks `routeIs('tags.show')`, TRUE here, but returns `$this->name` RAW (full stored translations array), NOT the `{ar,en}` object pattern used by every other resource — code-verified fourth shape) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale (`name` is raw here regardless) |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `tag` | path | integer id OR slug string | Required | No | No route-level constraint; `is_numeric` dispatch (soft-deleted rows miss — `Tag` uses `SoftDeletes`, code-verified) | Id or slug of a non-deleted tag | None | None | `1` or `organic` | Identifies the tag |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract. No ADMIN show test found (only the PUBLIC `general/tags/{slug}` sibling) — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/tags/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: tag returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new TagResource($tag))` with `products` loaded and MAPPED (not full product resources — slim `{id, name, slug, status, image.thumbnail}` rows, code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `TagResource` with RAW `name` (full translations array as stored) | `id`, `name` (object — raw array), `slug`, `image`, `icon` (same URL), `products[]` (slim rows) |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": { "en": "Organic" },
    "slug": "organic",
    "image": null,
    "icon": null,
    "products": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-tags` (constructor gate rejects; no route pipe exists) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without `view-tags` (code-traced) | See 403 example |
| 404 | No tag matches `{tag}` by id or slug (miss escapes as `ModelNotFoundException` → fixed string; the translated rethrow is dead for real misses) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown id or slug | See 404 example |
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
