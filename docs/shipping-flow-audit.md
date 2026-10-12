# Shipping Flow Audit (read-only, pre-change)

Date: 2026-10-11. All findings verified against source listed. No files modified during this phase.

## 1. What the "shipping flow" actually is

There is NO `shipping_flows` table and NO Marvel shipping-method state machine
in this path. The configurable workflow is the **Order Status Flow system**:

- `order_statuses` — global status catalog (`code` UNIQUE).
- `order_flows` — one row per workflow: `code` UNIQUE (stable identity),
  `shipping_type` **UNIQUE** (the single-flow bottleneck), `is_default`,
  `is_active`.
- `order_flow_statuses` — ordered membership (`flow+status` UNIQUE,
  `flow+sort_order` UNIQUE).
- `flow_inputs` / `order_flow_values` — per-flow dynamic checkout/transition
  inputs + immutable audit rows.
- `orders.shipping_type` + `orders.flow_id` (RESTRICT on delete) +
  `orders.current_status_id`.

Authoritative service: `app/Services/OrderFlow/OrderFlowService.php`.
Authoritative identity today: `shipping_type` (wrong layer); stable identity
available: `code` (UNIQUE) + `id` (PK).

## 2. Root cause of duplicate-type rejection (three independent layers)

| # | Layer | File:line | Mechanism |
|---|---|---|---|
| 1 | DB | `database/migrations/2026_09_28_000001_create_order_status_flow_tables.php:41` — `$table->string('shipping_type',30)->unique()` | `order_flows_shipping_type_unique`; second `local` row fails at INSERT even if validation is bypassed |
| 2 | Validation | `app/Http/Requests/Admin/OrderFlowUpsertRequest.php:47` — `Rule::unique('order_flows','shipping_type')->ignore($flowId)` | `POST /api/v1/admin/order-flows` with an existing type returns 422 before touching the DB |
| 3 | Semantics | `app/Http/Controllers/Api/Admin/OrderFlowController.php:118-124` — "shipping_type is the flow identity … can never be changed" | Update to another type rejected with 422 `shipping_type cannot be changed` |
| 4 | Tests mirror | `tests/Concerns/CreatesTestTables.php:619` — test helper recreates `shipping_type->unique()` | Test env enforces the same single-flow rule; must be updated with the migration |

`code` is already `->unique()` (migration:40, request:34) and stays the
identity constraint after the fix.

## 3. Hidden single-flow assumptions (all verified)

1. `OrderFlowService::resolveFlowForShippingType()` (service:222-225):
   `where(shipping_type,type)->where(is_active,true)->first()` — arbitrary
   first row once >1 active flow shares a type. **Must become deterministic
   default resolution + explicit-selection path.**
2. `OrderFlowService::columnsForNewOrder()` (service:366) and
   `assignFlowToOrder()` (service:389): type-only, delegate to (1).
3. `Marvel Order::creating` hook
   (`packages/marvel/src/Database/Models/Order.php:185-210`): type-only
   resolution; respects preset `flow_id` (explicit-flow compatible, verified).
4. `OrderCreationService::createOrder()` (`app/Services/Checkout/OrderCreationService.php:117-148`):
   calls `columnsForNewOrder(shippingType)` then `assignFlowToOrder(order, shippingType)` —
   type-only; needs an optional explicit-flow parameter.
5. `OrderService::addItemsInOrder` (`app/Services/General/OrderService.php:372-388`):
   resolves checkout flow + validates `flow_values` type-only; pending-retry
   guard (303-315) compares `shipping_type` only — needs a flow-mismatch guard.
6. `FlowDefinitionController::byShippingType`
   (`app/Http/Controllers/Api/General/FlowDefinitionController.php:47-59`):
   returns ONE flow per type. Keep as deterministic *default* for backward
   compat; multi-flow clients must use `available` + `flow_code`.
7. `AvailableOrderFlowResource` hides `id` (by design, guest-safe) but exposes
   `code` — `code` is the stable selection key; no change needed except docs.
8. `FlowInputSeeder:85` — `where(shipping_type)->first()` seeds intl inputs
   onto the first intl flow. Deterministic post-fix: target the seeded
   `international` code flow (fallback: default, then first).
9. `OrderFlowSeeder:92-99` — "one active default per type" convergence logic;
   remains valid with multiple flows per type (no change required).
10. `OrderRepository::flowColumnsForCreation` (marvel:629-657) — hardcodes
    `'local'` for the Marvel creation door; unaffected (legacy door, keep).
11. `FastShippingService:133` — `shipping_type !== local` guard; orthogonal
    fast-shipping fee machine, not an order flow; unaffected.
12. No `DELETE /admin/order-flows` route exists (Routes.php:521-525: only
    index/store/show/update) — historical `flow_id` references cannot be
    hard-deleted via API today. `orders.flow_id` is `restrictOnDelete`
    (migration 2026_09_28_000002:33). No new delete path will be added.

## 4. Supported shipping types (verified, not assumed)

- `OrderFlowService::SUPPORTED_SHIPPING_TYPES = [local, international]` (service:44-47) —
  single authoritative definition. `OrderCreateRequest:71-75` and
  `OrderFlowUpsertRequest:39-48` both `Rule::in()` it. No competing list found
  (`Marvel\Enums\ShippingType` is pricing, explicitly NOT this discriminator —
  migration comment:42-43).
- `ControlledShippingTypeTest` verifies `same_day/express/pickup/hyperloop`
  are 422 everywhere. Candidate types `pickup/express/same_day` have **no**
  fulfillment implementation: pricing is governorate-based
  (`OrderService::resolveShippingPrice`), fast-shipping is a separate local-only
  fee machine, pickup uses `pickup_location_id` + fulfillment_type (not a flow
  type). Adding enum values without fulfillment would be a false support claim.
  **Decision: keep exactly `local|international`; multi-flow requirement is met
  by multiple flows sharing one of these two types.**

## 5. Pricing / fulfillment boundary (verified)

- Shipping price: governorate `ShippingPrice` table, zero-able by coupon; NOT
  per-flow. Multiple flows sharing a type correctly share pricing inputs; the
  server recalculates at checkout (`OrderService:283-297`,
  `OrderCreationService:33-43`). Client never supplies the price. No change.
- Order Flow vs Shipment/Fulfillment machines: migration header states the
  Order flow "does NOT replace the Fulfillment or Shipment state machines".
  `allowsFlowTransition` (service:417) governs order-status only. No change.

## 6. Risk assessment

- Removing the UNIQUE without fixing (1)-(5) → nondeterministic order routing.
  Mitigated by deterministic default + explicit selection + mismatch guards.
- Rollback re-adding UNIQUE fails if duplicate types exist — migration down()
  must detect and abort with a clear message (data-preserving).
- Concurrent admin `is_default` writers: pre-existing race documented in
  `enforceSingleDefault`; unchanged by this task (admin-only, low frequency).
- `update` allowing `shipping_type` change: safe — orders keep stored `flow_id`
  (verified: no cascade rewrite in controller/service).

## 7. Evidence index (files read)

Migrations `2026_09_28_000001`, `2026_09_28_000002`;
`OrderFlowService`, `OrderFlow` model, `OrderFlowController`,
`OrderFlowUpsertRequest`, `Flow/OrderStatusOptions/Order` controllers,
`OrderCreateRequest` (marvel), `OrderService:280-430`,
`OrderCreationService:31-178`, Marvel `Order` model `:168-218`,
`OrderRepository:629-657`, `Routes.php:513-533`, `routes/api.php:120-130`,
resources `OrderFlow/AvailableOrderFlow/Order/OrderStatusOptions`,
`checkout.php` en/ar, seeders `OrderFlow/FlowInput`, `CreatesTestTables:560-670`,
`ControlledShippingTypeTest`, `FastShippingService:133`, composer.json.
