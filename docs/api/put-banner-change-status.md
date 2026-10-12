# Endpoint Name

Banners – Change Status (`PUT /api/v1/banner/change-status`)

# Description

Toggles the active flag of one banner by body id and returns 200 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `POST`/`PATCH` on this path → 405) |
| Full path | `/api/v1/banner/change-status` (NOTE singular `banner`, unlike the plural `banners` resource — code-verified) |
| Path composition | `api/v1` prefix + `banner/change-status` custom `Route::put` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:update-banners`. Registered BEFORE `apiResource('banners', ...)`; no collision (singular vs plural segments) |
| Controller action | `Marvel\Http\Controllers\BannerController@changeStatus` → inline `$request->validate(['id' => 'required\|exists:banners,id'])` (→ 422 on missing/unknown/trashed-check gap below); then `BannerRepository::changeStatus($id)` which flips `status` (`!$banner->status`); falsy result → 500 `SOMETHING_WENT_WRONG`; NO try/catch in the controller — repository `HttpException`s escape to `Handler` (see 400 race edge) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-banners` via route middleware AND constructor `only: [update, changeStatus, reorder]` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Order matters: 401/403 → 422 (validation) → 200/500/400-race. The toggle is unconditional (no target value is sent — it always flips).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | body | integer | Required | No | `required\|exists:banners,id` (scopeless string syntax — a SOFT-DELETED id PASSES validation since `exists` ignores the `deleted_at` scope, then hits the 400 race edge below; code-verified trap) | Id of an existing banner | None | None | `1` | Identifies the banner to toggle |

No path or query parameters.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/banner/change-status",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "id": 1
  }
}
```

# Responses

## Success: status toggled — HTTP 200

Returned via `ApiResponse::apiResponse(BANNER_STATUS_CHANGED, 200, true, BannerResource::make($banner))` (locale-string `title`; `products` NOT loaded — key omitted). Verified by `test_authenticated_admin_can_toggle_banner_status` (`assertOk`, `data.status: true` after flipping `false`) using `putJson`, matching this route and method.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BANNER_STATUS_CHANGED` | `en` → `"Banner status changed successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Toggled `BannerResource` | `id`, `title` (string), `slug`, `description`, `image{desktop, mobile}`, `status` (bool — the NEW value) |

```json
{
  "status": 200,
  "message": "Banner status changed successfully",
  "success": true,
  "data": {
    "id": 1,
    "title": "Summer Sale",
    "slug": "summer-sale",
    "description": null,
    "image": { "desktop": null, "mobile": null },
    "status": true
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_change_status`, `putJson`) | See 401 example |
| 403 | Authenticated caller lacks `update-banners` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-banners` (verified by `test_user_without_update_permission_gets_forbidden_for_change_status`) | See 403 example |
| 405 | `POST`/`PATCH` on `/api/v1/banner/change-status` (only `PUT` registered; note `POST banner/reorder` is a DIFFERENT path) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Inline validation fails (missing `id`, unknown `id: 9999`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Empty body (verified by `test_change_status_returns_422_for_missing_id`); unknown id (verified by `test_change_status_returns_422_for_nonexistent_id` for `9999`) | See 422 example |
| 400 | SOFT-DELETED `id` passes `exists` validation (which ignores `deleted_at`) but `BannerRepository::find($id)` returns null → `null->update()` throws `Error` → rethrown as `HttpException(400, NOT_FOUND)` → escapes the try-less controller → `Handler` `HttpException` branch renders the RAW key (same wart class as the slider `changeStatus` 400; code-traced, NOT test-verified) | `{message: string, status: boolean}` | None | `"ERROR.NOT_FOUND"` (RAW untranslated key — `Handler` does not translate `HttpException` messages) | None | Toggling a trashed banner id | See 400 example |
| 500 | Repository returns falsy without throwing (defensive branch — `if(!$banner)` → `SOMETHING_WENT_WRONG`; unreachable in practice since failures throw, code-verified dead-ish branch) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG` | None | Falsy result (code-traced edge) | See 500 example |
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
  "message": "The selected id is invalid.",
  "status": false,
  "errors": {
    "id": ["The selected id is invalid."]
  }
}
```

400 example (trashed-id race edge):

```json
{
  "message": "ERROR.NOT_FOUND",
  "status": false
}
```

500 example (defensive falsy branch):

```json
{
  "status": 500,
  "message": "Something went wrong",
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
