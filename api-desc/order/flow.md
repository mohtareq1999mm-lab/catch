# Data Flow - Order Feature

## Flow: Customer Order List (index)

```
Customer App
  |
  GET /api/v1/general/orders?status=completed&limit=15
  Authorization: Bearer <token>
  |
  v
auth:sanctum middleware + throttle:authenticated
  |
  v
App\Http\Controllers\Api\General\OrderController@index
  |
  +-- OrderService::paginateForUser($request)
  |     +-- getLimit() → 15 (default, max 100)
  |     +-- Order::query()->forUser(userId)
  |     +-- when(status) -> where('status', status)      // pending|processing|completed|delivered|cancelled
  |     +-- with([orderItems.product(+avg rating, media), productVariant.attributeProducts.attributeValue,
  |               transactions, pickupLocation, latestInvoice])
  |     +-- paginate(15)->withQueryString()
  |     +-- enrich each item's product with pricing
  |
  v
new App\OrderCollection($paginator)  →  App\OrderResource items
  |
  v
JSON Response
  {
    status:200, message:"Data fetched successfully", success:true,
    data: { data:[ OrderResource... ], links:{ current_page, ..., last_page_url, first_page_url } }
  }
```

## Flow: Customer Invoice View (invoiceByOrderId — canonical)

```
Customer
  |
  GET /api/v1/general/orders/{orderId}/invoice
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:authenticated
  |
  v
App\Http\Controllers\Api\General\OrderController@invoiceByOrderId
  |
  +-- Order::where('user_id', auth id)->findOrFail(orderId)
  |     missing order OR foreign order → ModelNotFound → Handler JSON 404 (no leak)
  |
  +-- $order->latestInvoice()->first()
        ├─ null (pending — nothing created yet) → 404 {status:404,message:"Not found",success:false}
        └─ Invoice → CustomerInvoiceResource → 200
             (identical payload to legacy uuid route)
```

## Flow: Customer Invoice View — REMOVED legacy route

> `GET /orders/invoice/{uuid}` and `OrderController::invoice()` were **removed 2026-08-22**. Use the canonical Order-ID flow above (`invoiceByOrderId`).

## Flow: Admin Order List (index)

```
Admin Client
  |
  GET /api/v1/orders?status=completed&search=ahmed&limit=15
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:admin
  |
  v
permission:view-orders middleware (Spatie)
  |
  v
Marvel\Http\Controllers\Order\OrderController@index($request)
  |
  +-- getLimit($request) → 15
  +-- Order::query()
  |     +-- with(['user', 'orderItems.product', 'orderItems.productVariant.attributeProducts.attributeValue', 'transactions', 'pickupLocation'])
  |     +-- where('status', 'completed')
  |     +-- where(function($q) { $q->where('name','LIKE','%ahmed%')->orWhere(...) })
  |     +-- paginate(15) → LengthAwarePaginator        // ordered by created_at DESC via global scope
  |
  v
new Marvel\OrderCollection($paginator)
  |
  v
JSON Response (200): { status, message, success, data: { data:[ minimal OrderResource ], links:{} } }
```

## Flow: Admin Order Detail (show)

```
Admin Client
  |
  GET /api/v1/orders/42      (42 = id or tracking number)
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:admin
  |
  v
permission:view-order middleware (Spatie)
  |
  v
Marvel\OrderController@show($request, '42')
  |
  +-- Order::query()
  |     +-- with([...5 relations...])
  |     +-- findOrFail('42')  -- also works with tracking number
  |
  v
new Marvel\OrderResource($order)
  |  -- conditionally includes customer_name, financial fields, order_items, transactions
  |     via mergeWhen(routeIs('orders.show'), [...])
  v
JSON Response (200): { status, message, success, data: { full OrderResource } }
```

# Order Flow API

## 1. Overview

The Order Flow system drives order lifecycles from checkout to delivery.
The backend owns flow resolution, transition rules, input validation, and
authorization; the frontend renders schemas and collects values.

```text
Flow Definition
      ↓
Shipping Type
      ↓
Order Flow Resolution
      ↓
Checkout
      ↓
Order Creation
      ↓
Current Status
      ↓
Available Status Options
      ↓
Status Transition
      ↓
Required Flow Inputs
      ↓
Permissions
      ↓
Business Rules
      ↓
Response
```

## 2. Business Concepts

### Shipping Type

Business discriminator the client sends (`local` | `international`) so the
backend can resolve the applicable Order Flow. Supported values are exactly
`local` and `international`; anything else fails with HTTP 422.

### Flow

Ordered lifecycle for one shipping type. Internal stable identity `code`
(e.g. `international`); resolution key `shipping_type`. The client sends
only `shipping_type` and never needs the internal `code`.

### Status

Global catalog value with stable machine `code` (e.g. `customs_clearance`)
and bilingual display `name` (`{en, ar}`). Catalog membership is global;
Flow membership is per-flow configuration.

### Flow Input

Dynamic data definition attached to a Flow (e.g. `from_country` at
checkout, `customs_reference` at `transition:customs_clearance`). Actual
business values live in domain-owned order/shipment columns plus an audit
trail — never in a generic blob.

## 3. How Flow Resolution Works

```text
shipping_type = international
      ↓
resolveFlowForShippingType() → ACTIVE flow with shipping_type = international
      ↓
422 when the type is malformed or has no active Flow
```

One flow row exists per `shipping_type` (unique constraint), so resolution
is deterministic. `is_default` is an administrative marking; runtime
resolution keys on the ACTIVE row. Omitted/empty checkout `shipping_type`
defaults to `local` (backward compatibility).

## 4. Public Flow Definition API

### GET /api/v1/general/order-flows/by-shipping-type/{shippingType}

#### Purpose
Gives the frontend the active Flow definition for the selected shipping
type before checkout. Returns ordered statuses plus active input
definitions for rendering the correct UI.

#### Authentication
`auth:sanctum`. Any authenticated user, including customers.

#### Permission
No additional permission.

#### Path Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| shippingType | string | yes | `local` or `international` |

#### Query Parameters

No query parameters.

#### Request Body

No request body.

#### Validation
`shippingType` must be `local` | `international` AND an active Flow must
exist for it. Anything else → 422. Definitions only — never order values,
customer PII, historical values, or secrets.

#### Business Flow

```text
request
→ normalize shipping type
→ resolve ACTIVE flow (422 when none)
→ load ordered statuses + active input definitions
→ response
```

#### Response 200

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
        "id": 7,
        "code": "customs_clearance",
        "name": {"en": "Customs Clearance", "ar": "التخليص الجمركي"},
        "is_active": true,
        "sort_order": 7
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
    ],
    "created_at": "2026-09-28T00:00:00+00:00",
    "updated_at": "2026-09-28T00:00:00+00:00"
  }
}
```

#### Error Responses

#### 401

```json
{"message": "Unauthenticated."}
```

#### 422

```json
{
  "status": 422,
  "message": "The selected shipping type is not available for this order.",
  "success": false
}
```

#### Frontend Usage
Fetch after the customer picks a shipping type; render statuses and inputs
from `data`; keep the definitions for checkout submission.

## 5. Checkout Integration

### POST /api/v1/general/checkout

#### Purpose
Creates the order for the selected shipping type and validates all
checkout-required Flow Inputs before creation. Then continues the existing
cart, inventory, and payment processing.

#### Authentication
`auth:sanctum`.

#### Permission
Authenticated customer with an active cart (existing checkout rules).

#### Path Parameters

No path parameters.

#### Query Parameters

No query parameters.

#### Request Body

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| name | string max:255 | yes | — | Customer name |
| user_phone | string max:255 | yes | — | Customer phone |
| user_email | email max:255 | no | — | Customer email |
| address | object/array | conditional | — | Required for delivery of physical goods; omitted for pickup/digital-only |
| notes | string | no | — | Order notes |
| governorate_id | integer | conditional | existing governorate id | Required for delivery; selects the shipping price |
| pickup_location_id | integer | conditional | existing pickup location id | Required for pickup fulfillment |
| fulfillment_type | string | no | `delivery`, `pickup` (pickup forced for pay-at-cashier; COD unavailable for pickup) | How the customer receives the order; default `delivery` |
| payment_method | string | no | `online`, `cod`, `pay_at_cashier` | Default `online` |
| gateway | string max:50 | no | configured gateway | Online payment provider |
| type | string | no | `mobile`, `web` | Client channel |
| selected_promotion_id | integer | no | existing promotion id | Applied promotion |
| selected_gift_product_id | integer | no | existing product id | Gift selection |
| shipping_type | string | no (defaults to `local`) | `local`, `international` | Flow selector; unknown values rejected |
| flow_values | object | conditional | keys must exist in the Flow; values per input type | Required when the resolved Flow defines checkout-required inputs |

Complete international example:

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

Complete local example (no checkout inputs required unless configured):

```json
{
  "name": "Test User",
  "user_phone": "01000000000",
  "address": {"street": "123 Main St"},
  "governorate_id": 4,
  "payment_method": "cod",
  "fulfillment_type": "delivery",
  "shipping_type": "local"
}
```

Unknown-key example (rejected):

```json
{
  "shipping_type": "international",
  "flow_values": {"from_country": 1, "to_country": 2, "fake_field": "abc"}
}
```

#### Validation
`shipping_type` must be `local` | `international` with an active Flow.
`flow_values` keys must exist in the active definitions; required checkout
inputs enforced; types, option lists, and `select`+`source` ids validated
against existing ACTIVE records.

#### Business Flow

```text
request
→ validate shipping_type
→ resolve active Flow
→ validate checkout Flow Inputs
→ create Order transaction
→ assign Flow
→ assign initial status
→ reserve inventory, clear cart slice
→ continue existing payment handling
→ response
```

Critical guarantee: input validation runs BEFORE the order row exists, so
a 422 creates NO order.

#### Response 200
Envelope `{status, message, success, data}` where `data` carries the order
reference plus the payment payload for the chosen method (gateway
redirect/data for online, confirmation for COD/cashier, `{order_id}` for
zero-value completion).

#### Error Responses

#### 400

```json
{"status": 400, "message": "Cart not found", "success": false}
```

#### 401

```json
{"message": "Unauthenticated."}
```

#### 422

Validation failure (fields, shipping type, flow availability, inputs,
payment rules). Input failures include per-key errors:

```json
{
  "status": 422,
  "message": "One or more flow inputs are invalid.",
  "success": false,
  "data": {"errors": {"from_country": ["Flow input from_country is required."]}}
}
```

#### 500

```json
{"status": 500, "message": "Error adding items to order", "success": false}
```

#### Frontend Usage
Fetch the definition first for `shipping_type`; submit `flow_values` only
for defined keys; on 422 fix the indicated fields; on 200 follow the
payment payload (redirect for online, confirmation otherwise).

### POST /api/v1/general/fast-shipping/checkout

#### Purpose
Creates a fast-shipping order through the SAME flow engine. Fast Shipping
is NOT a third shipping type: the backend forces `shipping_type = local`,
so the order resolves the same Local Flow with identical rules.

#### Authentication
`auth:sanctum`.

#### Permission
Authenticated customer with an active cart containing FAST-shipping lines.

#### Path Parameters

No path parameters.

#### Query Parameters

No query parameters.

#### Request Body

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| name | string max:255 | yes | — | Customer name |
| user_phone | string max:255 | yes | — | Customer phone |
| user_email | email max:255 | no | — | Customer email |
| address | array | yes | — | Delivery address |
| notes | string | no | — | Order notes |
| governorate_id | integer | yes | fast-shipping-enabled governorate id | Delivery area |
| pickup_location_id | integer | conditional | existing id | Required for pickup |
| fulfillment_type | string | no | `delivery`, `pickup` | Default `delivery` (COD+pickup rejected) |
| payment_method | string | no | `online`, `cod`, `pay_at_cashier` | Default `online` |
| gateway | string max:50 | no | configured gateway | Online provider |
| selected_promotion_id | integer | no | existing promotion id | Applied promotion |
| selected_gift_product_id | integer | no | existing product id | Gift selection |
| shipping_type | string | no | `local` ONLY | Any other value (including `international`) → 422; omitted means local |
| flow_values | object | conditional | same local-Flow input contract | Validated exactly like normal checkout |

#### Validation
Fast window/area/item eligibility (422 with the failed reason);
`shipping_type=international` or anything non-local → 422, no order.

#### Business Flow

```text
request
→ validate fast eligibility + shipping_type = local
→ resolve Local Flow (same resolver)
→ validate checkout Flow Inputs (same validator)
→ create Order with shipping_type = local
→ assign Local Flow + pending
→ reserve inventory, clear FAST cart slice
→ continue existing payment handling
→ response
```

#### Response 200
Same envelope conventions as normal checkout (order reference + payment
payload for the chosen method).

#### Error Responses

#### 400

```json
{"status": 400, "message": "Cart not found", "success": false}
```

#### 401

```json
{"message": "Unauthenticated."}
```

#### 422

Ineligible fast order, non-local shipping type, or input failure — no
order row is created.

#### 500

Creation/infrastructure failure, transaction rolled back.

#### Frontend Usage
Same as normal checkout, without ever sending a non-local shipping type;
fast eligibility (window/area/items) should be checked via the status
endpoint first.

### GET /api/v1/orders/{id}/statuses

#### Purpose
Returns the transitions available for THIS order and THIS actor. Advisory
only; PATCH revalidates transition rules, permissions, inputs, and
business rules server-side.

#### Authentication
`auth:sanctum`.

#### Permission
Order owner, or `view-orders` | `view-order`. No status-change permission
is required just to read the options.

#### Path Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| id | integer | yes | Order ID |

#### Query Parameters

No query parameters.

#### Request Body

No request body.

#### Business Flow

```text
request
→ resolve Order (404 when missing; 403 for non-owners without view permission)
→ resolve assigned Flow and current status
→ calculate candidate transitions (Flow order + supervised exits)
→ evaluate Flow rules and target permissions per candidate
→ response (no mutation, no PII, no order values)
```

#### Validation
`transition_allowed` (union Flow guard), `permitted`
(`change-order-status.<code>`), `allowed` (both). `reason` is one of
`forbidden_transition`, `missing_permission`, `inactive_status` (null when
allowed). `requires_inputs` lists transition-gated input keys so the
frontend collects them before PATCH.

#### Response 200

```json
{
  "status": 200,
  "success": true,
  "data": {
    "current_status": {
      "code": "pending",
      "name": {"en": "Pending", "ar": "قيد الانتظار"}
    },
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

#### Error Responses

#### 401

```json
{"message": "Unauthenticated."}
```

#### 403

```json
{
  "status": 403,
  "message": "You are not authorized to perform this action",
  "success": false
}
```

#### 404

```json
{"status": 404, "message": "Not found", "success": false}
```

#### Frontend Usage
Render only `allowed` candidates as actions; pre-collect `requires_inputs`
for the chosen target; never treat flags as authorization — PATCH decides.

## 7. Change Order Status

### PATCH /api/v1/orders/{id}/status

#### Purpose
Moves the order to the requested status after rechecking permissions,
Flow rules, inputs, and business rules inside one transaction.

#### Authentication
`auth:sanctum`.

#### Permissions
`update-order-status` (route) AND `change-order-status.<target>`
(server-side assert, 403). Unpaid → `completed` additionally needs
`payments.mark_paid`.

#### Path Parameters
`id`: order id.

#### Query Parameters
None.

#### Request Body

```json
{"status": "processing"}
```

```json
{
  "status": "customs_clearance",
  "flow_values": {"customs_reference": "CUS-2026-00125"}
}
```

#### Business Flow
1. Resolve the order (404 when missing).
2. Authorize actor access to the order.
3. Validate the target status exists in the catalog.
4. Check general AND target-status permissions.
5. Check the Flow transition (union guard).
6. Validate Flow Inputs for `transition:<target>`.
7. Execute payment/business rules.
8. Persist status plus the `current_status_id` mirror.
9. Sync legacy status field where applicable.
10. Execute existing side effects/events.
11. Return the order resource.

#### Validation
Unknown status, forbidden transition, missing/invalid inputs, or business
rule failure → 422 with the order status unchanged (no partial mutation).

#### Success Response

HTTP 200

```json
{
  "status": 200,
  "success": true,
  "data": {"id": 1001, "status": "customs_clearance"}
}
```

(Data is the order resource; abbreviated here.)

#### Error Responses

HTTP 401 — unauthenticated. HTTP 403 — missing general or target
permission. HTTP 404 — order not found. HTTP 422 — invalid
status/transition/inputs/business rule; status unchanged.

#### Notes
The only status-mutation endpoint. Frontend flags from the options
endpoint are never trusted. The PATCH response reflects the new `status`
and the invoice already exists at response time (created synchronously on
first leave-pending); activity-log rows, notifications, and the invoice PDF
are produced asynchronously by queue workers.

### Order detail flow context (GET single order)

Customer (`GET /api/v1/general/orders/{id}`, owner only) and admin
(`GET /api/v1/orders/{id}`, `view-order`) details expose the assigned Flow
context alongside the order. Lists expose `flow` + `current_status`
without `statuses[]`; details add the ordered `statuses[]`.

```json
{
  "id": 123,
  "shipping_type": "international",
  "flow": {
    "id": 2,
    "code": "international",
    "name": {"en": "International Flow", "ar": "المسار الدولي"},
    "shipping_type": "international",
    "is_active": true,
    "statuses": [
      {
        "id": 1,
        "code": "pending",
        "name": {"en": "Pending", "ar": "قيد الانتظار"},
        "sort_order": 1
      },
      {
        "id": 2,
        "code": "processing",
        "name": {"en": "Processing", "ar": "قيد التجهيز"},
        "sort_order": 2
      }
    ]
  },
  "current_status": {
    "id": 2,
    "code": "processing",
    "name": {"en": "Processing", "ar": "قيد التجهيز"},
    "sort_order": 2
  }
}
```

`flow.statuses` holds THIS flow's stages only (never the global catalog);
`current_status.sort_order` positions the order inside them (frontend
derives completed/current/upcoming). Fields are omitted (not null) when
relations are unavailable, e.g. legacy rows. `orders.status` (legacy
mirror) is retained unchanged for backward compatibility.

### Unified Status Mutation — PATCH /api/v1/orders/status

#### Purpose
ONE endpoint mutates ONE order (`order_ids: [101]`) or MANY (`order_ids:
[101, 102, 103]`) through the SAME orchestrator
(`OrderStatusBatchService`) and the SAME business pipeline as the
single-order PATCH (`OrderService::changeOrderStatus()` per order, own
transaction, own locks). There is no bulk-only engine and no
`/orders/bulk/status` endpoint. The legacy `PATCH /orders/{id}/status`
is a single-order view over the same orchestrator with its historical
response shape unchanged.

#### Authentication
`auth:sanctum` (+ `throttle:admin`, `lang`; same group as all order routes).

#### Permission
Route middleware `update-order-status`, then per order the granular
`change-order-status.<target>` (identical to single PATCH — staff roles
holding the general permission without view perms behave the same on
both endpoints). `payments.mark_paid` (F-1) applies per order when
completing unpaid orders. No ownership/view check is added: parity with
single PATCH is intentional.

#### Path Parameters

No path parameters.

#### Query Parameters

No query parameters.

#### Request Body

| Field | Type | Required | Allowed Values | Description |
|---|---|---|---|---|
| order_ids | array of int, 1–50, distinct | yes | existing or unknown ids | Unknown ids become per-order `order_not_found` (never aborts batch) |
| status | string | yes | global catalog code | `OrderFlowService::ALL_STATUS_CODES` is authoritative |
| flow_values | object | conditional | transition input keys | ONE common object applied to EVERY order independently |

`[]`, duplicates, non-integers, >50 ids, or unknown status → 422, no
mutation. Per-order values (`orders: [{id, flow_values}]`) are a
documented future extension, not this contract.

#### Validation
FormRequest rejects shape errors (422, nothing mutated). Existence is
per-order by design.

#### Business Flow

```text
request
→ validate shape (422 on failure, no writes)
→ for EACH id, independently:
  → find (missing → order_not_found result)
  → granular target assert (denied → missing_permission result)
  → changeOrderStatus (own transaction + lockForUpdate)
  → success result {status, current_status} or mapped error result
→ 200 summary + results
```

Same-status (`pending → pending`) is the same idempotent no-op as
single PATCH. Fast-shipping orders batch as normal local orders (no
fast logic in this endpoint).

#### Response 200
Envelope `{status, message, success, data}` with
`data = {summary: {total, succeeded, failed}, results: [...]}`.
Success entry: `{order_id, success: true, status, current_status:
{code, sort_order}}`. Failure entry: `{order_id, success: false,
error: {code, message[, details.errors]}}`. Processed batches are HTTP
200 even when every order fails; top-level `success` is true when at
least one order succeeded.

One order:

```json
{
  "status": 200, "success": true,
  "data": {
    "summary": {"total": 1, "succeeded": 1, "failed": 0},
    "results": [
      {"order_id": 101, "success": true, "status": "processing",
       "current_status": {"code": "processing", "sort_order": 2}}
    ]
  }
}
```

Partial (`[101, 102, 103]`, 102 cannot transition):

```json
{
  "status": 200, "success": true,
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

#### Error Responses

#### 401

```json
{"message": "Unauthenticated."}
```

#### 403

Missing general `update-order-status` (route middleware).

#### 422

Malformed body (duplicates, empty, >50, unknown status). Per-order
error codes (inside 200 results, never aborting the batch):
`order_not_found`, `missing_permission`, `forbidden_transition`,
`missing_flow_input`, `unknown_flow_input`, `invalid_flow_input`,
`payment_permission_required`, `internal_error` (reported server-side,
no class names leaked).

#### Frontend Usage
Submit one id or many through the same call; always render `results`
per order (never assume all-or-nothing); on partial success offer
"retry failed" with the failed ids only — retried successes are
idempotent for same-status and validated normally otherwise. Pre-check
each order via `GET /orders/{id}/statuses`, but PATCH revalidates.

#### Retry behavior
Safe: each order is independent; re-sending an already-transitioned
order re-runs the normal guards (same-status no-op where allowed,
transition error otherwise — never a silent skip-ahead).

#### Maximum batch size
50 ids. Synchronous by design (per-order locks + side effects);
queued bulk is a separate architectural decision, not this endpoint.

#### Important constraints
- No global batch transaction: winners COMMIT, losers ROLLBACK individually.
- `orders.status == current_status.code` holds after every success.
- Failed orders are byte-identical (status, mirror, history, values).
- Side effects (history, invoice, events, inventory/payment on
  completed/cancelled) are exactly the single-order ones, per order.
- Shipment/payment/inventory/fulfillment machines stay separate.

## 8. Transition Rules

Normal logistics statuses follow the configured immediate successor
(`current → next`), and the target must be active — otherwise fail closed.
Same-status requests are a no-op at the Flow layer. `completed` is allowed
from any non-terminal status as the payment/business milestone (still gated
by `payments.mark_paid` when unpaid — payment state is NOT merged into the
flow). `cancelled` is allowed from anything except `completed`, `delivered`,
`cancelled`. `failed_delivery` only from `out_for_delivery`; `returned` only
from `failed_delivery` or `out_for_delivery`. `delivered` and `cancelled`
are terminal sources. Inactive targets always fail closed.

## 9. Flow Inputs

Flow Inputs are dynamic definitions attached to a Flow; runtime business
values belong to domain-owned order columns plus the `order_flow_values`
audit trail. Generic types only: `text`, `number`, `boolean`, `date`,
`select`, `multi_select` — with `source` (`countries`, `governorates`,
`warehouses`, `pickup_locations`) instead of business-specific types.
Keys match `^[a-z][a-z0-9_]{1,49}$`, are unique per Flow, and immutable
after creation (rename = new input). `required_at` is `checkout` or
`transition:<status_code>` (validated against the catalog).

Checkout inputs are validated before the order row exists (422 creates
nothing). Transition inputs are validated before mutation (422 leaves the
status unchanged). Unknown keys, wrong types, and inactive/nonexistent
source records all fail closed.

## 10. Admin Flow APIs

### GET /api/v1/admin/order-flows

#### Purpose
Lists flows with their ordered statuses for administration.

#### Authentication
`auth:sanctum`.

#### Permissions
`view-order-flows` | `view-orders` | `view-order`.

#### Path Parameters
None.

#### Query Parameters
`shipping_type`, `is_active`, `search`, `per_page` (filters/pagination).

#### Request Body
None.

#### Business Flow
1. Filter and paginate flows with statuses.
2. Return the collection.

#### Validation
Standard filter validation.

#### Success Response

HTTP 200 — envelope with `data[]` (flow resources) and `meta`
pagination.

#### Error Responses

HTTP 401 — unauthenticated. HTTP 403 — no view permission.

#### Notes
Detail (with inputs) comes from `GET {id}` below.

### POST /api/v1/admin/order-flows

#### Purpose
Creates a flow with its ordered lifecycle for one shipping type.

#### Authentication
`auth:sanctum`.

#### Permissions
`create-order-flows` | `update-order-status`.

#### Path Parameters
None.

#### Query Parameters
None.

#### Request Body

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

#### Business Flow
1. Validate code/name/shipping_type/statuses.
2. Validate the status id list (non-empty, unique, existing, active).
3. Create the flow; array order becomes `sort_order`.
4. Enforce the single-default rule.
5. Return the flow with statuses.

#### Validation
`code` required/string/max:50/unique; `name` required with `name.en`;
`shipping_type` required/string/max:30/stable-identifier/unique (one flow
row per type; immutable after creation — create a new flow instead);
`status_ids` required non-empty ordered array of existing ACTIVE ids.

#### Success Response

HTTP 201 — envelope with the created flow resource.

#### Error Responses

HTTP 401/403 as above. HTTP 422 — duplicate code/shipping_type, empty or
duplicate/unknown/inactive statuses.

#### Notes
`shipping_type` accepts only `local` | `international` as supported
business values.

### GET /api/v1/admin/order-flows/{id}

#### Purpose
Returns one flow with ordered statuses and ordered input definitions.

#### Authentication
`auth:sanctum`.

#### Permissions
`view-order-flows` | `view-orders` | `view-order`.

#### Path Parameters
`id`: flow id.

#### Query Parameters
None.

#### Request Body
None.

#### Business Flow
1. Load the flow with statuses and inputs.
2. Return the resource.

#### Validation
404 when the flow does not exist.

#### Success Response

HTTP 200 — flow resource plus `inputs[]` (input resources, ordered).

#### Error Responses

HTTP 401/403 as above. HTTP 404 — flow not found.

#### Notes
Same shape as the public definition plus administrative flags.

### PUT /api/v1/admin/order-flows/{id}

#### Purpose
Updates flow configuration and re-links the status order.

#### Authentication
`auth:sanctum`.

#### Permissions
`update-order-flows` | `update-order-status`.

#### Path Parameters
`id`: flow id.

#### Query Parameters
None.

#### Request Body
Partial flow fields and/or `status_ids` (replacement ordering).

#### Business Flow
1. Validate the payload (`shipping_type` change rejected — identity).
2. Re-link memberships in the new order (neighbors re-link automatically).
3. Refuse to remove statuses holding in-flight non-terminal orders (422).
4. Enforce the single-default rule.
5. Return the flow.

#### Validation
Same field rules as create; `status_ids` optional on update.

#### Success Response

HTTP 200 — updated flow resource.

#### Error Responses

HTTP 401/403/404 as above. HTTP 422 — in-flight statuses would be
orphaned, or invalid status list.

#### Notes
No DELETE endpoint: deactivate via `is_active=false` to preserve history.

## 11. Admin Status Catalog APIs

### GET /api/v1/admin/order-statuses

#### Purpose
Lists the global status catalog for administration.

#### Authentication
`auth:sanctum`.

#### Permissions
`view-order-flows` | `view-orders` | `view-order`.

#### Path Parameters
None.

#### Query Parameters
`search`, `is_active`, `per_page`.

#### Request Body
None.

#### Business Flow
1. Filter and paginate catalog rows.
2. Return status resources with bilingual names.

#### Validation
Standard filter validation.

#### Success Response

HTTP 200 — envelope with `data[]` and `meta`.

#### Error Responses

HTTP 401/403 as above.

#### Notes
Global dictionary — not per-order options.

### GET /api/v1/admin/order-statuses/{id}

#### Purpose
Returns one catalog status.

#### Authentication
`auth:sanctum`.

#### Permissions
`view-order-flows` | `view-orders` | `view-order`.

#### Path Parameters
`id`: status id.

#### Query Parameters
None.

#### Request Body
None.

#### Business Flow
1. Load the status row.
2. Return the resource.

#### Validation
404 when missing.

#### Success Response

HTTP 200 — `{id, code, name{en,ar}, description, is_active, ...}`.

#### Error Responses

HTTP 401/403/404 as above.

#### Notes
`code` is the stable machine identifier and is never renamed through this
endpoint.

### PUT /api/v1/admin/order-statuses/{id}

#### Purpose
Renames or toggles a catalog status without touching its code.

#### Authentication
`auth:sanctum`.

#### Permissions
`update-order-flows` | `update-order-status`.

#### Path Parameters
`id`: status id.

#### Query Parameters
None.

#### Request Body

```json
{"name": {"en": "Customs Clearance", "ar": "التخليص الجمركي"}}
```

(A plain string is also accepted and stored as the English translation.)

#### Business Flow
1. Validate name/description/activation.
2. Update display fields only.
3. Return the resource.

#### Validation
`code` is immutable here; name accepts string or `{en,ar}`.

#### Success Response

HTTP 200 — updated status resource.

#### Error Responses

HTTP 401/403/404 as above. HTTP 422 — invalid payload.

#### Notes
No POST/DELETE: catalog codes are program-level vocabulary; flows control
membership.

## 12. Permissions

General `update-order-status` authorizes status mutation in the domain;
`change-order-status.<code>` authorizes the specific target (both
required; enforced server-side on PATCH and the legacy edit path).
`payments.mark_paid` remains separate for unpaid → `completed`.
Permissions are seeded/synchronized from the catalog
(`OrderStatusPermissionSeeder` + catalog hook); roles keep existing
semantics via an explicit additive backfill. No second permission system.

## 13. Validation & Error Handling

Envelope for service errors: `{status, message, success:false}` plus
`data.errors` for input failures. Form-request failures return the
validator error object. Semantics: 401 unauthenticated; 403 missing
general/target permission or order access; 404 order/flow/status missing;
422 malformed type, no active flow, forbidden transition, missing/invalid
inputs, payment/business failure. Any 422/403 leaves the order unchanged.

## 14. Frontend Integration Flow

```text
1. Frontend knows shipping type: local / international
2. GET /general/order-flows/by-shipping-type/{shippingType} → statuses +
   active input definitions
3. Render inputs by type/source/label/placeholder/required/validation
4. POST checkout with shipping_type + flow_values (backend authoritative)
   (fast shipping: same flow, shipping_type forced to local server-side)
5. GET /orders/{id} → flow + ordered statuses + current_status position
6. GET /orders/{id}/statuses → display allowed/advisory statuses,
   collect requires_inputs
7. PATCH /orders/{id}/status → backend revalidates everything
```

```text
Frontend
   ↓
shipping_type = international
   ↓
Resolve active Flow
   ↓
Load Flow statuses + input definitions
   ↓
Render checkout fields
   ↓
Submit flow_values
   ↓
Validate
   ↓
Create Order
   ↓
Assign Flow
   ↓
pending
```

```text
PATCH status
      ↓
Target permission
      ↓
Flow transition
      ↓
Required inputs
      ↓
Business rules
      ↓
Persist
      ↓
Response
```

## 15. Request / Response Examples

Covered per endpoint above using real field names from the Resources.
`code`, `key`, `shipping_type`, `source`, `type`, and permission names are
never translated; display names are always `{en, ar}`.

## 16. Known Constraints / Important Notes

- Supported shipping types: `local` | `international`. Anything else 422s —
  no active flow exists for it. Fast Shipping is not a type: it creates
  `local` orders through the same engine.
- One flow row per `shipping_type`; resolution uses the ACTIVE row;
  `is_default` is administrative marking.
- No flow DELETE (deactivate instead); no status POST/DELETE (catalog is
  code vocabulary); no `/inputs/bulk` (bulk lives on the existing POST).
- Payment/shipment/fulfillment/inventory/refund machines stay separate;
  `completed` keeps its payment milestone requirements.
- Legacy edit/GraphQL paths enforce the same transition + target-permission
  gates; expiry and refund paths are documented system exceptions that never
  touch flow state rules.
- Status mutation: `PATCH /api/v1/orders/status` handles one order
  (`order_ids: [id]`) or many through one orchestrator and one pipeline
  (independent per-order transactions, partial success); legacy
  `PATCH /api/v1/orders/{id}/status` delegates to it with its historical
  response shape unchanged. No `/orders/bulk/status` endpoint exists.

## Flow: Payment Callback (online)

```
Payment Gateway
  |
  ANY /api/v1/general/checkout/callback?paymentId=xxx     (public, no auth)
  |
  v
OrderController@checkoutCallback(Request)
  |
  +-- resolve transaction by gateway_transaction_id / invoice_id
  +-- PaymentGatewayFactory::make(...) → Gateway::verifyPayment(paymentId)
  |     |-- validates amount & currency against the order (mismatch blocks; apitest host exempt)
  |
  +-- FAILURE:
  |     |-- transaction.status = failed (+ merged gateway_response, error_message)
  |     |-- event(App\Events\PaymentFailed)               → queued user/admin notifications
  |     |-- mobile JSON or redirect to /payment/failed
  |
  +-- SUCCESS (DB::transaction, idempotent — skipped if locked order is not 'pending'):
        |-- transaction → paid + paid_at
        |-- order.payment_status = payment-success, paid_at = now()
        |-- inventory finalized (cart finalize, else per-order deduct)
        |-- promotion usage finalized
        |-- OrderService::changeOrderStatus(invoice_id, 'completed')
        |       └── fires OrderStatusChanged (and side effects above)
        |
        after commit:
        |-- event(App\Events\PaymentSucceeded(order->fresh()))   // exactly once ($processed flag)
        |
        v
        mobile JSON or redirect to /payment/success
```

Related flows using the same service:

- **COD:** admin `POST /checkout/cod/{orderId}/mark-paid` → `markCodAsPaid()` (transaction→paid, order→completed, coupon/promotion/inventory finalized, `PaymentSucceeded` fired inside the transaction).
- **Cashier QR:** same shape via `POST /checkout/cashier/{orderId}/mark-paid`.
- **Unpaid timeout:** console command `CancelUnpaidOrders` locks stale orders and dispatches `OrderCancelled` + `PaymentFailed`.
