# Shipping Flow Implementation Plan (multi-flow per type)

## 1. Design

Identity separation (no new columns):

- `id` — PK, internal.
- `code` — UNIQUE, stable flow identity, explicit selection key (`flow_code`).
- `shipping_type` — controlled category (`local|international`, unchanged set),
  non-unique after migration.
- `is_default` — exactly-one-active-default-per-type policy (existing
  `enforceSingleDefault` + seeder convergence) becomes the deterministic
  type-only resolution rule.
- `is_active` — availability; inactive flows unselectable for new orders.

Selection policy:

- Explicit `flow_code` (preferred) or `flow_id` → resolve exactly that flow;
  must be active; if `shipping_type` also supplied it must match (422 mismatch).
- Type-only (or omitted → `local`) → deterministic default: single active flow
  → it; multiple active → the one `is_default` active flow; none / multiple
  defaults → 422 (`shipping_type_unavailable` / new `flow_ambiguous` key).
- Pending-retry: stored `flow_id` wins; explicit different flow → 422
  `flow_mismatch`; type-only retry keeps existing flow (unchanged behavior).
- `by-shipping-type/{type}` keeps returning the resolved *default* (backward
  compat). `available` lists all active flows for explicit selection.

## 2. Schema strategy

New migration `2026_10_12_000001_allow_multiple_flows_per_shipping_type.php`:

- `up`: drop ONLY `order_flows_shipping_type_unique`
  (`$table->dropUnique(['shipping_type'])`; doctrine/dbal 3.7.1 present for
  MySQL; SQLite in tests supports it via table rebuild in Laravel 10).
  Keep `code` UNIQUE, `order_flows_shipping_active_idx`, all FKs.
- `down`: re-add `->unique('shipping_type')` ONLY if no duplicate types exist;
  else abort by throwing (data-preserving, explicit message).
- Update `tests/Concerns/CreatesTestTables.php:619` identically
  (`->unique()` → plain string + keep a non-unique index for parity).

## 3. Supported-type strategy

Unchanged: `SUPPORTED_SHIPPING_TYPES = [local, international]`.
`pickup/express/same_day` stay rejected (no fulfillment implementation; see
audit §4). Worked example uses `local_standard` + `local_simplified`
(both `shipping_type: local`), which the task explicitly allows.

## 4. Code changes

1. `OrderFlowService`:
   - `resolveFlowForShippingType()` → deterministic default resolution
     (active set; 1 → it; N → single active default else ambiguous 422).
   - New `resolveCheckoutFlow(?type, ?code, ?id)` implementing §1 policy;
     `columnsForNewOrder(type, status, flowId=null, flowCode=null)` and
     `assignFlowToOrder(order, type, flowId=null, flowCode=null)` overloads
     (backward-compatible defaults).
   - New lang keys: `flow_ambiguous`, `flow_mismatch`, `flow_inactive`
     (en + ar).
2. `OrderFlowUpsertRequest`: remove `Rule::unique` on `shipping_type`; keep
   `Rule::in(SUPPORTED)`; keep `code` unique-ignore.
3. `OrderFlowController::update`: allow `shipping_type` change (validated type;
   orders keep stored `flow_id`); include `shipping_type` in update keys;
   remove the 422-immutable block. Deactivation guard + `enforceSingleDefault`
   unchanged (already per-type correct).
4. `OrderCreateRequest` (marvel): add `flow_code` (`nullable|string|exists:order_flows,code`)
   + `flow_id` (`nullable|integer|exists:order_flows,id`).
5. `OrderService::addItemsInOrder`: resolve via `resolveCheckoutFlow`,
   validate `flow_values` against resolved flow, pass `flow_id` into
   `OrderCreationService::createOrder(..., shippingType, flowId)`; pending-retry
   flow-mismatch guard.
6. `OrderCreationService::createOrder`: new optional trailing `?int $flowId`
   (default null → old behavior); merge explicit columns when given.
7. `FlowInputSeeder`: resolve intl flow by `code=international` first
   (fallback default→first) — deterministic under multi-flow.
8. Lang files en/ar: three new keys.
9. No route/resource shape changes (backward compatible). Docs carry the
   `flow_code` contract.

## 5. Migration & rollback plan

- `php artisan migrate` (up drops unique). Verify with
  `SHOW INDEX FROM order_flows` / SQLite schema dump.
- Rollback `php artisan migrate:rollback --step=1` on a single-type DB →
  unique restored. On duplicated DB → aborts with message, data intact.
- Never run against production here; commands documented for ops.

## 6. Test plan (new `tests/Feature/OrderFlow/MultiFlowPerTypeTest.php`)

DB/identity: two flows same type coexist; duplicate code rejected; unsupported
type rejected; unrelated uniques intact.
Validation: second flow same type 201; update without type change 200; update
type local→international 200; bad type 422.
Selection: explicit `flow_code` among two local flows assigns correct `flow_id`;
mismatched flow+type 422; inactive flow 422; type-only with two actives and one
default resolves default; type-only with two actives and NO default → 422
ambiguous; existing order keeps `flow_id` after default switch.
Checkout: pending-retry with different explicit flow → 422, order untouched.
Auth: existing permission middleware unchanged (covered by suite).
Then: focused suite + `ControlledShippingTypeTest` + `FlowInputsTest` regression.
