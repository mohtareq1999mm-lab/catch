# Phase 10 — Refund Lifecycle

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The manual's P0-CRITICAL gap (refunds move no money — inventory/CN happen without gateway confirmation) is closed: both refund tracks now call the provider first (Marvel approval calls the gateway before its DB transaction; the modern admin path is a ledgered, idempotent gateway refund with txn→order lock ordering). Cross-path over-refund is guarded by a shared ledger cap, approvals are atomic-claimed, inventory restoration is exactly-once by state claim, and credit-note closure fires via Marvel-ESP wiring. Residual risks are structural, not financial: two refund tracks with divergent side-effect sets (modern direct refunds skip credit notes, review handling, wallet adjustments, and refund timelines), a duplicated restore-listener registration, no refund-status transition matrix, and unexecuted tests.

## 2. Phase Objective

Per `PHASE-10-REFUND-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-10-REFUND-LIFECYCLE.md`, 765 lines): specify the refund model/status/events, the approval fan-out (ratings/inventory/credit note), Marvel request/update notifications, gateway-refund posture, flow diagram, schema, 8 edge cases, and the P0–P3 recommendation matrix.

## 3. Scope

**In scope:** refund request/approval flows (Marvel track + modern admin track), gateway refund initiation + ledger, approval fan-out (ratings/inventory/credit-note/timeline/notifications/digital revocation), refund-status handling, wallet/balance adjustments, cross-path caps, permissions/throttles.

**Out of scope:** return lifecycle (Phase 11), invoice correction mechanics (Phase 07), payment verification (Phase 06), chargeback ingestion (covered as gap).

## 4. What Was Supposed to Be Implemented

The manual claims: customer request → Marvel `RefundRequested` mail; admin approve → Marvel `RefundUpdate` mail + explicit App `RefundApproved` → `RatingRemoved` (sync, deletes all order reviews) + `RestoreInventoryOnRefund` (medium, `inventory_restored_at` guard) + `GenerateCreditNoteOnRefund` (medium, CN + corrected); NO gateway integration (P0 gap — money never moves); dual `RefundApproved` confusion; `RefundStatus` without transition matrix; 8 edge cases; P0–P3 matrix headed by gateway integration + transition validation.

## 5. What Actually Exists

The manual's flow is accurate, and its P0 is closed — with a second track added:

- **Track A — Marvel request flow** (`RefundController::updateRefund` approve): atomic PENDING→PROCESSING claim (F-AUDIT-02, exactly-one-approver) → cross-path ledger cap check (`ledgerRemaining`; over-cap rejected, status restored) → **gateway refund BEFORE the DB transaction** (provider-first; failure → PENDING + 400, no local mutation) → DB transaction: payment-marker sync (`payment_status=REFUNDED`, lifecycle frozen per D5/P3-3) → `noteProviderRefund` shares the outcome to the shared ledger → shop balance decrement + customer wallet credit (locked, atomic) → dispatch App `RefundApproved` + App `RefundProcessed` → fan-out.
- **Track B — modern admin flow** (`PaymentRefundController::refund`, `permission:payments.refund`): `PaymentRefundService::refund` — locked txn→order (deadlock-ordered F-AUDIT-01), completed/delivered + payment-success gate, transaction-currency authority, minor-unit ledger math, idempotency-key replay (no second provider call), partial/full ledger entries capped, txn `refunded/partially_refunded`, full → `payment_status=REFUNDED` + synchronous inventory restore (state claim) + coupon release + history row. **No Refund row, no events, no credit note, no wallet/review/timeline effects.**
- **Fan-out (App `RefundApproved`)**: app ESP (timeline + restore) + Marvel ESP (ratings, restore DUPLICATE, credit note, digital revocation, user refunded notification) — all wired to the single App-namespaced class that physically lives in Marvel (`packages/marvel/src/Events/RefundApproved.php`, `ShouldDispatchAfterCommit`, intentional per docblock; autoloads via optimized classmap — verified in `vendor/composer/autoload_classmap.php:124`).
- **Restore upgraded**: both restore listeners delegate to `InventoryRestoreService::restore()` state claim (P7-7/D7-4) — cancel-then-refund/refund-then-cancel/replay all exactly-once; cancelled orders skipped (cancel path owned it); gift-only orders skipped.
- **Credit note**: fires via Marvel-ESP wiring (INV-12 closed); second approval finds `corrected` invoice → warn + skip (no double CN).
- **Digital**: `RevokePendingDigitalEntitlements` on approval; delivered-digital orders refuse approval (D7 guard in `updateRefund`).
- **Request flow**: one request per order, owner-or-super-admin, parent-orders only with child fan-out (`storeRefund`/`createChildOrderRefund`); `throttle:refunds` (5/min); `RefundRequested/RefundUpdate` mails preserved.
- **External refunds**: `recordExternalRefund` (PayPal webhook path) with event-id idempotency + same full-refund side effects — narrows the Phase-06 provider-refund blindness for PayPal (Stripe charge.refunded still ignored-200).

## 6. Architecture

```
TRACK A (request): customer Refund::create → RefundRequested mail
  → admin approve: claim(PENDING→PROCESSING) → ledger cap → GATEWAY FIRST
    → DB txn: payment-marker=REFUNDED (lifecycle frozen) → ledger note →
      wallet/balance → dispatch RefundApproved + RefundProcessed
        → ratings delete (sync) + restore (queued, exactly-once) + credit note (queued)
        + digital revoke + user notification + timelines
TRACK B (direct): admin POST payments/{order}/refund (idempotency_key required)
  → PaymentRefundService::refund: locks → gates → provider → ledger →
    full: REFUNDED + restore + coupon release + history | partial: ledger only
  (no Refund row, no events, no CN/reviews/wallet/timeline)
RECONCILIATION: shared _refunds ledger (cap + cross-path checks); recordExternalRefund for provider-initiated
```

## 7. Complete Execution Flow

Track A approval (verified `RefundController.php:253-410`): permission check → already-approved guard → atomic claim → ledger-remaining cap → gateway refund (unsupported/offline methods skip gracefully) → transactional local effects + wallet/balance → dual event dispatch → queued fan-out. Failure anywhere pre-dispatch restores PENDING (F-AUDIT-02) and reports; gateway success is never retried blindly (ledger note records it even on later failure — ops-visible).

Track B (verified `PaymentRefundService.php:68-283`): validation → idempotent replay check → minor-unit math → gateway checks → provider call → ledger append (capped) → txn/order markers → full-only side effects → status-change history row → summary.

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | Provider money moves before local refund effects (Track A) | Gateway call precedes DB transaction | Offline/unsupported methods skip (logged path) |
| R2 | Exactly one approver per request; concurrent approval fails | Atomic PROCESSING claim | No |
| R3 | Cross-track over-refund impossible (shared ledger cap) | `ledgerRemaining` gate (Track A) + ledger math (Track B) | `allowOverCap` only for already-moved provider money (flagged) |
| R4 | Refunds never touch lifecycle columns | D5/P3-3 freeze (payment-marker only) | No |
| R5 | Inventory restores exactly once across cancel/refund/replay | Shared state claim (P7-7) | No |
| R6 | Coupon quota never returns on refund (anti-abuse POLICY 5) | No coupon path in either track | No |
| R7 | Delivered digital entitlements block approval | D7 guard | No |
| R8 | One request per order; parent-orders only (children fan out) | `storeRefund` guards | Super-admin ownership override (intended) |
| R9 | Partial refunds keep inventory committed (Track B); Track A restores on any approval | Divergent by track (see F-01) | — |

## 9. Source of Truth / Authorities

- **Provider money**: gateway adapters (both tracks call them; Track A pre-transaction, Track B in-transaction with idempotency key).
- **Refundable accounting**: shared `_refunds` ledger on the transaction row (sole cap authority).
- **Order payment marker**: `payment_status=REFUNDED` (both tracks; lifecycle untouched).
- **Inventory**: `InventoryRestoreService::restore` state claim (all paths).
- **Documents**: `GenerateCreditNoteOnRefund` (Track A only — see F-01).
- **Request state**: `refunds.status` (no transition matrix — see F-04) with atomic claim discipline.
- **Wallet/balance**: Track A only (marketplace accounting).

## 10. Database Impact

`refunds` (request rows, PENDING→PROCESSING→APPROVED/REJECTED, wallet/balance references); txn `gateway_response._refunds` ledger (capped, idempotent keys, provider refs); `payment_reconciliation_results` (adjacent); `credit_notes` (Track A only); `order_status_history` (Track B history rows; Track A via marker updates); wallet/balance counters (Track A, locked). No destructive migrations; ledger cap prunes oldest-first (documented rationale).

## 11. API Surface

| Method | URI | Auth | Handler | Notes |
|---|---|---|---|---|
| GET/POST/… | `/refunds…` (apiResource) | sanctum + `throttle:refunds` | Marvel `RefundController` | request CRUD; approve via update |
| POST | `/v1/admin/payments/{order}/refund` | sanctum + `permission:payments.refund` | `PaymentRefundController::refund` | `RefundOrderRequest` (amount/reason/idempotency_key); 422 fail-closed |

## 12. Authentication & Authorization

Refund requests are owner-scoped (or super-admin); approvals require admin refund permission (Marvel `hasPermission` gate) or the dedicated financial `payments.refund` grant (modern); request creation throttled at 5/min/user (fraud posture); idempotency keys are caller-supplied per admin action (replay-safe).

## 13. Validation

`RefundRequest`/`RefundOrderRequest` own input validation; status values are enum-constrained at the edges but not transition-guarded (F-04); amounts validated against ledger remaining in minor units (currency-exponent-safe); reasons sanitized (500-char, tag-stripped); gateway availability/config checked pre-call (Track B) with graceful skip (Track A offline methods).

## 14. Transactions

Track A: claim transaction → provider call (outside) → effects transaction (locks: refund, order, balances, wallet) → events after commit (ShouldDispatchAfterCommit). Track B: single locked transaction spanning validation → provider call → ledger → markers → side effects → history. Cross-transaction failure (provider success + local failure) is ops-visible via ledger notes + PENDING restoration (never silent, never auto-retried blindly).

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Double approval | atomic PROCESSING claim + ALREADY_REFUNDED guard | STRONGLY REASONED |
| Approval vs direct refund overlap | shared ledger cap + txn→order lock order | STRONGLY REASONED |
| Duplicate approved dispatch | state-claim restore; corrected-invoice CN skip; rating DELETE idempotent | STRONGLY REASONED |
| Double gateway call (Track B) | idempotency-key ledger replay (no second provider call) | STRONGLY REASONED |
| True parallel proof | `MarvelRefundInterplayTest`, `RefundCurrencyAndLedgerTest`, `ExternalRefundTest`, `RefundWebhookFreezeTest`, EventSystemTest refund sections | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

`RefundApproved` (ShouldDispatchAfterCommit) → ratings (sync), restore + credit note + digital revoke + user notification (queued high) + timelines (app + refund-specific). `RefundProcessed` → refund timeline. `RefundRequested/RefundUpdate` (Marvel model events) → mails. Duplicate `RestoreInventoryOnRefund` registration across both providers → two jobs, second no-ops (F-02). No dead-letter beyond standard failed-jobs observability.

## 17. Error Handling

Gateway refusal → PENDING restoration + 400 with provider message (Track A) / 422 (Track B); over-cap → PENDING + 400; already-approved → 400; wrong-order-state → 422; listener failures → logged + retried (queue) without touching money state; missing invoice → warn + skip; orphan refund order → graceful no-op.

## 18. Security

- Financial grants separated (`payments.refund` vs generic admin); throttle on request creation.
- Provider outcomes recorded with refs (auditability); reasons sanitized.
- No mass-assignment surface (`$request->only([...])` allowlists; `$guarded=[]` on Refund model mitigated by request allowlists — validation thoroughness remains load-bearing, as the manual warns).
- Idempotency keys prevent double-charge on retry storms.
- Wallet/balance mutations locked and atomic.

## 19. Performance

Approval holds refund + order + balance + wallet locks across a provider call (Track A provider call is OUTSIDE the transaction — correct; Track B holds locks across its provider call — necessary for ledger atomicity, deadlock-ordered). Listeners are queued; ledger capped; history single-row. Request endpoints paginated/filtered via repository criteria.

## 20. Tests & Verification

`MarvelRefundInterplayTest`, `RefundCurrencyAndLedgerTest`, `ExternalRefundTest`, `RefundWebhookFreezeTest` + `EventSystemTest` refund sections (direct-handle restore tests, cancelled-skip, orphan, gifts). **None executed** (environment).

## 21. Edge Cases

Covered: double approval (claim); cross-track over-refund (ledger cap); gateway failure (PENDING restore); offline methods (graceful skip); cancel-then-refund (restore skipped, cancel owned it); second approval (CN skip via corrected status); orphan refund (no-op); gift-only (no restorable stock); delivered digital (approval refused); partial modern refund (ledger-only, no inventory movement); provider-initiated PayPal refund (recorded with same side effects).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: BUSINESS LOGIC
- Status: PROVEN
#### Finding
The two refund tracks have divergent side-effect sets. Modern direct refunds (`PaymentRefundService`) move money and update the ledger but create no Refund row, dispatch no events, generate no credit note, remove no reviews, adjust no wallet/balances, and record no refund timeline — while Track A does all of these. Finance therefore has no credit-note document and ops has no refund timeline for direct refunds; customer reviews survive direct refunds that would have removed them via Track A.
#### Evidence
`PaymentRefundService.php` (no Refund/event/credit-note/review/wallet references — verified by search); `RefundController.php:360-410` (Track A fan-out); ESP wiring (Track A listeners).
#### Why it matters
Audit-trail and document-completeness gap on the admin-fast-path refunds; inconsistent customer experience between tracks.
#### Current behavior
Money-correct; document/process-incomplete on Track B.
#### Recommended future action
Emit the same post-refund fan-out (or an explicit subset: credit note + timeline minimum) from the modern path, or document Track B as money-only with a manual CN step; pin parity with a test.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: LOW
- Type: ARCHITECTURE
- Status: PROVEN
#### Finding
`RestoreInventoryOnRefund` is registered for the same event in BOTH providers (app ESP `:185-187` and Marvel ESP `:108-113`), dispatching two queue jobs per approval. Safe (second no-ops on the state claim) but wasteful and confusing — the P5-C1 pattern relived.
#### Evidence
Both ESP blocks (verified above); state-claim safety (`InventoryRestoreService`, P7-7).
#### Why it matters
Double queue work per refund; future maintainers may "fix" one registration and change behavior unknowingly.
#### Current behavior
Safe; redundant.
#### Recommended future action
Keep a single registration (Marvel ESP, alongside its siblings) and remove the app-ESP duplicate; pin single-execution with a test.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: PROVEN
#### Finding
`RatingRemoved` deletes ALL reviews for the refunded order on ANY approval, including partial refunds (manual edge case #4, still true). No item-scoping, no partial-awareness.
#### Evidence
`packages/marvel/src/Listeners/RatingRemoved.php:23-27` (unconditional user+order delete).
#### Why it matters
Partial refunds destroy legitimate reviews of kept items; review integrity suffers.
#### Current behavior
As manual describes.
#### Recommended future action
Scope deletion to refunded items (or only on full refunds); pin with a test.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: LOW
- Type: DATA INTEGRITY
- Status: PROVEN
#### Finding
`RefundStatus` still has no transition matrix (manual concern stands): any of the four values can be written via `updateRefund`. In practice the approve path is claim-guarded and re-approval is rejected, but arbitrary pending↔rejected↔processing transitions are unconstrained.
#### Evidence
`RefundStatus.php` (4 constants, no matrix); `RefundRepository::updateRefund` (`$request->only(['status'])` direct write).
#### Why it matters
Admin tooling or future code can set incoherent states (e.g., approved→pending) outside the guarded path.
#### Current behavior
Guarded approve path; unguarded other transitions.
#### Recommended future action
Add a minimal transition matrix (or at least forbid leaving `approved`); pin with a test.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-05
- Severity: INFO
- Type: MAINTAINABILITY
- Status: PROVEN
#### Finding
`App\Events\RefundApproved` and `App\Listeners\RatingRemoved` are App-namespaced classes physically located in `packages/marvel/` — invisible to PSR-4 (`App\` → `app/`) and resolvable only via the optimized composer classmap (verified present at `vendor/composer/autoload_classmap.php:124`). Works in this checkout and in production builds, but breaks under non-optimized autoloads, confuses static analysis/IDEs, and misled this audit until the classmap was checked.
#### Evidence
File locations vs namespaces; classmap entry; `optimize-autoloader: true` in composer.json.
#### Why it matters
Fragile autoloading for a financial-lifecycle event; onboarding/troubleshooting hazard.
#### Current behavior
Functional via classmap.
#### Recommended future action
Move the two classes into `app/` (or document the exception prominently); add a CI check that the classmap resolves them.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-06
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
Four refund suites plus event-system refund sections exist and none were executed here; the claim/ledger/cap/fan-out behaviors are statically verified only. TRUE PARALLEL CONCURRENCY NOT PROVEN for approval races or cross-track overlap.
#### Evidence
Test inventory verified; execution impossible (MySQL-only).
#### Why it matters
Refund paths move money and stock; they need runtime proof most.
#### Current behavior
Well-constructed; unproven.
#### Recommended future action
Execute refund + event-system suites against real MySQL in CI; add a cross-track overlap test if absent.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Gateway success + local crash**: ledger note preserved (Track A) / ledger entry committed (Track B replay returns original) — ops can reconcile; never auto-retried blindly.
- **Double approval click**: claim serializes; loser gets ALREADY_REFUNDED.
- **Direct refund then request approval**: ledger cap rejects the request refund (remainder via admin path).
- **Refund on cancelled order**: restore skipped (cancel owned it); marker + CN still proceed (documented contract).
- **Refund on delivered-digital order**: approval refused (D7).
- **Provider-side PayPal refund**: recorded with full side effects (event-id idempotent); Stripe provider-side refunds remain manual (Phase 06 F-01/F-02).

## 24. Documentation Drift

Manual accurate for: request/approve flow shape, event names, listener roles, review-deletion behavior, CN mechanics, mail notifications, schema, edge cases #1/#3–#8. Drifted: gateway integration (P0 gap closed in both tracks); restore mechanics (guard-column → state claim); ESP wiring (credit note + digital revoke + user notification added; duplicate restore registration unmentioned); `RefundStatus` (unchanged — concern stands); `noteProviderRefund`/ledger sharing (new); external refunds (new); modern admin track (entirely absent); `RefundApproved` location/namespace subtlety (manual assumed `app/Events/` — half-right).

## 25. Dependencies

- **Depends on**: Phase 06 (adapters, ledger, reconcile), Phase 05 (payment-marker conventions, lifecycle freeze), Phase 07 (credit notes/invoice states), inventory services, wallet/balance (marketplace), digital entitlements, auth/permissions.
- **Consumed by**: Phase 11 (refund-adjacent returns), Phase 12 (refund notifications/tracking), Phase 15 (timeline refund stage).
- **Shared tables**: `refunds`, transactions ledger, `credit_notes`, `order_status_history`, wallet/balances, reviews.
- **Shared services**: `PaymentRefundService`, `RefundRepository`, `InventoryRestoreService`, `CreditNoteService`.

## 26. Out of Scope

Return merchandise flow (Phase 11), refund-policy window design (no enforcement found — unverified, not claimed), restocking fees (none found), vendor settlement adjustments beyond balance decrement, chargeback ingestion (gap noted).

## 27. Residual Risks

1. Track-B side-effect divergence (F-01).
2. Duplicate restore registration (F-02).
3. Review wipe on partials (F-03).
4. No status matrix (F-04).
5. Cross-package namespace fragility (F-05).
6. Runtime proof absent (F-06).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-10-REFUND-LIFECYCLE.md` (765 lines, read fully, temp extract). Code: `packages/marvel/src/Http/Controllers/RefundController.php` (`:253-410` approve flow verified); `packages/marvel/src/Database/Repositories/RefundRepository.php` (full: store/child/update/marker-fan-out); `app/Http/Controllers/Api/Admin/PaymentRefundController.php` (full); `app/Services/Payment/PaymentRefundService.php` (`:68-283` refund, `:285+` external/ledger — verified); `packages/marvel/src/Events/RefundApproved.php` (App-namespaced, ShouldDispatchAfterCommit); `app/Events/Refund/RefundProcessed.php`; app ESP (`:185-190`) + Marvel ESP (`:108-113`) wiring; `RatingRemoved`, `RestoreInventoryOnRefund` (full, P7-7), `GenerateCreditNoteOnRefund` (Phase 07), `RevokePendingDigitalEntitlements`, `SendUserOrderRefundedNotification`, `RecordRefundApprovedInTimeline`, `Refund/RecordRefundInTimeline`; `RefundStatus.php`; `RefundRequest`; routes (`Rest/Routes.php:440-444` refunds group; `api.php` admin refund route); composer autoload (`optimize-autoloader`, classmap `:124`). Tests (4 files + EventSystemTest sections, listed, not executed).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The refund lifecycle closed its headline financial gap — provider-first approvals, ledgered idempotent direct refunds, shared over-refund caps, atomic claims, and exactly-once restoration — and the legacy notification/document fan-out survives. It cannot reach PASS because the modern track silently skips the document/process fan-out, the status machine is unguarded, and runtime proof is absent. No blocking defect found in money movement.
