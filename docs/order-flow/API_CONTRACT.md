# Order Flow API Contract

> CURRENT REAL contract, traced from code in `D:\work\meem`. Every endpoint
> below was verified against `routes/`, controllers, FormRequests,
> services, and Resources. Anything without an HTTP surface is marked
> `INTERNAL ONLY`; anything genuinely absent is marked `NOT IMPLEMENTED`
> (with reason). Response envelope throughout:
> `{status, message, success, data?}` (service envelope) unless noted.

## 1. Scope

Admin creates/configures order lifecycle → frontend discovers available
configuration → customer checks out → backend pins the flow → order
lifecycle (status/payment/inventory/shipment/tracking/cancel/refund).
Covers ONLY the order lifecycle surface. Catalog, coupons, promotions,
and digital delivery are referenced only where they intersect checkout
or status side effects.

## 2. Actors

| Actor | Auth | Identification |
|---|---|---|
| Guest | none | `GET order-flows/available`, public catalogs, `POST track-order` (order_number + email/phone), payment callbacks/webhooks (provider-signed) |
| Customer | `auth:sanctum` | owns `orders.user_id`; order-scoped reads, checkout, self-cancel |
| Staff/Admin | `auth:sanctum` + Spatie permissions | flow config, status mutation, shipments, refunds, tracking dashboard |
| System | none (trusted context) | gateway callbacks, expiry reaper, queue listeners, reconcile command |

## 3. Concepts

### shipping_type

Controlled business discriminator: exactly `local` | `international`
(`OrderFlowService::SUPPORTED_SHIPPING_TYPES`). Defined in code, NOT
admin-creatable. Validated twice: `OrderCreateRequest` allow-list
(`Rule::in`, normalized trim/lowercase, empty→null→local default) and
fail-closed resolution (`resolveFlowForShippingType`). Scope is global
(no tenant/store columns anywhere on flow tables).

### Flow

One ACTIVE `order_flows` row per `shipping_type` (DB unique).
`shipping_type` = routing identity. `code` = human/admin label, never
used for routing. `is_default` = preserved marking, never read by
resolution. `is_active=false` = unavailable for NEW orders; existing
orders stay bound to stored `flow_id` and continue. Deactivating the
LAST active flow per type → 422 (`flow_deactivate_last_active`).

### Status

Three separate concepts: **global catalog** (`order_statuses`: stable
`code`, bilingual `name`, `is_active`; 22 codes in
`ALL_STATUS_CODES`) → **flow membership** (`order_flow_statuses`:
array order IS the transition map via `sort_order`) → **current
status** (`orders.current_status_id` + `orders.status` mirror, written
only at creation and in `changeOrderStatus`). Terminal set (central,
`OrderFlowService::TERMINAL_STATUSES`): `delivered, cancelled,
completed, returned`. Transitions = flow immediate-successor +
supervised exits (`completed` from any non-terminal; `cancelled`
except from completed/delivered/cancelled; `failed_delivery`/`returned`
around `out_for_delivery`) UNION legacy map (intentional, parity-gated).

### Flow Input

Definition (`flow_inputs`: immutable `key`, generic `type`, optional
`source` pointer, `required` + `required_at` = `checkout` |
`transition:<code>`, bilingual presentation, `sort_order`,
`validation`) → runtime validated value → business copy in owning
column (`from_country→origin_country_id`,
`to_country→destination_country_id`,
`customs_reference→customs_reference`) + immutable audit row in
`order_flow_values` per (order, key, context). Inactive definition =
retired (ignored, never demanded).

## 4. Admin Flow APIs

Auth: `auth:sanctum` + `throttle:admin`. Permissions below are OR-ed
with legacy order permissions as a deliberate compat bridge.

### `GET /api/v1/admin/order-flows`

Purpose: paginated flow list with ordered statuses. Filters:
`shipping_type`, `is_active`, `search` (code/name), `per_page` 1–200
(default 50). Permission: `view-order-flows|view-orders|view-order`.
Response 200: `{data: [OrderFlowResource], meta: {current_page,
per_page, total}}`. Next: `GET {id}` for inputs detail.

### `POST /api/v1/admin/order-flows`

Purpose: create one flow for one shipping type (full admin shape incl.
ids/flags). Permission: `create-order-flows|update-order-status`.
Body: `{code (unique, ≤50), name (string or {en,ar}, en required),
shipping_type (local|international, unique), is_default?, is_active?,
status_ids (ordered array ≥1 of existing ACTIVE status ids)}`.
Validation 422: duplicate code/shipping_type, empty/duplicate/unknown/
inactive status ids. Response 201: flow resource with statuses. Next:
`POST {id}/inputs`.

### `GET /api/v1/admin/order-flows/{id}`

Purpose: full flow detail + ordered `inputs[]`. Permission: view
family. 404 when missing. Next: edit or manage inputs.

### `PUT /api/v1/admin/order-flows/{id}`

Purpose: rename/flags/status-reorder. All fields optional.
`shipping_type` change → 422 (identity immutable; create a new flow).
Removing statuses holding in-flight (non-terminal) orders → 422.
Deactivating the last active flow per type → 422. Permission:
`update-order-flows|update-order-status`. Response 200: updated
resource. No `DELETE` exists by design (deactivate instead).

## 5. Admin Status APIs

### `GET /api/v1/admin/order-statuses` (+ `?search&is_active&per_page`)

Purpose: browse the global catalog. Permission: view family. Response
200: `{data: [{id, code, name{en,ar}, description, is_active}], meta}`.

### `GET /api/v1/admin/order-statuses/{id}`

Purpose: single catalog row. Same view permission. 404 when missing.

### `PUT /api/v1/admin/order-statuses/{id}`

Purpose: display/activation ONLY. Body: `{name (string|{en,ar}),
description (≤500|null), is_active}`. `code` is immutable (not
accepted). Deactivating a status held by in-flight orders → 422
(`flow_status_inflight_block`). Permission:
`update-order-flows|update-order-status`. No POST/DELETE: catalog codes
are program vocabulary. Next: assign to flows via flow update
(`status_ids` order = lifecycle).

Catalog vs membership: catalog = vocabulary (what codes exist);
membership = per-flow ordered participation (what moves where).
Inactive catalog rows cannot join flows and cannot be entered.

## 6. Admin Flow Input APIs

### `GET /api/v1/admin/order-flows/{flowId}/inputs` (`?is_active`)

Purpose: ordered input definitions for a flow. Permission: view
family. Response 200: `FlowInputResource[]` ordered by `sort_order`.

### `POST /api/v1/admin/order-flows/{flowId}/inputs`

Purpose: bulk create MANY definitions in ONE atomic transaction.
Contract is always `{inputs: [...]}` (1–100 items). Permission:
`manage-order-flow-inputs|update-order-status`. Per item: `key
(^[a-z][a-z0-9_]{1,49}$, unique per flow+request, IMMUTABLE after
create)`, `label{en required,ar?}`, `placeholder{en,ar}?`,
`help_text{en,ar}?`, `type ∈ text|number|boolean|date|select|
multi_select`, `source ∈ countries|governorates|warehouses|
pickup_locations|null`, `required?`, `required_at = checkout |
transition:<catalog-code>?`, `sort_order 1–1000?` (omitted slots
allocate sequentially), `validation{options≤200,min,max,pattern}?`,
`is_active?`. Any single failure rolls back everything. Adding a
REQUIRED input while in-flight orders exist → 422. Response 201:
created collection. Next: verify via public definition endpoint.

### `PUT /api/v1/admin/order-flow-inputs/{id}`

Purpose: edit one definition (`key` rename → 422 immutable).
Deactivating/unrequiring a REQUIRED input with in-flight orders → 422.
Same permission. Response 200: updated resource.

### `DELETE /api/v1/admin/order-flow-inputs/{id}`

Purpose: delete one definition. Deleting a REQUIRED input with
in-flight orders → 422. Same permission. Response 200: confirmation.

## 7. Customer Flow Discovery

### `GET /api/v1/general/order-flows/available` (canonical)

Auth: NONE (guest-safe). Purpose: ONE call returns every ACTIVE flow
so the frontend can render the shipping-type selector AND all dynamic
inputs. Sanitized contract — exposes `shipping_type, code,
name{en,ar}, statuses[{code, name{en,ar}, sort_order}],
inputs[{key, label{en,ar}, placeholder{en,ar}, help_text{en,ar}, type,
source, required, required_at, sort_order, validation}]`. NEVER
exposes `id, flow_id, is_active, is_default`, order values, or PII.
Response 200: `{flows: [...]}`. Frontend answers: available types,
per-type required/optional inputs + validation, what to send to
checkout (`shipping_type` + `flow_values`). Next: checkout.

### `GET /api/v1/general/order-flows/by-shipping-type/{type}`

Auth: `auth:sanctum` (kept for backward compatibility). SAME sanitized
contract for one flow. Unknown type or supported-but-inactive type →
422 (`shipping_type_unsupported` / `shipping_type_unavailable`).

## 8. Checkout

### `POST /api/v1/general/checkout`

Auth: `auth:sanctum`. Purpose: create (or retry) the order, then hand
to payment by method. Real body (`OrderCreateRequest`): `{name*,
user_phone*, user_email?, address (required for delivery of physical
goods), notes?, selected_promotion_id?, selected_gift_product_id?,
type (mobile|web)?, fulfillment_type (delivery|pickup)?,
payment_method (online|cod|pay_at_cashier)?, gateway?, shipping_type
(local|international, default local)?, flow_values (object, conditional)?,
governorate_id (conditional), pickup_location_id (conditional)}`.
`flow_id` is NEVER accepted as authoritative (ignored for routing).
Responses: online → 200 `{url}` (gateway redirect); cod → 200
`{order_id}`; cashier → 200 `{order_id}`; zero-value online → 200
`{order_id}` (completes locally). Errors: 400 cart empty; 401; 422
validation OR flow-input envelope `{errors: {key: [...]}}` (zero
writes); 500 creation failure.

### `POST /api/v1/general/fast-shipping/checkout`

Auth: `auth:sanctum`. Same shape via `FastCheckoutRequest` except
`shipping_type` is local-only (`Rule::in([local])`) and the service
FORCES local resolution server-side. Adopting an international pending
order → 422 (`fast_pending_order_conflict`).

## 9. Order APIs

Customer resource = `App\Http\Resources\Order\OrderResource`
(sanitized: flow/current_status WITHOUT ids/flags; includes
`payment_status`, `fulfillment_status`). List (`GET
/api/v1/general/orders`) loads `flow` + `currentStatus` WITHOUT stages
(`flow.statuses = null`, flat queries). Detail (`GET
/api/v1/general/orders/{id}`, owner) adds `flow.statuses[]` ordered.
Admin resource = `Marvel\Http\Resources\Order\OrderResource` (full
shape incl. ids/flags, `customer`, `transactions`, `available_statuses`
dropdown targets). Admin list/detail: `GET /api/v1/orders`,
`GET /api/v1/orders/{id}` (`view-orders|view-order`). Order detail
fields: `id, order_number, status (+legacy mirror), shipping_type,
flow{code,name,shipping_type,(statuses on detail)},
current_status{code,name,sort_order}, payment/fulfillment state,
items, totals/tax/currency, invoice_summary, timestamps`.

### `GET /api/v1/general/orders/{orderId}/invoice`

Auth: owner. Purpose: latest customer invoice (canonical; legacy uuid
route removed). 404 when none (pending) or foreign.

## 10. Status APIs

### `GET /api/v1/orders/{id}/statuses`

Auth: `auth:sanctum`; owner or `view-orders|view-order`. Purpose:
"what can THIS actor do to THIS order RIGHT NOW?" Response 200:
`{current_status{code,name}, flow{code,shipping_type},
statuses[{code, name, sort_order, transition_allowed, permitted,
allowed (=AND), permission: "change-order-status.<code>",
reason ∈ forbidden_transition|missing_permission|inactive_status|null,
requires_inputs[]}]}`. Advisory: PATCH revalidates everything. Next:
collect `requires_inputs`, then PATCH.

### `PATCH /api/v1/orders/status` (canonical)

Auth + `permission:update-order-status`. Body: `{order_ids (1–50,
distinct ints; existence NOT pre-validated), status ∈ 22 catalog
codes, flow_values?}` (one common object applied per order). Pipeline
per order, own transaction: granular assert →
`changeOrderStatus()` (flow union guard → input gate → F-1 →
mutation+mirror+history+side effects). Processed batches always HTTP
200: `{summary{total,succeeded,failed}, results[{order_id, success,
status?, current_status{code,sort_order}?, error{code,message,
details?}?}]}`. Error codes: `order_not_found,
missing_permission, forbidden_transition, missing_flow_input,
unknown_flow_input, invalid_flow_input, payment_permission_required,
internal_error`. Malformed shape → 422, zero writes. MAX_BATCH = 50
(synchronous ceiling; queued bulk is a separate decision).

### `PATCH /api/v1/orders/{id}/status` (legacy, delegate)

Same middleware + `OrderStatusUpdateRequest{status, flow_values?}`.
Delegates to the SAME orchestrator; translates the single result to
the historical contract: 404 / 403 / 422 (+`errors.details` for
inputs) / 200 with Marvel `OrderResource`. NOT a second engine.

## 11. Payment APIs

Checkout responses (§8) initialize payment. `POST
/api/v1/general/checkout/cod|/cashier/{orderId}/mark-paid` (auth +
`payments.mark_paid`): confirm pending COD/cashier transaction →
canonical `completed` (commit, coupon, events). Unpaid → `completed`
by anyone else → blocked (F-1). `GET
/api/v1/general/payment-gateways` (public availability snapshot);
`PUT /api/v1/admin/payment-gateways/{code}` (`update-settings`).
`POST /api/v1/admin/payments/{order}/refund`
(`payments.refund`): gateway refund, full/partial, idempotency-keyed.
Payment NEVER mutates order status except through `changeOrderStatus`
(callbacks pass `emitPaymentSuccess=false` and use the same path).

## 12. Fulfillment State

`fulfillment_status` on the order is a mirror owned by the status
pipeline (`processing` on leave-pending, `cancelled`,
`delivered`). The `fulfillments` domain (release/picking/packing
services, `FulfillmentTransition` single owner) has
**NOT CURRENTLY EXPOSED** HTTP surface and no production callers —
dormant phase. Release rule (for the coming phase): capture methods
need COMMITTED+paid; deferred need ACTIVE+pending; idempotent per key.

## 13. Tracking APIs

### `POST /api/v1/general/track-order` (public)

Body: `{order_number*, user_email|user_phone*}`. Response:
`{order{id,order_number,status,payment_status,fulfillment_status,…},
timeline (customer-visible events), current_status{status,payment,
fulfillment,label,description,icon,color,progress},
estimated_delivery}`.

### `GET /api/v1/general/orders/{orderId}/track` (owner)

Same + full timeline, `can_cancel` (pending/processing + unpaid),
`progress{stages[order_placed,payment_confirmed,preparing_shipment,
shipped,out_for_delivery,delivered], progress_percentage}`.
Pending-online orders get tiered `verification_message`.

### `GET /api/v1/general/my-orders`

Owner paginated list with `payment_status`, `fulfillment_status`,
`last_update`, `status_info`, `tracking_url`.

### Admin tracking (`auth` + own `view-orders` check)

`GET /api/v1/admin/tracking/dashboard` (overview, breakdown, revenue),
`GET .../tracking/orders` (filtered/paginated, whitelisted sort),
`GET .../tracking/orders/{orderId}`, `GET .../requires-attention`.

Tracking relates: order status = lifecycle position; shipment events
feed the timeline/progress; fulfillment mirror feeds estimates.

## 14. Shipment APIs

Staff shipment objects (`auth`, `view/create/update-shipment` per
action): `GET/POST /api/v1/shipments`, `GET .../uuid/{uuid}`,
`GET|PUT .../{id}`, `PUT .../{id}/status` (enum-validated +
`canTransitionTo` guard, 422 otherwise). Order shipment fields
(admin): `GET /api/v1/admin/orders/{orderId}/shipment`
(`view-shipment|view-shipments|view-orders|view-order`) and `POST
.../update-status` (`update-shipment|create-shipment`) — permission
middleware REQUIRED (SEC-1: previously absent). Mutation applies via
`ShipmentStatusChanged` listener (timeline row + order columns +
`actual_delivery_at` on delivered). `orders.shipment_status`
transitions are unguarded by design (admin ops field, audited).
Carrier chain helpers (`dispatch`, `markDelivered`,
`maybeCompleteOrder` → canonical `delivered`) are
**INTERNAL ONLY** (no HTTP; reached via packing chain/tests).

## 15. Cancellation APIs

- Customer: `POST /api/v1/general/orders/{id}/cancel` (owner;
  pending/processing + unpaid only; 404/422/401/200 with order
  resource). Delegates to canonical pipeline (inventory + coupon
  release, history, events).
- Staff: `PATCH /orders/status → cancelled` (dual permission;
  paid+committed → stock restore; unpaid → release).
- Expiry: `orders:cancel-unpaid` INTERNAL (24h online/cashier, 7d COD;
  gateway paid-check, lock + re-check, intentional pipeline bypass
  minus promo decrement, ORD-1).
Double cancel: staff path is a legacy-union no-op; customer path 422s
(nothing to cancel).

## 16. Invoice APIs

Generated exactly once on first leave-pending (idempotent service;
failures never block status). Customer: `orders/{id}/invoice`,
`invoices/my-invoices`, `verify/{uuid}`, signed view/download.
Admin (auth): show/regenerate/correct/cancel/debit-note by id/uuid.
Credit notes on refund approval (listener); digital revocation on
refund approval (listener).

## 17. Refund APIs

- `POST /api/v1/admin/payments/{order}/refund` (`payments.refund`):
  gateway money movement, idempotency-keyed, duplicate-callback safe.
- Marvel `refunds` apiResource (auth + throttle, internal role
  checks): customer submit (`CUSTOMER` role), admin approve/reject →
  wallet credit + shop deduction + credit note + timeline.
- `GET /api/v1/refunds[/{id}]` read paths with same internal checks.

## 18. Callback/Webhook APIs

- `GET|POST .../checkout/callback`, `.../error-callback` (public,
  throttled): MyFatoorah browser flow — MyFatoorah has NO webhook by
  design.
- `POST .../checkout/webhooks/stripe|paypal` (public, throttled,
  signature-verified): `PaymentCompletionService` — idempotency token
  primary, order-pending secondary, amount/currency match; duplicates
  held for reconciliation (D-06), never double-completed; completion
  runs the canonical `completed` path.
- Zero-value online orders complete locally (paid zero-amount txn, no
  gateway call).

## 19. Internal Commands

| Command | Purpose | State changed | Next |
|---|---|---|---|
| `orders:cancel-unpaid` | reap expired unpaid pendings | reservation→released, status→cancelled (+history/events) | none (terminal) |
| `payments:reconcile` | reconcile gateway vs ledger | reconciliation records | manual review on mismatch |
| `picking:sweep-expired-claims` | release stale picking leases | task claims | re-claimable tasks |
| `inventory:migrate-reservations` | backfill/fix reservation columns | report or reservation fields | — |
| coupon/currency schedulers | claims expiry, rate sync, distribution | coupon/currency domains | — |

All INTERNAL ONLY. Scheduler wiring lives in `app/Console/Kernel.php`.

## 20. Complete Lifecycle

```text
ADMIN
 ↓ POST /api/v1/admin/order-flows (shipping_type=local|international, status_ids ordered)
 ↓ POST .../{id}/inputs ({inputs:[...]}) · PUT catalog/flags
 ↓ flow active
FRONTEND
 ↓ GET /api/v1/general/order-flows/available (guest)
 ↓ customer chooses shipping_type → render required inputs (+validation/sources)
CHECKOUT
 ↓ POST /api/v1/general/checkout {shipping_type, flow_values, cart, payment...}
 ↓ normalize→allow-list→active-Flow→validate inputs→create→pin(shipping_type,flow_id,current_status_id)→reserve→payment-init
ORDER RESPONSE (customer OrderResource: no ids/flags; payment_status+fulfillment_status included)
 ↓ current_status + flow.stages (detail) → GET /api/v1/orders/{id}/statuses (allowed actions)
 ↓ PATCH /api/v1/orders/status {order_ids,status,flow_values?} (dual permission; per-order results)
 ↓ payment callbacks → completed (F-1) → inventory commit → invoice/coupons/notifications
 ↓ shipment APIs / timeline → tracking (progress, can_cancel, ETA)
 ↓ POST .../orders/{id}/cancel (customer) | PATCH→cancelled (staff) | expiry reaper | refunds
```

## 21. Master API Table

| Actor | Method | Endpoint | Purpose | Request | Response | Next |
|---|---|---|---|---|---|---|
| Admin | GET | /api/v1/admin/order-flows | list flows | filters | paginated resources | show |
| Admin | POST | /api/v1/admin/order-flows | create flow | code/name/type/status_ids | 201 resource | inputs |
| Admin | GET | /api/v1/admin/order-flows/{id} | flow+inputs detail | — | resource | edit |
| Admin | PUT | /api/v1/admin/order-flows/{id} | update/reorder/deactivate | partial + guards | 200/422 | verify |
| Admin | GET | /api/v1/admin/order-flows/{f}/inputs | list inputs | ?is_active | ordered defs | create/edit |
| Admin | POST | /api/v1/admin/order-flows/{f}/inputs | bulk create inputs | {inputs:[...]} | 201 collection | discovery check |
| Admin | PUT/DELETE | /api/v1/admin/order-flow-inputs/{id} | edit/delete input | partial / — | 200 / 422-guarded | — |
| Admin | GET | /api/v1/admin/order-statuses[/{id}] | catalog browse | filters | resources | assign to flow |
| Admin | PUT | /api/v1/admin/order-statuses/{id} | rename/describe/activate | partial | 200/422-guarded | — |
| Customer | GET | /api/v1/general/order-flows/available | discover (guest) | — | {flows:[...]} | select type |
| Customer | GET | /api/v1/general/order-flows/by-shipping-type/{t} | one flow (auth) | — | same contract | checkout |
| Customer | POST | /api/v1/general/checkout | create/retry order | §8 body | {url}\|{order_id} | pay/track |
| Customer | POST | /api/v1/general/fast-shipping/checkout | local fast order | local-only body | same handlers | pay/track |
| Customer | GET | /api/v1/general/orders | list (light) | ?status&limit | collection | detail |
| Customer | GET | /api/v1/general/orders/{id} | detail (stages) | — | OrderResource | statuses/cancel |
| Customer | POST | /api/v1/general/orders/{id}/cancel | self-cancel | — | 200/404/422 | track/refund-path |
| Customer | GET | /api/v1/orders/{id}/statuses | allowed actions | — | candidates+reasons | PATCH |
| Staff | PATCH | /api/v1/orders/status | canonical mutation 1..50 | order_ids/status/values | summary+results | detail/shipment |
| Staff | PATCH | /api/v1/orders/{id}/status | legacy delegate | status/values | legacy shapes | — |
| Staff | POST | /api/v1/general/checkout/cod\|cashier/{id}/mark-paid | confirm payment | reason? | completed path | completed |
| Customer | GET | /api/v1/general/orders/{id}/track | tracking+progress | — | timeline/can_cancel/ETA | cancel/wait |
| Customer | GET | /api/v1/general/my-orders | tracked list | ?per_page | states+tracking_url | track |
| Guest | POST | /api/v1/general/track-order | public tracking | number+email/phone | order+timeline | support |
| Customer | GET | /api/v1/general/orders/{id}/invoice | invoice | — | CustomerInvoiceResource | pay/verify |
| Staff | GET/POST/PUT | /api/v1/shipments… | shipment objects | §14 bodies | ShipmentResource | dispatch chain |
| Admin | GET/POST | /api/v1/admin/orders/{id}/shipment… | order ship fields | status/tracking/courier | gated read/mutation | timeline |
| Admin | POST | /api/v1/admin/payments/{o}/refund | gateway refund | amount/reason/key | refund result | records |
| User | GET/POST… | /api/v1/refunds… | refund records | role-checked | wallet/credit flow | approval |
| Public | ANY | checkout/callback*, webhooks/* | provider flows | signed payloads | idempotent completion | completed |
| Admin | GET | /api/v1/admin/tracking/… | ops dashboard | filters | stats/orders | action |
| System | — | orders:cancel-unpaid etc. | reapers | schedule | state effects §19 | — |

## 22. Frontend Implementation Map

Checkout screen → `GET available` → receive local+international →
select type → render inputs (type/source/required/validation) →
`POST checkout {shipping_type, flow_values}` → `{url}` (redirect to
gateway) or `{order_id}` (COD/cashier/zero) → show status.
Order details → `GET orders/{id}` (status, payment, fulfillment, flow
stages, position) → `GET orders/{id}/statuses` (staff actions) /
`GET orders/{id}/track` (progress, ETA, can_cancel).
Payment → callback/webhook (no frontend action) → poll detail/track.
Tracking → `track`/`my-orders`/public `track-order`.
Cancellation → `POST orders/{id}/cancel` (owner, unpaid) → cancelled
detail; paid → support/refund path (422 message).
Invoice → `orders/{id}/invoice` → verify/signed PDF.
Refund → `POST refunds` (customer request) → admin approval → wallet
credit + notifications.

## 23. Implemented vs Deferred

IMPLEMENTED: everything in §21 except below. DEFERRED (reasoned, not
missing-by-accident): fulfillment/picking/packing/return HTTP surface
(services exist + tested at service level, zero production callers —
next phase surfaces them); customer shipment-object read (staff-only
by design; timeline covers customers); `dispatch`/`markDelivered`
HTTP (packing-chain/internal only); queued bulk mutation (sync
ceiling of 50 documented). INTENTIONAL ABSENCES: flow DELETE, status
POST/DELETE, dynamic shipping types, tenancy, versioning, edges table.

## 24. Verification

- Routes: PASS (`route:list` for orders/checkout/shipment/return/
  refund/fulfillment/payment/tracking; fulfillment/picking/packing =
  zero routes confirmed).
- Requests: PASS (Upsert/Batch/Bulk/Input/Catalog/Shipment/Create/
  OrderCreate/OrderStatusUpdate/FastCheckout rules read verbatim).
- Responses: PASS (Available/OrderFlow/FlowInput/Status/Options/
  customer+admin Order/Shipment/CustomerInvoice resources read;
  handler payloads `{url}`/`{order_id}` verified; legacy delegate
  mapping verified).
- Services: PASS (resolution/assignment/transition/validator/
  batch envelope+codes/completion idempotency/reservation machine/
  shipment guards/listener-applied admin mutation/reaper bypass
  documented).
- Tests: PASS (FinalArchitectureTest 30, CustomerLifecycleContractTest
  10, OrderStatusFlowTest, UnifiedOrderStatusTest, StatusOptionsTest,
  FlowInputsTest, OrderLifecycleResponseTest, ShipmentBoundaryTest —
  green on sqlite except one pre-existing sqlite-only legacy-mirror
  artifact; neighbor failures proven identical with/without changes;
  MySQL canonical re-verification required before release).
