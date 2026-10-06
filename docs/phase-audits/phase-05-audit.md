# Phase 05 — Order Lifecycle

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The order lifecycle has been rebuilt around a flow authority since the manual: `OrderService::changeOrderStatus` consults ONLY `OrderFlowService::allowsFlowTransition` (legacy map deprecated, never consulted at runtime), creation guarantees flow assignment on the INSERT path, Marvel's `updateOrder` funnels through the same writer, the reaper routes through it, and manual payment confirmation carries a dedicated financial permission. Seventeen OrderFlow test files plus the order suites pin the authority. Three of the manual's five problems are fixed or retired (P5-C2 null-column fallthrough, P5-C4 reaper payment marker, P5-C1 app-side double registration). Residual risks: completed→cancelled is flow-rejected (paid-cancel path reachability rests on non-completed paid states), self-transitions are intentional noops that still emit events, and `parent_id` is a dormant column.

## 2. Phase Objective

Per `PHASE-05-ORDER-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-05-ORDER-LIFECYCLE.md`, 336 lines): document the three state machines (order/payment/fulfillment), the `changeOrderStatus` coordination flow, events and listeners, inventory/coupon/promotion effects on cancel, problems P5-C1..C5, and recommendations R5-1..R5-6.

## 3. Scope

**In scope:** order/payment/fulfillment status machines, `changeOrderStatus` authority, flow system (catalog, assignment, inputs, granular permissions), customer cancel, admin status endpoints, Marvel adapter funnel, reaper integration, history/audit rows, cancel side-effects (inventory/promotion/coupon/fulfillment cascade).

**Out of scope:** checkout creation mechanics (Phase 01), payment verification (Phase 06), fulfillment internals (Phase 09), refund flows (Phase 10), shipment binding (Phase 09), tracking projections (Phase 12/15).

## 4. What Was Supposed to Be Implemented

The manual claims: three machines coordinated by `changeOrderStatus`/`markCodAsPaid`/`markCashierPaid` with a legacy transition map (`$allowedOrderTransitions`); dual payment-status (column + accessor with null-column bug); fulfillment mapping table; `OrderStatusChanged` on every change including self-transitions; `RestoreProductInventory` double-registered on App+Marvel `OrderCancelled`; inventory restore via `inventory_restored_at` guard; coupon never reversed / promotion reversed; problems P5-C1 (dual registration), P5-C2 (null-column accessor), P5-C3 (self-transitions), P5-C4 (no payment marker on cancel), P5-C5 (schema-guard atomicity); recommendations R5-1..R5-6.

## 5. What Actually Exists

The manual's mechanics survive, but authority moved to the Order Flow program (Sept–Oct 2026):

- **Flow-only runtime authority**: `changeOrderStatus` consults `allowsFlowTransition` and never the legacy map (`OrderService.php:853-869`); legacy map retained for advisory display only (`OrderStatusOptionsController.php:129,152`; definition `OrderService.php:747-803`).
- **Transition rules** (`OrderFlowService::allowsFlowTransition`, verified): fail-closed without flow; noop (self) allowed; terminal (`delivered/cancelled`) locked; `cancelled ← anything but completed`; `completed ← anything` (financial gate separate); `delivered ← completed` (terminal absorption) or linear succession; `failed_delivery ← out_for_delivery`; `returned ← failed_delivery/out_for_delivery`.
- **Creation guarantee**: flow columns ride the INSERT + `assignFlowToOrder` fail-closed (P2; `OrderCreationService.php:112-148`); backfill migration `2026_10_04_000001`; Marvel creation assigns local flow (`OrderRepository.php:652-656`).
- **Funnel**: Marvel `updateOrder` → `changeOrderStatus` adapter (`OrderRepository.php:462-484`); reaper → `changeOrderStatus(cancelled, skipPromotionDecrement, markPaymentFailed)`; customer cancel (pending/processing + unpaid only) → same writer (`OrderController.php:92-120`).
- **Financial gates**: F-1 `payments.mark_paid` for unpaid→completed (`OrderService.php:895-907`); Phase-8 D8-5 delivered invariant + audited force-deliver hatch (`:919-946`).
- **P5-C2 fixed**: accessor falls through on null column (`Order.php:374-395`: `array_key_exists && !== null`, then latest-transaction match, then status match).
- **P5-C4 addressed**: reaper passes `markPaymentFailed` (never-paid → `payment-failed`); ordinary cancels intentionally leave the marker (explicit opt-in design).
- **P5-C1 improved**: app ESP registers no `Marvel\Events` at all; `RestoreProductInventory` listens only to App's `OrderCancelled` and delegates to the exactly-once `InventoryRestoreService::restore()` state claim (shared with the synchronous cancel path — parallel execution safe by construction).
- **History**: immutable `order_status_history` rows with flow provenance + sanitized audit context on every transition; legacy `order_status` column synced for Marvel readers (D4).
- **Cancel cascade**: paid-cancel restores (exactly-once claim), unpaid releases reservation, conditional promotion decrement, coupon release, fulfillment cascade — all inside the same transaction (verified Phase 01, `OrderService.php:1187-1225`).

## 6. Architecture

```
OrderService::changeOrderStatus (SOLE lifecycle writer, 12-param contract)
  resolve (invoice→txn→locked order | orderId→locked order)
  → flow gate (allowsFlowTransition; invalid_flow_transition 422-class RuntimeException)
  → flow-input gate (transition context, validated pre-mutation)
  → F-1 payment-authority gate (unpaid→completed)
  → D8-5 delivered invariant / force-deliver hatch
  → markers (status/payment/fulfillment/legacy sync) + history row + logging/metrics
  → invoice-on-first-leave-pending (Phase 07)
  → completed: coupon/promotion/inventory/metrics-afterCommit
  → txn markers; cancelled: restore-or-release, conditional decrement, coupon release, fulfillment cascade
  → OrderStatusChanged always; OrderCancelled/OrderDelivered/PaymentSucceeded conditionally

Producers: checkout completion (all gateways), mark-paid (admin), reaper (system),
           customer cancel, Marvel admin update (adapter), shipment maybeCompleteOrder (Phase 09),
           refund paths (frozen to payment-marker-only, Phase 10)
Consumers: notification fan-out, timeline recorders, invoice, fulfillment release,
           metrics rebuild, coupon distribution triggers
```

## 7. Complete Execution Flow

Verified end-to-end in Phase 01 §7 (creation → completion → cancel); this phase adds the surrounding control plane:

1. **Customer cancel**: `cancel()` gates pending/processing + unpaid → `changeOrderStatus(cancelled)` → full side-effect suite → 200 with fresh resource (`OrderController.php:92-120`).
2. **Admin transitions**: granular `change-order-status.<code>` permissions + flow options/inputs endpoints (`OrderStatusOptionsController`, `OrderFlowController`, `OrderStatusCatalogController`, batch service `OrderStatusBatchService` with per-order `flow_values`); compat `update-order-status` recognized during transition (F-1 notes, `OrderService.php:1046-1050`).
3. **Flow definition**: guest-safe sanitized discovery (`FlowDefinitionController::available/byShippingType`, `routes/api.php:126-129`); admin catalog CRUD with granular perms (`routes/api.php:276-286+`).
4. **Reaper**: expired order-owned reservations → canonical cancel with ORD-1 + payment-marker parity (`CancelUnpaidOrders.php:100-140`).
5. **Marvel admin**: legacy `order_status` codes mapped to flow codes, funneled through the authority; unmapped legacy codes rejected (D5 freeze).
6. **Shipment binding**: `maybeCompleteOrder` → authority with the completed+paid+all-delivered rule (Phase 09 owns the rule; the writer stays here).

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | Flow decides every transition; legacy map never consulted at runtime | `OrderService.php:853-869` | No (display-only use in options controller) |
| R2 | Every order has exactly one flow from INSERT (fail-closed) | P2 creation guarantee + backfill + NOT NULL posture | No |
| R3 | Self-transitions are intentional noops (still emit `OrderStatusChanged`) | `allowsFlowTransition` from==to → true; P5-C3 kept by design | N/A (see F-03) |
| R4 | Terminal states are absorbing | `delivered/cancelled` reject all exits | No |
| R5 | `completed` requires payment authority unless established elsewhere | F-1 gate | System/gateway/zero-value only |
| R6 | `delivered` requires the shipment completion invariant (or audited force) | D8-5 guard | `forceDelivered` + perm + reason |
| R7 | Cancel effects: paid→restore, unpaid→release; promotion conditional; coupon released-never-returned; fulfillments cascade atomically | Cancel branch (`OrderService.php:1187-1225`) | No |
| R8 | `completed → cancelled` is flow-rejected | `allowsFlowTransition` | No direct path (see F-01) |
| R9 | History is immutable and complete (creation + every transition with provenance) | `recordStatusChange` call sites | No |

## 9. Source of Truth / Authorities

- **Lifecycle**: `changeOrderStatus` (sole writer; `current_status_id` mirror + legacy sync co-located, D9 mirror-forever).
- **Flow definition**: `OrderFlowService` + `order_flows/order_statuses/flow_inputs` tables + seed (`flowsSeed`, `ALL_STATUS_CODES` — verified verbatim in final-verification evidence).
- **Payment authority**: F-1 gate + `payments.mark_paid` grant.
- **Inventory on cancel**: `InventoryRestoreService::restore` state claim (listener + sync path share it).
- **History**: `order_status_history` (no updates, inserts only).
- **Dormant**: legacy transition map (display only); Marvel `OrderCancelled` event (fires only on legacy trait paths; app listeners ignore it).

## 10. Database Impact

`orders` status/payment/fulfillment markers + `flow_id/current_status_id/shipping_type/flow_values*` + `legacy order_status` + `parent_id` (dormant — F-04) + `cancelled_at/completed_at/paid_at` + `inventory_state/reservation_expires_at/inventory_restored_at`; `order_status_history` (immutable, flow metadata); flow catalog tables (`order_status_flows`, statuses, inputs, values per migrations `2026_09_28_000001`, `2026_09_30_000001/2/3`, `2026_10_01_000001`, `2026_10_02_000001/2`, `2026_10_04_000001`, `2026_10_05_000001`); transactions markers on completion/cancel. No destructive changes; backfill-then-guarantee posture (D6).

## 11. API Surface

| Method | URI | Auth | Handler | Notes |
|---|---|---|---|---|
| POST | `/orders/{id}/cancel` (customer) | sanctum (owner) | `OrderController::cancel` | pending/processing + unpaid gate; 422 otherwise |
| GET | `/order-flows/available`, `/order-flows/by-shipping-type/{t}` | public | `FlowDefinitionController` | sanitized discovery (D8b) |
| GET/POST/PUT | `/v1/admin/order-flows…`, `/v1/admin/order-statuses…` | sanctum + granular flow perms | admin controllers | catalog management |
| (options/PATCH/batch) | status options, granular PATCH, bulk inputs | sanctum + `change-order-status.<code>` | options/batch controllers | per-target 403s, terminal locks (StatusOptionsTest) |
| PUT | Marvel admin order update | admin | `OrderRepository::updateOrder` | funneled adapter (mapped codes; legacy-only rejected) |

## 12. Authentication & Authorization

Customer cancel is owner-scoped with state gates (unpaid pending/processing). Status mutation requires route-level `update-order-status` AND per-target `change-order-status.<code>` (403-then-200 matrix tested) AND `payments.mark_paid` for unpaid→completed AND delivery-invariant or force permission for `delivered`. Flow catalog reads are permission-split from writes. Webhook/reaper/system paths run unauthenticated with provider verification (Phase 06) or scheduler trust.

## 13. Validation

Flow values validated against flow definitions at checkout (`checkout` context) and per transition (`transition:<status>` context); unknown keys and missing required inputs fail closed with 422 (`FlowInputValidationException` + error map). Shipping-type allowlist at request layer; availability fail-closed at service layer. Batch inputs validated per order (P5-1). Admin flow edits guarded with in-flight protections (versioning deferred with revisit trigger — stated decision D7).

## 14. Transactions

Single-transaction transitions (locks: order; plus txn row on invoice-resolved paths); nested callers join; history/logging/invoice/metrics failures never break the transition (report/try-catch/afterCommit); cancel cascade (fulfillments, inventory, coupon, promotion, history, events) is atomic in the same transaction; batch endpoint isolates per-order units (partial success posture per P5-1).

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Concurrent transitions, same order | order `lockForUpdate` + flow gate re-evaluated in-lock | STRONGLY REASONED |
| Double cancel | `previousStatus !== cancelled` guards + idempotent side-effects (restore claim, release delete, decrement floor) | STRONGLY REASONED |
| Reaper vs completion | reaper lock + re-check + gateway pre-check; completion token + pending check | STRONGLY REASONED |
| Listener + sync restore duplication | shared exactly-once state claim (P7-2) | STRONGLY REASONED |
| In-flight flow-definition edits | guards-now, versioning deferred (D7) | STRONGLY REASONED (documented trade-off) |
| True parallel proof | 17 OrderFlow suites + order suites | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

`OrderStatusChanged` (every change incl. noop) → status notifications (admin/user/sms/email/push) + timeline; `OrderCancelled` → restore (queued, exactly-once) + notifications; `OrderDelivered` → delivered notifications + timeline; `PaymentSucceeded` emission control via `emitPaymentSuccess` (callback owns it; others emit inside). All payment/order events are `ShouldDispatchAfterCommit` (Phase-0 P1/P2). Structured logging + metrics (P2-5) on every transition, failure-swallowed.

## 17. Error Handling

Invalid transition → `RuntimeException(invalid_flow_transition)` → 422-class mapping at controllers; authority failures → permission error + warning log; flow-input failures → 422 with error map, nothing persisted; poison orders never abort the reaper; history/invoice/metrics failures reported, never blocking; `false` return only when order resolution fails (mapped to 404/500 upstream — the customer-cancel 500 mapping on `false` is imprecise, see F-05).

## 18. Security

Granular per-target permissions prevent privilege creep (a holder of one status code cannot move others); financial completion requires the dedicated `payments.mark_paid` (not generic order permission); force-deliver requires intent flag + reason + actor (logged warning); audit context sanitized (500-char, tag-stripped); guest discovery exposes no ids/flags/values; admin live-edit semantics documented with in-flight guards.

## 19. Performance

Transitions lock one order row (+txn row when invoice-resolved) — minimal scope; metrics rebuild deferred to `afterCommit` with fresh read; logging/metrics failure-swallowed; history insert is one row; flow lookups are small-table reads; batch endpoint loops per-order units (no bulk SQL — safe, linear).

## 20. Tests & Verification

17 `tests/Feature/OrderFlow/*` suites (flow matrix, options/granular perms, inputs/bulk, creation guarantee, reaper authority, Marvel create/update adapters, refund-webhook freeze, subsystem binding, architecture, lifecycle-response, unified status, export-processing stage, shipping-type control) + `AdminOrderTest`, `CartOrderLifecycleTest`, `OrderCreationFlowTest`, `OrderBroadcastingTest`, `CheckoutPendingOrderRedesignTest`, cancel/reaper/concurrency suites. Manual R5-6 (decrement-on-cancel regression) is covered by cancel-branch tests statically. **None executed** (environment).

## 21. Edge Cases

Covered: resolution miss (false → 404/500); noop re-set (allowed, event emitted — F-03); cancel of paid-but-not-completed (restore path — reachable in multi-stage flows); cancel of completed (flow-rejected — F-01); reaper on gateway-paid-but-callback-pending (pre-check skips); transition with missing flow (fail-closed reject); legacy-only admin code (rejected with reason); modern-only stages leave legacy column untouched (D4); batch mixed inputs (per-order validation, partial success); concurrent admin edits (DB-constraint surfacing, integrity-safe).

## 22. Potential Bugs

### Finding F-01
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: STRONGLY REASONED (design question, not defect)
#### Finding
`completed → cancelled` is unconditionally flow-rejected, so the paid-cancel restore path in `changeOrderStatus` is reachable only for paid-but-not-completed orders (multi-stage flows) — never for completed orders. Post-completion remediation (paid order that must be voided) has no lifecycle path through the canonical writer; refunds operate payment-only by design (D5/P3-3).
#### Evidence
`OrderFlowService::allowsFlowTransition` (`$to === 'cancelled': return $from !== 'completed'`); cancel branch paid handling (`OrderService.php:1187-1204`); refund freeze (`PaymentTrait.php:414-428`).
#### Why it matters
If ops ever needs to cancel a completed order (fraud, duplicate), there is no sanctioned transition — inviting direct-DB edits that bypass history/inventory/coupon/promotion handling.
#### Current behavior
Rejected with `invalid_flow_transition`.
#### Recommended future action
Confirm the intended post-completion remediation path (void-via-refund + compensating actions vs a supervised completed→cancelled flow transition) and document it; pin with a test.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Manual P5-C2 (null-column accessor returning null) is fixed — the accessor falls through on null; P5-C4 (no payment marker on cancel) is addressed by design (opt-in `markPaymentFailed` for the reaper; ordinary cancels intentionally marker-free); P5-C1 app-side double registration is gone (no `Marvel\Events` in the app provider; restore is exactly-once by state claim).
#### Evidence
`Order.php:374-395`; `OrderService.php:989-994`; app ESP (no Marvel imports); `RestoreProductInventory.php` docblock (P7-2).
#### Why it matters
Record fixed/retired items; prevents duplicate work.
#### Current behavior
Correct.
#### Recommended future action
None (record only).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: PROVEN (intentional)
#### Finding
Manual P5-C3 (self-transitions emit events) is retained by design: noop re-sets are valid flow transitions and emit `OrderStatusChanged` (plus notification fan-out) despite no state change.
#### Evidence
`allowsFlowTransition` from==to → true; `OrderStatusChanged` dispatched unconditionally (`OrderService.php:1227`); manual §Events ("Fired on every status change (including self-transitions)").
#### Why it matters
Noisy noops cost queue work and confuse timelines ("changed pending → pending").
#### Current behavior
Intentional (idempotency-friendly API semantics).
#### Recommended future action
Either keep with a comment (done in flow service) or suppress fan-out on noop while keeping the 200; record the decision.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: INFO
- Type: LEGACY
- Status: PROVEN (corrected during audit — an earlier draft of this finding incorrectly called the column dormant)
#### Finding
`orders.parent_id` (migration `2026_10_02_000002`) is live in the Marvel marketplace semantics: `Order::children` (`hasMany` via `parent_id`), `RefundRepository::storeRefund` (parent-only refunds with child fan-out via `createChildOrderRefund`, `REFUND_ONLY_ALLOWED_FOR_MAIN_ORDER`), and `markOrderPaymentRefunded` (parent + children marker sync). It is NOT used by the App order-flow layer (no flow/authority semantics attached), so its meaning is confined to the Marvel parent/children order family.
#### Evidence
`Order.php` (`children()` relation); `RefundRepository.php` (`storeRefund`, `createChildOrderRefund`, `markOrderPaymentRefunded`); migration `2026_10_02_000002_add_parent_id_to_orders_table.php`.
#### Why it matters
Dual semantics risk: the same column means "marketplace family" in Marvel code and nothing in App flow code — future flow work must not reinterpret it without a decision.
#### Current behavior
Active in Marvel paths; inert in App paths.
#### Recommended future action
Document the column's ownership (Marvel family semantics) and keep App flow logic off it unless explicitly designed.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-05
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: PROVEN
#### Finding
Customer `cancel()` maps a `false` return from `changeOrderStatus` (order-resolution miss — already handled as 404 above, so practically unreachable) to `ERROR_ADDING_ITEMS_TO_ORDER` 500 — a copy-paste status mapping that would misreport a failure class.
#### Evidence
`OrderController.php:92-120` (404 on missing order at `:95-97`, then 500-with-wrong-constant at `:115-117`).
#### Why it matters
Misleading 500s on an unreachable branch; cosmetic but indicative.
#### Current behavior
Unreachable in practice (missing orders 404 earlier).
#### Recommended future action
Map to a cancel-specific error constant.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-06
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
Seventeen flow suites plus order suites exist but none were executed here; all §15 protections are STRONGLY REASONED at best. TRUE PARALLEL CONCURRENCY NOT PROVEN for transition races, reaper-vs-completion, or batch partial-failure.
#### Evidence
`tests/Feature/OrderFlow/*` (17 files) listed; execution impossible (MySQL-only).
#### Why it matters
The lifecycle authority is the system's converence point for money, stock, and fulfillment — it needs runtime proof most.
#### Current behavior
Well-constructed; unproven at runtime.
#### Recommended future action
Run OrderFlow + order + reaper suites against real MySQL in CI; record results.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Transition on flow-less legacy row**: fail-closed reject (never legacy-allowed) — ops must backfill/assign first.
- **Reaper vs gateway payment**: pre-check + lock + pending-gate serialize; worst case the order completes and the reaper no-ops.
- **Refund webhook on completed order**: payment-marker-only freeze; lifecycle untouched (RefundWebhookFreezeTest pins statically).
- **Batch with one bad input**: per-order validation; partial success (documented P5-1 posture).
- **Flow definition edited mid-flight**: guards hold; versioning deferred (D7 revisit trigger: in-flight incident or product demand).
- **History-table outage**: transition proceeds (reported) — availability over audit completeness (stated trade-off).

## 24. Documentation Drift

Manual accurate for: three-machine framing, cancel side-effect inventory (coupon-never/promotion-conditional), history purpose, event names. Drifted: transition authority (legacy map → flow; `canTransitionOrderStatus` no longer consulted at runtime); `markCodAsPaid/markCashierPaid` as separate writers (now thin delegates); `RestoreProductInventory` mechanics (guard-column → state-claim exactly-once); payment accessor null case (fixed); cancel payment marker (opt-in parity); missing subsystems (flow catalog/inputs/granular perms/batch/force-deliver/F-1/D8-5/legacy sync/current_status_id mirror/invoice trigger/metrics-afterCommit/fulfillment cascade); event table (SMS/email/push/timeline listeners added; Marvel dual-registration removed app-side).

## 25. Dependencies

- **Depends on**: Phase 01 (creation/completion producers), Phase 03/04 (consumption/decrement callees), Phase 06 (payment verification + reconcile), Phase 07 (invoice trigger), Phase 09 (fulfillment cascade + shipment binding), inventory services, flow tables.
- **Consumed by**: Phase 06 (completion target), Phase 09 (shipment/fulfillment transitions), Phase 10 (refund adjacency), Phase 12 (status notifications/tracking), Phase 15 (timeline backbone), Phase 16 (gap baseline).
- **Shared tables**: `orders`, `transactions`, `order_status_history`, flow catalog tables, `invoices`.
- **Shared services**: `OrderService`, `OrderFlowService`, `OrderStatusBatchService`, `InventoryRestoreService`, `OrderReservationService`.

## 26. Out of Scope

Fulfillment stage internals, shipment rules, refund state machines, payment gateway behavior, tracking projections, analytics views, admin order-listing filters.

## 27. Residual Risks

1. Post-completion remediation has no lifecycle path (F-01).
2. Noop transitions emit full fan-out (F-03, intentional).
3. `parent_id` carries Marvel-only family semantics with no App-flow meaning (F-04).
4. Runtime proof absent (F-06).
5. Manual describes a pre-flow authority (drift §24).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-05-ORDER-LIFECYCLE.md` (336 lines, temp extract). Code: `OrderService.php:747-803` (legacy map, display-only), `:827-1276` (authority + cascade), `:1331-1400` (authority helpers), `:1641+` (reservation revalidation); `OrderFlowService.php` (allowsFlowTransition, resolve/validate/assign/persist, seed); `OrderFlow/*` models; `OrderStatusBatchService`; `FlowDefinitionController`, `OrderStatusOptionsController` (`:129,152` legacy display), `OrderFlowController`, `OrderStatusCatalogController`; `OrderController.php:52-129` (index/show/cancel); `OrderRepository.php:448-559` (adapter; `:652-656` creation flow); `Order.php` (`:18-44` constants, `:308-395` history + accessor); `CancelUnpaidOrders.php`; `RestoreProductInventory.php`; `InventoryRestoreService`; app ESP (`:146-180` order wiring; no Marvel imports); Marvel ESP (`:71-72`); `PaymentTrait.php:414-428` (freeze); routes (`api.php:120-158,267-300+`); `ORDERFLOW_IMPLEMENTATION_PLAN.md` + `ORDERFLOW_AUDIT_REPORT.md` (root, surviving) + final-verification evidence (quoted). Tests: 17 `OrderFlow/*` + `AdminOrderTest`, `CartOrderLifecycleTest`, `OrderCreationFlowTest`, `OrderBroadcastingTest`, `CheckoutPendingOrderRedesignTest` (all listed, not executed). Migrations: status/flow family (`2026_07_08_141643`, `2026_07_27_081643/20000`, `2026_07_28_000007`, `2026_08_19_000001`, `2026_09_11_000001`, `2026_09_21_074300`, `2026_09_21_121356`, `2026_09_28_000001/2`, `2026_09_29_000001`, `2026_09_30_000001-3`, `2026_10_01_000001`, `2026_10_02_000001/2`, `2026_10_04_000001`, `2026_10_05_000001`).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The order lifecycle is the system's best-governed authority: single writer, flow-gated transitions, funneled adapters, audited financial gates, atomic cascades, and deep (if unexecuted) test coverage. The manual's framing survives but its authority description predates the flow program. Capped by missing runtime proof, the post-completion remediation gap, and manual drift. No blocking defect found.
