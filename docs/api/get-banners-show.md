# Endpoint Name

Banners – Show Banner (`GET /api/v1/banners/{banner}`)

# Description

Returns one banner by id with bilingual title object and media links.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/banners/{banner}` |
| Path composition | `api/v1` prefix + `banners/{banner}` from `Route::apiResource('banners', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-banners` gate decides) |
| Controller action | `Marvel\Http\Controllers\BannerController@show(string $id)` → `BannerRepository::findOrFail($id)` (BaseRepository; respects `SoftDeletes` — trashed ids miss); the try/catch converts ANY `\Exception` (miss OR unexpected failure) into `apiResponse(NOT_FOUND, 404, false)` (code-verified collapsing wart: even a DB error renders as 404 here) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-banners` via controller constructor (applies to `index` and `show`); the method itself performs no inline check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `banners.show` (apiResource default naming — and NOTE: `BannerResource` checks `routeIs('banners.show')`, which is TRUE here, so `title` is the `{ar,en}` OBJECT on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale (`title` is bilingual here regardless) |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `banner` | path | string id | Required | No | No route-level constraint; passed to `findOrFail` (non-numeric strings miss → 404; soft-deleted ids miss → 404) | Id of a non-deleted banner | None | None | `1` | Identifies the banner |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

Valid request showing a banner (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/banners/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: banner returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, BannerResource::make($banner))` WITHOUT `products` eager-loaded (controller does not load it here — `products` key is omitted via `whenLoaded`, unlike store/update which load it). Verified by `test_authenticated_user_can_show_banner` (`assertOk`, `data.id`, `data.slug`) and `test_banner_resource_structure_on_show` (keys `id, title, slug, description, image, status`; `image` is an array), both `getJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Marvel `BannerResource` with `title` as `{ar,en}` OBJECT (code-verified show branch) | `id`, `title{ar, en}`, `slug`, `description`, `image{desktop, mobile}`, `status` (bool); NO `products` key when not loaded |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "title": { "ar": "تخفيضات الصيف", "en": "Summer Sale" },
    "slug": "summer-sale",
    "description": null,
    "image": { "desktop": null, "mobile": null },
    "status": true
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract. NOTE the 404 here is the GENERIC `ERROR.NOT_FOUND` (`en` → `"Not found"`), and the catch-all means even non-miss failures render as 404 on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (verified by `test_guest_gets_401_for_show_banner`, `getJson`) | See 401 example |
| 403 | Authenticated caller lacks `view-banners` (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token + user without `view-banners` (same constructor gate as index, test-verified on the index sibling) | See 403 example |
| 404 | Unknown, soft-deleted, or non-numeric `{banner}` — OR any unexpected lookup failure (catch-all collapses everything to 404; code-verified) | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | `en` → `"Not found"` (generic `ERROR.NOT_FOUND`, NOT a banner-specific key) | None | Unknown id (verified by `test_show_banner_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
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
