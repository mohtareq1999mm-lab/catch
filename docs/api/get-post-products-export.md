# Endpoint Name

Products – Start Export (`GET, POST /api/v1/products/export`)

# Description

Queues a filtered product export job with idempotent replay and returns 202 with the tracker.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` AND `POST` (BOTH registered to the same `export` action — `admin.products.export` + `admin.products.export.post`; code-verified dual-method; tests absent for both) |
| Full path | `/api/v1/products/export` |
| Path composition | `api/v1` prefix + `products/export` custom routes in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with NO route-level permission middleware — but the controller constructor enforces `auth:sanctum` + `permission:export-product` on EVERY action (code-verified backstop). Registered BEFORE `apiResource('products', ...)` precisely so the single-segment path is not captured as `products/{product}` (source comment verified) |
| Controller action | `Marvel\Http\Controllers\ProductExportController@export(Request $request)`: manual `Validator::make($request->all(), ProductExportRequest rules)` (NOT a `FormRequest` — works for both verbs; failure → CUSTOM 422 `{success: false, message: 'Validation failed' (LITERAL English, untranslated — code-verified), errors}`); `Idempotency-Key`/`X-Idempotency-Key` replay (24h per user; NO lock here, unlike import — code-verified asymmetry) → 202 SAME export; otherwise creates the `Import` row (`type: PRODUCT_EXPORT`, `pending`), caches non-empty filters 2h (replay persistence), broadcasts the queued event, dispatches `ExportProductsJob($id, $filters)` → 202 `EXPORT_STARTED_SUCCESSFULLY` with `{export_id, status}` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Required: `export-product` via controller constructor (all actions — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` (+ `Content-Type: application/json` for POST bodies; GET carries filters as query) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`); `Idempotency-Key` (or `X-Idempotency-Key`) for replay protection |
| Supported request formats | Query string (GET) or `application/json` (POST) — both feed the same validator |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.export` (GET) + `admin.products.export.post` (POST) — two names, one action |

Middleware runs before validation: 401/403 precede 422/202.

## Request inputs

Manual validation of `ProductExportRequest` rules (unknown keys are ignored by the validator — only listed fields persist into the cached filter set).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (recorded as `created_by`) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `Idempotency-Key` | header | string | Optional | No | Free-form; per-USER namespace | Any string | None (no replay) | None | `"exp-77aa"` | Replay key (24h) |
| `status` | query/body | boolean | Optional | No | `sometimes\|boolean` | Booleans | None (no filter) | None | `true` | Status filter |
| `product_type` | query/body | string | Optional | No | `sometimes\|string\|in:simple,variable` | `simple`, `variable` | None (no filter) | None | `"simple"` | Kind filter |
| `item_type` | query/body | string | Optional | No | `sometimes\|string\|in:PHYSICAL,DIGITAL` | `PHYSICAL`, `DIGITAL` | None (no filter) | None | `"PHYSICAL"` | Fulfillment filter |
| `category_id` | query/body | integer | Optional | No | `sometimes\|integer\|exists:categories,id` | Existing category id | None (no filter) | None | `2` | Category filter |
| `brand_id` | query/body | integer | Optional | No | `sometimes\|integer\|exists:brands,id` | Existing brand id | None (no filter) | None | `1` | Brand filter |

No endpoint test found — contracts code-traced.

## Request examples

POST variant (GET variant moves the same fields to query):

```json
{
  "method": "POST",
  "url": "/api/v1/products/export",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "Idempotency-Key": "exp-77aa",
    "lang": "en"
  },
  "body": {
    "status": true,
    "product_type": "simple"
  }
}
```

# Responses

## Success: export queued — HTTP 202 (ALWAYS 202, including replays)

Returned via `ApiResponse::apiResponse(EXPORT_STARTED_SUCCESSFULLY, 202, true, ['export_id' => ..., 'status' => ...])` for fresh requests AND idempotency replays alike.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `202` on accept (NOT 200/201) |
| `message` | string | Always present | Translated `MESSAGE.EXPORT_STARTED_SUCCESSFULLY` | `en` → `"Export started successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on accept |
| `data` | object | Always present on accept | Tracker payload (keys verified on both paths) | `export_id` (Import row id — track via the status endpoint), `status` (row status at accept time, typically `"pending"`) |

```json
{
  "status": 202,
  "message": "Export started successfully",
  "success": true,
  "data": {
    "export_id": 9,
    "status": "pending"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `export-product` (constructor gate — no route pipe exists to satisfy instead) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `export-product` (code-traced) | See 403 example |
| 422 | Manual filter validation fails (bad `product_type`/`item_type`, unknown `category_id`/`brand_id`, non-boolean `status`) | `{success: boolean, message: string, errors: object}` (CUSTOM shape — literal English message, NO `status` key; code-verified) | None | `"Validation failed"` (LITERAL English — untranslated, unlike every `FormRequest` 422 in this API) | Field → messages | Invalid filters on either verb (code-traced) | See 422 example |
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

422 example (custom shape — literal message, no `status` key):

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "product_type": ["The selected product type is invalid."]
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
