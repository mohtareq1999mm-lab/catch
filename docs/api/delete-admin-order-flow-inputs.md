# Endpoint Name

Order Flow Inputs – Delete Input (`DELETE /api/v1/admin/order-flow-inputs/{id}`)

# Description

Deletes one optional input definition behind the required-in-flight guard, returning 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin/order-flow-inputs/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — input id directly, same family as the update twin) |
| Path composition | `api/v1` prefix + `admin/order-flow-inputs/{id}` from the `Route::prefix('admin/order-flow-inputs')->middleware(['api', 'lang', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-flow-inputs.destroy`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\FlowInputController@destroy(int $id)` → `findOrFail` (miss → 404 fixed; no validation exists, so NO 422 can precede it — code-verified); REQUIRED row + in-flight (non-terminal) orders on the flow → translated 422 WITH the key (optional rows delete freely — the guard checks `$input->required` ONLY, not `is_active`; code-verified: deactivating and deleting share the in-flight key but the delete guard ignores the active flag); else hard `delete()` (no `SoftDeletes` on `FlowInput` in the model excerpt read — hard delete, MEDIUM confidence) → 200 with NO data key |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `manage-order-flow-inputs` / `update-order-status` via the route pipe (no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-flow-inputs.destroy` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing input id | None | None | `9` | Identifies the input definition |

No query parameters, body fields, or validation-driven `422` (the sole 422 below is the business guard, not validation).

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin/order-flow-inputs/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: input deleted — HTTP 200 (NO data key)

Returned via `ApiResponse::apiResponse('Flow input deleted successfully.', 200, true)` (literal message; no payload). Verified by `test_admin_input_crud_and_guards` (`assertStatus(200)` via `deleteJson` on an OPTIONAL input).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Flow input deleted successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Flow input deleted successfully.",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so the ONLY 422 is the guard (no `errors` map on any row here).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NEITHER `manage-order-flow-inputs` NOR `update-order-status` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without either (code-traced; the read/write twins pin 403 for this family) | See 403 example |
| 404 | No input with `{id}` (`findOrFail` — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | REQUIRED row + in-flight (non-terminal) orders on its flow (guard checks `required` ONLY — an INACTIVE-but-required row still refuses; optional rows always pass; translated WITH the key) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `checkout.flow_input_inflight_block` WITH `key` | None | Deleting a live demand (verified by `test_required_input_cannot_be_removed_with_inflight_orders` → 422 on the delete twin; the optional twin deletes 200 in the CRUD test) | See 422 example |
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

422 example (translated WITH key, no map):

```json
{
  "status": 422,
  "message": "Translated in-flight block text with key from_country (checkout.flow_input_inflight_block)",
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
