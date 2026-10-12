# Endpoint Name

Products – Cancel Export (`POST /api/v1/products/export/{id}/cancel`)

# Description

Requests export cancellation pre-terminally under import-worded messages and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/products/export/{id}/cancel` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `products/export/{id}/cancel` custom `Route::post(...)->name('admin.products.export.cancel')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:export-product` (plus the constructor's identical gate — double gate, same permission) |
| Controller action | `Marvel\Http\Controllers\ProductExportController@cancel(int $id)`: ownership scoping (non-SUPER_ADMIN limited to own rows) + `authorize('cancel', ...)` policy; terminal statuses (`completed/completed_with_errors/failed/cancelled`) → 409 translated `IMPORT_CANNOT_CANCEL` pre-write (IMPORT-worded message on an EXPORT endpoint — code-verified wart shared with the success message); else cancel signal file + cancelling broadcast + conditional row flip (lost-race re-check → second 409 path; `QueryException` reported-and-swallowed) + terminal broadcast → 200 `IMPORT_CANCELLED_SUCCESSFULLY` with `{export_id, status: 'cancelled'}` (both keys import-worded message, export-worded payload keys — code-verified split) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Required: `export-product` (route + constructor) AND row ownership (or SUPER_ADMIN) AND `cancel` policy — three layers |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.export.cancel` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the ownership key) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Own non-terminal export id | None | None | `9` | Identifies the export |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/products/export/9/cancel",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: cancellation recorded — HTTP 200 (IMPORT-worded message)

Returned via `ApiResponse::apiResponse(IMPORT_CANCELLED_SUCCESSFULLY, 200, true, ['export_id' => ..., 'status' => 'cancelled'])` (the `status` string is a LITERAL `'cancelled'` — code-verified, same as import cancel).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY` (import-worded on an export endpoint — code-verified wart) | `en` → `"Import cancelled successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Result payload (EXPORT-worded keys — code-verified split from the message) | `export_id`, `status: "cancelled"` (literal) |

```json
{
  "status": 200,
  "message": "Import cancelled successfully",
  "success": true,
  "data": {
    "export_id": 9,
    "status": "cancelled"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `export-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Row exists but `cancel` policy denies | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (NO trailing period) | None | Policy denial (code-traced) | See 403-policy example |
| 404 | No accessible export with `{id}` (own-row miss or foreign row) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown/foreign id | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 409 | Export already terminal — checked pre-write (no signal/broadcast/row touch) or on the lost-race re-check | `{status: integer, message: string, success: boolean}` | None | Translated `MESSAGE.IMPORT_CANNOT_CANCEL` (import-worded — code-verified wart): `en` → `"Import cannot be cancelled in its current state"` | None | Terminal export (code-traced both paths) | See 409 example |
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

409 example (import-worded):

```json
{
  "status": 409,
  "message": "Import cannot be cancelled in its current state",
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
