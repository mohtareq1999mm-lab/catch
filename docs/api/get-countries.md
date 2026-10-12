# Endpoint Name

Countries – List Countries (`GET /api/v1/countries`)

# Description

Returns a paginated list of countries with locale-string names and optional search/status filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/countries` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `countries` from `Route::apiResource('countries', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-country` gate decides) |
| Controller action | `Marvel\Http\Controllers\CountryController@index` → `CountryRepository::paginate(per_page, search, status)` ordered `id DESC` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-country` via controller constructor (applies to `index` and `show`); the route pipe lists `view-country\|create-country\|update-country\|delete-country` but the constructor gate is decisive |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; other values ignored, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `countries.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale and `name` locale string |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `per_page` | query | integer | Optional | No | Cast `(int)`, default `15`; passed to paginator | Any positive integer | `15` | None | `15` | Page size |
| `search` | query | string | Optional | No | Case-insensitive `LIKE` on `name->en` and `name->ar` (lowercased); blank skips | Any string | None | None | `egy` | Filters by translated name |
| `status` | query | boolean-ish | Optional | No | Only applied when the key is present (`$request->has('status')`); cast `(bool)` | `0`, `1`, `true`, `false` | None (no filter) | None | `1` | Filters by active flag |

No body fields, path parameters, or file uploads.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/countries?per_page=15&search=egy&status=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CountryResource::collection($countries))`. Verified by `test_authenticated_user_can_list_countries` (`assertOk`, `success: true`, 2 rows) and `test_list_countries_returns_empty_when_none_exist` (0 rows).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty) | Paginated items serialized by `CountryResource`; route is NOT `countries.show`, so `name` is the locale STRING | Item: `id`, `name` (string), `phone_code`, `status` (bool), `governorates` (empty — not loaded), `created_at` (ISO-8601) |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Egypt",
      "phone_code": "+20",
      "status": true,
      "governorates": [],
      "created_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation runs here, so no `422` contract.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (verified by `test_guest_gets_401_for_list_countries`, `getJson`) | See 401 example |
| 403 | Authenticated caller lacks `view-country` (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token + user without `view-country` (verified by `test_user_without_view_permission_gets_forbidden_for_index`) | See 403 example |
| 405 | `POST` on `/api/v1/countries` routes to store (sibling); other unregistered verbs (e.g. `PATCH` on the collection) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Unregistered method on collection path (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (`en` locale):

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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
