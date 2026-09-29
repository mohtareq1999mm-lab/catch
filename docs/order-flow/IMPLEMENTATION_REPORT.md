# Order Flow — Implementation Report

## Final alignment (2026-09-29) — controlled identity + hardened contracts

Authoritative corrections to earlier sections of this file:

- `shipping_type` is CONTROLLED (`local` | `international`,
  `OrderFlowService::SUPPORTED_SHIPPING_TYPES` + request allow-list),
  NOT dynamically admin-creatable. Any earlier "dynamic shipping type"
  wording in this repo is superseded.
- `code` is an admin/human label, never the routing key. `is_default`
  is preserved but NOT read by resolution.
- Per-target `change-order-status.<code>` permissions ARE implemented
  (seeder + dual asserts); the older "not implemented" note is
  superseded.
- Pending retry across `shipping_type` now fails closed
  (`pending_order_shipping_type_conflict`, 422) in regular checkout,
  matching Fast Shipping's existing guard.
- New: guest-safe `GET /api/v1/general/order-flows/available`
  (canonical discovery); per-type endpoint reuses the same sanitized
  contract (no ids/flags). Customer order payloads no longer expose
  `flow.id`/`flow.is_active`.
- New guards: last-active-flow deactivation → 422; status deactivation
  with in-flight holders → 422.
- Fixed: `validation.options` now enforced for `multi_select`.
- Kept intentionally: legacy-union transition fallback (parity tests
  lock it in; removal needs proven equivalence), OR-legacy permission
  bridge on flow-management routes (tightening deferred pending a
  production role audit), live-definition flows (no versioning).

## What already existed (reused, not duplicated)

- `order_statuses` / `order_flows` / `order_flow_statuses` + 3 migrations,
  `OrderFlowService` (resolve/assign/transition/validate), admin
  flow/catalog controllers + requests + resources, `OrderFlowSeeder`,
  union transition guard in `OrderService::changeOrderStatus()`,
  `order_status_history` audit, shipment/fulfillment/payment/inventory
  machines, Spatie permissions, `resources/lang/{en,ar}` + Spatie
  Translatable models, 30 flow tests.

## What was missing (added)

- `flow_inputs` table/model (`FlowInput`: generic types + sources,
  translatable label/placeholder/help, `required_at`, sort_order).
- Bilingual catalog: `order_statuses.name` + `order_flows.name` converted
  to Spatie-translatable `{en, ar}` (migration `2026_10_01_000001`;
  codes untouched); all flow/status resources emit `{en, ar}`;
  `LocalizedName` helper with legacy-string fallback.
- `order_flow_values` audit table/model.
- `orders.origin_country_id / destination_country_id / customs_reference`
  (nullable, FK-constrained).
- `FlowInputValidator` + `FlowInputValidationException` (fail-closed,
  per-key 422 errors).
- Checkout gate + transition gate inside `OrderService` (same
  transactions; status never changes on validation failure).
- `GET order-flows/by-shipping-type/{type}` (definitions only).
- Admin inputs CRUD (`FlowInputController` + `FlowInputUpsertRequest` +
  `FlowInputBulkStoreRequest` + `FlowInputResource` with `{en, ar}` contract).
  POST is bulk-only `{inputs: [...]}`: one request → one flow → many
  definitions → one transaction (all-or-nothing; duplicates in-request and
  against existing rows, sort collisions, and required-adds with in-flight
  orders all fail closed before persisting).
- `flow_values` on `OrderCreateRequest` + `OrderStatusUpdateRequest`;
  structured 422 errors in checkout + status controllers.
- 4 permissions (enum + seeder + en/ar labels) wired OR-legacy.
- Seeder hardening: `PermissionSeeder` now creates the UNION of the master
  list and all role arrays before syncing (previously a name referenced
  only by a role array — e.g. `correct-invoice` — aborted `db:seed` with
  `PermissionDoesNotExist`). firstOrCreate keeps it idempotent;
  super_admin still receives the full set.
- 25+ en/ar validator/config messages.
- `FlowInputSeeder` (additive, preserves customizations) + 15 new tests.

## What was changed (compat-preserving)

- `OrderService::changeOrderStatus()` gained trailing `array $flowValues = []`
  (all existing positional calls unaffected); `Order` fillable + 3 relations
  (`originCountry`, `destinationCountry`, `flowValues`);
  `OrderRepository::syncOrderStatusColumn()` now enforces the union guard
  for flow-carrying orders (legacy + GraphQL funnel);
- Controlled `shipping_type`: the `SUPPORTED_SHIPPING_TYPES`
  allow-list (`OrderFlowService`, checkout request validation) is the
  intentional business discriminator (`local` | `international`);
  resolution is fail-closed on top (active flow required). Checkout keeps
  its local-default; Flow creation keeps `shipping_type` required +
  unique + immutable. No migration: both columns were already
  controlled strings.
  `OrderFlow::inputs()`; `OrderFlowService` gained input helpers (no
  existing method altered); admin flow `show` adds `inputs[]`; routes added
  (none removed); seeder + lang additions only.

## What was intentionally NOT changed

- No new workflow engine; `OrderFlowService` remains the authority.
- Payment/shipment/fulfillment/inventory/return state machines untouched.
- No `DELETE` flow endpoint (deactivate, don't delete).
- No status-specific permissions (decision + hook documented).
- No full flow versioning (deferred with guard, see below).
- No frontend code (APIs are complete/deterministic for external clients).
- `docs/api/*` + `API_STORY.md` untouched (documentation safety mode).

## Legacy paths found

- `OrderRepository::updateOrder()` + `OrderManagementTrait::changeOrderStatus()`
  + `syncOrderStatusColumn()`: FUNNELED — union transition guard for
  flow-carrying orders AND granular target-status assert
  (`change-order-status.<mapped>`, 403) for authenticated callers.
  Covers the admin edit endpoint and the GraphQL `OrderMutator` (which
  delegates to `updateOrder()`). Unmapped legacy-only codes keep
  general-permission behavior. Residual: still skips history/F-1 side
  effects — transition-safe; full parity stays canonical.
- `CancelUnpaidOrders`: GUARDED exception (pending→cancelled, unpaid +
  expired only, mirror + history synced, ORD-1 policy). Unchanged.
- `RefundRepository::changeOrderStatus()` (private): SAFE, no change —
  writes only the legacy `order_status` column (`order-refunded`) +
  `payment_status` on refund approval; never touches `orders.status`,
  `flow_id`, or `current_status_id`. Refund vocabulary is deliberately
  outside Order Flow.
- Payment callbacks/webhooks: canonical `OrderService` path, no-user
  (provider-verified) callers skip only the granular user assert;
  transition/input/business gates still apply.

## Security issues found / fixed

- `$request->all()` never used; `flow_values` allow-listed per flow;
  unknown keys → 422; sources checked `exists + active`; key regex + caps;
  definition endpoint returns no values/PII; `OrderFlowUpsertRequest`
  still relies on route middleware (unchanged) while the new
  `FlowInputUpsertRequest` follows the same convention — documented as
  LOW residual with route-level coverage verified via 403 tests.

## Permission decisions

Granular config perms (4) + legacy OR-wiring; transitions stay on
`update-order-status` + F-1; status-specific perms deferred with revisit
trigger; warehouse roles excluded by design.

## Translation decisions

Spatie translatable JSON for admin-authored labels (matches `Country`);
lang-file messages under `checkout.*`; `{en, ar}` resource contract;
no new localization structure.

## Database changes

`2026_09_30_000001` flow_inputs, `000002` order_flow_values,
`000003` orders columns — all additive/nullable/reversible. Test trait
`CreatesTestTables` extended in parity.

## API changes

1 public definition GET, per-order options GET
(`GET /api/v1/orders/{id}/statuses`: current + candidates with
transition/permission flags, bilingual names, required-input hints),
4 admin input routes, 2 extended payloads (`flow_values`), 1 extended
admin response (`inputs[]`). PATCH now enforces general AND granular
target permission (403) in addition to transition/input/business gates.
Zero breaking changes except one intentional contract change: flow/status
`name` is now `{en, ar}` (admin writes still accept plain strings, stored
as `en`). Inactive input keys are ignored (retired gracefully); unknown
keys 422.

## Tests

`tests/Feature/OrderFlow/FlowInputsTest.php` (15 tests: definition,
checkout, transition, CRUD/guards, permissions, security, i18n) +
`tests/Feature/OrderFlow/StatusOptionsTest.php` (15 tests: auth, IDOR,
general/target permission matrix, flow-override denial, inactive target,
super-admin, F-1 payment gate, txn safety, bilingual names, PII).
Execution: MySQL `catch` unavailable in this environment and Docker
daemon down — suites written against the existing harness
(`CreatesTestTables` + `DatabaseTransactions`) for CI; local run NOT
executed (marked UNVERIFIED, must run in CI before merge).

## Verification results

- Pass 1 architecture: no duplicated engine; boundaries kept; dependency
  direction controller→service→model preserved. `php -l` clean on all
  26 touched files; `route:list` confirms 5 new routes.
- Pass 2 business: matrix + gates trace every transition/input/side
  effect; union semantics preserved (existing 30 flow tests unaffected —
  no required inputs exist until seeded/published).
- Pass 3 security: endpoint×permission matrix in PERMISSIONS.md; 403/401/
  unknown-key/inactive-source/PII tests included.
- Pass 4 data: FKs RESTRICT/CASCADE mirror existing conventions;
  downgrades safe; backfill unnecessary (all nullable).
- Pass 5 API: docs/order-flow/{API,GLOBAL_ORDER_FLOW_BACKEND}.md with
  exact shapes; `{en,ar}` localization verified in test.

## Remaining risks

1. Tests not executed locally (no DB) — MUST run in CI.
2. Concurrent admins creating colliding keys/sorts fail at the DB unique
   constraint (integrity safe; same accepted pattern as the existing
   single-default race). Pre-checks make the single-writer path 422-clean.
3. Versioning deferred: required-input edits on published flows rely on
   the in-flight guard; revisit on first blocked admin change.
3. Marvel legacy status path + `CancelUnpaidOrders` still exist —
   funnel in follow-up; new code never uses them.
4. `authorize()=true` on flow requests (route middleware is the real
   gate; covered by 403 tests but belt-and-braces check recommended).
