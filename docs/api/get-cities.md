# Endpoint Name

Cities – List Cities (`GET /api/v1/cities`)

# Description

Returns a paginated list of cities with governorate eager-loaded and optional search/governorate filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/cities` |
| Path composition | `api/v1` prefix + `cities` from `Route::apiResource('cities', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-city` gate decides) |
| Controller action | `Marvel\Http\Controllers\CityController@index` → `CityRepository::paginate(per_page, search, governorate_id)` with `governorate` ALWAYS eager-loaded, ordered `id DESC` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-city` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `cities.index` (apiResource default naming) |

## Request inputs

NOTE (code-verified): there is NO `status` filter on this endpoint, unlike countries/governorates — a `status` query key is silently ignored. `City` has no `status` column at all.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale and `name` locale string |
| `per_page` | query | integer | Optional | No | Cast `(int)`, default `15` | Any positive integer | `15` | None | `15` | Page size |
| `search` | query | string | Optional | No | Case-insensitive `LIKE` on `name->en`/`name->ar`; blank skips | Any string | None | None | `nasr` | Filters by translated name |
| `governorate_id` | query | integer | Optional | No | Applied only when truthy; NO `exists` check — unknown id yields empty page, not 422 | Any integer | None (no filter) | None | `1` | Filters by governorate |

No body fields or path parameters.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/cities?per_page=15&search=nasr&governorate_id=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CityResource::collection(...))`. `CityResource` carries NO `status` key and NO relations (the eager-loaded `governorate` is never serialized — code-verified dead eager load). Route is NOT `cities.show`, so `name` is the locale STRING.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty) | Items by `CityResource` | Item: `id`, `governorate_id`, `name` (string), `created_at` ONLY |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "governorate_id": 1,
      "name": "Nasr City",
      "created_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (test-verified pattern on sibling city routes; index guest 401 asserted in `CityApiTest::test_guest_gets_401_for_list_cities`) | See 401 example |
| 403 | Authenticated caller lacks `view-city` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-city` (same constructor gate as show/store family) | See 403 example |
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
