# Phase 14 — Support Team Manual

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** As a decision-tree runbook, the manual's structure is sound and most branches still resolve correctly: terminal states are terminal, cancel/refund routing (cancel pending/processing, refund completed/delivered) matches the flow authority, invoice status actions map to real permission-gated endpoints, shipment transitions match the DAG, and the escalation matrix is reasonable. Support is additionally better equipped than the manual knows (`requiresAttention` triage endpoint with stuck-payment detection + logging, reconcile job + dashboard, structured tracking logs). But five guidance items are stale and operationally consequential: mark-paid permission renamed, cashier QR removed, BUG-4 scenario fixed (no escalation needed), gateway refunds integrated (manual says not), and invoice verification is authenticated (not public). A support agent following the manual verbatim would misroute these cases.

## 2. Phase Objective

Per `PHASE-14-SUPPORT-TEAM-MANUAL.md` (source: `HEAD:docs/production-manual/PHASE-14-SUPPORT-TEAM-MANUAL.md`, 328 lines): give support a non-developer runbook — order/payment/invoice/shipment decision trees, five common scenarios, error-message reference, and escalation matrix.

## 3. Scope

**In scope:** correctness of every decision branch, action, permission reference, and scenario playbook against current behavior; support tooling inventory (triage endpoint, reconcile, tracking logs, timelines).

**Out of scope:** engineering runbooks (DB surgery, worker restarts — referenced only), frontend support UX, SLA design, staffing/roles (no SUPPORT role exists — support operates via scoped admin grants).

## 4. What Was Supposed to Be Implemented

The manual claims: per-status trees (pending/processing/completed/delivered/cancelled; four payment states; nine invoice states; four shipment branches); five scenarios (paid-but-pending, missing invoice, coupon failure, cancel request, refund request); 10-key error table; 5-row escalation matrix.

## 5. What Actually Exists

Branch-by-branch verification:

- **§14.1 pending**: correct (cancel allowed; mark-paid for COD/cashier) except permission cited as `update-order-status` (now `payments.mark_paid`) and cashier flow cites a customer QR code (removed — Phase 06 F-03). "Trigger callback manually? NO" still correct; reconcile + stuck-payment detection now assist (manual predates both).
- **§14.1 processing**: correct (cancel allowed). Cancel effects nuance: promotion decrement is now conditional (paid-kept / never-paid-skipped — manual states unconditional decrement); inventory restore now via state claim (not listener guard); coupon never reversed (correct).
- **§14.1 completed**: correct (no cancel → refund; flow rejects completed→cancelled). Invoice "must exist — if missing check queue": now sync-first (queue check is the backup path, not primary).
- **§14.1 delivered**: correct terminal; "return via separate return process (not built yet)" — still true operationally (Phase 11 F-01: service exists, unwired).
- **§14.1 cancelled**: correct (terminal; coupon consumed; promotion conditional; invoice NOT auto-cancelled — still true, manual action required).
- **§14.2 payment-pending/failed**: BUG-4 scenario ("gateway paid but locally failed → escalate") is FIXED — error callback now completes on gateway success; the escalate path is obsolete for new callbacks (historical rows may still need reconcile).
- **§14.2 payment-refunded**: correct (irreversible).
- **§14.3 invoice trees**: statuses/transitions/permissions correct except: debit-note permission exists (manual table shows "—"); "placeholder marked ready" stale (real PDFs); verification described as public (now authenticated — support must verify as an authenticated user); regenerate/correct/cancel endpoints and allowed statuses match.
- **§14.4 shipment tree**: transitions correct; support actions now run through permissioned admin endpoints (manual lists unpermissioned paths); retry-delivery via `out_for_delivery` re-transition holds.
- **§14.5 scenario 1** (paid-but-pending): reconcile job + stuck-payment logging + `requiresAttention` now exist — playbook step 4 ("engineering must manually update") is the last resort, not the first step.
- **§14.5 scenario 2** (missing invoice): sync-first reality inverts the playbook (check order status/trigger first, queue second).
- **§14.5 scenario 3** (coupon): CPN-1 fixed; silent-clear + compensation guidance still valid.
- **§14.5 scenario 4** (cancel): matches with the promotion-conditional nuance above.
- **§14.5 scenario 5** (refund): "gateway refund not yet integrated" is STALE — both tracks integrate the gateway; playbook must add provider-outcome checking (ledger) and the delivered-digital refusal case.
- **§14.6 error table**: keys resolve in `lang/en/message.php` (spot-verified present); meanings/actions hold.
- **§14.7 escalation**: structurally sound; first-line capabilities expanded (triage endpoint, reconcile dashboard).

## 6. Architecture

```
SUPPORT TOOLING (as-built):
  Admin tracking dashboard (view-orders): order search, per-order track, requiresAttention
    (payment_pending 24h / processing_delayed 48h / payment_failed 7d /
     verification_stuck 30m–24h + stuck-payment logging P2-5)
  Reconciliation: payments:reconcile (scheduled) + dashboard summary + mismatch rows
  Tracking: access-logged public/authenticated tracking + timelines + metrics
  Invoices: admin ops (six grants) + regenerate + timeline visibility
  Refunds: request review + dual approval tracks + ledger visibility (admin)
  Notifications/history: order timelines + notification history per user
```

## 7. Complete Execution Flow

Per-scenario current flows: paid-but-pending → triage endpoint → reconcile → (gateway-paid: callback replay completes via token/idempotency; gateway-unpaid: customer retry; stuck: reaper cancels on expiry); missing invoice → check order left-pending (sync generation) → listener/queue → admin regenerate; coupon failure → validity check → compensation coupon (no retroactive consumption — policy); cancel → state-gated cancel → conditional side effects; refund → eligibility (completed/delivered + success + non-digital-delivered) → track-A approval or track-B direct → ledger + fan-out.

## 8. Business Rules

| # | Rule (support-relevant) | Current truth |
|---|---|---|
| R1 | Cancel allowed from pending/processing (unpaid); never from completed/delivered/cancelled | Flow-enforced; support guidance correct |
| R2 | Mark-paid is COD/cashier-only, `payments.mark_paid` holders | Manual cites old permission (fix guidance) |
| R3 | Refunds need completed/delivered + payment-success; delivered-digital excluded | Manual omits digital exclusion (add) |
| R4 | Coupon never returns; promotion conditional; inventory exactly-once; invoice never auto-cancelled | Manual needs promotion-conditional + state-claim updates |
| R5 | Gateway outcomes are checkable (reconcile/ledger) before engineering escalation | Manual predates (update playbooks) |
| R6 | Verification requires authentication | Manual says public (fix guidance) |

## 9. Source of Truth / Authorities

Support answers derive from the same authorities as Phases 01–11 (no support-specific writers): order lifecycle, payment completion/ledger, invoice service, shipment authority, refund tracks, tracking projections. Support-visible projections: `requiresAttention` counts, reconcile summary, tracking timelines, notification history, invoice timeline, `order_status_history`.

## 10. Database Impact

Support reads (no support writes outside admin ops already audited): orders/transactions/invoices/shipments/refunds timelines + history + tracking events + reconcile rows + notification history. No support-exclusive schema.

## 11. API Surface

Support uses admin endpoints (Phase 13) + triage/tracking endpoints: `v1/admin/tracking/*` (dashboard, list, track, requiresAttention — `view-orders`), reconcile dashboard, invoice admin ops, refund review/approval, shipment admin ops, customer notification history (for "did they get notified" checks). No dedicated support-role endpoints (support = scoped admin grants; no SUPPORT role constant exists).

## 12. Authentication & Authorization

Support staff authenticate as admin users with scoped grants (`view-orders`, invoice grants, shipment/refund grants as assigned). No support-specific role; least-privilege scoping is an ops provisioning decision (provisionally unverified how support accounts are provisioned — marked UNPROVEN). Triage endpoints enforce `view-orders` with explicit 403s.

## 13. Validation

Support inputs are admin inputs (validated per Phase 13). Runbook-driven manual DB edits (scenario 1's "fix via DB") remain the highest-risk support-adjacent action — now rarely needed (replay/reconcile/reaper cover the cases); no validated tool endpoint exists for manual payment completion (deliberate — completes only via provider verification).

## 14. Transactions

Support actions execute through the same transactional authorities (no support-bypass paths found). Manual DB surgery (escalation path) bypasses all guards by nature — should require dual-control per ops policy (outside codebase scope; noted).

## 15. Concurrency

No support-specific concurrency surface (single-actor admin ops join domain locks). Support-relevant: concurrent approval serialized (refund claim); concurrent mark-paid idempotent (latest-pending + canonical transition); concurrent cancel vs pay resolved by locks + re-checks (home phases).

## 16. Async / Queues / Events

Support-observable async: listener-backed invoice generation (backup path), PDF rendering, notification fan-out, fulfillment auto-release, credit notes, outbox publishing, sweepers/reapers/reconcilers. Queue health via Telescope + failed-job alerts; stuck-payment logging surfaces silent stalls. No support-facing queue-retry tool endpoint (admin re-dispatch is code-level).

## 17. Error Handling

Support sees the same envelope + reason codes as customers (error table verified present); throttles answer 429; signed-URL issues 404-mask; provider failures surface sanitized messages. The runbook's "escalate" rows map to: reconcile dashboard → engineering data fix (rare) → gateway ops (outage).

## 18. Security

Support tooling respects the same boundaries (owner-scoping N/A for admin views, but `view-orders` gating + access logging on tracking); no support impersonation feature found (good — no such affordance to audit); manual DB-edit guidance is the residual risk (unlogged by app audit when done at SQL level — use app endpoints where they exist).

## 19. Performance

Triage counts are indexed count queries (bounded); tracking/admin lists paginated; reconcile cursors lazily; notification history paginated. No support-path hot loop.

## 20. Tests & Verification

No support-specific tests (runbook is prose). Adjacent coverage: admin auth/RBAC suites, tracking tests, reconcile tests, invoice admin suites — all unexecuted here. The runbook's factual claims are verified statically in this audit (branch-by-branch above).

## 21. Edge Cases

Covered by runbook + verified: paid-but-pending (three sub-cases), missing invoice (three), coupon failure (compensation path), cancel matrix (five states), refund preconditions, tampered invoices (409, withhold), unresolvable webhooks (ignored-200, no support action), duplicate approvals (rejected), expired claims/reservations (swept), revoked entitlements (fail-closed downloads).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Five runbook items give wrong guidance: (1) mark-paid permission cited as `update-order-status` (now `payments.mark_paid`); (2) cashier flow assumes a customer QR code (removed); (3) BUG-4 scenario instructs escalation for gateway-paid/locally-failed (now auto-completes; escalation only for historical rows); (4) refund scenario states gateway refunds are unintegrated (integrated in both tracks); (5) invoice verification described as public (authenticated-only).
#### Evidence
§§14.1–14.3, 14.5 vs Phase 01/05/06/08/10 verifications (each cited).
#### Why it matters
Support following the runbook will request wrong permissions, look for nonexistent QRs, escalate fixed bugs, and mishandle refund verification.
#### Current behavior
Product correct; runbook wrong on these items.
#### Recommended future action
Patch the five items (surgical update, on explicit docs request); keep the tree structure.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: LOW
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Three nuance drifts: promotion decrement on cancel is conditional (manual: unconditional); invoice generation is sync-first (manual: queue-first troubleshooting order); delivered-digital orders refuse refunds (manual: no mention).
#### Evidence
§§14.1, 14.5 vs OrderService cancel branch, changeOrderStatus invoice trigger, updateRefund D7 guard.
#### Why it matters
Minor misdiagnosis risk ("promotion should have decremented", "check the queue first", "why can't I refund this digital order").
#### Current behavior
Correct product; imprecise guidance.
#### Recommended future action
Fold into the F-01 patch.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Support is better equipped than the manual knows: `requiresAttention` triage (with stuck-payment detection/logging), reconcile job + dashboard summary, access-logged tracking, per-user notification history, and invoice/shipment timelines all postdate or escape the manual. The manual sends support to engineering where self-service tooling now exists.
#### Evidence
`AdminOrderTrackingController.php:128-200` (triage); reconcile + dashboard (Phases 06/13); tracking controllers (Phase 12).
#### Why it matters
Underused tooling → unnecessary escalations.
#### Current behavior
Tooling exists; runbook omits it.
#### Recommended future action
Add a "support tooling" section to the runbook (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Support follows stale permission**: mark-paid attempt 403s → confusion/delay (fix guidance, not code).
- **Manual DB completion**: bypasses guards/history/inventory events — last-resort only; replay/reconcile paths preferred (document the preference explicitly).
- **Support account provisioning**: no SUPPORT role; over-granting admin rights to support staff would widen blast radius (provisioning process unverified — UNPROVEN, ops-owned).
- **Refund of delivered-digital**: correctly refused by system; support needs the explanation script (D7 rationale).
- **Chargeback without local refund**: manual path only (Phase 06 F-02); support playbook has no chargeback row (add).

## 24. Documentation Drift

Manual accurate for: tree structures, terminal states, cancel/refund routing, invoice actions/endpoints (modulo debit perm + auth), shipment transitions, error keys, escalation shape. Stale: five guidance items (F-01), three nuances (F-02), missing tooling (F-03), "return system not built" (still operationally true — Phase 11), queue-first troubleshooting order.

## 25. Dependencies

- **Depends on**: all domain authorities (runbook is a reader), triage/reconcile/tracking observability, notification history, admin ops endpoints.
- **Consumed by**: support operations (prose only).
- **Shared tables**: read-only over domain + observability stores.
- **Shared services**: none exclusive.

## 26. Out of Scope

Staffing, SLAs, shift procedures, frontend support UX, chargeback operations design, DB-surgery authorization policy, support account provisioning ceremony.

## 27. Residual Risks

1. Five wrong guidance items (F-01).
2. Three nuance drifts (F-02).
3. Undocumented tooling (F-03).
4. No support provisioning/impersonation model verified (UNPROVEN).
5. Manual DB-edit path unguarded by app controls.

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-14-SUPPORT-TEAM-MANUAL.md` (328 lines, read fully, temp extract). Code: `AdminOrderTrackingController.php:128-219` (triage + auth verified); home-phase authorities (Phases 01–11, cited per branch); `lang/en/message.php` (error-key presence verified); reconcile + dashboard (Phases 06/13); tracking/notification/invoice/shipment/refund endpoints (Phases 12/07/09/10). Tests: none support-specific (adjacent suites listed, not executed).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The runbook's decision structure is correct and the product gives support more leverage than the manual admits — but five concrete guidance items are wrong in ways that misroute real cases, so the manual cannot be followed verbatim. The verdict reflects a sound support posture with a runbook needing surgical correction, not a product defect.
