# Endpoint Name

Countries – Bulk Status (`POST /api/v1/countries/change-status`)

# Description

Bulk-updates the status flag of a list of countries and returns the updated count.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (note: governorates sibling uses `PUT`; this one is `POST` — code-verified asymmetry) |
| Full path | `/api/v1/countries/change-status` |
| Path composition | `api/v1` prefix + `countries/change-status` custom `Route::post` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with `['auth:sanctum', 'permission:update-country']` (no collision with `POST /countries` store: 2 segments vs 1) |
| Controller action | `Marvel\Http\Controllers\CountryController@bulkStatus` → `CountryRepository::bulkStatus(ids, status)` (`whereIn->update`, returns affected count) + `flushTag(countries)`; returns `['updated' => $count]` (NOTE: governorates sibling returns NO data — code-verified difference) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-country` via route middleware (constructor does NOT gate `bulkStatus`) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Middleware runs before validation: 401/403 precede 422.

## Request inputs

`BulkStatusRequest::resolveTable` inspects `$this->path()`: path contains `governorates` → `governorates`, `shipping-prices` → `shipping_prices`, else → `countries`. Here the path has neither keyword, so `ids.*` validates `exists:countries,id` (code-verified). Unknown ids fail 422 rather than silently skipping.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `ids` | body | array of integers | Required | No | `required\|array\|min:1` | Non-empty array | None | None | `[1, 2]` | Countries to update |
| `ids.*` | body | integer | Required per element | No | `integer\|min:1\|distinct\|exists:countries,id` | Existing country ids, no duplicates | None | None | `1` | One country id |
| `status` | body | integer | Required | No | `required\|in:0,1` (strict `0`/`1`; boolean `true`/`false` FAILS — code-verified strictness) | `0`, `1` | None | None | `1` | Target status |

No path or query parameters. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/countries/change-status",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "ids": [1, 2],
    "status": 1
  }
}
```

# Responses

## Success: statuses updated — HTTP 200

Returned via `ApiResponse::apiResponse(BULK_STATUS_UPDATED_SUCCESSFULLY, 200, true, ['updated' => $count])`. `updated` is the affected-row count (may be `0` when ids were already in that status — still 200, code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BULK_STATUS_UPDATED_SUCCESSFULLY` | `en` → `"Bulk status updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Result payload | `{"updated": integer}` |

```json
{
  "status": 200,
  "message": "Bulk status updated successfully",
  "success": true,
  "data": {
    "updated": 2
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced; no guest test for this route) | See 401 example |
| 403 | Authenticated caller lacks `update-country` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-country` (code-traced) | See 403 example |
| 422 | `BulkStatusRequest` fails (missing `ids`, empty array, duplicate/unknown id, missing or non-`0`/`1` `status`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; sibling governorates bulk 422 test-verified on identical request class) | See 422 example |
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
  "message": "The ids field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "ids": ["The ids field is required."],
    "status": ["The status field is required."]
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
