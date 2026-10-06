# Phase 16 — Gap Analysis & Final Production Readiness Report

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** This phase re-audits all 14 GAPs from the manual against current code: **9 are fixed or retired** (GAP-1, 2, 3/13, 4, 5, 6, 9, 11, 12), **1 is moot by redesign** (GAP-7), **1 is partial** (GAP-8 returns: built but dark), **1 is narrowed but open** (GAP-10 overrides: allowlisted, not re-validated), and **1 is closed by supersession** (GAP-14 notifications: full center exists). The manual's overall "6/10 NOT PRODUCTION READY" baseline is superseded: the P0 roadmap is worked off, the test corpus tripled (447 files), and no HIGH blocker from its matrix survives. What keeps this from PASS is the same cross-cutting condition as every phase — zero runtime execution proof — plus a carried forward register of new residual gaps discovered in Phases 01–15 (refund-reconcile stub, QR artifact, return wiring, journey E2E, auth-wall decisions).

## 2. Phase Objective

Per `PHASE-16-GAP-ANALYSIS.md` (source: `HEAD:docs/production-manual/PHASE-16-GAP-ANALYSIS.md`, 496 lines): compare implementation vs expected production design for every subsystem (14 GAPs with severity/impact/fix), architecture diagram, readiness scores, P0–P3 roadmap (22 items), test matrix (~150 tests + 78 required), launch checklists, and rollback strategy.

## 3. Scope

**In scope:** re-verification of GAP-1..14, roadmap-item completion status, test-matrix currency, scorecard refresh, launch-checklist currency, carried new-gap register, rollback-strategy currency.

**Out of scope:** re-proving domain mechanics (home phases); executing the launch (no deployment action taken or recommended here).

## 4. What Was Supposed to Be Implemented

The manual claims 2 HIGH + 6 MEDIUM gaps (+ subsystem absences), scores the system 6/10 NOT READY, roadmaps 22 items (P0 blockers → P3 future), inventories ~150 tests with 78 required (incl. zero concurrency/load/security/contract coverage), and defines launch/rollback checklists predicated on the gaps.

## 5. What Actually Exists

GAP-by-GAP re-verification (all evidence in home phases):

| GAP | Manual claim | Current status | Evidence |
|---|---|---|---|
| GAP-1 debit-note permission | Missing (HIGH) | **FIXED** — `permission:ISSUE_DEBIT_NOTE` present | Phase 07 (`InvoiceController.php:40`) |
| GAP-2 shipment permissions | Missing (HIGH) | **FIXED** — public surface unrouted; admin surface permissioned | Phase 09 (`api.php:264-265,402-407,409-411`) |
| GAP-3/13 error callback | Always-failed (MED) | **FIXED** — success honored via canonical service | Phase 01 (`OrderController.php:672`) |
| GAP-4 dual events | Lost notifications (MED) | **RETIRED** — legacy fanout frozen, listeners dormant, App events authoritative | Phases 01/05/06 |
| GAP-5 PDF placeholder | No PDF (MED) | **FIXED** — real mPDF rendering, Arabic shaping, stored checksums | Phase 07 (PDF job) |
| GAP-6 reaper | Missing (MED) | **FIXED** — exists, scheduled 5m, locked + re-checked + gateway pre-check | Phase 01 (command + Kernel) |
| GAP-7 cart expiry schedule | Missing (MED) | **MOOT** — carts hold no reservations; nothing to expire (`CartExpirationTest` pins) | Phase 02 |
| GAP-8 return system | Absent (MED) | **PARTIAL** — service+models+tables+tests exist; no API/callers/money linkage | Phase 11 |
| GAP-9 shipment events | Absent (MED) | **FIXED** — status/ETA events + timeline + tracking + notifications | Phase 09 (ESP wiring) |
| GAP-10 correction overrides | Arbitrary (MED) | **NARROWED** — request allowlist constrains keys; post-override snapshot NOT re-validated | §22 F-01 (this file) |
| GAP-11 correction event | Missing (LOW) | **FIXED** — `InvoiceCreated` + PDF job dispatched afterCommit | `InvoiceService.php` correction block |
| GAP-12 state lists | Inconsistent (LOW) | **ADDRESSED** — cancel ⊇ debit by void-vs-debit design | Phase 07 (`cancelInvoice` 7-state list) |
| GAP-14 notifications | Missing (MED) | **CLOSED** — full center (channels×events×quiet-hours), ~30 notification classes, history | Phase 12 |

Roadmap status: P0 items 1–7 all worked off (permissions ×2, error callback, reaper, cart-expiry moot, dual events retired, notifications built); P1 mostly done (PDF, overrides narrowed, correction event, shipment events, state unity) except QR-on-PDF; P2 split (policies partial — `ImportPolicy` exists, Order/Invoice policies not found; payment accessor fixed; returns partial); P3 largely open (contract/load/pen-test absent; concurrency suites exist statically).

Test matrix: ~150 files claimed → **447 test files** in 32 dirs today; P0/P1 required tests exist as files (idempotency, error-callback-success, mismatch, reaper, permission, PDF, correction, override-validation, shipment events, concurrency suites); concurrency/load/security/contract runtime proof still absent (suites exist statically except load/contract/pen-test).

## 6. Architecture

The manual's Part-B diagram is structurally accurate (frontend → API → thin controllers → services → events/jobs/models → MySQL → gateway/pusher/mail) but incomplete: missing the flow authority, WMS DAG, coupon claim/distribution, multi-gateway registry, webhook ingress, outbox, tracking projections, digital delivery, notification center, and settings-driven gateway definitions. No architectural contradiction — only omission.

## 7. Complete Execution Flow

Launch-readiness flow as rebuilt by this audit: P0 gaps closed → domain authorities verified per phase → residual risks catalogued (Phases 01–15) → carried register below → runtime proof outstanding (CI with MySQL) → docs refresh (on request) → launch decision (product/ops owned, explicitly not made here).

## 8. Business Rules

Gap-closure rules confirmed: fail-closed financial handling preserved through every fix (no fix weakened a guard to close a gap); void-vs-debit state design (GAP-12) is intentional; cart-expiry mootness is by redesign (not neglect); notification supersession is additive (no advisory contract broken).

## 9. Source of Truth / Authorities

This phase owns no state; it certifies home-phase authorities. Gap dispositions reference the exact file:line evidence in Phases 01–15 §22 findings (no duplicate claims; this file is the index).

## 10. Database Impact

No gap closure required destructive schema work (all additive migrations, verified in the migration inventory). Backstop constraints added (label uniqueness, pending-unique repair, coupon canonicalization, assignment-usage checks) fail loudly by design. Archival remains the only unbounded-growth area with no policy (carried).

## 11. API Surface

No phase-owned endpoints. Launch-checklist endpoint references (verify throttle 60/min, download 30/min) are stale: verify is now authenticated + 5/min; download is signed + 30/min (Phase 08). Queue/worker names in the checklist are stale (semantic names now).

## 12. Authentication & Authorization

Checklist permission items are satisfied and exceeded (six invoice grants, per-target status grants, financial grants, WMS confinement). Remaining auth-adjacent work: Order/Invoice policy classes (P2 item 14 — `ImportPolicy` exists as precedent; order/invoice object policies not found), support provisioning model (UNPROVEN, ops-owned).

## 13. Validation

Correction-override validation exists at the request layer (allowlist) but not at the snapshot-invariant layer (F-01). All other P0/P1 validation gaps (flow inputs, refund amounts in minor units, WMS requests, targeting trees) are closed as verified in home phases.

## 14. Transactions

No gap fix introduced transaction-scope regressions (verified per home phase: nested savepoints, afterCommit fan-out, lock ordering, failure isolation). Correction runs in a single transaction with afterCommit dispatch (GAP-11 fix preserves atomicity).

## 15. Concurrency

The manual's concurrency section is superseded by per-phase §15 tables (all STRONGLY REASONED, none PROVEN at runtime). The reaper-concurrency question it raised is answered (locks + re-checks + pre-checks). The remaining concurrency work is execution, not construction — except load characterization, which was never built.

## 16. Async / Queues / Events

Queue posture modernized since the manual: semantic names, config-resolved physical queues, afterCommit discipline, DLQ-adjacent recovery (distribution), failed-job alerts, bounded prunes. No queue-related gap survives except worker/queue rename convention discipline (deployment-owned).

## 17. Error Handling

Gap fixes preserved or improved error semantics (reason-coded rejections, loud refusals, ledger-noted provider divergences, reconcile surfacing). No fix converts a loud failure into a silent one (verified per finding).

## 18. Security

Both HIGH gaps closed; throttle posture expanded (cart/refunds/tracking/verify/callbacks/webhooks/admin); webhook cryptography added; test-bypass triple-gated + tested (statically); audit redaction added; mass-assignment surfaces allowlisted. Open security-adjacent items: refund-reconcile stub (blind spot), QR/hash exposure hygiene, verify auth-wall decision, Order/Invoice object policies, pen-test absence.

## 19. Performance

No measured profile exists (then or now). Structural posture is sound (row-scoped locks, chunked schedulers, cached aggregates, queued fan-out, paginated reads). The manual's load-test roadmap (P3) remains the honest answer: unmeasured.

## 20. Tests & Verification

447 files / 32 dirs vs manual's ~150: the corpus tripled with targeted coverage (idempotency, concurrency/stress, webhook/security, RBAC/gates, lock tests, recovery tests, distribution pipeline, E2E-ish suites). **Zero executed here** (MySQL-only). Missing as files: load tests, contract tests, pen-tests, journey E2E, and a handful of pin tests (decrement floor, parity extraction, single-registration). The manual's Part-E is thus half-obsolete (counts/coverage grown) and half-current (runtime proof still the gap).

## 21. Edge Cases

Gap-edgedual review: double-approval/double-dispatch/double-delivery all guarded (claims/tokens/replays); provider-success/local-crash ledger-noted on both refund tracks; cancel-during-WMS atomic with refusals; return-then-refund shared-authority safe; digital terminal-state policy open; key-rotation HMAC caveat open; worker-mismatch convention risk open.

## 22. Potential Bugs

### Finding F-01
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: PROVEN
#### Finding
GAP-10 is narrowed but not closed: `CorrectInvoiceRequest` allowlists override keys (total/amount_paid/shipping/customer/address/notes with type bounds), but `correctInvoice` applies them via `data_set` and recomputes the hash WITHOUT re-running the snapshot validator pipeline. An admin can therefore persist a financially inconsistent snapshot (e.g., total ≠ subtotal − discounts + shipping) with a valid HMAC — a "verified" document that violates the generation-time invariant.
#### Evidence
`InvoiceService.php` correction block (`data_set` loop, hash recompute, no `snapshotValidator->validate` call) vs generation block (validates); `CorrectInvoiceRequest.php` (allowlist rules).
#### Why it matters
Corrections are the highest-trust documents (disputes, audits, tax); an inconsistent-but-"verified" correction undermines the snapshot-integrity story.
#### Current behavior
Allowlisted overrides, unvalidated result.
#### Recommended future action
Run the snapshot validator on the post-override snapshot inside `correctInvoice` (same exception path as generation); pin with `CorrectInvoiceOverrideValidationTest`.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
The entire gap-closure program (9 fixes + redesigns) is statically verified only. The manual's launch checklist item "all tests passing" cannot be asserted from this environment, and its P3 testing roadmap (load, contract, pen-test) has no artifacts at all.
#### Evidence
447-file inventory (listed, not executed); negative search for load/contract/pen-test suites.
#### Why it matters
A gap analysis that cannot execute the corpus cannot certify readiness; it can only certify construction.
#### Current behavior
Well-constructed; unproven.
#### Recommended future action
Execute the full suite against real MySQL in CI with recorded results; build the P3 test artifacts (or formally defer with risk acceptance).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Parts C–G are stale as planning instruments: scores predate the fixes (e.g., Shipment 4/10, Refund 5/10, QR 3/10, Support 3/10), the roadmap's P0 is worked off, the test matrix counts are tripled-out, launch-checklist values (throttles, queues, permissions, PDF placeholder, cart expiry) are wrong, and rollback rows reference pre-fix failure modes (e.g., "error callback bug", "cart expiration aggressive").
#### Evidence
This phase's re-verification table vs manual Parts C–G.
#### Why it matters
Launching (or declining to launch) against this scorecard would misprice risk in both directions.
#### Current behavior
Superseded planning artifact.
#### Recommended future action
Replace Parts C–G with the refreshed scorecard (§29) and carried register (§27) on explicit docs request; do not edit the original without approval (closed-phase rule).
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Launch on stale scorecard**: mispriced risk (F-03) — use §29 + carried register instead.
- **Correction abuse**: F-01 path (allowlisted but unvalidated overrides) — constrain correct-grant holders until validated.
- **Unproven corpus**: F-02 — first production traffic is the de-facto load/contract test (mitigate with canary + reconcile monitoring).
- **Archival growth**: no policy — monitor table sizes; decide retention before growth forces it.
- **Key rotation**: HMAC invalidation across all invoices — rotation runbook required before any rotation event.

## 24. Documentation Drift

This phase IS the drift register for the whole program: home-phase §24 sections itemize per-domain drift; §5 above tabulates gap dispositions; F-03 records instrument staleness. The production-manual series as a whole is a pre-flow, pre-WMS, pre-claims, pre-registry snapshot (2026-08-02) of a system that has since been substantially rebuilt — accurate in shape, stale in mechanics, with the specific deltas proven per phase.

## 25. Dependencies

- **Depends on**: all phases (certification view).
- **Consumed by**: launch decision (product/ops), docs-refresh work, CI-proof work.
- **Shared tables/services**: none owned.

## 26. Out of Scope

The launch decision itself (explicitly not made here), deployment execution, data migration/backfill execution, secret rotation, load-test execution, pen-test execution, contract-test authorship.

## 27. Residual Risks (carried register)

1. Runtime proof absent everywhere (F-02; caps every phase verdict).
2. Correction overrides unvalidated post-apply (F-01).
3. Refund-reconcile stub + provider-refund manual path (Phase 06 F-01/F-02).
4. QR artifact missing + verify auth-wall decision (Phase 08 F-01/F-02).
5. Return system dark + completion moneyless (Phase 11 F-01/F-02).
6. Modern-refund side-effect divergence (Phase 10 F-01).
7. Cross-package namespace fragility (Phase 10 F-05).
8. Unpaid-invoice `amount_paid` accuracy (Phase 07 F-02).
9. Post-completion remediation path absent (Phase 05 F-01).
10. Journey E2E + load/contract/pen-test absent (Phases 12/15).
11. Archival policy absent (F-02 adjacent).
12. Stale manuals as operational references (all phases F-01-class).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-16-GAP-ANALYSIS.md` (496 lines, read fully, temp extract). Code: gap dispositions re-verified against Phases 01–15 evidence (cited, not re-listed); plus `InvoiceService.php` correction block + `CorrectInvoiceRequest.php` (GAP-10/11/12 recheck); test corpus inventory (447 files / 32 dirs, listed); migration inventory (no destructive work); launch-checklist values rechecked against current routes/config. Tests: none executed.

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

Refreshed subsystem scores (evidence-linked, runtime-unproven):

| Subsystem | Old | New | Basis |
|---|---|---|---|
| Checkout | 7/10 | 8/10 | Single authority, idempotent completion, 3 fixed bugs; unproven concurrency |
| Cart | 6/10 | 8/10 | Coherent redesign + pinned contract; manual obsolete |
| Coupon | 8/10 | 8/10 | Reservations + claims + distribution added; per-assignment gap noted |
| Promotion | 8/10 | 8/10 | Engine preserved; gift re-architecture; stacking implicit |
| Order lifecycle | 7/10 | 9/10 | Flow authority + funnels + gates; post-completion path open |
| Payment | 6/10 | 8/10 | Multi-gateway + signed webhooks + ledgered refunds; reconcile stub |
| Invoice | 8/10 | 8/10 | Pipeline + real PDF + closure; unpaid-amount + override-validation notes |
| Invoice QR | 3/10 | 5/10 | Verification hardened; QR artifact + access decision open |
| Shipment | 4/10 | 8/10 | WMS pipeline + permissions + events; manual obsolete |
| Refund | 5/10 | 8/10 | Gateway integrated both tracks; side-effect divergence noted |
| Return | 0/10 | 4/10 | Service built + tested; dark + moneyless |
| Customer exp. | 5/10 | 8/10 | Journey complete + notification center; tracking caveats |
| Admin exp. | 7/10 | 8/10 | Gated + audited + observable; manual stale |
| Support | 3/10 | 7/10 | Runbook sound + triage tooling; guidance patch needed |
| Timeline | n/a | 8/10 | Coherent composition; map stale |
| Gap closure | n/a | 8/10 | 9 fixed/retired, 1 moot, 1 partial, 1 narrowed |

The P0 launch blockers from the manual's matrix do not survive re-verification. What survives is a homogeneous residual condition — no runtime proof — plus the 12-item carried register. This audit certifies construction, not production readiness: readiness requires the CI-proof run this environment cannot perform.
