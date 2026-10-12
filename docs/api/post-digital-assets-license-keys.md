# Endpoint Name

Digital Assets – Provision License Keys (`POST /api/v1/digital-assets/{uuid}/license-keys`)

# Description

Bulk-provisions encrypted keys into a license pool, returning counts only with no plaintext.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/digital-assets/{uuid}/license-keys` (explicit `{uuid}` placeholder with `whereUuid('uuid')`) |
| Path composition | `api/v1` prefix + `digital-assets/{uuid}/license-keys` custom `Route::post(...)->name('admin.digital-assets.license-keys.store')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:manage-digital-licenses` (source comment tags it W5/A2/A4 — code-verified annotation) |
| Controller action | `Marvel\Http\Controllers\DigitalAssetController@storeLicenseKeys(StoreLicenseKeysRequest $request, string $uuid)` → uncaught `firstOrFail` (miss → 404 fixed; validation runs FIRST, so 422 precedes 404 — code-verified order) → `DigitalAssetService::addLicenseKeys` (non-LICENSE pool → 422 `DIGITAL_LICENSE_POOL_ONLY`; oversize batch → 422 `DIGITAL_ASSET_TOO_LARGE` with `:max`; keys ENCRYPTED at rest — the response carries COUNTS ONLY, plaintext never returns; blank entries skipped+counted; already-stored keys skipped+counted; intra-batch duplicates rejected at validation) → 201 generic message with `{asset_uuid, created, skipped_blank, skipped_duplicate}` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `manage-digital-licenses` via route middleware AND controller constructor `only: [storeLicenseKeys]` (double gate, same permission — repaired: the route previously demanded `update-product`, which wrongly rejected license managers; verified by `DigitalExternalUrlLicenseTest::test_admin_bulk_import_requires_manage_digital_licenses_permission`) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `admin.digital-assets.license-keys.store` (explicit named route) |

Order matters: 401/403 → 422 (validation) → 404 (lookup) → 201/422-service.

## Request inputs

`StoreLicenseKeysRequest` runs before the body (batch cap from `digital.licenses.max_batch_keys`, default 500 — config value **Unverified in source** beyond the default expression).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `uuid` | path | UUID string | Required | No | `whereUuid` + `string $uuid`; must be a LICENSE-pool asset (else 422-service) | UUID of a LICENSE asset | None | None | `"123e4567-e89b-12d3-a456-426614174000"` | Identifies the pool |
| `keys` | body | array of strings | Required | No | `required\|array\|min:1\|max:<batch-cap, default 500>` | 1–500 keys | None | None | `["XXXX-YYYY", "ZZZZ-WWWW"]` | Plaintext keys to encrypt-store (never echoed back) |
| `keys.*` | body | string | Required per element | No | `required\|string\|min:1\|max:2000` + intra-batch trimmed-duplicate check (`withValidator`: exact case-sensitive match after `trim` → 422 `ERROR.DIGITAL_LICENSE_DUPLICATE_KEY` on the offending index) | Non-empty strings ≤ 2000 chars, unique within the batch after trim | None | None | `"XXXX-YYYY"` | One key (surrounding whitespace trimmed before storage) |

Blank entries via HTTP fail the `required` rule explicitly (422 `errors.keys.N`, nothing stored — verified by test). The `skipped_blank` counter covers programmatic/service-layer callers whose input bypasses the FormRequest (whitespace-only trimmed to empty is skipped there, not an error). Keys already stored in this pool (decrypted exact comparison) are SKIPPED and counted as `skipped_duplicate` — repeating a request is therefore safe (full repeat → `created: 0`). Intra-batch duplicates are a 422 instead (explicit rejection, not silent).

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/digital-assets/123e4567-e89b-12d3-a456-426614174000/license-keys",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "keys": ["XXXX-YYYY", "ZZZZ-WWWW"]
  }
}
```

# Responses

## Success: keys provisioned — HTTP 201 (counts only)

Returned via `ApiResponse::apiResponse(CREATE_DATA_SUCCESSFULLY, 201, true, ['asset_uuid' => ..., 'created' => ..., 'skipped_blank' => ..., 'skipped_duplicate' => ...])` (generic message). Plaintext NEVER returns — not even the just-stored keys (code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CREATE_DATA_SUCCESSFULLY` | `en` → `"Data created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Counts payload (keys verified — NO key material) | `asset_uuid` (echoed pool UUID), `created` (rows encrypted-stored), `skipped_blank` (empty-after-trim entries ignored), `skipped_duplicate` (already-stored keys ignored) |

```json
{
  "status": 201,
  "message": "Data created successfully",
  "success": true,
  "data": {
    "asset_uuid": "123e4567-e89b-12d3-a456-426614174000",
    "created": 2,
    "skipped_blank": 0,
    "skipped_duplicate": 0
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-digital-licenses` (product-only editors are rejected here by design) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `manage-digital-licenses` | See 403 example |
| 404 | No asset with `{uuid}` (validation passes first for unknown UUIDs) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown UUID with valid keys (code-traced order) | See 404 example |
| 404 | Malformed `{uuid}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-UUID segment | See 404-constraint example |
| 422 | `StoreLicenseKeysRequest` fails (missing/empty/oversize `keys`, bad element, intra-batch duplicate) | `{message: string, status: boolean, errors: object}` | None | Laravel default, except intra-batch duplicates use translated `ERROR.DIGITAL_LICENSE_DUPLICATE_KEY`: `en` → `"Duplicate license key in this request."` | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Asset is NOT a LICENSE pool (translated at throw, verbatim on the wire) | `{message: string, status: boolean}` | None | Translated `ERROR.DIGITAL_LICENSE_POOL_ONLY`: `en` → `"License keys can only be added to license pool assets."` | None | FILE/URL/ACCESS asset (code-traced) | See 422-pool example |
| 422 | Batch exceeds the byte cap (translated with `:max` interpolation) | `{message: string, status: boolean}` | None | Translated `ERROR.DIGITAL_ASSET_TOO_LARGE` (e.g. `en` → `"The file exceeds the maximum upload size of 2048 KB."` — exact cap config-dependent) | None | Oversize batch (code-traced) | See 422-size example |
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
  "message": "The keys field is required.",
  "status": false,
  "errors": {
    "keys": ["The keys field is required."]
  }
}
```

422-pool example (translated at throw):

```json
{
  "message": "License keys can only be added to license pool assets.",
  "status": false
}
```

422-size example (cap interpolated):

```json
{
  "message": "The file exceeds the maximum upload size of 2048 KB.",
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
