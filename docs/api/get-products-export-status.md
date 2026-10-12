# Endpoint Name

Products – Export Status (`GET /api/v1/products/export/{id}`)

# Description

Returns export progress with ownership scoping under a brand-worded status message.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/products/export/{id}` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `products/export/{id}` custom `Route::get(...)->name('admin.products.export.status')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with NO route-level permission middleware (constructor's `export-product` gate covers it — code-verified backstop) |
| Controller action | `Marvel\Http\Controllers\ProductExportController@status(int $id)`: ownership scoping (`created_by` = caller UNLESS `SUPER_ADMIN` — foreign rows 404, code-verified) + `authorize('view', ...)` policy (denial → 403 no-period); column-selected `findOrFail` (miss → 404 fixed escape); `cancelling` overlay from the cancel signal file; terminality from the row status; custom `response()->json` shape + `no-cache` headers (NOT `ApiResponse` — code-verified, same as import status) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Required: `export-product` (constructor) AND row ownership (or SUPER_ADMIN) AND `view` policy — three layers |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.export.status` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the ownership key) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Own export id (SUPER_ADMIN: any) | None | None | `9` | Identifies the export |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/products/export/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: status returned — HTTP 200 (CUSTOM shape + no-cache headers)

Custom `response()->json` (NOT `ApiResponse`). WART (code-verified copy-paste): the message key is `MESSAGE.BRAND_EXPORT_STATUS_FETCHED` (`en` → `"Brand export status fetched successfully"`) — a PRODUCT endpoint answering with the BRAND text. `completed_at` derives from `updated_at` when terminal, else null.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BRAND_EXPORT_STATUS_FETCHED` (brand-worded on a product endpoint — code-verified wart) | `en` → `"Brand export status fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Progress payload (keys verified; NO `progress` percent and NO `successful_rows` alias — unlike import status) | `id`, `status` (effective: `cancelling` overlay or row status), `total_rows`, `processed_rows`, `successful_rows`, `failed_rows`, `errors`, `error_count`, `created_at` (ISO), `completed_at` (ISO or null) |

```json
{
  "status": 200,
  "message": "Brand export status fetched successfully",
  "success": true,
  "data": {
    "id": 9,
    "status": "processing",
    "total_rows": 100,
    "processed_rows": 40,
    "successful_rows": 38,
    "failed_rows": 2,
    "errors": [],
    "error_count": 0,
    "created_at": "2026-01-01T00:00:00+00:00",
    "completed_at": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `export-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Row exists but `view` policy denies | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (NO trailing period) | None | Policy denial (code-traced) | See 403-policy example |
| 404 | No accessible export with `{id}` (own-row miss or foreign row) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown/foreign id | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (missing permission):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403-policy example (policy denial — note NO period):

```json
{
  "message": "This action is unauthorized",
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

404-constraint example:

```json
{
  "message": "Not Found",
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
