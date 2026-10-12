# Endpoint Name

Order Flows – Update Flow (`PUT /api/v1/admin/order-flows/{id}`)

# Description

Partially updates one flow with identity lock and in-flight guards, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified, unlike product/coupon CRUD which registers both) |
| Full path | `/api/v1/admin/order-flows/{id}` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/order-flows/{id}` from the `Route::prefix('admin/order-flows')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-flows.update`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\OrderFlowController@update(OrderFlowUpsertRequest $request, int $id)` → `findOrFail` FIRST (miss → 404 fixed BEFORE validation-of-state; note validation itself ran earlier, so 422-validation still precedes 404 — code-verified order); `status_ids` re-validated when present (same service rules); `shipping_type` change REJECTED even though validation would usually catch it first (explicit belt-and-braces with its own literal 422 — the comment anticipates future sea/air types; code-verified); deactivation computes `$deactivating` (edge→off ONLY — already-inactive stays a no-op 200, test-verified) then transacts: last-active-for-type guard (row-locked count; zero → 422 translated `checkout.flow_deactivate_last_active`, test-verified with content assertion + still-active proof) → whitelisted update (`code/name/is_default/is_active` ONLY — `status_ids`/`shipping_type` never mass-assigned; code-verified `array_intersect_key`) → conditional status re-sync (REMOVALS re-link neighbours automatically AND refuse when non-terminal orders sit at removed statuses — `test_flow_reorder_relinks_neighbours` proves relinking + `assertOk`; in-flight holding refusal is code-traced with live count text) → single-default enforcement → 200 |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `update-order-flows` / `update-order-status` via the route pipe (legacy status permission accepted — code-verified; no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-flows.update` (explicit named route) |

Order matters: 401/403 → 422 (validation) → 404 (lookup) → 422 (identity/deactivation/in-flight business rules) → 200.

## Request inputs

Same `OrderFlowUpsertRequest` as create (all `sometimes`), PLUS the update-only semantics below. `status_ids` on update is a FULL REPLACEMENT set (not a patch — omitted members are REMOVED subject to the in-flight guard; code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing flow id | None | None | `2` | Identifies the flow |
| `code` | body | string | Optional | No | `sometimes\|required\|string\|max:50\|unique-ignore-self` | Unique ≤ 50 chars | Unchanged | None | `"eu_flow"` | Flow code |
| `name` | body | object/string | Optional | No | Same bilingual rules as create (legacy strings auto-wrapped) | Bilingual or string | Unchanged | None | `{"en": "EU Flow"}` | Localized name |
| `shipping_type` | body | string | Optional | No | IMMUTABLE in practice: any value ≠ stored → 422 literal (even a VALID type — identity never changes; create a new flow instead) | Must equal stored value | Unchanged | None | `"international"` | Identity (read-only after create) |
| `status_ids` | body | array of integers | Optional | No | Same service rules as create (non-empty/distinct/known/active); FULL replacement with neighbour re-linking + in-flight holding refusal | Active status ids | Unchanged | None | `[1, 2, 3]` | Replacement transition map |
| `is_default` | body | boolean | Optional | No | `sometimes\|required\|boolean` (demotes the previous default when set active-true) | `true`, `false` | Unchanged | None | `true` | Default flag |
| `is_active` | body | boolean | Optional | No | `sometimes\|required\|boolean` (last-active-for-type deactivation → 422; already-inactive → no-op 200) | `true`, `false` | Unchanged | None | `false` | Active flag |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/admin/order-flows/2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "is_active": false
  }
}
```

# Responses

## Success: flow updated — HTTP 200

Returned via `ApiResponse::apiResponse('Order flow updated successfully.', 200, true, (new OrderFlowResource($flow))->toArray(request()))` (literal message; resource array). Verified for reorder (`test_flow_reorder_relinks_neighbours` → `assertOk` + neighbour proof), deactivation no-op (`test_deactivating_already_inactive_flow_is_noop` → `assertOk`), and in-flight-coherent reorder (`test_reorder_with_inflight_orders_allowed_and_coherent` → `assertOk`) — all via `adminPut` (same method + path).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Order flow updated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `OrderFlowResource` array with `statuses` loaded | Same key set as show (minus the top-level `inputs` — update does NOT append inputs; code-verified) |

```json
{
  "status": 200,
  "message": "Order flow updated successfully.",
  "success": true,
  "data": {
    "id": 2,
    "code": "intl_flow",
    "name": { "en": "International" },
    "shipping_type": "international",
    "is_default": true,
    "is_active": false,
    "statuses": [],
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. All business 422s render `{status, message, success}` with NO `errors` map (only validation carries one).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NEITHER `update-order-flows` NOR `update-order-status` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without either (code-traced) | See 403 example |
| 404 | No flow with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PATCH` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `OrderFlowUpsertRequest` fails (bad `code`/`shipping_type`/duplicate/unknown `status_ids`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (inactive-member case verified by `test_inactive_status_cannot_join_flow` → 422 on this action) | See 422-validation example |
| 422 | `shipping_type` change attempt (explicit identity lock — fires even for otherwise-valid types) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | `"shipping_type cannot be changed. Create a new flow instead."` (literal WITH period) | None | `shipping_type` ≠ stored value (code-traced; validation usually rejects third types first) | See 422-identity example |
| 422 | Last-active-for-type deactivation (row-locked guard; concurrent writers serialized in-transaction) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `checkout.flow_deactivate_last_active` (content-asserted) | None | Deactivating the sole active flow of its type (verified by `test_cannot_deactivate_last_active_flow_for_type` → 422 + still-active proof; the already-inactive twin is a no-op 200) | See 422-deactivate example |
| 422 | Status removal holding in-flight (non-terminal) orders (live count interpolated) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | `"Cannot remove statuses holding {N} in-flight order(s). Move them first."` (live text) | None | Pruning occupied statuses (code-traced; pure reorder stays 200 per `test_flow_reorder_relinks_neighbours`) | See 422-inflight example |
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
  "message": "The status ids field is required.",
  "status": false,
  "errors": {
    "status_ids": ["The status ids field is required."]
  }
}
```

422-identity example (literal WITH period, no map):

```json
{
  "status": 422,
  "message": "shipping_type cannot be changed. Create a new flow instead.",
  "success": false
}
```

422-deactivate example (translated guard text, no map):

```json
{
  "status": 422,
  "message": "Translated last-active guard text (checkout.flow_deactivate_last_active)",
  "success": false
}
```

422-inflight example (live count, no map):

```json
{
  "status": 422,
  "message": "Cannot remove statuses holding 2 in-flight order(s). Move them first.",
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
