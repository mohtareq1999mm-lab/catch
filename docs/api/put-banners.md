# Endpoint Name

Banners – Update Banner (`PUT /api/v1/banners/{banner}`)

# Description

Partially or fully updates one banner by id, including images and product links, and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action; tests use `putJson`) |
| Full path | `/api/v1/banners/{banner}` |
| Path composition | `api/v1` prefix + `banners/{banner}` from `Route::apiResource('banners', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-banners` gate decides) |
| Controller action | `Marvel\Http\Controllers\BannerController@update(BannerUpdateRequest $request, string $id)` → `BannerRepository::updateBanner` in a DB transaction: `findOrFail`, mass-updates all fields except the two images, syncs `products` ONLY when the key is present (absent key leaves links untouched — code-verified), replaces supplied images; ANY exception (miss OR upload/DB failure) is rethrown as `HttpException(400, NOT_FOUND)` → controller catch-all renders generic 404 (code-verified collapsing wart: a failed image upload on an existing banner ALSO returns 404) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-banners` via controller constructor (applies to `update`, `changeStatus`, `reorder`); `BannerUpdateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` (or `multipart/form-data` when replacing images) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` for field-only updates (test-verified); `multipart/form-data` when sending `image_desktop`/`image_mobile` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `banners.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 404/422. Validation precedes the miss lookup, so 422 precedes 404 when both apply. Slug is recomputed from the English title on every save via `Banner::booted` (verified by `test_authenticated_admin_can_update_banner`: title → `Winter Sale` yields DB `slug: winter-sale`).

## Request inputs

`BannerUpdateRequest` runs before the body. COSMETIC WART (code-verified): update rules are pipe-DELIMITED STRINGS with surrounding spaces (`"sometimes | array"`), unlike the create request's arrays — behavior is unchanged (Laravel accepts both), but the style differs. `UniqueTranslationRule::for('banners','title')->ignore($this->route("banner"))` lets the banner keep its own title (the `{banner}` placeholder name matches, so the ignore resolves).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `banner` | path | string id | Required | No | No route constraint; `string $id` → `findOrFail` (soft-deleted ids miss → 404) | Id of a non-deleted banner | None | None | `1` | Identifies the banner |
| `title` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "Winter Sale"}` | Localized titles wrapper |
| `title.en` | body | string | Optional | No | `sometimes\|string\|max:255\|min:3` + unique-ignore-self (vendor text **Unverified in source**) | String 3–255 chars | Unchanged | None | `"Winter Sale"` | English title (recomputes slug) |
| `title.ar` | body | string | Optional | No | Same rule set as `title.en` | String 3–255 chars | Unchanged | None | `"تخفيضات الشتاء"` | Arabic title |
| `description` | body | object | Optional | No | `sometimes\|array` | Object | Unchanged | None | `{"en": "New text"}` | Localized descriptions wrapper |
| `description.en` | body | string | Optional | Yes | `nullable\|string\|max:500\|min:10` + unique-ignore-self (NOTE `min:10` vs create's `min:5` — code-verified asymmetry) | String 10–500 chars or null | Unchanged | None | `"New text here"` | English description |
| `description.ar` | body | string | Optional | Yes | Same rule set as `description.en` | String 10–500 chars or null | Unchanged | None | `"نص جديد هنا"` | Arabic description |
| `image_desktop` | body (file) | file (image) | Optional | No | `sometimes\|image\|mimes:jpeg,png,jpg,gif\|max:2048` (absent = image kept) | jpeg/png/jpg/gif ≤ 2 MB | Unchanged | None | `desktop2.jpg` | Replacement desktop image |
| `image_mobile` | body (file) | file (image) | Optional | No | Same rule set as `image_desktop` | jpeg/png/jpg/gif ≤ 2 MB | Unchanged | None | `mobile2.jpg` | Replacement mobile image |
| `status` | body | string/integer | Optional | No | `sometimes\|in:0,1` (strict `1`/`0`) | `1`, `0` | Unchanged | None | `0` | Active flag |
| `products` | body | array of integers | Optional | No | `sometimes\|array`, per-element `exists:products,id` (present-but-empty array DETACHES all products via `sync([])` — code-verified; absent key leaves links untouched) | Product ids | Unchanged | None | `[3]` | Replacement product links |

## Request examples

Field-only update (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/banners/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "title": { "en": "Winter Sale", "ar": "تخفيضات الشتاء" },
    "status": 0
  }
}
```

# Responses

## Success: banner updated — HTTP 200

Returned via `ApiResponse::apiResponse(BANNER_UPDATED_SUCCESSFULLY, 200, true, BannerResource::make($banner))` with `products` loaded. Verified by `test_authenticated_admin_can_update_banner` (`assertOk`, `success: true`, DB `slug: winter-sale`) using `putJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BANNER_UPDATED_SUCCESSFULLY` | `en` → `"Banner updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed Marvel `BannerResource`, locale-string `title` | `id`, `title` (string), `slug`, `description`, `image{desktop, mobile}`, `status`, `products` |

```json
{
  "status": 200,
  "message": "Banner updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "title": "Winter Sale",
    "slug": "winter-sale",
    "description": null,
    "image": { "desktop": null, "mobile": null },
    "status": false,
    "products": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_update_banner`) | See 401 example |
| 403 | Authenticated caller lacks `update-banners` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-banners` (verified by `test_user_without_update_permission_gets_forbidden_for_update`) | See 403 example |
| 404 | Unknown/soft-deleted `{banner}` — OR any post-validation failure (failed image upload, DB error) swallowed by the double catch-all into generic 404 (code-verified wart) | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic `ERROR.NOT_FOUND`) | None | Unknown id with valid body (verified by `test_update_banner_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
| 422 | `BannerUpdateRequest` fails (bad `title`, bad mime/oversize image, unknown `products.*`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; create-side 422 tests verify the shared rule family) | See 422 example |
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
  "status": 404,
  "message": "Not found",
  "success": false
}
```

422 example:

```json
{
  "message": "The title.en field must be at least 3 characters.",
  "status": false,
  "errors": {
    "title.en": ["The title.en field must be at least 3 characters."]
  }
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
