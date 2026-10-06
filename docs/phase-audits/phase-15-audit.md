# Phase 15 — End-to-End Production Timeline

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** As a single narrative spine, the timeline's phase order still matches the product (discover → cart → coupon/promo → checkout → pay → invoice → notify → manage → fulfill/ship → deliver → refund/return), and its concurrency/failure/table summaries are directionally right. But stage-by-stage mechanics are stale at nearly every timestamp: cart reservations, EGP-only money, single gateway, queued-only invoices, placeholder PDFs, public verification, manual shipments without events, refund-without-money, and "not implemented" archiving. Two of its "not implemented" notes are now wrong in opposite directions (taxes ARE in snapshots; archiving is STILL absent). The file cannot serve as "the single source of truth for how the platform behaves in production" (its stated purpose) without a rewrite — yet no cross-phase contradiction was found in the underlying system itself: the phases compose coherently.

## 2. Phase Objective

Per `PHASE-15-END-TO-END-PRODUCTION-TIMELINE.md` (source: `HEAD:docs/production-manual/PHASE-15-END-TO-END-PRODUCTION-TIMELINE.md`, 541 lines): trace every step from discovery through archiving with timestamps, summarize concurrency protections, failure points + recovery, and all touched tables — as the production single source of truth.

## 3. Scope

**In scope:** timeline accuracy per stage (A–I), concurrency-summary accuracy, failure-table accuracy, table-inventory completeness, cross-phase composition coherence (this file doubles as the cross-phase consistency pass for the domain chain).

**Out of scope:** re-proving domain mechanics (home phases 01–14); frontend timing claims (t=0:00-style wall-clock narrative is illustrative, not contractual).

## 4. What Was Supposed to Be Implemented

The manual claims a timestamped trace: discovery/cart with `reserveItem` + 3-day reservation; coupon/promotion selection; checkout with `ensureCartReservation`; MyFatoorah-only payment (EGP, 0.01 tolerance) with BUG-4-noted callbacks; queued-only invoice generation with placeholder PDF; notifications; order management; manual admin shipments without events; post-delivery refund (gateway unintegrated) + unimplemented archiving; plus concurrency/failure/table summaries.

## 5. What Actually Exists

A corrected timeline (verified across Phases 01–14):

- **A (discover/cart)**: public catalog (+cursor pagination/filter aliases); cart is reservation-free (no `reserveItem`, no TTL expiry, no `reserved_quantity` writes); prices snapshot at add.
- **B (promo/coupon)**: read-only eligibility; claim-gated coupons with reason codes; gift descriptors (no cart gift rows); explicit selection with loud failures.
- **C (checkout)**: no `ensureCartReservation`; order-owned atomic reservation; flow/shipping-type/input gates; pending-reuse with conflict guards; tax computed authoritatively; multi-currency 3dp totals.
- **D (payment)**: three gateways behind a fail-closed registry; per-order currency; x1000 matching; token-idempotent completion shared by callbacks + signed webhooks; BUG-4 fixed; coupon-blocked recovery; D-06 holds; zero-value local path.
- **E (invoice)**: three triggers (sync on first-leave-pending incl. cancel paths, queued backup, admin regen); real mPDF rendering; snapshot carries REAL taxes (manual's "empty" note is wrong); version still 2.1.0/schema 3; signed customer access; authenticated verification.
- **F (notifications)**: preference-gated fan-out across ~30 notification classes + center + history (manual's two-listener view is a fraction).
- **G (manage)**: owner-scoped orders/invoices; knowledge-gated + owner-full tracking; refund requests (one-per-order).
- **H (fulfill/ship)**: payment-triggered auto-release → WMS DAG (pick/pack/packages) → fulfillment-bound labeled shipments → rule-bound auto-delivery — not manual admin shipment CRUD; events + tracking throughout.
- **I (post-delivery)**: dual refund tracks (gateway-integrated both) with ledger caps + atomic claims + exactly-once restore + credit notes; returns exist as an unwired service (no replacement flow); archiving STILL has no automation (manual's note still true).

## 6. Architecture

The system composes as a pipeline of single authorities (each verified in its home phase): selection (cart) → pricing (promotion/coupon/currency/tax) → creation (flow-gated) → payment (registry → completion service) → lifecycle (flow authority) → documents (idempotent invoice) → fulfillment (WMS DAG) → shipment (transition authority) → completion (rule-bound) → post-lifecycle (refund/return tracks). No stage writes another stage's authoritative state except through the documented funnels (completion service → lifecycle; shipment → lifecycle via `maybeCompleteOrder`; cancel → fulfillment cascade; refund → payment markers). Cross-phase conflicts found: none in runtime authorities (see §24 for the manual-vs-code drift register, which is documentation-only).

## 7. Complete Execution Flow

Corrected end-to-end (timestamps illustrative): browse → add (snapshot, no reservation) → coupon/promotion selection (validated, reason-coded) → checkout (locked cart, revalidated coupon, flow inputs, totals+taxes, order INSERT with flow, order-owned reservation, slice cleanup, after-commit OrderCreated) → payment routing (registry-gated; coupon reserved pre-invoice; pending row) → verify/complete (token → pending → x1000/currency/ref checks → commit → canonical transition → events) → invoice (sync + queued backup + PDF) → notifications (preference-gated) → fulfillment auto-release → WMS pick/pack → label → dispatch → deliver → auto-deliver (rule) → post-delivery (refund tracks / return service-dark). Failure branches: retry-resume, fail-closed blocks with reconcile surfacing, reaper expiry, idempotent replays, loud refusals.

## 8. Business Rules

The timeline introduces no new rules; it composes home-phase rules (R-lists in Phases 01–14, all cited). Composition invariants verified: money moves only via provider-verified completion or ledgered refunds; stock moves only via order-owned reservation/commit/restore; lifecycle moves only via the flow authority; documents generate only via the idempotent service; shipments move only via the transition authority; discounts consume only at completion with track-appropriate reversal.

## 9. Source of Truth / Authorities

No timeline-owned state exists (correct — the timeline is a view, not a writer). Authoritative per stage: cart container, pricing calculators, flow service, gateway registry, completion service, lifecycle writer, invoice service, WMS services, shipment service, refund services/ledger. Projections: tracking events, timelines, notification history, dashboard aggregates, order mirrors.

## 10. Database Impact

The manual's §15.4 table list is a subset missing: `coupon_reservations/claims/targetings` + distribution family, flow catalog tables, fulfillment/WMS family, `order_tracking_events`, `payment_reconciliation_results`, refunds ledger surface, digital family, notification preference/device/history tables, `return_requests/items`, invoice sequences/uuid columns, transaction idempotency/3dp columns, warehouse/user extensions. Full inventory lives in the home-phase §10 sections; nothing in the timeline contradicts them.

## 11. API Surface

No timeline-owned endpoints (correct). The journey traverses the surfaces catalogued in Phases 01–14 (§11 each). No orphan or undocumented stage-transition endpoint was found by route inventory.

## 12. Authentication & Authorization

Composition preserves least privilege end-to-end: public (catalog, gateway entry points, knowledge-gated tracking) → authenticated ownership (cart/orders/invoices/downloads/tracking-full) → granular admin (status targets, financial actions, WMS scope, document ops). No stage transition is reachable without its stage's grant (verified per home phase).

## 13. Validation

Validation composes fail-closed along the timeline: request allowlists → eligibility re-checks under lock → flow/input gates → provider verification → mismatch guards → ledger caps → status-machine guards → transition DAGs. A failure at any stage aborts that stage without corrupting prior stages (transactional boundaries verified per home phase).

## 14. Transactions

Boundary composition verified: checkout single-transaction → completion transaction → lifecycle transaction (nested callers join) → document transaction (savepoint-nested, non-blocking) → WMS single-transaction ops → refund transactions (provider-outside/ledger-inside split). No distributed-transaction span; eventual consistency only via idempotent queued fan-out (notifications, release, credit notes, outbox) with afterCommit dispatch.

## 15. Concurrency

Manual §15.2 status per row: cart locks (overstated — no reservation race exists by design; merge locks remain) ✓-but-reframe; checkout cart lock ✓; callback txn+order locks + token ✓ (stronger than listed); coupon assignment/usage locks ✓ (+ reservation/claim locks unlisted); invoice lock target is the invoice row, not the order (correction); numbering sequence lock ✓; restore via state claim, not `inventory_restored_at` guard (correction); promotion once-flag ✓; coupon once-flag ✓. Unlisted: flow-gate atomicity, label backstop, refund claim/ledger, WMS claim leases. Runtime proof absent everywhere (standard limitation).

## 16. Async / Queues / Events

The timeline's async backbone (all verified): afterCommit domain events → queued listeners (notifications, invoice backup, fulfillment release, credit notes, digital delivery, outbox) → scheduled sweepers/reapers/reconcilers/publishers/notifiers. Manual's Phase-F two-listener view understates the fan-out by an order of magnitude but is not wrong.

## 17. Error Handling

Composition is fail-closed with reason codes at every customer touchpoint and loud refusals at every operator touchpoint; cross-stage failure (provider success + local crash) is ledger-noted and reconcile-visible, never silently retried; poison units never abort batch/scheduled runs. Manual §15.3 row updates: cart-stock (now checkout-time), PDF placeholder (fixed), restore guard (state claim), refund-retry semantics (idempotent keys), mismatch handling (auto-failed + held, not manual-only).

## 18. Security

End-to-end posture inherits home phases (format allowlists, signature verification, fail-closed matching, granular grants, warehouse confinement, redacted audit, throttles, signed URLs). No cross-stage trust bypass found: each stage re-establishes authority (flow re-gated per transition, eligibility revalidated at completion, ledger re-checked at approval, guards re-evaluated under lock).

## 19. Performance

No timeline-level bottleneck identified: locks are row-scoped with consistent global order; long transactions areCheckout/completion-bounded with afterCommit deferrals (metrics, fan-out); reads are eager/paginated/cached; scheduled work is chunked and idempotent. Unmeasured (no load profile in-repo) — stated, not claimed.

## 20. Tests & Verification

No timeline-level E2E suite exists (Phase 12 F-03 carried): coverage is per-domain (Phases 01–14 §20 each). Composition is statically reasoned in this audit; runtime composition unproven. Manual provides no test mapping (it predates most suites).

## 21. Edge Cases

Cross-stage edges verified: retry-resume (pending reuse + fresh txn rows), method/constraint conflicts (422s), coupon invalidation mid-flight (clear/revalidate/block), gateway-timeout at initiation (no row), callback-before-row (B4 fail-safe), duplicate provider money (D-06 hold), reaper-vs-pay (pre-check + locks), cancel-during-WMS (atomic cascade with refusals), refund-after-cancel (skip-safe), return-then-refund (shared authority, no double credit), digital-only (no auto-deliver), zero-value (local completion), key rotation (HMAC caveat).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The timeline's stated purpose ("the single source of truth for how the platform behaves in production") is unmet: stages A–I carry superseded mechanics at nearly every timestamp (reservations, currency, gateways, callbacks, invoice triggers/PDF, verification access, manual shipments, event-less operations, money-less refunds), and §§15.2–15.4 summaries are partially wrong (lock targets, guard mechanisms, table inventory) as itemized in §§15/17/10 above.
#### Evidence
This phase's stage-by-stage verification vs Phases 01–14 evidence.
#### Why it matters
New engineers and operators onboarding via this file will build a wrong mental model of every stage; incident response will look in the wrong places first.
#### Current behavior
System coherent; map outdated.
#### Recommended future action
Rewrite the timeline from the corrected narrative in §5/§7 (on explicit docs request); keep the timestamp format (it reads well).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: LOW
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Two "not implemented" notes err in opposite directions: snapshot taxes ARE implemented (manual §Phase E claims empty) while invoice archiving is STILL absent (manual §Phase I note still true, with no owner/schedule).
#### Evidence
`InvoiceSnapshotService.php:64-116` (tax lines); archiving search (enum/resources only, no command/schedule).
#### Why it matters
Tax-in-snapshot affects finance integrations built against the manual; archiving remains unbounded table growth with no policy.
#### Current behavior
Taxes snapshotted; no archival.
#### Recommended future action
Correct the taxes note; decide archival policy (retention + command + schedule) or formally defer.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
No end-to-end composition test exists and nothing was executed here; cross-stage behavior (the subject of this phase) is the least-proven layer by construction.
#### Evidence
Test inventories across all phases (domain suites only); execution impossible (MySQL-only).
#### Why it matters
Composition breaks at seams (flags, mirrors, ledgers, projections) where unit contracts meet.
#### Current behavior
Seams statically reasoned; runtime unproven.
#### Recommended future action
Add a journey smoke suite + execute all domain suites in CI with recorded results (carried from Phase 12 F-03).
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

Cross-stage scenarios (all covered by home-phase mechanisms): provider success + local crash (ledger-noted, reconcile-visible); reaper/completion/about-to-pay races (locks + pre-checks + tokens); cancel during WMS work (atomic cascade/refusals); refund after return-restock (shared authority, tested); double approval/dispatch/delivery (claims/tokens/idempotent replays); key rotation (HMAC invalidation — ops runbook item); worker/queue mismatch (convention + prior refactor audit).

## 24. Documentation Drift

Consolidated cross-phase drift register (documentation-only; no runtime authority conflicts found): cart-reservation model (02/15), currency/precision (01/06/15), gateway count + registry (06/15), completion internals (01/06/15), coupon reservations/claims/distribution (03/15), gift model (04/15), flow authority (05/15), invoice triggers/PDF/access (07/08/15), fulfillment/WMS/shipment binding (09/15), refund gateway integration (10/15), return existence-vs-reachability (11/15), journey mechanics (12/15), admin permissions/endpoints (13/15), support guidance (14/15), taxes-in-snapshot (07/15). No Phase-A-says-X-vs-Phase-B-assumes-Y runtime contradiction was found.

## 25. Dependencies

- **Depends on**: every phase (composition view).
- **Consumed by**: onboarding, incident response, Phase-16 gap baseline.
- **Shared tables/services**: none owned; all referenced.

## 26. Out of Scope

Wall-clock performance claims, load/stress characterization, frontend timing, data-retention policy design (archiving decision), chaos/fault-injection testing.

## 27. Residual Risks

1. Timeline unfit as single source of truth (F-01).
2. Taxes/archiving notes wrong in opposite directions (F-02).
3. Composition runtime-unproven (F-03).
4. Standard environment limits (no execution anywhere).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-15-END-TO-END-PRODUCTION-TIMELINE.md` (541 lines, read fully, temp extract). Code: composition verified via Phases 01–14 evidence (cited, not re-listed); plus `InvoiceSnapshotService.php:14-15,64-116` (version + taxes); archiving negative search (enum/resources only). Tests: none timeline-specific (domain suites per home phases, not executed).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The system's stages compose into a coherent pipeline with no cross-phase authority conflicts — the timeline's shape is right even where its mechanics are stale. The verdict reflects map staleness and missing composition proof, not system incoherence. No blocking defect found at the seams.
