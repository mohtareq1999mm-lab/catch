# Endpoint Name

Banners – Reorder Banners (`POST /api/v1/banner/reorder`)

# Description

Replaces the display order of banners from an ordered id list and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (only `POST` is registered — `PUT` on this path → 405; note the sibling `banner/change-status` is PUT-only — code-verified method asymmetry) |
| Full path | `/api/v1/banner/reorder` (NOTE singular `banner`, unlike the plural `banners` resource — code-verified) |
| Path composition | `api/v1` prefix + `banner/reorder` custom `Route::post` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:update-banners`. Registered BEFORE `apiResource('banners', ...)`; no collision (singular vs plural segments) |
| Controller action | `Marvel\Http\Controllers\BannerController@reorder` → inline `$request->validate(['banners' => 'required\|array', 'banners.*' => 'required\|exists:banners,id'])` (→ 422); then `BannerRepository::reorder` → `setNewOrder($banners)` (Spatie `SortableTrait`, writes the `order` column in the given array sequence); ANY exception → 500 `SOMETHING_WENT_WRONG` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-banners` via route middleware AND constructor `only: [update, changeStatus, reorder]` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Order matters: 401/403 → 422 (validation) → 200/500. The list is authoritative: ids not in the array keep their relative behavior per `setNewOrder` mechanics (**Unverified in source** — vendor trait internals not inspected); duplicate ids are NOT rejected by validation (no `distinct` rule — code-verified laxity).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `banners` | body | array of integers | Required | No | `required\|array` (empty array PASSES `required\|array` but then `setNewOrder([])` is a no-op success — code-verified edge; the 422 missing test sends an absent key, not `[]`) | Array of banner ids in desired display order | None | None | `[2, 1]` | The new order (first element = position 1) |
| `banners.*` | body | integer | Required per element | No | `required\|exists:banners,id` (scopeless; soft-deleted ids pass validation — post-validation behavior on trashed ids is vendor-trait territory, **Unverified in source**) | Existing banner ids | None | None | `2` | One banner id in sequence |

No path or query parameters.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/banner/reorder",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "banners": [2, 1]
  }
}
```

# Responses

## Success: banners reordered — HTTP 200

Returned via `ApiResponse::apiResponse(BANNERS_REORDERED_SUCCESSFULLY, 200, true)` with NO data key. Verified by `test_authenticated_admin_can_reorder_banners` (`assertOk`, `success: true`) using `postJson`, matching this route and method.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BANNERS_REORDERED_SUCCESSFULLY` | `en` → `"Banners reordered successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Banners reordered successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_reorder`, `postJson`) | See 401 example |
| 403 | Authenticated caller lacks `update-banners` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-banners` (verified by `test_user_without_update_permission_gets_forbidden_for_reorder`) | See 403 example |
| 405 | `PUT`/`PATCH` on `/api/v1/banner/reorder` (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Inline validation fails (missing `banners` key, non-array, unknown id `9999`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Absent key (verified by `test_reorder_returns_422_for_missing_banners`); `[9999]` (verified by `test_reorder_returns_422_for_invalid_ids`) | See 422 example |
| 500 | `setNewOrder` throws AFTER validation (DB failure) — message swallowed into the generic key (code-verified) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG` | None | Post-validation failure (code-traced; no test forces this path) | See 500 example |
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
  "message": "The banners field is required.",
  "status": false,
  "errors": {
    "banners": ["The banners field is required."]
  }
}
```

500 example (post-validation failure):

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
