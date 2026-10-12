# Endpoint Name

Digital Assets – Create Asset (`POST /api/v1/products/{product}/digital-assets`)

# Description

Creates one file, URL, license, or access asset on a digital product and returns 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/products/{product}/digital-assets` (explicit `{product}` placeholder with `whereNumber('product')`) |
| Path composition | `api/v1` prefix + nested path custom `Route::post(...)->name('admin.products.digital-assets.store')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:create-product` |
| Controller action | `Marvel\Http\Controllers\DigitalAssetController@store(DigitalAssetCreateRequest $request, int $product)` → `Product::findOrFail` (miss escapes → 404 fixed); NON-digital product → 422 translated `DIGITAL_ASSET_INVALID_FILE` (message reads file-ish but the trigger is the WRONG `item_type` — code-verified mismatch); then registry-dispatched creation: `URL` → `createUrl`, `LICENSE` → `createLicense`, `ACCESS` → `createAccess`, anything else (including ABSENT `type`) → FILE `store` (default branch — code-verified); service `HttpException`s (translated at throw time via `__()`) escape the `MarvelException`-only catch into `Handler` verbatim; other `MarvelException`s rethrow as 500 translated |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-product` via route middleware AND constructor `only: [store]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` (FILE branch) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `multipart/form-data` (FILE/URL/LICENSE/ACCESS — file required only on the FILE path) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.products.digital-assets.store` (explicit named route) |

Middleware runs before validation: 401/403 precede 404/422/500. Validation precedes the product lookup, so 422 precedes 404 when both apply.

## Request inputs

`DigitalAssetCreateRequest` derives file/type rules from the `AssetTypeRegistry` at runtime (extensions/size caps are config-driven — exact values **Unverified in source** beyond the mechanism; document the structure, not the numbers).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `product` | path | integer | Required | No | `whereNumber` + `int $product`; must be `item_type: DIGITAL` (else 422-wrong-type) | Id of a DIGITAL product | None | None | `5` | Identifies the product |
| `file` | body (file) | file | Conditionally required/prohibited | No | REQUIRED on the FILE path (absent/unknown `type` included); PROHIBITED for URL/LICENSE/ACCESS types (sending a file with `type: URL` 422s — code-verified); `file\|mimes:<registry>\|max:<registry>` | Registry extensions/cap | None | Required iff FILE-ish `type`; forbidden otherwise | `manual.pdf` | Asset bytes |
| `type` | body | string | Optional | No | `sometimes\|string\|Rule::in(creatable)` (registry list; absent → FILE branch — code-verified default) | `FILE`, `URL`, `LICENSE`, `ACCESS` (uppercase enum values — code-verified) | `FILE` behavior | None | `"FILE"` | Delivery type |
| `original_name` | body | string | Optional | Yes | `sometimes\|nullable\|string\|max:255` | ≤ 255 chars | Service default | None | `"Manual"` | Original filename label |
| `sort_order` | body | integer | Optional | No | `sometimes\|integer\|min:0` | Integer ≥ 0 | Service default | None | `0` | Display order |
| `external_url` | body | string | Conditionally required | Yes | `requiredIf(type=URL)\|nullable\|string\|max:<config max, default 2048>` (validated HTTPS allow-list enforcement lives in the service — outcome **Unverified in source** at message level) | URL string | None | Required iff `type` is `URL` | `"https://example.com/manual"` | Link target |
| `secret` | body | string | Conditionally required | Yes | `requiredIf(type=ACCESS)\|nullable\|string\|max:2000` (missing secret → service 422 `DIGITAL_ACCESS_SECRET_REQUIRED`; code-verified) | ≤ 2000 chars | None | Required iff `type` is `ACCESS` | `"s3cr3t"` | Access credential (never returned) |

LICENSE pools are provisioned via the dedicated key-import endpoint, NOT at creation (source-commented — code-verified). No endpoint test found — contracts code-traced.

## Request examples

FILE-branch upload (other branches swap `file` for `external_url`/`secret`):

```json
{
  "method": "POST",
  "url": "/api/v1/products/5/digital-assets",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "multipart/form-data",
    "lang": "en"
  },
  "body": {
    "file": "<file manual.pdf>",
    "type": "FILE"
  }
}
```

# Responses

## Success: asset created — HTTP 201

Returned via `ApiResponse::apiResponse(CREATE_DATA_SUCCESSFULLY, 201, true, new DigitalAssetResource($asset))` (generic message, NOT an asset-specific key — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CREATE_DATA_SUCCESSFULLY` | `en` → `"Data created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `DigitalAssetResource` ( secret-free; `external_url` only for URL assets) | `id`, `uuid`, `type`, `original_name`, `display_name`, `mime`, `size` (int), `sort_order` (int), `external_url?`, `created_at`, `updated_at` |

```json
{
  "status": 201,
  "message": "Data created successfully",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "type": "FILE",
    "original_name": "manual.pdf",
    "display_name": null,
    "mime": "application/pdf",
    "size": 102400,
    "sort_order": 0,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Service `HttpException`s carry TRANSLATED messages (service calls `__()` at throw time — code-verified, unlike the RAW-key families).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `create-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No product with id (`findOrFail` escapes — no translated product message here) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{product}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | `DigitalAssetCreateRequest` fails (missing FILE-branch file, file with URL type, bad `type`, oversize, missing `external_url`/`secret` on their branches) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Product is NOT digital (message reads file-ish — code-verified mismatch, document as-is) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `ERROR.DIGITAL_ASSET_INVALID_FILE`: `en` → `"The uploaded file must be a valid PDF document."` | None | PHYSICAL product id with valid body (code-traced) | See 422-type example |
| 422 | Service upload/type guards (invalid file/MIME/mismatch, blocked/unresolvable URL, missing secret, non-pool license keys — translated at throw, verbatim on the wire) | `{message: string, status: boolean}` | None | Translated per-cause text (e.g. `ERROR.DIGITAL_ASSET_INVALID_MIME`) | None | Post-validation service refusal (code-traced guard family) | See 422-service example |
| 500 | Other `MarvelException` (rethrown `SOMETHING_WENT_WRONG`) or service 500s (upload persistence failure) | `{status/message mix by path}` | None | Translated `ERROR.SOMETHING_WENT_WRONG` or `ERROR.DIGITAL_ASSET_UPLOAD_FAILED` | None | Post-validation failure (code-traced) | See 500 example |
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

422-validation example:

```json
{
  "message": "The file field is required.",
  "status": false,
  "errors": {
    "file": ["The file field is required."]
  }
}
```

422-type example (PHYSICAL product — note the message):

```json
{
  "status": 422,
  "message": "The uploaded file must be a valid PDF document.",
  "success": false
}
```

422-service example (translated at throw):

```json
{
  "message": "The file MIME type is not allowed. Only PDF files are accepted.",
  "status": false
}
```

500 example:

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
