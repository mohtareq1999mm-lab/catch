# Endpoint Name

Order Statuses – Update Status (`PUT /api/v1/admin/order-statuses/{id}`)

# Description

Updates one status's display fields under an in-flight deactivation guard, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified, same as flow update) |
| Full path | `/api/v1/admin/order-statuses/{id}` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/order-statuses/{id}` from the `Route::prefix('admin/order-statuses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-statuses.update`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\OrderStatusCatalogController@update(OrderStatusCatalogUpdateRequest $request, int $id)` → `findOrFail` FIRST (miss → 404 fixed BEFORE the guard; validation itself ran earlier, so 422-validation still precedes 404 — code-verified order); then the DEACTIVATION GUARD (edge→off on an ACTIVE row only, and only when the `orders.current_status_id` column exists): counts NON-terminal holders by EITHER the mirrored `current_status_id` OR the legacy pair (`current_status_id` NULL + legacy `status` string equals the code — the comment calls out never-backfilled mirrors; code-verified dual-holder rule); holders > 0 → 422 translated `checkout.flow_status_inflight_block` WITH the code; else full `$status->update($data)` (only whitelisted display keys can arrive — `code` has NO rule, so it is unchangeable here; code-verified immutability) → 200 with the fresh resource array |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `update-order-flows` / `update-order-status` via the route pipe (legacy status permission accepted — code-verified; no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-statuses.update` (explicit named route) |

Order matters: 401/403 → 422 (validation) → 404 (lookup) → 422 (in-flight guard) → 200.

## Request inputs

Display fields ONLY — `code` is immutable once referenced (no rule exists for it; sending `code` is silently ignored, not rejected — code-verified). Legacy string `name` auto-wraps to `{en}` pre-validation (both `Preparing Order` and `{en, ar}` objects test-proven).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing status id | None | None | `3` | Identifies the status |
| `name` | body | object/string | Optional | No | `sometimes\|required` (plain strings auto-wrapped to `{en}` — code-verified legacy path) | Bilingual object or legacy string | Unchanged | None | `"Preparing Order"` | Display name (sets `en`; test-proven, `code` provably unchanged) |
| `name.en` | body | string | Optional | No | `sometimes\|required\|string\|max:100` | String ≤ 100 chars | Unchanged | None | `"Customs Clearance"` | English name |
| `name.ar` | body | string | Optional | Yes | `nullable\|string\|max:100` | String ≤ 100 chars or null | Unchanged | None | `"التخليص الجمركي"` | Arabic name |
| `description` | body | string | Optional | Yes | `nullable\|string\|max:500` | String ≤ 500 chars or null | Unchanged | None | `"Cleared desc"` | Description |
| `is_active` | body | boolean | Optional | No | `sometimes\|required\|boolean` (edge→off on an ACTIVE row with holders → 422 guard; already-inactive or holder-free → 200) | `true`, `false` | Unchanged | None | `false` | Active flag |

## Request examples

Legacy-string rename (test-verified call — `code` provably untouched):

```json
{
  "method": "PUT",
  "url": "/api/v1/admin/order-statuses/3",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "name": "Preparing Order"
  }
}
```

# Responses

## Success: status updated — HTTP 200

Returned via `ApiResponse::apiResponse('Order status updated successfully.', 200, true, (new OrderStatusResource($status->fresh()))->toArray(request()))` (literal message; FRESH re-read — code-verified). Verified by `test_catalog_update_changes_name_not_code` (`assertOk` via `adminPut`, `data.name.en` + `data.code` unchanged proofs incl. DB-level) and `test_catalog_update_accepts_bilingual_name` (`assertOk` via `putJson`, both locales + `code` proofs).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Order status updated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `OrderStatusResource` array | `id`, `code` (UNCHANGED — never writable here), `name` (localized), `description`, `is_active`, `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Order status updated successfully.",
  "success": true,
  "data": {
    "id": 3,
    "code": "processing",
    "name": { "en": "Preparing Order" },
    "description": null,
    "is_active": true,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. The guard 422 renders `{status, message, success}` with NO `errors` map (only validation carries one).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NEITHER `update-order-flows` NOR `update-order-status` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without either (code-traced) | See 403 example |
| 404 | No status with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PATCH` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `OrderStatusCatalogUpdateRequest` fails (overlong strings, non-boolean `is_active`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Deactivating a status HELD by in-flight (non-terminal) orders — mirrored OR legacy holders both count (dual-holder rule; supervised exits stay available so nothing hard-strands, per the comment) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `checkout.flow_status_inflight_block` WITH the status code | None | Edge→off with holders (verified by `test_cannot_deactivate_status_holding_inflight_orders` → 422 + still-active proof; the terminal-holders twin is `assertOk` with deactivated proof) | See 422-guard example |
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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422-validation example:

```json
{
  "message": "The name.en field must not be greater than 100 characters.",
  "status": false,
  "errors": {
    "name.en": ["The name.en field must not be greater than 100 characters."]
  }
}
```

422-guard example (translated WITH code, no map):

```json
{
  "status": 422,
  "message": "Translated in-flight holder text with code pending (checkout.flow_status_inflight_block)",
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
