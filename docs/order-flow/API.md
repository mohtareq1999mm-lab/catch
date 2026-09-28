# Order Flow — API

Envelope for service responses: `{status, message, success, data?}`.
Form-request validation failures return `{"message", "errors"}`.
Bilingual display fields are always `{en, ar}`; machine identifiers
(`code`, `key`, `shipping_type`, `source`, `type`, permissions) are never
translated. Supported shipping types: `local` | `international` only.

## GET order status options (per-order transitions)

```text
METHOD
GET /api/v1/orders/{id}/statuses
```

Purpose: order-specific candidate transitions with Flow validity AND the
caller's granular authorization precomputed. Advisory only — PATCH
revalidates everything; never trust these flags client-side.

Authentication: `auth:sanctum`.

Permission: order owner, or `view-orders|view-order`. No status permission
needed to READ options.

Path parameters: `id` (integer, required) — order ID.

Query parameters: none.

Request body: none.

### Response 200

```json
{
  "status": 200,
  "success": true,
  "data": {
    "current_status": {"code": "pending", "name": {"en": "Pending", "ar": "قيد الانتظار"}},
    "flow": {"code": "local", "shipping_type": "local"},
    "statuses": [
      {
        "code": "processing",
        "name": {"en": "Processing", "ar": "قيد التجهيز"},
        "sort_order": 2,
        "transition_allowed": true,
        "permitted": true,
        "allowed": true,
        "permission": "change-order-status.processing",
        "reason": null,
        "requires_inputs": []
      },
      {
        "code": "delivered",
        "name": {"en": "Delivered", "ar": "تم التوصيل"},
        "sort_order": 6,
        "transition_allowed": false,
        "permitted": true,
        "allowed": false,
        "permission": "change-order-status.delivered",
        "reason": "forbidden_transition",
        "requires_inputs": []
      }
    ]
  }
}
```

`reason` ∈ `forbidden_transition | missing_permission | inactive_status`
(null when allowed). `requires_inputs` lists transition-gated input keys
(e.g. `["customs_reference"]` for `customs_clearance`) so the frontend
collects them BEFORE attempting PATCH.

### Response 401

```json
{"message": "Unauthenticated."}
```

### Response 403

Not the owner and no view permission:

```json
{
  "status": 403,
  "message": "You are not authorized to perform this action",
  "success": false
}
```

### Response 404

```json
{"status": 404, "message": "Not found", "success": false}
```

Business Flow: resolve order → access check → flow candidates →
transition flags → permission flags → respond (no mutation, no PII).

Next: after the user picks a target, use `PATCH /api/v1/orders/{id}/status`.

---

## GET flow definition

```text
METHOD
GET /api/v1/general/order-flows/by-shipping-type/{shippingType}
```

Purpose: frontend fetches the flow schema BEFORE checkout and renders
inputs from it. Definitions only — never order values.

Authentication: `auth:sanctum` (any authenticated user, incl. customers).

Permission: none beyond authentication.

Path parameters: `shippingType` (string, required) — `local` |
`international`.

Query parameters: none.

### Request Body — Example 1

No body.

### Validation rules

`shippingType` must be supported AND have an active flow, else 422.

### Response 200

```json
{
  "status": 200,
  "message": "Order flow definition retrieved successfully.",
  "success": true,
  "data": {
    "id": 2,
    "code": "international",
    "name": {"en": "International Flow", "ar": "المسار الدولي"},
    "shipping_type": "international",
    "is_default": true,
    "is_active": true,
    "statuses": [
      {
        "id": 1,
        "code": "pending",
        "name": {"en": "Pending", "ar": "قيد الانتظار"},
        "is_active": true,
        "sort_order": 1
      }
    ],
    "inputs": [
      {
        "id": 1,
        "flow_id": 2,
        "key": "from_country",
        "label": {"en": "Country of origin", "ar": "بلد المنشأ"},
        "placeholder": {"en": "Select origin country", "ar": "اختر بلد المنشأ"},
        "help_text": {"en": "Where the shipment starts.", "ar": "من أين تبدأ الشحنة."},
        "type": "select",
        "source": "countries",
        "required": true,
        "required_at": "checkout",
        "sort_order": 1,
        "validation": null,
        "is_active": true
      }
    ]
  }
}
```

### Response 401 / 403

```json
{"message": "Unauthenticated."}
```

### Response 422

Unsupported type (not `local` | `international`):

```json
{
  "status": 422,
  "message": "The selected shipping type is not supported.",
  "success": false
}
```

No active flow for a supported type:

```json
{
  "status": 422,
  "message": "The selected shipping type is not available for this order.",
  "success": false
}
```

Business Flow: fetch definition → render inputs from the schema → collect `flow_values`.

Next: after success, use `POST /api/v1/general/checkout`.

---

## POST checkout (flow_values extension)

```text
METHOD
POST /api/v1/general/checkout
```

Purpose: create an order; validates checkout-required flow inputs first.

Authentication: `auth:sanctum`. Permission: customer checkout (active
cart, existing checkout rules).

Path parameters: none. Query parameters: none.

### Request fields

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| name | string max:255 | yes | — | Customer name |
| user_phone | string max:255 | yes | — | Customer phone |
| user_email | email max:255 | no | — | Customer email |
| address | object/array | conditional | — | Required for delivery of physical goods |
| notes | string | no | — | Order notes |
| governorate_id | integer | conditional | existing governorate id | Required for delivery; selects shipping price |
| pickup_location_id | integer | conditional | existing pickup location id | Required for pickup fulfillment |
| fulfillment_type | string | no | `delivery`, `pickup` (pickup forced for pay-at-cashier; COD unavailable for pickup) | Default `delivery` |
| payment_method | string | no | `online`, `cod`, `pay_at_cashier` | Default `online` |
| gateway | string max:50 | no | configured gateway | Online payment provider |
| type | string | no | `mobile`, `web` | Client channel |
| selected_promotion_id | integer | no | existing promotion id | Applied promotion |
| selected_gift_product_id | integer | no | existing product id | Gift selection |
| shipping_type | string | no (defaults to `local`) | `local`, `international` | Flow selector |
| flow_values | object | conditional | keys must exist in the Flow; values per input type | Required when the Flow defines checkout-required inputs |

### Request Body — Example 1 (international with inputs)

```json
{
  "name": "Test User",
  "user_phone": "01000000000",
  "address": {"street": "123 Main St"},
  "governorate_id": 4,
  "payment_method": "cod",
  "fulfillment_type": "delivery",
  "shipping_type": "international",
  "flow_values": {"from_country": 1, "to_country": 2}
}
```

### Request Body — Example 2 (local, legacy client, no flow_values)

```json
{
  "name": "Test User",
  "user_phone": "01000000000",
  "address": {"street": "123 Main St"},
  "governorate_id": 4,
  "payment_method": "cod",
  "fulfillment_type": "delivery"
}
```

### Request Body — Example 3 (unknown key → rejected)

```json
{
  "shipping_type": "international",
  "flow_values": {"from_country": 1, "to_country": 2, "fake_field": "abc"},
  "name": "Test User",
  "user_phone": "01000000000",
  "address": {"street": "123 Main St"},
  "governorate_id": 4,
  "payment_method": "cod"
}
```

### Validation rules

`flow_values`: nullable array. Keys allow-listed from the flow's active
inputs; required checkout inputs enforced; `select+source` ids must exist
AND be active; unknown keys rejected. All fail closed (422, no order row).

### Response 200

Envelope `{status, message, success, data}` where `data` carries the order
reference plus the payment payload for the chosen method (gateway
redirect/data for online, confirmation for COD/cashier, `{order_id}` for
zero-value completion).

### Response 400

```json
{"status": 400, "message": "Cart not found", "success": false}
```

### Response 401

```json
{"message": "Unauthenticated."}
```

### Response 422

Field/type/transition failures (FormRequest shape — errors object only):

```json
{
  "shipping_type": ["The selected shipping type is invalid."]
}
```

Flow Input failures (service envelope, status unchanged, NO order row):

```json
{
  "status": 422,
  "message": "One or more flow inputs are invalid.",
  "success": false,
  "data": {"errors": {"from_country": ["Flow input from_country is required."]}}
}
```

### Response 500

```json
{"status": 500, "message": "Error adding items to order", "success": false}
```

Business rule: validation runs inside `OrderService` transaction BEFORE
creation; payment retry reuses the pending order without changing flow.

Next: after success, use `GET /api/v1/general/orders/{id}` or the payment
endpoints; on 422 fix the indicated fields and retry.

---

## PATCH order status (flow_values extension)

```text
METHOD
PATCH /api/v1/orders/{id}/status
```

Purpose: advance an order; validates transition-gated inputs first.

Authentication: `auth:sanctum`. Permission: `update-order-status`
(route middleware) **AND** `change-order-status.<target>` (explicit
server-side assert — general alone is 403; see PERMISSIONS.md).
Unpaid → `completed` additionally needs `payments.mark_paid` (F-1).

Path parameters: `id` (integer, required) — order ID.

Query parameters: none.

### Request fields

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| status | string | yes | global catalog code (e.g. `processing`, `customs_clearance`) | Target status |
| flow_values | object | conditional | keys must exist in the Flow; values per input type | Required when the transition demands inputs |

### Request Body — Example 1 (customs with reference)

```json
{"status": "customs_clearance", "flow_values": {"customs_reference": "CUS-2026-00125"}}
```

### Request Body — Example 2 (plain step, no inputs)

```json
{"status": "processing"}
```

### Request Body — Example 3 (missing reference → rejected)

```json
{"status": "customs_clearance"}
```

### Validation rules

`status` ∈ 22-code catalog; transition must pass the union guard;
`flow_values` validated in context `transition:<status>`.

### Response 200

```json
{
  "status": 200,
  "message": "Order status updated successfully",
  "success": true,
  "data": {"id": 1001, "status": "customs_clearance"}
}
```

(Data is the full order resource; identifying fields shown.)

### Response 401

```json
{"message": "Unauthenticated."}
```

### Response 403

Missing general permission (middleware):

```json
{"message": "User does not have the right permissions."}
```

Missing target permission (server-side assert, status unchanged):

```json
{
  "status": 403,
  "message": "You are not authorized to move orders to packed.",
  "success": false
}
```

### Response 404

```json
{"status": 404, "message": "Not found", "success": false}
```

### Response 422

Unknown status (FormRequest shape):

```json
{
  "message": "The selected status is invalid.",
  "errors": {"status": ["The selected status is invalid."]}
}
```

Forbidden transition (status unchanged):

```json
{
  "status": 422,
  "message": "Cannot change order status from pending to delivered in its flow.",
  "success": false
}
```

Missing required input (status unchanged):

```json
{
  "status": 422,
  "message": "One or more flow inputs are invalid.",
  "success": false,
  "data": {"errors": {"customs_reference": ["Flow input customs_reference is required."]}}
}
```

Business Flow: resolve order → transition check → input check →
F-1/business checks → persist + mutate → side effects.

Next: after success, use order details / shipment / fulfillment endpoints;
on 403 request access; on 422 fix the indicated problem.

> The route above is a single-order view over the unified orchestrator
> below (`OrderStatusBatchService`): same pipeline, same rules, legacy
> response shape unchanged. New integrations should use
> `PATCH /api/v1/orders/status` with `order_ids: [id]`.

---

## PATCH order status — unified (one or many)

```text
METHOD
PATCH /api/v1/orders/status
```

Purpose: mutate ONE order (`order_ids: [101]`) or MANY
(`order_ids: [101, 102, 103]`) through one orchestrator and one
business pipeline. Each order runs the exact single-order authority
(`OrderService::changeOrderStatus()`: permission, flow, inputs,
payment guards, locking, mutation, mirror, history, side effects) in
its own transaction — one failure never rolls back the batch.

Authentication: `auth:sanctum`. Permission: `update-order-status`
(route middleware) **AND** `change-order-status.<target>` per order
(same assert as single PATCH). Unpaid → `completed` needs
`payments.mark_paid` per order (F-1). No ownership check added —
parity with single PATCH is intentional (staff roles hold the general
permission without view perms).

Path parameters: none.

Query parameters: none.

### Request fields

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| order_ids | array of int, 1–50, distinct | yes | any ids | Unknown ids become per-order `order_not_found` (never aborts batch) |
| status | string | yes | global catalog code | `OrderFlowService::ALL_STATUS_CODES` is authoritative |
| flow_values | object | conditional | transition input keys | ONE common object, applied to EVERY order independently |

`[]`, duplicates, non-integers, >50 ids, unknown status → 422 with
zero writes. Existence is per-order by design (no `exists` rule).

### Request Body — Example 1 (one order)

```json
{"order_ids": [101], "status": "processing"}
```

### Request Body — Example 2 (many, shared transition input)

```json
{
  "order_ids": [101, 102, 103],
  "status": "customs_clearance",
  "flow_values": {"customs_reference": "CUS-2026-00125"}
}
```

### Validation rules

Shape validated once (422, nothing mutated); then per order: find →
granular assert → `changeOrderStatus()` → result. Same-status
(`pending → pending`) is the same idempotent no-op as single PATCH.
Fast-shipping orders batch as normal local orders (no fast logic here).

### Response 200 (one order)

```json
{
  "status": 200,
  "message": "Order status updated successfully.",
  "success": true,
  "data": {
    "summary": {"total": 1, "succeeded": 1, "failed": 0},
    "results": [
      {"order_id": 101, "success": true, "status": "processing",
       "current_status": {"code": "processing", "sort_order": 2}}
    ]
  }
}
```

### Response 200 (partial: 102 cannot transition)

```json
{
  "status": 200,
  "message": "2 of 3 orders updated; see per-order results.",
  "success": true,
  "data": {
    "summary": {"total": 3, "succeeded": 2, "failed": 1},
    "results": [
      {"order_id": 101, "success": true, "status": "processing",
       "current_status": {"code": "processing", "sort_order": 2}},
      {"order_id": 102, "success": false,
       "error": {"code": "forbidden_transition", "message": "..."}},
      {"order_id": 103, "success": true, "status": "processing",
       "current_status": {"code": "processing", "sort_order": 2}}
    ]
  }
}
```

Processed batches are always HTTP 200 (even all-failed); top-level
`success` is true when at least one order succeeded. Winners COMMIT,
losers ROLLBACK individually; failed orders stay byte-identical
(status, mirror, history, values). `orders.status ==
current_status.code` holds after every success.

### Response 401

```json
{"message": "Unauthenticated."}
```

### Response 403

Missing general permission (middleware). Per-order granular denial
arrives as a `missing_permission` result entry, not a 403.

### Response 422

Malformed body only (duplicates, empty, >50, unknown status).
Per-order error codes (inside 200 results):
`order_not_found`, `missing_permission`, `forbidden_transition`,
`missing_flow_input`, `unknown_flow_input`, `invalid_flow_input`
(+ `details.errors`), `payment_permission_required`, `internal_error`.

### Retry behavior

Safe: re-sending a batch re-runs the normal guards per order —
already-transitioned winners become same-status no-ops where allowed,
losers fail again. Never a silent skip-ahead. "Retry failed" = resend
the failed ids only.

Business Flow: validate shape → per order (find → granular assert →
`changeOrderStatus()` in its own transaction) → summary + results.

---

## Admin: flow CRUD (inputs included on show)

Supported shipping types: `local` | `international` only. One flow row per
`shipping_type` (unique); resolution uses the ACTIVE row. `shipping_type`
is required on creation and immutable afterwards — create a new flow
instead of renaming it.

### GET /api/v1/admin/order-flows

Permission: `view-order-flows|view-orders|view-order`.

Query parameters: `shipping_type`, `is_active`, `search` (code/name),
`per_page` (1–200, default 50).

Request body: none.

Response 200: envelope with `data[]` (flow resources with ordered
`statuses[]`) and `meta` (`current_page`, `per_page`, `total`).
401 unauthenticated; 403 no view permission.

Next: use `GET {id}` for inputs detail.

### POST /api/v1/admin/order-flows

Permission: `create-order-flows|update-order-status`.

Request fields:

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| code | string max:50 | yes | unique | Stable flow identity |
| name | string or `{en,ar}` | yes | `name.en` required | Display name (string stored as `en`) |
| shipping_type | string max:30 | yes | `local`, `international`; unique | Business discriminator; immutable after creation |
| is_default | boolean | no | — | Default marking |
| is_active | boolean | no | — | Active flag |
| status_ids | array of integers, min:1 | yes | existing ACTIVE status ids | Ordered lifecycle; array order IS the transition map |

```json
{
  "code": "international",
  "name": {"en": "International Flow", "ar": "المسار الدولي"},
  "shipping_type": "international",
  "is_default": true,
  "is_active": true,
  "status_ids": [1, 2, 3]
}
```

Validation: duplicate code/shipping_type, empty/duplicate/unknown/inactive
status ids → 422.

Response 201: envelope with the created flow resource (with statuses).
401/403 as above; 404 n/a.

Next: add inputs via `POST .../{id}/inputs`, verify via `GET .../{id}`.

### GET /api/v1/admin/order-flows/{id}

Permission: `view-order-flows|view-orders|view-order`.

Path parameters: `id` (integer, required) — flow ID. No body.

Response 200: flow resource plus ordered `inputs[]` (input resources).
401/403 as above; 404 flow not found.

Next: edit via PUT, manage inputs via the inputs endpoints.

### PUT /api/v1/admin/order-flows/{id}

Permission: `update-order-flows|update-order-status`.

Path parameters: `id` (integer, required). Same field rules as POST, all
optional; `status_ids` replaces the ordering (neighbors re-link).
`shipping_type` change is rejected (422) — identity is immutable.

Response 200: updated flow resource. Removing statuses that hold
in-flight non-terminal orders → 422. 401/403/404 as above.

> Contract note: `name` on flows, statuses, and order `flow` /
> `current_status` snapshots is `{en, ar}` (Spatie Translatable
> convention, same as `countries.name`). `code` remains the stable
> machine identifier — never use translated names in business logic.
> Admin writes accept a legacy plain string (stored as `en`) or
> `{en, ar}`.

## Admin: status catalog

### GET /api/v1/admin/order-statuses

Permission: `view-order-flows|view-orders|view-order`.

Query parameters: `search`, `is_active`, `per_page` (1–200, default 50).
Request body: none.

Response 200: envelope with `data[]` of:

```json
{"id": 7, "code": "customs_clearance", "name": {"en": "Customs Clearance", "ar": "التخليص الجمركي"}, "description": "Shipment under customs inspection", "is_active": true}
```

401/403 as above.

Next: rename via PUT; assign to flows via flow update.

### GET /api/v1/admin/order-statuses/{id}

Permission: `view-order-flows|view-orders|view-order`.

Path parameters: `id` (integer, required) — status ID. No body.

Response 200: single status resource (same shape). 401/403 as above;
404 status not found.

### PUT /api/v1/admin/order-statuses/{id}

Permission: `update-order-flows|update-order-status`.

Path parameters: `id` (integer, required).

Request fields:

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| name | string or `{en,ar}` | no | `name.en` required when object | Display name (string stored as `en`) |
| description | string max:500, nullable | no | — | Description |
| is_active | boolean | no | — | Activation flag |

```json
{"name": {"en": "Customs Clearance", "ar": "التخليص الجمركي"}}
```

Business rule: `code` never changes once referenced; only
name/description/activation are editable. No POST/DELETE — catalog codes
are program-level vocabulary.

Response 200: updated status resource. 401/403/404 as above; 422 invalid
payload.

## Admin: flow input management

> POST creates MULTIPLE input definitions in ONE atomic transaction:
> one request → one flow → many inputs. No `/inputs/bulk` endpoint.

### GET /api/v1/admin/order-flows/{flowId}/inputs

Permission: `view-order-flows|view-orders|view-order`.

Path parameters: `flowId` (integer, required) — flow ID.

Query parameters: `is_active` (optional boolean filter).

Request body: none.

Response 200: envelope with `data[]` of input resources ordered by
`sort_order` (same shape as below). 401/403 as above; 404 flow not found.

Next: create via POST, edit via PUT, retire via PUT/DELETE.

### POST /api/v1/admin/order-flows/{flowId}/inputs

Permission: `manage-order-flow-inputs|update-order-status`.

Path parameters: `flowId` (integer, required).

Request fields (`inputs`: required array, 1–100 items):

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| inputs[].key | string | yes | `^[a-z][a-z0-9_]{1,49}$`, unique per flow and within the request | Stable frontend contract; immutable after creation |
| inputs[].label | object | yes | `label.en` required string max:100; `label.ar` optional | Display label |
| inputs[].placeholder | object | no | `*.en`/`*.ar` strings max:150 | Placeholder |
| inputs[].help_text | object | no | `*.en`/`*.ar` strings max:500 | Help text |
| inputs[].type | string | yes | `text`, `number`, `boolean`, `date`, `select`, `multi_select` | Generic widget |
| inputs[].source | string | no | `countries`, `governorates`, `warehouses`, `pickup_locations` | Domain option source |
| inputs[].required | boolean | no | — | Default false |
| inputs[].required_at | string | no | `checkout` (default) or `transition:<catalog-code>` | When demanded |
| inputs[].sort_order | integer 1–1000 | no | must not collide | Explicit order; omitted slots allocate sequentially |
| inputs[].validation | object | no | `options[]` max:200, `min`/`max` numeric, `pattern` string max:255 | Value constraints |
| inputs[].is_active | boolean | no | — | Default true |

### Request Body — bulk create (the contract)

```json
{
  "inputs": [
    {
      "key": "from_country",
      "label": {"en": "Origin country", "ar": "بلد المنشأ"},
      "placeholder": {"en": "Select origin", "ar": "اختر بلد المنشأ"},
      "type": "select",
      "source": "countries",
      "required": true,
      "required_at": "checkout"
    },
    {
      "key": "to_country",
      "label": {"en": "Destination country", "ar": "بلد الوجهة"},
      "placeholder": {"en": "Select destination", "ar": "اختر الوجهة"},
      "type": "select",
      "source": "countries",
      "required": true,
      "required_at": "checkout"
    },
    {
      "key": "customs_reference",
      "label": {"en": "Customs reference", "ar": "المرجع الجمركي"},
      "type": "text",
      "required": true,
      "required_at": "transition:customs_clearance",
      "validation": {"min": 3, "max": 100}
    }
  ]
}
```

### Request Body — update (PUT, unchanged shape)

PUT /api/v1/admin/order-flow-inputs/{id} (permission as above):

```json
{"label": {"en": "Customs ref.", "ar": "المرجع الجمركي"}, "is_active": true}
```

Key is immutable (422 on rename); deactivating/unrequiring a REQUIRED
input with in-flight non-terminal orders → 422. Response 200: updated
input resource.

DELETE /api/v1/admin/order-flow-inputs/{id} (permission as above): no
body; deleting a REQUIRED input with in-flight orders → 422; else 200
with a confirmation message and no data.

### Response 200 / 201 / 401 / 403 / 404 / 422

### Validation rules

`inputs`: required array, 1–100 items. Per item: `key` immutable after
create, `^[a-z][a-z0-9_]{1,49}$`, unique per flow AND unique within the
request; `type` ∈ 6 generics; `source` ∈ allow-list; `required_at` =
`checkout` | `transition:<catalog-code>`; label.en required;
`sort_order` optional (explicit values must not collide; omitted slots
allocate sequentially in request order). Adding a REQUIRED input is 422
while in-flight (non-terminal) orders exist on the flow. Any single item
failure rolls back the ENTIRE request — partial persistence never happens.

### Response 200 / 201 / 401 / 403 / 404 / 422

Standard shapes; POST returns **201 with the created collection**
(`data[]` in request order). 422 shapes: `{inputs: [...]}` required;
per-item definition errors are prefixed `inputs.{i}: ...`.

Business rule: key is the stable frontend contract — rename = new input;
updates stay on PUT orchestrated per id.

Next: after success, use `GET .../by-shipping-type/{type}` to verify the
public schema.

---

## Endpoints intentionally NOT added

- No `DELETE` for flows (deactivate via `is_active=false`; history stays
  intact). No status-specific permission endpoints (deferred, see
  PERMISSIONS.md). No `/orders/bulk/status` — bulk is
  `PATCH /api/v1/orders/status` with several ids. Shipment / fulfillment
  / payment endpoints unchanged.

## Bilingual contract

Machine identifiers are never translated: `shipping_type`, `flow.code`,
`status.code`, `input.key`, `source`, `type`, permission names.
Human-readable fields are always `{en, ar}`: flow/status names, input
`label`/`placeholder`/`help_text`, permission labels, validation
messages. Spatie Translatable convention throughout; no second system.

## Error contract

Service errors: `{status, message, success: false}`, plus
`data.errors: {key: [...]}` for input failures. Form-request failures:
`{"message", "errors"}` (PATCH status) or bare errors object (checkout).
401 unauthenticated; 403 missing permission/access; 404 missing
order/flow/status; 422 validation/transition/input/business failure with
the order unchanged and no partial persistence.

## Frontend flow

```text
1. Select shipping type: local OR international
2. GET /general/order-flows/by-shipping-type/{shippingType}
3. Receive flow + statuses + inputs; render by type/source/label/required
4. POST /general/checkout with shipping_type + flow_values when required
5. GET /orders/{id}/statuses → show allowed candidates, collect
   requires_inputs
6. PATCH /orders/status with order_ids ([one] or [many]) → per-order
   results; backend revalidates everything (legacy PATCH
   /orders/{id}/status delegates to the same pipeline)
```
