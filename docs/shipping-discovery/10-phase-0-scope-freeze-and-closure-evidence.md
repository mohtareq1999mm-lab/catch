# Phase 0 Scope Freeze and Closure-Evidence Plan

Status: **PLANNING / EVIDENCE PLAN — NO IMPLEMENTATION AUTHORIZED.** This document satisfies #138 (parent #126). It freezes Phase 0 scope, defines per-risk acceptance criteria and required evidence types, and provides a closure matrix. No application code, schema, migration, route, test, or API documentation file is changed by this plan.

## 1. Scope freeze

**Frozen Phase 0 scope:** risks **R1, R2, R3, R4, R5, R6, R7, R8, R10**.

**Deferred:** **R9**.

**R9 deferral trigger:** any future introduction of **DB-backed provider settings** (credentials, provider enablement/configuration persisted in DB, or runtime-editable provider settings). While provider configuration remains environment/config-release backed and no DB-backed provider settings exist, R9 remains deferred. Triggering R9 requires an explicit decision record before implementation.

**Rationale (recorded, not re-litigated here):**

- R1-R7 address shipment lifecycle integrity, authorization, timeline consistency, and transition ownership.
- R8 addresses parallel event namespaces that can cause missing or duplicated side effects when provider events are integrated.
- R10 establishes return-status ownership and prevents carrier-return handling from drifting away from existing return/inventory workflows.
- R9 covers `FastShipping` settings audit trail, not carrier credentials; it is deferrable while provider config is env/config backed.

**Authority boundary:** this freeze is a dependency/gate decision only. It does not authorize provider automation, schema work, route changes, or unrelated refactors. No provider automation begins before R1-R8 and R10 are closed with reviewed evidence.

## 2. Closure rule

A Phase 0 risk may be marked **CLOSED** only when all of the following are true:

1. The risk's acceptance criterion is satisfied by repository/runtime evidence.
2. The required evidence type is produced and reviewed.
3. The PR records exact command(s), environment, and result for executed verification.
4. Evidence includes file:line references and classifies claims as VERIFIED / TESTED / INFERRED / UNKNOWN / RISK where appropriate.
5. Adjacent lifecycle authorities are checked for regression (shipment, fulfillment, order, payment, inventory, timeline/mirror).
6. An independent review has no unresolved MUST-FIX findings for the risk closure.

**Prohibited closure basis:** a design document, proposal, architecture note, isolated code inspection, or passing isolated unit test alone **cannot** close a risk. Design approval is not closure. Matrix approval is not closure.

## 3. Risk ledger

| Risk | Scope | Exact risk | Acceptance criterion | Required evidence type | Closure status |
|---|---|---|---|---|---|
| R1 | Included | Legacy order-only shipment creation bypasses `ready_to_ship` gate. | Legacy path is removed or gated through the same fulfillment `ready_to_ship` validation used by canonical creation; no shipment row can be created without a fulfillment that passes the gate; legacy route, if retained, is a compatibility shim delegating to canonical service behavior. | Feature/regression test; route evidence (`route:list` or equivalent) if routes change; DB assertion showing zero created rows on rejection; PR evidence record. | OPEN |
| R2 | Included | Legacy `updateStatus()` can mark delivered without fulfillment/order convergence. | Any delivered path converges through `markDelivered()`/canonical delivery invariants; direct `updateStatus('delivered')` is refused or routed through canonical convergence; no delivered shipment mirror without fulfillment/order convergence required by existing invariants. | Feature/regression test for delivered bypass; convergence assertions (shipment, fulfillment, order mirror/state as applicable); static/code-path evidence; PR evidence record. | OPEN |
| R3 | Included | Admin order shipment endpoint writes mirror only, not shipment rows. | Admin shipment status endpoint delegates to `ShipmentService` or is retired as shim; no direct order mirror write outside canonical writer; no duplicate transition/timeline emission. | Controller delegation test; DB assertions for shipment row + mirror consistency; static check for no direct mirror write; PR evidence record. | OPEN |
| R4 | Included | Two mirror writers + recomputed ETA in customer tracking. | Exactly one order shipment-mirror writer; ETA comes from canonical aggregate source; customer tracking reads canonical/shipment-derived values and does not fabricate unavailable ETA from `created_at + fulfillment_type` when shipment aggregate data exists. | Static call-graph/writer enumeration; aggregate truth-table tests (including amended R12-Q8-CORRECTION cases); ETA unavailable/clearing tests; customer contract regression test; PR evidence record. | OPEN |
| R5 | Included | `delayed`/`failed_delivery` unreachable via WMS; public generic route commented out. | Authorized WMS/service paths exist for `delayed -> in_transit|out_for_delivery` and `failed_delivery -> out_for_delivery|returned` through canonical `ShipmentService`; terminal states cannot regress; no dead commented public generic route remains as the intended path. | Feature tests for transitions and authorization; transition-matrix/governance test; `route:list` or route test evidence; PR evidence record. | OPEN |
| R6 | Included | Bulk WMS endpoints lack per-item warehouse scope. | Every bulk create/dispatch/deliver validates warehouse scope per item; out-of-scope item fails closed (403/masked 404 per policy) with zero partial mutations. | Feature tests with mixed-warehouse fixtures; DB assertions proving no mutation on failure; authorization/scope review; PR evidence record. | OPEN |
| R7 | Included | Shipment timeline events dispatched from different paths. | `ShipmentService` is the sole canonical transition emitter; timeline row is written in the transition transaction; transition event dispatch occurs after commit with canonical transition identity; rollback leaves no timeline/event side effect; duplicate listener/replay is deduped by transition identity. | Static dispatch/call-path evidence; rollback test; after-commit dispatch test; payload/identity test; duplicate/replay dedupe test; PR evidence record. | OPEN |
| R8 | Included | Parallel event namespaces for order lifecycle events. | One canonical event class per event concept in the shipping path; listener registration is unambiguous; no duplicate listener registration or double side effects; deprecation/alias strategy documented where needed. | Static namespace/registration audit; dispatch/listener registration tests; side-effect count assertions; PR evidence record. | OPEN |
| R9 | Deferred | No shipping provider settings audit trail (`FastShippingController::updateSettings` -> `FastShippingRepository::updateSettings`). | **Deferred until trigger.** When triggered, every provider-settings mutation records actor, before/after, timestamp, and affected scope in an audited/redacted record. | Audit-row/feature test when triggered; redaction/security review; PR evidence record. | DEFERRED |
| R10 | Included | Return status uses literals in `ReturnService`, no enum/transition owner. | One authoritative return-status enum/transition owner; literals replaced; invalid transitions rejected; legacy stored values remain read-compatible where present; no new `restocked` transition introduced without separate approved decision. | Unit/feature tests for allowed/denied transitions; static test/search showing no unauthorized status literals remain; legacy-row read compatibility test if applicable; PR evidence record. | OPEN |

## 4. Closure matrix

The matrix below is the review checklist for closing Phase 0 risks. **Matrix approval/review is not closure.** Each row remains OPEN until its own evidence is attached to a reviewed PR.

**Implementer self-review record (independent review pending):** self-reviewed by the implementing agent against #138 acceptance criteria only. Referenced shipping-discovery source docs (Doc 1/7/8/9) are **not present in this isolated worktree**; cross-document consistency is **UNVERIFIED** pending review in a base containing those docs. Stakeholder/independent review is still required before using any row as closure evidence. Matrix approval is not closure.

| Order | Risk | Scope | Acceptance criterion (summary) | Required evidence types | Required artifact / PR evidence | Closure status |
|---:|---|---|---|---|---|---|
| 1 | R7 | Included | Single canonical transition emitter; timeline in transaction; event after commit with identity; rollback/replay safe. | Static dispatch/call-path evidence; feature/regression test; transaction rollback test; after-commit dispatch test; dedupe/replay test; PR evidence record. | PR link, command/env/result, file:line, rollback test, after-commit test, payload/identity assertions | OPEN |
| 2 | R4 | Included | One mirror writer; canonical aggregate/ETA; no fabricated ETA when shipment data exists. | Static writer/call-graph enumeration; feature/regression tests; aggregate truth-table/ETA tests; customer contract regression test; PR evidence record. | PR link, writer enumeration, amended truth-table matrix, ETA unavailable/clearing tests, customer contract regression | OPEN |
| 3 | R1 | Included | No shipment without `ready_to_ship` fulfillment; legacy create path gated/shimmed. | Feature/regression test; route evidence (`route:list` or equivalent); DB assertion; PR evidence record. | PR link, route evidence, rejection test with zero-row assertion, shim/delegation proof | OPEN |
| 4 | R2 | Included | Delivered path converges through canonical invariants; no delivered bypass. | Feature/regression test; convergence assertions; static/code-path evidence; PR evidence record. | PR link, delivered-bypass regression, convergence assertions, code-path evidence | OPEN |
| 5 | R3 | Included | Admin shipment status endpoint delegates; no mirror-only write. | Controller delegation feature/regression test; DB assertions; static no-direct-write evidence; PR evidence record. | PR link, controller delegation test, row+mirror assertions, no-direct-write evidence | OPEN |
| 6 | R5 | Included | Delayed/failed-delivery transitions reachable through canonical WMS/service path. | Feature/regression transition tests; authorization tests; route evidence; PR evidence record. | PR link, transition matrix tests, auth tests, route evidence | OPEN |
| 7 | R6 | Included | Bulk WMS operations enforce per-item warehouse scope; fail closed. | Feature/regression tests with mixed-warehouse fixtures; authorization/scope review; DB zero-mutation assertions; PR evidence record. | PR link, mixed-warehouse tests, zero-mutation assertions, scope review | OPEN |
| 8 | R8 | Included | Canonical event namespace/listener registration; no double side effects. | Static namespace/registration audit; integration/feature dispatch tests; side-effect count assertions; PR evidence record. | PR link, namespace audit output, registration/dispatch tests, side-effect count assertions | OPEN |
| 9 | R10 | Included | Single return-status owner/enum; invalid transitions rejected; legacy read compatible. | Unit/feature transition tests; static literal-search evidence; legacy-read compatibility evidence; PR evidence record. | PR link, transition tests, literal-search/static evidence, legacy-read compatibility evidence | OPEN |
| - | R9 | Deferred | Audit trail required only after DB-backed provider settings trigger. | Audit-row/feature test when triggered; redaction/security review; PR evidence record. | Decision record triggering R9 + PR evidence when implemented | DEFERRED |

## 5. Evidence package template

Every risk-closure PR must include:

- **Risk ID / scope:** R1-R8/R10 included; R9 only if trigger decision is recorded.
- **Exact commands:** command, working directory/environment, date/run context, exit code, relevant output summary.
- **Files inspected/changed:** file:line references.
- **Tests/evidence:** type (static/feature/integration/regression/runtime), result, and why it proves the acceptance criterion.
- **Adjacent regression check:** shipment/fulfillment/order/payment/inventory/timeline-mirror impact.
- **Classification:** VERIFIED / TESTED / INFERRED / UNKNOWN / RISK.
- **Reviewer sign-off:** independent review result; MUST-FIX items resolved or risk remains OPEN.

## 6. Dependencies and sequencing

Recommended closure order from prior discovery (dependency plan, not implementation authorization):

1. R7
2. R4
3. R1
4. R2
5. R3
6. R5
7. R6
8. R8 and R10 in parallel only after R7/R4 foundations and shared dependencies are closed

R9 is outside this sequence until its trigger fires.

## 7. References

- Issue: #138 (parent #126)
- Referenced shipping-discovery source docs below are **not present in this isolated worktree**; section-level consistency is **UNVERIFIED** here and must be confirmed in a base containing these files:
  - `docs/shipping-discovery/01-existing-architecture-audit.md` — risk definitions and closure-evidence matrix baseline (UNVERIFIED in this worktree).
  - `docs/shipping-discovery/07-implementation-backlog.md` — Phase 0 sequencing and exit criteria (UNVERIFIED in this worktree).
  - `docs/shipping-discovery/08-risks-and-open-decisions.md` — R7-Q3 scope freeze, R9 deferral trigger, Round 12 constraints (UNVERIFIED in this worktree).
  - `docs/shipping-discovery/09-round12-decision-frontier.md` — R12-Q11 repository-evidence gaps and amendments (UNVERIFIED in this worktree).
