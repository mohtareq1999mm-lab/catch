# COMMERCE WORKFLOW ARCHITECTURE (Phase 1 Lock)

> Status: LOCKED (Phase 1). Extends: `docs/order-lifecycle.md`, `docs/complete-flow.md`,
> `docs/architecture/runtime-pricing-architecture.md`, `docs/financial-flow.md`.
> Rule: SOURCE CODE > DOCUMENTATION. No code changed in Phase 1.

## 1. Locked decision: Release-to-Warehouse (Option C)

Neither "commit-then-fulfill" (breaks COD: warehouse must pick before cash is collected) nor
"fulfill-then-pay" (exposes unpaid online orders to pickers, complicates failure rollback) is correct
for all methods. Locked model: **fulfillment creation requires RELEASE, not payment capture.**

```text
Checkout → Reserve (active) → Payment phase → RELEASE DECISION (per-method rule)
→ Create Fulfillment → Allocate → Pick → Pack → Packages → Shipment → Delivery → Completion
```

Release rule (normative):
- Online-capture methods (myfatoorah + future Stripe/PayPal): release requires
  `payment_status=payment-success` AND `inventory_state=committed`.
- Deferred-capture methods (`cod`, `pay_at_cashier`): release requires `inventory_state=active`
  AND `payment_status=payment-pending` (capture happens at delivery / cashier mark-paid → completed →
  commit, all idempotent via existing `changeOrderStatus` canonical path).
- Picking eligibility = fulfillment exists and is released. Pickers NEVER see payment state.

## 2. Layer boundaries (normative)

```text
Order (business lifecycle) → Payment (attempts, provider-agnostic) → Inventory (sole authority:
products/product_variants + OrderReservationService) → Fulfillment (physical execution) →
Allocation (non-mutating plan) → Picking → Packing → Packages → Shipment (carrier state) → Delivery
```

Invariants: one inventory authority (§12 directive); Reservation ≠ Allocation ≠ Picking;
one transition owner per state machine (§25 matrix in COMMERCE_ORDER_STATE_MACHINE.md);
warehouse ops never write financial states; no Marvel→App reverse deps (adapter/contract instead).

## 3. End-to-end flow (see master Mermaid in COMMERCE_FAILURE_RECOVERY.md §10)

Happy path per §4 directive with failure branches: payment-failed → release + cancel-release;
callback-timeout → reconcile (`payments:reconcile` 15min); fulfillment-create-failed → retry queue
(order stays released, no fulfillment row → safe retry); allocation-failed → manual-assign item;
pick-cancel race → cancel wins pre-pick, exception flow post-pick; sealed-package + shipment-fail →
retry with idempotency key; dispatch-fail → shipment stays `label_created`, retry; duplicate webhook →
idempotent replay.

## 4. Phase plan (locked order, §41)

P0 lock (here) → P1 remediate B2/B3/B4/B5/B6/M2/M5 → P2 payment abstraction → P3 order states →
P4 reservation proof → P5 fulfillment → P6 warehouse/location → P7 allocation → P8 order picking →
P9 batch picking → P10 packing/packages → P11 shipment → P12 delivery/completion → P13 security →
P14 MySQL concurrency/idempotency → P15 audit/observability → P16 legacy cleanup → P17 validation.
