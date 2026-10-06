# ORDER FLOW SINGLE SOURCE OF TRUTH — IMPLEMENTATION PLAN (awaiting approval)

> Phase-1 deliverable. NOTHING below is implemented. Each phase lists Why / Files /
> Deps / Risk / DB impact / API impact / Tests / Acceptance. Prohibitions from §24 hold
> (no shipment redesign, no inventory/payment/coupon replacement, no RabbitMQ, no Marvel
> rebuild, no silent single-package, no auto-fulfillment listener, no second engine).

## Phase 0 — Prerequisites (before any authority change)

- P0-1 Verify + commit prior fulfillment hardening (working tree: WarehouseService,
  FK fix, batch guards, lease config, migration `2026_10_03_000001`, 2 new test files).
  Why: §13 known issues + C11; implementation assumes that baseline. Files: as per
  `git status`. Deps: owner of that session. Risk: low (verify-only). Tests: re-run
  WMS suites in isolated DB. Accept: suite green, changes committed, audit §13 items ticked.
- P0-2 Backfill audit: count flow-less orders (`flow_id NULL`) + unmapped statuses on
  `catch` (read-only queries). Why: sizes D2/D6 migrations. No writes. Accept: numbers on record.

## Phase 1 — Single guard (core acceptance)

- P1-1 `changeOrderStatus` flow-only guard. Why: remove C1 (legacy OR leg). Files:
  `app/Services/General/OrderService.php:815-836` (+ `getFlowAwareStatusTargets:752-782`
  advisory alignment). Deps: P0-2, P2-1 (no flow-less rows at runtime). Risk: high
  (touches every transition) — mitigated by existing `OrderFlow/*Test` matrix (12 files).
  DB: none. API: error keys unchanged (`invalid_flow_transition`). Tests: union-parity
  tests updated to flow-authority; new: legacy-only transition now rejected; direct
  mutation rejected. Accept: every test in §17-Transition-authority passes; no path consults the legacy map at runtime.
- P1-2 Legacy map deprecation (D1: default = deprecate, not delete). Why: audit/reference
  without runtime authority. Files: `OrderService:719-744` (+ docblock), keep
  `getAllowedOrderStatusTargets` only for flow-less migration window. Risk: low. Tests:
  no runtime caller of legacy leg (grep assertion test). Accept: static search proves zero runtime reads.
- P1-3 `fulfillment_status` mirror discipline (C10). Why: kill third vocabulary drift.
  Files: `OrderService:913-929` (sole writer stays); remove #13's independent write via P3-1.
  Tests: mirror-invariant tests (already in OrderLifecycleResponseTest). Accept: only #1 writes it.

## Phase 2 — Every order has one flow (C2/C6)

- P2-1 Creation enforcement. Why: close Marvel door. Files: `OrderRepository::storeOrder`
  (assign default-local flow via `assignFlowToOrder` — D2), `OrderCreationService` (assert,
  already fail-closed). Risk: medium (admin path). DB: none. API: none. Tests: Marvel-create
  assigns local flow; invalid type rejected; inactive flow aborts. Accept: no creation path yields flow-less row.
- P2-2 Backfill + NOT NULL (D6). Why: DB guarantee. Files: new migration (data backfill
  local-default + status mapping; then `flow_id/current_status_id` NOT NULL). Non-destructive,
  fails closed on unmapped (abort + report, never guess). Risk: medium. Tests: migration on
  seeded legacy fixture (NULLs → assigned; unmapped → abort). Accept: zero NULL rows; constraint holds.
- P2-3 `updateOrder`/`update` legacy `Order::create` call sites (seeders/dashboard/tests):
  route through flow-assigned factories. Why: stop manufacturing flow-less rows. Risk: low. Accept: grep clean.

## Phase 3 — Bypass removal (C3/C4/C5)

- P3-1 `CancelUnpaidOrders` via authority. Why: kill intentional bypass without losing ORD-1
  policy. Files: `CancelUnpaidOrders:100-121` + `changeOrderStatus` new
  `skipPromotionDecrement=false` param (D3). Deps: P1-1. Risk: medium (money-adjacent; behavior
  preserved by flag). Tests: expiry cancels via #1; promotion untouched; gateway-paid pre-check;
  concurrent cancel↔pay race. Accept: zero direct `orders.*` writes in command; outcomes identical.
- P3-2 Marvel `updateOrder` → thin adapter over #1 (D4). Why: one funnel. Files:
  `OrderRepository:448-559` (keep permission + legacy `order_status` column sync — canonical
  writer also syncs `orders.order_status` so Marvel readers never drift), trait untouched.
  Unmapped legacy codes: frozen (reject with explicit error) per D5 default. Risk: medium.
  Tests: admin PUT parity (mapped codes), legacy-only code rejected with reason, mirror parity.
  Accept: #4 contains no independent guard logic.
- P3-3 Refund + legacy webhook branches (D5). Why: C5. Files: `RefundRepository:115-139`
  (payment-only: no lifecycle mutation — matches modern refund service), `PaymentTrait:417-430`
  (route via #1 or freeze to payment-marker-only). Risk: medium. Tests: refund leaves lifecycle
  untouched; non-success webhook never moves status. Accept: grep proves no direct lifecycle writes.

## Phase 4 — Fulfillment/Shipment/Payment binding (no redesign)

- P4-1 Fulfillment: assert-only (already zero writes). Why: §7/§13. Files: none expected;
  add contract tests (fulfillment ops never write `orders.*`; batch scope intact). Deps: P0-1.
  Accept: tests green; no fulfillment→order edge except via #1 (none exists — none added).
- P4-2 Shipment: keep `maybeCompleteOrder`→#1 pattern (already Flow-validated). Why: §7.
  Files: none expected; add test pinning the conditional rule. Accept: shipment cannot move
  Order except completed+paid+all-delivered via #1.
- P4-3 Payment: no change (already via #1). Why: §7. Add pinning tests only. Accept: as-is + tests.

## Phase 5 — Inputs & contracts (close C8 + §9/§11/§12)

- P5-1 Batch per-order `flow_values` (D8a). Files: `OrderStatusBatchRequest` + service loop.
  Tests: mixed-input batch partial success. Accept: per-order values enforced.
- P5-2 Guest `by-shipping-type` (D8b, if approved): mirror sanitized resource. Risk: low.
  Tests: guest parity with `available`. Accept: frontend needs no auth for discovery.
- P5-3 Source extensions only on demand (D8c deferred). No speculative sources.
- P5-4 Admin safety note: document live-edit semantics + in-flight guards (D7 default);
  versioning table explicitly deferred with revisit trigger (in-flight incident or product demand).

## Phase 6 — Seeds (C9/D10 + §18)

- P6-1 Align international seed with §18 (add `export_processing` in order) + ensure
  `FlowInputSeeder` intl inputs + full fixture (order/flow/stages/inputs/customer/products/
  fulfillment/warehouse/locations/ProductLocations) via existing seeders. Files: `OrderFlowSeeder`,
  `FlowInputSeeder`, docs. Tests: seed idempotency + matrix tests cover new stage. Accept: §18 fixture reproducible.

## Phase 7 — Verification (§19–§21)

- P7-1 Targeted suites (Flow, Order, Fulfillment, Shipment, Payment, Inputs, concurrency)
  in isolated DB (shared `catch` has concurrent writers — observed). Then full suite.
  Record: executed/passed/failed/skipped/assertions.
- P7-2 Second audit: re-search all §20 bypass patterns; prove zero runtime bypass.
  Fix→retest→re-audit loop until clean.
- P7-3 Final report A–L (§21), including every previous bypass (file/class/method/
  caller/old/new) and authority identification (`OrderService::changeOrderStatus` +
  `OrderFlowService`, roles split: persistence+side-effects vs stage authority).

## Explicitly OUT (this implementation)

Shipment redesign, inventory/payment/coupon replacement, RabbitMQ/infra, Marvel rebuild,
single-package enforcement, auto-fulfillment listeners, second engine, legacy map as fallback,
destructive DB ops, `catch` reset.

## Approval requested

Phase-1 only. No code changed. Approve implementation (with decisions D0–D10) or request changes.
Defaults if undecided: D1 deprecate-not-delete, D2 default-local, D3 flag preserves ORD-1,
D4 adapter+column-sync, D5 freeze legacy-only codes, D6 backfill-then-NOT-NULL, D7 guards-now
versioning-later, D8 batch-values first, D9 mirror-forever, D10 align seed, D0 verify-first.
