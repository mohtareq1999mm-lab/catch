# Endpoint Name

Products – Import Status (`GET /api/v1/products/import/{id}`)

# Description

Returns live import progress with ownership scoping and honest counter reconciliation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/products/import/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `products/import/{id}` custom `Route::get(...)->name('admin.products.import.status')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:import-product` (plus the constructor's identical gate — double gate, same permission) |
| Controller action | `Marvel\Http\Controllers\ProductImportController@status(int $id)`: ownership scoping (`created_by` = caller UNLESS `SUPER_ADMIN` role — cross-user rows are INVISIBLE, miss as 404, not 403; code-verified) + `authorize('view', $import)` policy (second layer — denial → 403 `'This action is unauthorized'`); column-selected `findOrFail` (miss → 404 fixed `ModelNotFoundException` escape); then signal-file reconciliation: `cancelling` overlay when a cancel signal exists, counter clamping to `total_rows`, `processed = success + failed` repair outside `processing`, and derived-progress honesty clamping while `processing` (all code-verified forensic rules); custom `response()->json` shape + `no-cache` headers (NOT `ApiResponse` — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Required: `import-product` (route + constructor) AND row ownership (or SUPER_ADMIN) AND `view` policy — three layers (code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.import.status` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the ownership key) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Own import id (SUPER_ADMIN: any) | None | None | `12` | Identifies the import |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/products/import/12",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: status returned — HTTP 200 (CUSTOM shape + no-cache headers)

Custom `response()->json` (NOT `ApiResponse`): same envelope keys but the message is `MESSAGE.IMPORT_STATUS_FETCHED` and BOTH `successful_rows` and `success_rows` are returned (duplicate aliases — code-verified). `Cache-Control: no-cache, no-store, must-revalidate` + `Pragma: no-cache` + `Expires: 0` headers always present.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.IMPORT_STATUS_FETCHED` | `en` → `"Import status fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Progress payload (keys verified) | `id`, `status` (effective: `cancelling` overlay possible; else row status: `pending/processing/completed/completed_with_errors/failed/cancelled`), `total_rows`, `processed_rows`, `successful_rows` + `success_rows` (DUPLICATE aliases, same value), `failed_rows`, `progress` (percent: 100 terminal-ok, derived while processing, 0 otherwise), `errors` (array), `error_count` |

```json
{
  "status": 200,
  "message": "Import status fetched successfully",
  "success": true,
  "data": {
    "id": 12,
    "status": "processing",
    "total_rows": 100,
    "processed_rows": 40,
    "successful_rows": 38,
    "success_rows": 38,
    "failed_rows": 2,
    "progress": 39.6,
    "errors": [],
    "error_count": 0
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `import-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Row exists but policy denies (`authorize('view')`) | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (NO trailing period — `Handler` text) | None | Policy denial on a visible row (code-traced second layer) | See 403-policy example |
| 404 | No accessible import with `{id}` (own-row miss, OR another user's row for non-SUPER_ADMIN — indistinguishable by design) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string — `findOrFail` escapes, no translated import message here) | None | Unknown/foreign id | See 404 example |
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
