# Endpoint Name

Fulfillments – Assign Fulfillment (`POST /api/v1/admin/fulfillments/{id}/assign`)

# Description

Assigns one fulfillment to a user with first-winner conflicts and a terminal-state quirk.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/fulfillments/{id}/assign` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/fulfillments/{id}/assign` custom `Route::post(...)->name('api.admin.fulfillments.assign')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\FulfillmentController@assign(AssignFulfillmentRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST — code-verified order); `authorizeWarehouseScope(warehouse_id, 'manage-fulfillment')` (verbatim-403 path — cross-warehouse 403 with `assigned_to` provably NULL, test-verified); then `assignToUser` with the REQUEST's `user_id` ONLY (actor comes from context — caller-sent `actor_id`/`cancelled_by`/`status` fields IGNORED with `status` provably unmoved, test-verified): first winner 200, DIFFERENT second user → `RuntimeException` → 409 (first assignment provably retained), SAME-user replay → 200. QUIRK (test-documented, NOT fixed): `assignToUser` has NO terminal guard — assigning a CANCELLED fulfillment still 200s and sets the assignee (the adapter reflects the service; `test_assign_on_cancelled_fulfillment_documents_existing_service_behavior`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-fulfillment` via the route pipe (a viewer fails it — test-verified 403 on assign for viewers) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.assign` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200/409.

## Request inputs

`AssignFulfillmentRequest` runs before the body (single `user_id` rule — the request carries NO actor fields by design; source-commented).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (NOT recorded — the assignee is) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Fulfillment id manageable by the caller | None | None | `1` | Identifies the fulfillment |
| `user_id` | body | integer | Required | No | `required\|integer\|exists:users,id` (the ASSIGNEE — never the actor; any existing user, cross-warehouse membership unchecked — code-verified absence) | Existing user id | None | None | `7` | The user receiving the fulfillment |

## Request examples

Valid request assigning a fulfillment (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/1/assign",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "user_id": 7
  }
}
```

# Responses

## Success: fulfillment assigned — HTTP 200 (bounded detail)

Returned via `ApiResponse::apiResponse('Fulfillment assigned successfully.', 200, true, FulfillmentDetailResource::make($detail))` (literal message). Verified: first-winner 200 + `assigned_to` proof; same-user replay 200; forgery-field 200 with `assigned_to` + unmoved-`status` proofs.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including replays and the cancelled-row quirk) |
| `message` | string | Always present | Literal (untranslated) | Always `"Fulfillment assigned successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Bounded `FulfillmentDetailResource` (`assigned_to` set) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Fulfillment assigned successfully.",
  "success": true,
  "data": {
    "id": 1,
    "fulfillment_number": "FF-000001",
    "order_id": 42,
    "warehouse_id": 1,
    "status": "pending",
    "priority": null,
    "assigned_to": 7,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Empty body by a guest (verified by the 401 matrix on assign) | See 401 example |
| 403 | Authenticated caller lacks `manage-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Homed permissionless user (verified 403); homed viewer (verified 403 on assign) | See 403 example |
| 403 | Out-of-scope `manage-fulfillment` holder at the SCOPE layer (verbatim, assignment provably absent) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse assign (verified → 403 + `assigned_to` NULL proof) | See 403-scope example |
| 404 | No fulfillment with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 409 | DIFFERENT second user while one holds the row (first-winner conflict — the survivor is provably retained) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | Live engine text (exact string engine-dependent) | None | Contested assign (verified → 409 + first-winner proof) | See 409 example |
| 422 | `AssignFulfillmentRequest` fails (missing/unknown `user_id`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Unknown `user_id: 999999` (verified → 422) | See 422 example |
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

403-scope example (verbatim, no assignment):

```json
{
  "message": "Forbidden warehouse operation.",
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

409 example (live text, first winner retained):

```json
{
  "message": "Live engine conflict text (exact string engine-dependent)",
  "status": false
}
```

422 example:

```json
{
  "message": "The selected user id is invalid.",
  "status": false,
  "errors": {
    "user_id": ["The selected user id is invalid."]
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
