# Endpoint Name

Governorates – List Governorates (`GET /api/v1/governorates`)

# Description

Returns a paginated list of governorates with optional search/status/country filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/governorates` |
| Path composition | `api/v1` prefix + `governorates` from `Route::apiResource('governorates', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-governorate` gate decides) |
| Controller action | `Marvel\Http\Controllers\GovernorateController@index` → `GovernorateRepository::paginate(limit, search, status, country_id)` ordered `id DESC` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-governorate` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `governorates.index` (apiResource default naming) |

## Request inputs

WART (code-verified): the controller reads `(int)$request->get('limit', 15)` — NOT `per_page`. A `per_page` query key is silently ignored; use `limit`.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale and `name` locale string |
| `limit` | query | integer | Optional | No | Cast `(int)`, default `15` | Any positive integer | `15` | None | `15` | Page size (NOT `per_page`) |
| `search` | query | string | Optional | No | Case-insensitive `LIKE` on `name->en`/`name->ar`; blank skips | Any string | None | None | `cairo` | Filters by translated name |
| `status` | query | boolean-ish | Optional | No | Only when key present; cast `(bool)` | `0`, `1`, `true`, `false` | None (no filter) | None | `1` | Filters by active flag |
| `country_id` | query | integer | Optional | No | Applied only when truthy (`? (int) : null`); `0`/absent = no filter; NO `exists` check — unknown id yields empty page, not 422 | Any integer | None (no filter) | None | `1` | Filters by country |

No body fields or path parameters.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/governorates?limit=15&search=cairo&status=1&country_id=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, GovernorateResource::collection(...))`. Verified by `test_authenticated_user_can_list_governorates` (`assertOk`, 2 rows) and `test_list_governorates_returns_empty_when_none_exist` (0 rows), both `getJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty) | Items by `GovernorateResource`; NOT `governorates.show`, so `name` is locale STRING; no relations loaded on index | Item: `id`, `country_id`, `name` (string), `status` (bool), `is_fast_shipping_enabled` (bool), `country`/`cities`/`shipping_price` (null — not loaded), `created_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "country_id": 1,
      "name": "Cairo",
      "status": true,
      "is_fast_shipping_enabled": false,
      "country": null,
      "cities": [],
      "shipping_price": null,
      "created_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_list_governorates`) | See 401 example |
| 403 | Authenticated caller lacks `view-governorate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-governorate` (verified by `test_user_without_view_permission_gets_forbidden_for_index`) | See 403 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
