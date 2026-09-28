# GLOBAL ORDER FLOW + DYNAMIC INPUT SYSTEM — AUDIT & IMPLEMENTATION PLAN

> Classification: DISCOVERY / AUDIT — no production code modified.
> Date (UTC): 2026-09-28
> Scope gate: TARGET REPOSITORY = `D:\work\meem` (Laravel monolith: `app/` + vendored `packages/marvel/`).
> Method: repository evidence only. Every material claim cites file path + symbol. Unverified items are marked NOT VERIFIED.

---

# 1. Executive Summary

**What exists (VERIFIED):**

1. A **configurable Order Status Flow system already exists** — it is NOT greenfield:
   - Tables: `order_statuses` (global catalog), `order_flows` (one row per `shipping_type`), `order_flow_statuses` (ordered membership, `sort_order` IS the transition map).
   - Migrations: `database/migrations/2026_09_28_000001_create_order_status_flow_tables.php`, `2026_09_28_000002_extend_orders_with_flow.php`, `2026_09_29_000001_widen_order_status_enum_for_global_catalog.php`.
   - Authority: `app/Services/OrderFlow/OrderFlowService.php` (catalog seed, flow seeds, `resolveFlowForShippingType`, `assignFlowToOrder`, `allowsFlowTransition`, `validateFlowStatuses`, `nextStatus`).
   - Admin API: `GET/POST/PUT v1/admin/order-flows` (`app/Http/Controllers/Api/Admin/OrderFlowController.php`), `GET/PUT v1/admin/order-statuses` (`OrderStatusCatalogController.php`).
   - Orders carry `shipping_type` (default `local`) + `flow_id` + `current_status_id`; `orders.status` is the backward-compatible mirror of `current_status_id`.
   - Transition enforcement is **union semantics**: legacy map OR flow-immediate-successor, in `App\Services\General\OrderService::changeOrderStatus()` — the single authoritative path.
2. **No Dynamic Input / Flow Input system exists.** Verified 0 matches repo-wide for `flow_input|FlowInput|flow_definition|dynamic.?field|custom.?field|field_definition|form_schema|input_definition` (lean-ctx regex + grep, `app/` + `packages/marvel/`). No `from_country/to_country/origin_country/destination_country` columns exist anywhere (only the status *code* `arrived_at_destination_country`).
3. **Flow ownership today = `shipping_type` on Order** (`local` | `international` only, allow-listed in `OrderFlowService::SUPPORTED_SHIPPING_TYPES`, unique per flow). It is NOT owned by payment method, store/tenant, product type, or delivery method.
4. **Definition vs runtime data are already separated for statuses** (catalog/flows = definition; `orders.status/flow_id/current_status_id` + `order_status_history` = runtime) — but there is **no input-definition layer at all**, so the proposed `Flow → statuses + inputs` design is a *net-new subsystem* grafted onto a working flow system.

**Recommendation (preview, §21):** KEEP the existing status-flow system; ADD a new `flow_inputs` table (Option A / hybrid) keyed to `order_flows.id`, with generic types (`text|number|boolean|date|select|multi_select`) + `source` references (`countries|governorates|warehouses|…`), NOT domain-primitive types (`country`, `city`). Persist *values* into their owning domains (address/country/governorate/shipment metadata), never into a blob on `orders`. Expose definition via `GET v1/order-flows/by-shipping-type/{type}` (new, customer-safe) alongside existing admin CRUD. Version flows immutably (draft→published→archived) because current flows are MUTABLE with only an in-flight guard.

---

# 2. Current Order Architecture

## 2.1 Canonical ownership

| Concept | Owner | Evidence |
|---|---|---|
| Order persistence | Marvel kernel | `packages/marvel/src/Database/Models/Order.php` (table `orders`, SoftDeletes, global `orderBy created_at desc` scope) |
| Order creation (app checkout) | `app/` | `app/Services/Checkout/OrderCreationService.php::createOrder()`, orchestrated by `app/Services/General/OrderService::addItemsInOrder()` |
| Order status authority | `app/` | `App\Services\General\OrderService::changeOrderStatus()` — sole writer of `status` + `current_status_id` + history + side effects |
| Legacy status writes | Marvel (still present, secondary) | `packages/marvel/src/Traits/OrderManagementTrait::changeOrderStatus()`, `OrderStatusManagerWithPaymentTrait`, `OrderRepository::updateOrder()` + `syncOrderStatusColumn()` |
| Flow definition authority | `app/` | `App\Services\OrderFlow\OrderFlowService` |
| Shipment machine | `app/` | `app/Models/Shipment.php::allowedTransitions()`, `app/Http/Controllers/Api/ShipmentController.php`, `app/Services/.../ShipmentService` |
| Fulfillment machine | `app/` | `app/Models/Fulfillment/Fulfillment.php` (+ `Warehouse`, `Package`, `PickingTask`, `PackingTask`, `ReturnRequest`) |
| Pricing authority | centralized | `Marvel\Services\Pricing\ProductPricingService` (used by `OrderCreationService::createOrderItems()`); tax via `App\Services\Tax\TaxCalculator` + `OrderService::withTaxes()` |
| Inventory reservation | `app/` | `App\Services\Inventory\OrderReservationService` (reserve/commit/release), `InventoryRestoreService` |

## 2.2 Request flow (verified)

```text
POST checkout (Marvel\Http\Requests\OrderCreateRequest)
  → App\Http\Controllers\Api\General\OrderController::checkout()
      merges fulfillment_type/payment_method/payment_gateway/shipping_type
  → App\Services\General\OrderService::addItemsInOrder()
      → OrderCreationService::createOrder() → assignFlowToOrder() (fail-closed)
      → createOrderItems() → reserveForOrder() → clearCheckedOutSlice()
      → finalizeOrder() dispatches App\Events\OrderCreated
```

Admin status change:

```text
PATCH orders/{id}/status (Marvel\Http\Requests\OrderStatusUpdateRequest)
  → Marvel\Http\Controllers\Order\OrderController::updateStatus()
  → OrderService::changeOrderStatus()  ← union guard (legacy map + flow)
      → mirror current_status_id, payment/fulfillment sync, history row,
        invoice (first leave-pending), inventory commit/release,
        coupon/promotion finalize, OrderStatusChanged/Cancelled/Delivered/PaymentSucceeded events
```

## 2.3 Files examined (core)

- `packages/marvel/src/Database/Models/Order.php` — fillable/casts/relations/`recordStatusChange()`
- `packages/marvel/src/Database/Repositories/OrderRepository.php` — legacy `storeOrder()` (guest checkout, wallet, COD branches), `updateOrder()`, `syncOrderStatusColumn()`
- `packages/marvel/src/Traits/OrderStatusManagerWithPaymentTrait.php`, `OrderManagementTrait.php`
- `app/Services/General/OrderService.php` (1172+ lines) — `allowedOrderTransitions`, `allowedFulfillmentTransitions`, `getFlowAwareStatusTargets()`, `markCodAsPaid/markCashierPaid`
- `app/Services/Checkout/OrderCreationService.php`
- `app/Services/OrderFlow/OrderFlowService.php` (358 lines)
- `app/Models/OrderFlow/OrderFlow.php`, `OrderFlowStatus.php`, `OrderStatus.php`
- `app/Http/Controllers/Api/Admin/OrderFlowController.php`, `OrderStatusCatalogController.php`
- `app/Http/Controllers/Api/General/OrderController.php` (checkout, `shipping_type` passthrough L106–117)
- `packages/marvel/src/Http/Controllers/Order/OrderController.php` (`updateStatus` → `OrderService::changeOrderStatus`)
- `packages/marvel/src/Http/Requests/OrderCreateRequest.php`, `OrderStatusUpdateRequest.php`
- `app/Http/Requests/Admin/OrderFlowUpsertRequest.php`, `OrderStatusCatalogUpdateRequest.php`
- `app/Models/Shipment.php`, `app/Models/Fulfillment/Fulfillment.php`, `app/Models/OrderStatusHistory.php`
- Migrations listed in §18; `database/seeders/OrderFlowSeeder.php`
- `routes/api.php` L121–257; `packages/marvel/src/Rest/Routes.php` L186/L447
- `tests/Feature/OrderFlow/OrderStatusFlowTest.php`, `OrderFlowMatrixTest.php`

---

# 3. Current Order State Machine

**Source of truth (runtime):** `OrderService::$allowedOrderTransitions` + `$allowedFulfillmentTransitions` + `OrderFlowService::allowsFlowTransition()` (union). Linear flow order = `order_flow_statuses.sort_order` array position N→N+1 (`OrderFlowController::syncStatuses()` docblock).

## 3.1 Seeded flows (from `OrderFlowService::flowsSeed()`)

```text
LOCAL (default):        pending → processing → packed → shipped → out_for_delivery → delivered
INTERNATIONAL (default): pending → processing → packed → shipped → in_transit
                         → arrived_at_destination_country → customs_clearance
                         → customs_cleared → local_carrier → out_for_delivery → delivered
```

## 3.2 Legacy order map (`OrderService` L666–672)

```text
pending    → pending | processing | completed | cancelled
processing → processing | completed | cancelled
completed  → completed | delivered
delivered  → delivered            (terminal)
cancelled  → cancelled            (terminal)
```

## 3.3 Flow-supervised exits (`OrderFlowService::allowsFlowTransition()` L267–305)

- `→ completed`: allowed from ANY non-terminal flow status (COD paid-on-delivery milestone).
- `→ cancelled`: allowed from anything EXCEPT `completed` (refund keeps `completed`), `delivered`, `cancelled`.
- `→ failed_delivery`: only from `out_for_delivery`.
- `→ returned`: only from `failed_delivery | out_for_delivery`.
- `delivered | cancelled` are terminal sources (`from` rejected).
- Logistics steps: ONLY immediate successor in the order's flow + target must be `is_active` (fail-closed).
- `from == to` (no-op re-set) always allowed at flow layer.

## 3.4 Transition table (abridged; Source column = enforcing code)

| Current | Next | Actor | Validation | Side effects | Source |
|---|---|---|---|---|---|
| `pending` | `processing` | admin (`update-order-status`) / system | legacy map OR flow successor | fulfillment→processing; invoice generated (first leave-pending, idempotent); history row | `OrderService::changeOrderStatus` L756–964 |
| `pending` | `completed` | gateway callback / `payments.mark_paid` holder | legacy map OR flow exit; F-1 blocks unpaid+unauthorized | payment_status=success, paid_at, inventory commit, promotion finalize, coupon usage, `PaymentSucceeded` | L792–804, L819–827, L966–1061 |
| any non-terminal | `completed` | same | flow exit `true` | same as above | `OrderFlowService::allowsFlowTransition` L285–287 |
| any (≠completed/delivered/cancelled) | `cancelled` | admin/system | flow exit | unpaid→release reservation + coupon release + promotion decrement; paid+committed→inventory restore; `OrderCancelled` | L1016–1044 |
| `completed` | `cancelled` | — | REJECTED (both layers) | — (refunds keep `completed`) | L281–283 + legacy map |
| `completed` | `delivered` | admin | legacy map | fulfillment→delivered; `OrderDelivered` | L669, L1047–1049 |
| `out_for_delivery` | `failed_delivery` | carrier/admin | flow exit | history + `OrderStatusChanged` | L289–291 |
| `failed_delivery`/`out_for_delivery` | `returned` | admin | flow exit | history | L293–295 |
| logistics N | N+1 | admin | flow `nextStatus()` + target active | `current_status_id` mirror sync, history, logging/metrics | L297–305, L810–817 |
| logistics N | N+2 (skip) | — | REJECTED | — (tested: `test_skipped_status_is_rejected`) | L302–304 |

**Who owns state:** `OrderService::changeOrderStatus()` is the ONLY place writing `status` + `current_status_id` together (L806–817 comment: "Written ONLY here (and at creation)"). Creation path: `OrderFlowService::assignFlowToOrder()` ("The ONLY place that writes the three flow columns on creation", L233–236).

**Every status-change entry point found:**

1. `Marvel\Http\Controllers\Order\OrderController::updateStatus()` → `OrderService::changeOrderStatus()` (admin PATCH).
2. `OrderService::markCodAsPaid()/markCashierPaid()` → `changeOrderStatus(...completed...)` (manual paid, `payments.mark_paid`).
3. Payment callbacks/webhooks (`PaymentCheckoutHandler`, `PaymentCompletionService`, `checkoutCallback`) → `changeOrderStatus(..., assertPaymentAuthority=false)`.
4. `CancelUnpaidOrders` console command (direct update + history, `app/Console/Commands/CancelUnpaidOrders.php` L112–152).
5. Legacy `OrderRepository::updateOrder()` → `OrderManagementTrait::changeOrderStatus()` (Marvel shop-scoped path; writes legacy `order_status` column + `syncOrderStatusColumn()` mirror — parallel implementation, see §6 conflict).
6. Gateway `updatePaymentOrderStatus()` implementations (`packages/marvel/src/Payment/*.php`).

---

# 4. All Existing Order Statuses

## 4.1 Global catalog (`OrderFlowService::ALL_STATUS_CODES`, 22 codes — MUST match `orders.status` ENUM)

`pending, processing, packed, shipped, in_transit, arrived_at_destination_country, customs_clearance, customs_hold, customs_cleared, local_carrier, out_for_delivery, delivered, failed_delivery, returned, completed, cancelled, confirmed, ready_to_ship, ready_for_pickup, picked_up, export_processing, import_processing`

Seed source: `catalogSeed()` L75–103. ENUM widened by `2026_09_28_000002` (15 codes) + `2026_09_29_000001` (+7 custom-flows-only codes). `OrderStatusUpdateRequest` allow-lists exactly `ALL_STATUS_CODES`.

## 4.2 Other status vocabularies in the repo (NOT order lifecycle)

- Marvel legacy enum `packages/marvel/src/Enums/OrderStatus.php`: `order-pending|order-processing|order-completed|order-cancelled|order-refunded|order-failed|order-at-local-facility|order-out-for-delivery|order-ready-for-pickup` (prefixed; used by legacy `OrderRepository::storeOrder()` + `syncOrderStatusColumn()` map L473–484).
- `Order` model constants: `ORDER_STATUS_*` (pending/processing/completed/cancelled/delivered), `PAYMENT_STATUS_*` (payment-pending/success/failed/refunded), `FULFILLMENT_STATUS_*` (pending/processing/ready_for_pickup/out_for_delivery/delivered/cancelled), `SHIPMENT_STATUS_*` (pending/label_created/picked_up/in_transit/out_for_delivery/delivered/failed_delivery/returned/cancelled), `INVENTORY_STATE_*` (none/active/released/committed/restored).
- `Marvel\Enums\PaymentStatus`: `payment-pending|payment-processing|payment-success|payment-failed|payment-reversal|payment-refunded|payment-cash-on-delivery|payment-cash|payment-wallet|payment-awaiting-for-approval`.
- Shipment runtime machine `Shipment::allowedTransitions()`: pending→label_created→picked_up→in_transit→out_for_delivery→delivered, plus delayed/failed_delivery/returned/cancelled branches.
- Fulfillment runtime: `Fulfillment.status` free-text with `pending` scope; lifecycle via timestamps (`picking_started_at … delivered_at`).

---

# 5. Status Classification (mandatory split)

| Status | Classification | Evidence |
|---|---|---|
| pending, processing, packed, shipped, in_transit, arrived_at_destination_country, customs_clearance, customs_hold, customs_cleared, export_processing, import_processing, local_carrier, out_for_delivery, delivered, failed_delivery, returned, confirmed, ready_to_ship, ready_for_pickup, picked_up | **Order lifecycle** (catalog) | `OrderFlowService::ALL_STATUS_CODES`; `orders.status` ENUM |
| completed, cancelled | **Order lifecycle + payment milestone** (dual) | `changeOrderStatus`: completed⇒payment_status=success+paid_at; cancelled-from-completed forbidden |
| payment-pending/success/failed/refunded (+ cash/cod/wallet variants) | **Payment only** | `orders.payment_status`, `Marvel\Enums\PaymentStatus`, transactions table |
| ready_for_pickup/out_for_delivery/delivered/cancelled on `fulfillment_status` | **Fulfillment mirror** | `fulfillmentStatusMap` L836–852; separate `fulfillments` table |
| label_created/picked_up/in_transit/out_for_delivery/delivered/failed_delivery/returned/cancelled/delayed on `shipments.status` | **Shipment only** | `Shipment::allowedTransitions()`; `shipment_status/tracking_number/courier` on orders |
| none/active/committed/released/restored | **Inventory** | `inventory_state` + reservation services |
| refunded / return_requests | **Refund/Return** — deliberately OUTSIDE order flow | `2026_09_29_000001` docblock: "Payment/refund/return/shipment-owned states are DELIBERATELY absent"; `ReturnRequest/ReturnItem` models; `RefundController/RefundRepository` |

> Design rule (verified intent): do NOT fold payment/refund/return/shipment states into Order Flow. Keep them in their subsystems.

---

# 6. Existing Flow/Workflow Infrastructure

**Exists — reuse, do not duplicate:**

| Layer | Path |
|---|---|
| Models | `app/Models/OrderFlow/OrderFlow.php` (`statuses` BelongsToMany via pivot + `memberships`, `orders`), `OrderFlowStatus.php`, `OrderStatus.php` |
| Service | `app/Services/OrderFlow/OrderFlowService.php` |
| Admin controllers | `OrderFlowController.php` (index/show/store/update + `syncStatuses` + `enforceSingleDefault`), `OrderStatusCatalogController.php` (index/show/update name/desc/active only; code immutable once referenced) |
| Requests | `OrderFlowUpsertRequest.php` (`shipping_type ∈ {local,international}`, unique per type; `status_ids` array order = sort_order), `OrderStatusCatalogUpdateRequest.php` |
| Resources | `app/Http/Resources/OrderFlowResource.php`, `OrderStatusResource.php` |
| Routes | `routes/api.php` L248–257 (`v1/admin/order-flows`, `v1/admin/order-statuses`, sanctum + `view-orders`/`update-order-status`) |
| Migrations + seeder | `2026_09_28_000001`, `2026_09_28_000002` (adds `shipping_type/flow_id/current_status_id`, backfills to local flow, ENUM widen), `2026_09_29_000001` (+7 codes), `database/seeders/OrderFlowSeeder.php` (idempotent, additive-only, preserves admin customizations) |
| Tests | `tests/Feature/OrderFlow/OrderStatusFlowTest.php` (22 tests), `OrderFlowMatrixTest.php` (8 tests) |
| Docs | `api-desc/order/flow.md`, `docs/architecture/*` (pricing), `COUPON_*` reports (not flow) |

**Key semantics (verified):**

- `shipping_type` IS the flow identity: `Rule::unique('order_flows','shipping_type')`, immutable after create ("cannot be changed. Create a new flow instead." L120–122).
- Exactly one active default per `shipping_type` (`enforceSingleDefault()`); resolution keys on `shipping_type` + `is_active`, fail-closed (`resolveFlowForShippingType()` throws `shipping_type_unsupported/unavailable`).
- Array order = transition map; removing a status re-links neighbours; removal blocked when in-flight orders sit at removed non-terminal status (L149–182).
- `Order` relations `flow`, `currentStatus` eager-loaded in admin order relations (`OrderController::relations()` L111–121).

**Conflict — parallel legacy implementation (MUST-FIX awareness):** `OrderRepository::updateOrder()` + `OrderManagementTrait::changeOrderStatus()` + `syncOrderStatusColumn()` form a second status-write path using the prefixed Marvel enum. It does NOT consult `allowsFlowTransition()`. Runtime admin path (`Marvel\Http\Controllers\Order\OrderController::updateStatus`) correctly delegates to `OrderService::changeOrderStatus()`, but the Marvel repository path remains callable (shop-scoped updates, tests, future callers) and can bypass flow + F-1 guards. Any Flow-Input work must funnel ALL writes through `OrderService::changeOrderStatus()` and deprecate/guard the Marvel path.

**No generic workflow engine** (no state-machine lib, no `Workflow/StateMachine/Pipeline` classes) — transitions are hand-coded maps. Correct: do not introduce one (§33).

---

# 7. Existing Dynamic Input Infrastructure

**VERIFIED: none.**

- Repo-wide search for `flow_input|FlowInput|flow_definition|dynamic.?field|custom.?field|field_definition|form_schema|input_definition` → **0 matches** (`app/` + `packages/marvel/`).
- No `flow_inputs` table, migration, model, resource, request, or test.
- Closest analogues (NOT reusable as input engines):
  - `orders.address` (array cast), `shipments.origin_address/destination_address/items/metadata` (array casts), `fulfillments.metadata`, `order_status_history.metadata`, `order_tracking_events.metadata` — schemaless JSON bags with NO definition/validation layer.
  - Product `attributes` / variation `attributeProducts` — catalog variant options, not order inputs.
  - `Settings::options` / `order_tax_*` config — server config, not customer-submitted schema.
- Checkout inputs today are **static FormRequest rules** (`OrderCreateRequest`: name/phone/email/address/governorate_id/pickup_location_id/fulfillment_type/payment_method/gateway/shipping_type). No schema endpoint exists.

**Conclusion:** the Input Definition subsystem is greenfield. Do NOT retrofit product attributes or metadata bags; build the Flow Input layer per §21.

---

# 8. Existing Shipping Architecture

| Element | State | Evidence |
|---|---|---|
| `shipping_type` (flow key) | `local` (default) / `international` only | `OrderFlowService::SUPPORTED_SHIPPING_TYPES`; `OrderCreateRequest` allow-list; `normalizeShippingType()` (empty→local) |
| `shipping_method` | `SCHEDULED` / `FAST` (pricing/speed axis, orthogonal to flow) | `Marvel\Enums\ShippingMethod`; `OrderCreationService` defaults `SCHEDULED`; `FastShippingController/Network` |
| Delivery area | `governorate_id` → `shipping_prices` (price + `free_shipping_over`) | `OrderService::resolveShippingPrice()` L423–456; `Marvel\Database\Models\Governorate`, `ShippingPrice` |
| `fulfillment_type` | `delivery` / `pickup` | `Marvel\Enums\FulfillmentType`; checkout merges default `delivery`; COD+pickup rejected; pay_at_cashier forces pickup |
| Pickup snapshot | `pickup_location_id` + denormalized name/address/phone/coordinates | `Order` fillable L84–89; `resolvePickupLocationSnapshot()` |
| Shipments | own table + machine + courier/tracking/addresses/items/weights | `app/Models/Shipment.php`; `ShipmentController/Service`; `AdminShipmentController` (`{orderId}/shipment`) |
| Countries/cities/zones/carriers | **NOT wired to orders** | `Country` model + `CountryController` exist but checkout never references them; NO `shipping_zones`, `carriers` tables; `courier` is free text; NO customs/tax-info fields on orders/shipments |
| International support | **Status-level only** (customs steps in flow), NO data-level | International flow has customs statuses; but NO origin/destination country columns, NO duties/tax columns, NO zone tables — this is exactly the gap Flow Inputs must fill |

---

# 9. Existing International Order Support

- **Exists:** international *lifecycle* (11-step seeded flow with `in_transit → arrived_at_destination_country → customs_clearance → customs_cleared → local_carrier`); `shipping_type=international` selection at checkout (`OrderController::checkout` L106, `OrderCreateRequest` L70–74); fail-closed availability.
- **NOT FOUND:** `origin_country`, `destination_country`, `origin_address`, `destination_address` (as order-level customs fields), `customs_reference`, `duties/taxes`, `shipping_zone`, `carrier` registry, `from_country/to_country` inputs. (`Shipment.origin_address/destination_address` JSON arrays exist but are shipment-scoped, schemaless, and NOT flow-driven.)
- **Implication:** the proposed `from_country/to_country` inputs have NO existing columns. §16 maps where each must live.

---

# 10. Existing Frontend Flow

- **No in-repo SPA storefront/admin.** `shop-frontend`/`admin` dirs NOT FOUND; `resources/js` exists but contains no order-flow client (repo is API-first; clients are external). `api-desc/*` + `docs/*` are the frontend contracts.
- **Order creation contract (hard-coded today):** `name, user_phone, user_email?, address (array, required iff physical + delivery), governorate_id (required iff delivery), pickup_location_id (required iff pickup), fulfillment_type, payment_method, gateway, shipping_type?, selected_promotion_id?, selected_gift_product_id?` — `OrderCreateRequest::rules()`.
- **Status consumption:** `GET orders` / `GET orders/{id}` (`OrderResource` exposes `shipping_type`, `fulfillment_type`, pickup snapshot); admin list supports `?status=` filter; `getFlowAwareStatusTargets()` drives the admin dropdown (legacy targets + flow successor + supervised exits).
- **Hard-coded assumptions to break:** every checkout field above; `PACKED/SHIPPED/...` progression assumed by external clients reading `status`; `shipping_type ∈ {local,international}` allow-list shared by client validation.
- **No dynamic form renderer, no schema consumer** — frontend migration (§25) must be additive: keep current fields working, add `flow_definition` + `flow_values` alongside.

---

# 11. Existing APIs

| Method + URL | Auth | Request | Response | Validation | Service / side effects |
|---|---|---|---|---|---|
| `POST v1/checkout` (`api.checkout`) | sanctum user | `OrderCreateRequest` (see §10) | order + payment intent/redirect | FormRequest + fail-closed flow resolve | `OrderService::addItemsInOrder` → reserve → cart slice; online→gateway, cod→`handleCodPayment`, cashier→QR |
| `POST v1/fast-shipping/checkout` | sanctum | `FastCheckoutRequest` | order | same + fast fee | `FastShippingController` |
| `GET v1/orders`, `GET v1/orders/{id}` | sanctum (owner) | `?status=&limit=` | `OrderCollection/OrderResource` | owner scope `forUser` | `OrderService::paginateForUser/getOrderForUser` + pricing enrichment |
| `PATCH admin/orders/{id}/status` (Marvel `orders/{id}/status`) | `update-order-status` | `{status: ALL_STATUS_CODES}` | `OrderResource` | `OrderStatusUpdateRequest` + union guard | `OrderService::changeOrderStatus` (full side-effect chain §3.4) |
| `GET v1/admin/order-flows`, `GET {id}` | `view-orders\|view-order` | `?shipping_type=&is_active=&search=&per_page=` | `OrderFlowResource[]` (flow + ordered statuses) | query filters | `OrderFlowController` |
| `POST v1/admin/order-flows` | `update-order-status` | `{code,name,shipping_type,status_ids[]}` | 201 flow | `OrderFlowUpsertRequest` + `validateFlowStatuses` | create + `syncStatuses` + `enforceSingleDefault` |
| `PUT v1/admin/order-flows/{id}` | `update-order-status` | same (shipping_type immutable) | 200 flow | same + in-flight removal guard | update + resync |
| `GET/PUT v1/admin/order-statuses[/{id}]` | view / `update-order-status` | `{name,description,is_active}` (code immutable) | catalog rows | `OrderStatusCatalogUpdateRequest` | rename/disable only |
| `GET/POST {orderId}/shipment[/update-status]` | admin | shipment payload / `{status}` | `ShipmentResource` | `ShipmentService` + `Shipment::allowedTransitions` | shipment machine (independent of order flow) |
| `POST checkout/cod| cashier/{orderId}/mark-paid` | `payments.mark_paid` | `{reason?}` | order | pending txn lookup + lock | `markCodAsPaid/markCashierPaid` → `changeOrderStatus(completed)` |
| Webhooks `checkout/webhooks/stripe|paypal`, `checkout/callback` | throttle + provider verify | provider payload | outcome | gateway verify | `PaymentCompletionService` (passes `assertPaymentAuthority=false`) |
| GraphQL `OrderQuery/OrderMutator` | app guard | legacy inputs | legacy shapes | Marvel validators | legacy paths (compat surface, §28) |

**Where Flow Definition should be exposed (evaluated §13):** NOT `GET /flows/{flow}` in isolation. Canonical: **`GET v1/order-flows/by-shipping-type/{shipping_type}` (public, cached)** returning flow + ordered statuses + input definitions — mirrors the existing `shipping_type → flow` resolution (`resolveFlowForShippingType`) and the `OrderCreateRequest.shipping_type` selector. Admin CRUD stays under `v1/admin/order-flows` (add nested `inputs` sub-resource, do not invent a parallel top-level namespace).

---

# 12. Existing Database Schema (verified tables only)

**Flow (new subsystem):** `order_statuses(id,code UNIQUE,name,description,is_active,timestamps)`, `order_flows(id,code UNIQUE,name,shipping_type UNIQUE,is_default,is_active,timestamps)`, `order_flow_statuses(id,flow_id FK CASCADE,status_id FK RESTRICT,sort_order, UNIQUE(flow,status), UNIQUE(flow,sort_order))`.

**Orders:** `orders` + `shipping_type DEFAULT local + INDEX`, `flow_id FK RESTRICT`, `current_status_id FK RESTRICT`, widened `status` ENUM (22), `payment_status`, `fulfillment_status`, `inventory_state(+reserved_at/expires_at/restored_at)`, `shipment_status/tracking_number/courier_name/estimated|actual_delivery_at`, `governorate_id FK`, `address JSON NULL`, `pickup_location_id + snapshot cols`, `parent_id` (multi-shop split), currency/tax snapshots, `paid_at/completed_at/cancelled_at + cancellation_reason/trigger`. History: `order_status_history` (immutable, old/new status+payment+fulfillment, actor, notes, metadata JSON, changed_at). Tracking: `order_tracking_events`, `order_notifications`, `order_performance_metrics_view`.

**Fulfillment/Shipment:** `fulfillments` (+warehouse_id, priority, timestamps, metadata), `fulfillment_items`, `fulfillment_batches`, `packages/package_items`, `picking_tasks`, `packing_tasks/stations`, `warehouses/locations/product_locations`, `shipments` (uuid, order_id, fulfillment_id, courier, status, shipping_method/cost/currency, origin/destination JSON, items JSON, weights, shipped/ETA/delivered_at, idempotency_key, metadata), `return_requests/return_items`, refunds tables, `transactions/invoices`.

**Reference:** `countries`, `governorates`, `shipping_prices`, `pickup_locations`, `shops/balances/commissions`, `products/variations`, `carts/cart_items`, `coupons/promotions`.

**Missing for proposed design (ADD):** `flow_inputs` (+ value columns nowhere yet — values go to owning domains, §16); flow versioning columns (`version`, `status draft/published/archived`, `published_at`, `supersedes_id`); optional `order_flow_values` audit snapshot (recommended, §16); NO new country/zone/carrier tables needed in phase 1 (reuse `countries/governorates/warehouses/pickup_locations` as `source`s).

---

# 13. Existing Transition Logic

- **Validated (not free-form):** union guard `if (!($flowAllows || $legacyAllows)) throw invalid_transition` (`OrderService` L769–783); shipment machine `canTransitionTo()`; fulfillment map `canTransitionFulfillmentStatus()`; `OrderStatusUpdateRequest` allow-list.
- **Invalid examples blocked (tested):** `delivered→pending`, `cancelled→processing`, `completed→shipped`, skips (`pending→shipped`), backward moves, removal-target races (`OrderFlowMatrixTest::test_invalid_skips_and_backward_moves_fail`, `test_terminal_states_reject_everything_but_noop`, `test_stale_state_concurrent_update_fails_safely`).
- **A configurable Flow REQUIRES the existing engine, not a new one:** `allowsFlowTransition()` already reads membership order dynamically. Flow Inputs add a second gate (required-input completeness per transition) — extend `changeOrderStatus()`, do not fork it.

---

# 14. Existing Side Effects (must not be bypassed)

Per `changeOrderStatus()` + listeners (verified):

| Transition | Side effects |
|---|---|
| first leave-`pending` (any target) | idempotent `InvoiceService::generateFromOrder()`; failures reported, never block |
| →`completed` | `payment_status=success`, `paid_at/completed_at`, transaction→paid, inventory **commit**, promotion finalize, coupon usage record, customer metrics rebuild (afterCommit), `PaymentSucceeded` → `FulfillDigitalProducts`, invoice, notifications, timeline, analytics |
| →`cancelled` | unpaid→reservation release + coupon release + promotion decrement; paid+committed→inventory restore; transaction→failed; `OrderCancelled` → `RevokePendingDigitalEntitlements`, notifications |
| →`delivered` | fulfillment→delivered, `OrderDelivered`, push/SMS (`OrderSmsTrait`, `SendOrderPushNotification`), timeline |
| any change | `order_status_history` row (with flow provenance), `OrderTrackingLogger`, `OrderTrackingMetrics`, `OrderStatusChanged` broadcast, vendor `Balance` (legacy Marvel path only) |

**Rule for Flow Inputs:** every input-gated transition MUST call through `changeOrderStatus()` so this chain fires. Direct `Order::update(['status'=>…])` anywhere (incl. `CancelUnpaidOrders`, seeders, future input code) must be audited — `grep status.*=.*completed|cancelled` outside `OrderService` before phase 6.

---

# 15. Security Findings

1. **Dynamic-field mass assignment risk (future):** no input layer exists, so no current hole — but the new `flow_values` payload MUST be allow-listed against the flow's input definitions (key-by-key), never `$request->all()` → model. Enforce server-side `source` existence checks (e.g. `countries.id`, `governorates.id`) with `exists:` rules built from definitions; never trust client-supplied option lists.
2. **`OrderFlowUpsertRequest::authorize()` returns `true`** (`app/Http/Requests/Admin/OrderFlowUpsertRequest.php` L11–14) — authorization relies SOLELY on route middleware (`permission:update-order-status`). VERIFIED present on all mutating flow routes, but any new input-definition routes must carry the same middleware; prefer belt-and-braces `authorize()` checking `update-order-status` too.
3. **F-1 payment gate:** `completed` on unpaid orders requires `payments.mark_paid` (L792–804). Flow Inputs must NOT create an alternate path to `completed` that skips `$assertPaymentAuthority`. Keep the flag threaded through any new transition service.
4. **Tenant/shop:** flows are GLOBAL (no `shop_id`); `OrderRepository::updateOrder()` enforces shop ownership for Marvel path, `OrderService` paths enforce owner (`forUser`) or admin permission. New input `source`s spanning shops (warehouses, pickup locations) need per-row authorization (e.g. active + shippable governorate logic already in `resolveShippingPrice()`).
5. **DoS/validation:** `status_ids` bounded (`min:1`, exists, dedup, active-check); mirror for inputs: cap inputs per flow (e.g. 50), `key` regex `^[a-z][a-z0-9_]{1,49}$`, `sort_order` unique per flow (same UNIQUE pattern as statuses).
6. **Secrets/PII:** no secrets in flow code; order PII (phone/email/address) already in `orders` — input values containing PII must inherit order visibility (owner + `view-orders`), never leak via public flow-definition endpoint (definitions only, no values).

---

# 16. Multi-store/Tenant Findings

- `Shop` model + `shop_id` exist (Marvel marketplace legacy: `Shop::orders()`, `Balance`, child orders per shop via `createChildOrder()`). `StoreNotice`, `ChannelContext/ChannelMiddleware` referenced in AGENTS.md but **no `channel`/`tenant_id` columns on `order_flows`/`orders`** in the verified schema.
- **Current ownership: flows are GLOBAL.** `order_flows` has NO `shop_id/store_id/tenant_id`; resolution is by `shipping_type` only. `OrderFlowSeeder` seeds system-wide defaults.
- **Decision for inputs:** keep `flow_inputs` GLOBAL in phase 1 (same scope as flows). Shop-specific overrides (system + overrides) are a later phase ONLY if a multi-vendor requirement is evidenced — do not add `shop_id` speculatively (YAGNI).

---

# 17. Versioning Findings

- **Today: MUTABLE flows** with exactly one safety rail: `syncStatuses()` refuses to remove statuses holding in-flight non-terminal orders (`delivered/cancelled/completed` excluded). Everything else (reorder, rename via catalog, add) applies immediately to ALL orders in the flow — including in-flight ones (re-linking neighbours changes their `nextStatus()`).
- **Risk:** adding/removing a REQUIRED INPUT mid-flight has the same blast radius (old orders suddenly invalid; admin loosening bypasses audit). The status precedent proves the team already accepts guarded mutability — but inputs carry PII/compliance weight (customs data), so the bar is higher.
- **Recommendation: Draft → Published (immutable snapshot) → Archived**, with `order_flow_versions` (or `version` + `status` columns on `order_flows` + immutable `flow_input_versions`). Orders pin `flow_version_id` at creation (alongside `flow_id`). Admin edits create a new draft; publish freezes it. Migration `2026_09_28_000002.down()` already establishes the "fail closed, never lose data" precedent — follow it.
- **Minimal phase-1 alternative (if versioning deferred):** reuse the in-flight guard pattern for inputs (refuse to delete/alter required inputs while non-terminal orders exist on that flow version) + append-only `order_flow_values` audit. Document as MEDIUM-risk debt with revisit trigger (first production input change).

---

# 18. Global Scenario Matrix (evidence-only; NOT FOUND = searched, absent)

| Scenario | Support | Current flow | Required inputs (today static) | Missing for dynamic Flow |
|---|---|---|---|---|
| Domestic delivery (governorate) | YES | local flow 6 steps | `governorate_id`, `address` | input defs: `governorate(select→governorates)`, `address` passthrough |
| International + customs | PARTIAL (lifecycle only) | international 11 steps | `shipping_type=international` only | `from_country`, `to_country` (select→countries), `customs_reference?`, duties ack — NO columns yet |
| Pickup / pay-at-cashier | YES | local flow (same) + fulfillment_type=pickup | `pickup_location_id` | `pickup_location(select→pickup_locations)` def; COD+pickup stays rejected |
| COD | YES | any →`completed` exit on cash collect | `payment_method=cod` | no new inputs; `markCodAsPaid` unchanged |
| Online payment (gateway) | YES | pending→(gateway)→completed | `payment_method=online`, `gateway` | no flow inputs (payment stays out of flow) |
| Split fulfillment (multi-shop child orders) | YES (legacy) | parent + per-shop children | — (system-split) | NOT flow inputs; keep in fulfillment domain |
| Partial/split shipment, packages | PARTIAL | shipments table + machine independent | courier/tracking/addresses (manual) | expose as input *sources*, not flow states |
| Returns / refunds | YES (separate) | `returned` order code + `return_requests`/refunds | — | keep OUT of flow (per §5) |
| Multi-warehouse | PARTIAL | `warehouses/locations` + fulfillment assign | — (ops-side) | `warehouse(select→warehouses)` as admin-gated source |
| Digital-only (no shipping) | YES | D4 skips address/governorate | — | inputs conditional on `item_type` (skip when digital-only) |
| Saudi/Kuwait/Qatar/Egypt/marketplace/multi-vendor/multi-package | NOT FOUND as flows | — | — | covered generically by `select+source` + versioning; NO country-specific code |

---

# 19. Proposed Architecture (for approval — NOT implemented)

```text
order_flows (EXISTING, + version columns)
 ├── order_flow_statuses (EXISTING — lifecycle definition, unchanged)
 └── flow_inputs (NEW — input definition, generic + extensible)
      ├── key            string UNIQUE(flow_id,key)  e.g. from_country
      ├── label_key      string (i18n key, e.g. flow.inputs.from_country)
      ├── type           enum text|number|boolean|date|select|multi_select
      ├── source         nullable (countries|governorates|shipping_zones*|warehouses|pickup_locations|addresses)
      ├── required       bool
      ├── required_at    enum checkout|transition:STATUS_CODE (default checkout)
      ├── sort_order     uint UNIQUE(flow_id,sort_order)
      ├── validation     JSON (min/max/pattern/options when source=null)
      └── is_active      bool
```

Runtime:

```text
checkout / transition
  → FlowInputValidator (NEW, app/Services/OrderFlow/)
      loads flow + active input defs
      validates flow_values{key: value} against defs (required/type/source/exists/authz)
  → persist VALUES to owning domains (NOT a blob):
      from_country/to_country → shipments.origin/destination or order-level customs cols (decision §16 table)
      governorate/address     → orders.governorate_id/address (existing)
  → order_flow_values (NEW audit snapshot: order_id, flow_version, key, value JSON, validated_at)
  → proceed via OrderService::changeOrderStatus() (unchanged chain)
```

Public contract:

```text
GET v1/order-flows/by-shipping-type/{type}   (public, cached)
→ { flow: {code,name,shipping_type,version}, statuses: [...ordered], inputs: [...ordered defs] }
POST checkout  +=  flow_values: { key: value }   (optional today, required per defs once published)
PATCH admin/orders/{id}/status  +=  flow_values (for required_at=transition inputs)
```

---

# 20. Alternatives Considered

| Option | Verdict |
|---|---|
| **A. `flow_inputs` table** (normalized rows) | **RECOMMENDED** — matches existing `order_flow_statuses` pattern (UNIQUE + sort_order + admin CRUD + tests), queryable, permission-friendly, versionable per row. Cost: one migration + CRUD. |
| B. JSON `schema` column on `order_flows` | REJECTED for phase 1 — unqueryable ("which flows require X?"), harder to version/diff/audit, diverges from the pivot-table convention the team already operates. |
| C. Reuse existing dynamic-field infra | REJECTED — none exists (§7 verified 0 matches). Product attributes are catalog-scoped, metadata bags are schemaless. |
| D. Hybrid (table + cached JSON snapshot) | ACCEPTABLE as optimization — normalize in DB, cache compiled schema for the public endpoint. Adopt only if definition reads measure slow. |
| Primitive types (`type: country/city/address`) | REJECTED — hard-codes domain into the type system; every new source needs a code change. Generic `select + source` reuses `Country/Governorate/Warehouse/PickupLocation` tables, keeps frontend renderer to 6 widgets, localizes via `label_key`. |
| Payment/fulfillment inside Order Flow | REJECTED — §5 classification + migration docblocks forbid it; keep subsystems. |
| New workflow framework / eventsourcing / CQRS / Kafka | REJECTED per §33 — hand-coded union engine + tests already cover the machine. |

---

# 21. Recommended Architecture (final, challenges answered §31)

1. **Flow = Order concept keyed by `shipping_type`** (keep). Shipping Flow and Order Flow are ONE thing in this codebase (`shipping_type → flow → ordered statuses`); do NOT split. Payment/fulfillment/shipment stay separate machines.
2. **Inputs belong to Flow** (not Order Type — no such entity exists; not Shipping Type string — flows already embody it). `flow_inputs.flow_id FK`.
3. **Definitions = DB rows** (Option A); **values = owning domains + audit snapshot** (§16 mapping below).
4. **Types = generic + `source`** (`select + source=countries` — answers G).
5. **Versioning = Draft→Published→Archived**, orders pin version (answers H).
6. **Dynamic fields can never bypass rules** (answers I): validator allow-lists keys, `exists:` + authz per source, all writes funnel through `changeOrderStatus()` + F-1 gate.
7. **Extensibility without country hard-coding** (answers J): new country = new `countries` row + optional new flow version referencing `source=countries`; zero code deploys for geography.

## Input → value location mapping

| Input | Definition location (NEW) | Actual value location (EXISTING/NEW col) | Reason |
|---|---|---|---|
| `from_country`, `to_country` | `flow_inputs` (select→countries, required_at=checkout, international flows) | NEW nullable `orders.origin_country_id` / `destination_country_id` FK→`countries` (or shipment-level if multi-shipment; decide in design review — single-shipment orders favor order-level) | customs truth belongs to order/shipment domain, queryable + FK-constrained; never a metadata blob |
| `customs_reference?` | `flow_inputs` (text, optional) | `shipments.metadata->customs_reference` + `order_flow_values` snapshot | shipment-scoped, provider-specific |
| `governorate_id`, `address` | `flow_inputs` (select→governorates; address passthrough) | EXISTING `orders.governorate_id` + `orders.address` | reuse, no migration |
| `pickup_location_id` | `flow_inputs` (select→pickup_locations, pickup contexts) | EXISTING `orders.pickup_location_id` + snapshot cols | reuse |
| `warehouse_id?` | `flow_inputs` (select→warehouses, admin-gated) | `fulfillments.warehouse_id` | ops domain owns it |
| every submitted value | — | `order_flow_values` audit (order_id, flow_version_id, key, value, validated_at) | immutable proof of what was validated under which definition version |

---

# 22. Database Changes Required (future phases — DO NOT run yet)

1. `create_flow_inputs_table`: `id, flow_id FK CASCADE, key, label_key, type ENUM(6), source NULLABLE, required BOOL, required_at STRING DEFAULT 'checkout', sort_order UINT, validation JSON NULLABLE, is_active BOOL DEFAULT true, timestamps, UNIQUE(flow_id,key), UNIQUE(flow_id,sort_order), INDEX(flow_id,sort_order)`.
2. `add_flow_versioning`: `order_flows.version UINT DEFAULT 1, status ENUM(draft,published,archived) DEFAULT 'published', published_at NULLABLE, supersedes_id NULLABLE FK`; backfill existing rows `published`.
3. `create_order_flow_values`: `id, order_id FK CASCADE, flow_id, flow_version, key, value JSON, validated_at, UNIQUE(order_id,key,flow_version)`.
4. `add_customs_countries_to_orders` (nullable FKs to `countries`) — confirm shipment-vs-order grain in design review first.
5. `lang/en|ar` keys for input labels + validator messages (follow `lang/*` nesting, reuse `checkout.*` prefix).

Rollback: inputs/values tables drop cleanly (no legacy dependency); versioning columns nullable-safe; customs FKs nullable with `restrictOnDelete` mirroring `flow_id` precedent.

---

# 23. API Changes Required

- NEW `GET v1/order-flows/by-shipping-type/{shipping_type}` — public (or sanctum-optional), `throttle:api`, cached 60s, returns flow + ordered statuses + active input defs. Reuses `resolveFlowForShippingType()` (fail-closed 422 when unavailable).
- EXTEND `POST v1/checkout` — accept optional `flow_values: {object}`; validate via `FlowInputValidator` when flow version has required-at-checkout inputs; legacy clients omitting it keep working until a flow publishes required inputs (then 422 with per-key errors).
- EXTEND `PATCH admin/orders/{id}/status` — accept optional `flow_values` for `required_at=transition:{to}` inputs; same validator; failures 422 BEFORE `changeOrderStatus()`.
- EXTEND `v1/admin/order-flows` CRUD — nested `inputs[]` write model (same array-order→sort_order convention as `status_ids`); reuse `validateFlowStatuses` pattern as `validateFlowInputs`.
- NO breaking changes: all additions optional/nullable until admin publishes required inputs; `OrderResource` gains `flow`, `current_status`, `flow_values?` additively.

---

# 24. Admin Changes Required

- Flow builder: existing code/name/shipping_type/status_ids editor + NEW inputs tab (key/label/type/source/required/required_at/validation/sort via drag order, active toggle). Enforce key regex, per-flow caps, source allow-list, in-flight guard on required-input removal (mirror `syncStatuses()`).
- Catalog: unchanged (name/desc/active only).
- Publish flow: draft→published freezes version; published→archived blocked with in-flight non-terminal orders (mirror status guard).
- Permissions: reuse `update-order-status` for input writes; fix `OrderFlowUpsertRequest::authorize()` to check it explicitly.

---

# 25. Frontend Changes Required (external clients)

- Fetch `GET …/by-shipping-type/{type}` after shipping-type selection; render 6 generic widgets (text/number/boolean/date/select/multi_select); `source=countries|governorates|…` → option lists (reuse existing country/governorate endpoints or embed options).
- Submit `flow_values` alongside existing checkout payload. Keep ALL current hard-coded fields (they become the `local` flow's input defs) — no flag-day.
- Admin UI: extend existing flow editor (no new app needed if admin is API-driven).
- Mobile/GraphQL: additive fields only; legacy `status` mirror preserved so old clients never break.

---

# 26. Validation Architecture

Current: `OrderCreateRequest` (checkout static rules) → `OrderService` (business) → `OrderStatusUpdateRequest` (status allow-list) → `changeOrderStatus()` (union guard). Policies/Gates via `permission:*` middleware; DTOs (`CheckoutTotals`) for totals; `TaxCalculator` for tax.

New: `App\Services\OrderFlow\FlowInputValidator` (pure, testable):

```text
validate(flow, values, context{at: checkout|transition:CODE, actor, order?})
 → per-key: unknown-key reject → required → type cast → source exists: + active/authz
 → validation JSON (min/max/pattern/options) → returns validated map or throws FlowInputValidationException (422 {errors:{key:[...]}})
```

Called (a) in `OrderService::addItemsInOrder()` BEFORE `createOrder()` (fail-closed, inside DB txn), (b) at the top of `changeOrderStatus()` for transition-gated inputs. Persist values only after validation passes, inside the same transaction. Never validate in controllers/resources/models (per AGENTS.md Phase 4 + pricing-boundary rule).

---

# 27. Migration Strategy

- PHASE 0 (this report): DONE — no code touched.
- Deploy order: migrations (inputs + versioning + values + customs FKs, all nullable/additive) → seeder (seed `local` input defs mirroring `OrderCreateRequest`: governorate/address/pickup; `international` += from/to country) → backfill `order_flow_values` for existing orders where mappable (governorate/address only; countries left NULL) → admin publish → clients adopt `flow_values` at leisure.
- `orders.status` ENUM: no further widen needed (inputs are data, not states).
- Rollback: each migration reversible; seeder additive-only (same contract as `OrderFlowSeeder`); values table informational.

---

# 28. Backward Compatibility

- Existing orders: pinned to current (v1/published) flow; backfill sets `flow_version=1`; unmapped `current_status_id` precedent (warn-log, leave NULL) reused for unmappable inputs.
- Existing APIs: `orders.status` mirror retained; `OrderResource` additive; GraphQL untouched; webhooks unchanged.
- Existing clients: checkout without `shipping_type` → local (preserved); checkout without `flow_values` → passes until required inputs published (then 422 — communicated as the feature flag).
- Marvel legacy paths: `syncOrderStatusColumn()` map covers only 9 prefixed codes vs 22 catalog codes — already drifted; flow work must not widen it, must route callers to `OrderService`.

---

# 29. Testing Strategy

Existing: 413 test files; order-relevant ~24 files (§2.3 + `OrderLifecycleTest`, `OrderStatusLifecycleTest`, `OrderCreationFlowTest`, `OrdersProductionHardenTest`, `CartOrderLifecycleTest`, `PendingOrderLifecycleTest`, `Inventory/OrderReservationLifecycleTest`, `Fulfillment/OrderPickingTest`, `Currency/*`, `Notifications/*`). Flow: 30 tests across 2 files (matrix walk, skips, terminals, concurrency, seeder idempotency, defaults-per-type, catalog↔ENUM parity).

New (per AGENTS.md Phase 11–13 + QA gate):

- Unit: `FlowInputValidator` (per type/source/required/pattern/unknown-key/caps).
- Feature: definition CRUD + versioning (publish freeze, archived block, in-flight guards); checkout with/without `flow_values` (local + international + digital-only skip); transition-gated inputs; 422 shapes; authz (customer cannot invent keys; admin without permission blocked).
- Regression: every bug fix reproduces first; legacy milestone tests (`pending→completed`, COD, cashier, zero-value) must keep passing unmodified.
- Concurrency: stale-state + double-submit `flow_values` idempotency.
- Security: source-spoofing (inactive country id), cross-shop warehouse, PII leakage via definition endpoint (values never returned).

---

# 30. Risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| 1 | Parallel Marvel status path bypasses flow + F-1 | HIGH | Funnel all writes via `OrderService::changeOrderStatus`; deprecate `OrderManagementTrait::changeOrderStatus`; add failing test on direct path |
| 2 | Mutable flows change in-flight meaning | HIGH | Versioning (draft→published→archived) + pin `flow_version` on orders |
| 3 | Dynamic inputs become authz bypass | HIGH | Allow-list keys, `exists`+active+ownership per source, F-1 preserved, values never mass-assigned |
| 4 | Splitting Order vs Shipment vs Fulfillment state | MEDIUM | Keep classification (§5); inputs reference but never mutate sibling machines directly |
| 5 | `shipping_type` allow-list blocks new lanes (sea/air) | MEDIUM | Extend `SUPPORTED_SHIPPING_TYPES` + migration of `order_flows` UNIQUE stays per-type; no code fork |
| 6 | Frontend flag-day | MEDIUM | Additive `flow_values`; legacy fields valid until required inputs published |
| 7 | ENUM drift (22 codes vs 9-code legacy map) | MEDIUM | Do not extend legacy map; sunset Marvel path |
| 8 | `authorize()=true` on flow requests | LOW | Add explicit permission check; keep route middleware |
| 9 | Default-driver churn (SQLite rebuild recipes) | LOW | Follow established rebuild pattern; MySQL native MODIFY in prod |

---

# 31. Implementation Phases (incremental; each ends in tests + review)

```text
PHASE 0  Discovery/Audit .............. ✓ COMPLETED (this file)
PHASE 1  Domain/Contracts ............. FlowInput value-object, source registry (countries|governorates|warehouses|pickup_locations), label/i18n keys; NO tables yet
PHASE 2  Database ..................... flow_inputs + versioning cols + order_flow_values + customs FKs (nullable); seeder additive-only
PHASE 3  Definitions API (admin) ...... nested inputs CRUD under v1/admin/order-flows + publish/archive + guards
PHASE 4  Validator .................... FlowInputValidator + integration into addItemsInOrder + changeOrderStatus (fail-closed, transacted)
PHASE 5  Public definition API ........ GET by-shipping-type + caching + 422 parity with resolveFlowForShippingType
PHASE 6  Checkout/transition wiring ... flow_values persistence to owning domains + audit snapshot; Marvel-path funnel
PHASE 7  Admin UI support ............. (API-first; only if admin SPA in scope) inputs tab + version timeline
PHASE 8  Frontend (external) .......... dynamic renderer + flow_values submit; legacy fallback
PHASE 9  Migration/backfill ........... version pin, values backfill, customs data import plan
PHASE 10 Security hardening ........... permission authorize(), source authz, PII review, rate limits
PHASE 11 Testing ...................... unit+feature+regression+concurrency+security per §29
PHASE 12 E2E verification ............. local + international + pickup + COD + online + split + return walks on seeded + custom flows
```

Per-phase template (to be filled at implementation kickoff): Goal · Files to create · Files to modify · Files to leave untouched · DB changes · API changes · Dependencies · Risks · Tests · Verification · Rollback.

---

## Appendix A — Second verification pass (2026-09-28, post-report)

Re-searched: `order status|flow|workflow|transition|shipping|international|country|dynamic field|metadata|schema|custom field|checkout|fulfillment|shipment|payment` across `app/`, `packages/marvel/src`, `database/migrations`, `routes/`, `tests/`, `docs/`, `api-desc/`.

- No additional status vocabularies, workflow engines, or input-definition tables found. Report stands unchanged.
- One residual to confirm at implementation: `resources/js` contents (listing returned empty in this shell) and any out-of-repo frontends — treat all clients as external until evidenced otherwise.

## Appendix B — Third architectural review (challenge responses)

- **A. Is Flow an Order concept?** YES — verified: `orders.flow_id/shipping_type/current_status_id`, resolution at checkout, transitions in `OrderService`. Keep.
- **B. Shipping vs Order Flow?** ONE thing here (`shipping_type→flow`). Do not split.
- **C/D. Payment/Fulfillment inside?** NO — separate columns, machines, side effects; migration docblocks explicitly exclude them.
- **E. Inputs → Flow or Type?** FLOW (`flow_inputs.flow_id`) — no Order-Type entity exists.
- **F. DB rows or JSON?** ROWS (Option A) — matches pivot convention, queryable, versionable.
- **G. `country` vs `select+source`?** `select + source=countries` — 6 widgets, zero code per geography.
- **H. Flow changes vs existing orders?** Pin version; draft→published→archived.
- **I. Bypass?** Allow-list + exists/authz + single transition authority + F-1.
- **J. Global extensibility?** New geography = data rows, not code.

## Appendix C — Self-review checklist (§29)

Searched entire repo ✓ · every status found + classified ✓ · existing Flow infra found + documented ✓ · dynamic-field infra proven absent ✓ · frontend hard-codes identified ✓ · every relevant API mapped ✓ · migrations inspected ✓ · side effects mapped ✓ · international/pickup/COD/payment inspected ✓ · versioning + compat inspected ✓ · tests inventoried ✓ · nothing invented (all claims cited) ✓ · no duplicate infra proposed (reuse statuses/flows/shipment/fulfillment/country tables) ✓ · Flow kept shipping-generic, types kept domain-generic ✓ · Definition vs Value separated ✓ · existing Order behavior preserved (additive only) ✓.
