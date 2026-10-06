# Phase 03 — Coupon Lifecycle

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The coupon domain is the most functionally evolved since the manual: the documented core (apply → validate → consume, assigned vs public paths, never-return quota policy) is intact and hardened, and the manual's headline concurrency gap (P3-C3, unreserved quota) was closed by a reservation system (30-min TTL, locked capacity check, consume/release discipline). A full claim/targeting lifecycle, outbox-based distribution pipeline, discovery APIs, canonical code handling, and saved-address area eligibility were added after the manual. Residual risks: reservation capacity covers only the global limiter (per-assignment races fail closed into stuck paid orders), silent-clear UX gaps remain open, and runtime concurrency proof is unavailable.

## 2. Phase Objective

Per `PHASE-03-COUPON-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-03-COUPON-LIFECYCLE.md`, 314 lines): document the two consumption models (assigned quota vs public single-use), the stateless-apply / consume-after-payment contract with never-reversed quota, validation rules, reservation posture, consumption paths, events, tables, problems (P3-C1..C4), and recommendations (R3-1..R3-5).

## 3. Scope

**In scope:** coupon apply, validation (assignment + rules + targeting + area), calculation, cart attachment, checkout revalidation, payment-window reservation, claim lifecycle, completion-time consumption, expiry/reconcile commands, distribution pipeline (as coupon producer), discovery endpoints, notifications.

**Out of scope:** promotion engine (Phase 04), checkout orchestration (Phase 01), payment completion mechanics (Phase 06), admin coupon CRUD (Phase 13), refund quota policy interactions (Phase 10, cross-referenced).

## 4. What Was Supposed to Be Implemented

The manual claims: `POST /coupons/apply` → `CouponService::addCouponToCart` → `CouponOrchestrator::validateByCode` (assignment validator + rule validator) → `CouponCalculator` → code stored on cart with NO reservation; consumption via `recordCouponUsage` from three callers with `coupon_consumed` idempotency, assigned path (locked increments + audit row + `afterCommit` event) and public path (`firstOrCreate` + unique constraint); FREE_SHIPPING handling; silent clear on expiry; `AssignedCouponConsumed` dispatched with NO listeners (P3-C4 gap); problems P3-C1 (silent clear), P3-C2 (stale display), P3-C3 (quota race), P3-C4 (listener-less event); recommendations R3-1..R3-5.

## 5. What Actually Exists

Everything the manual describes, plus five post-manual subsystems:

1. **Payment-window reservations** (`CouponReservationService`, 30-min TTL, idempotent per order, locked `used + active reservations vs limiter` capacity check, deadlock retry ×3; consumed on redemption, released on failure/cancel/expiry; `coupons:expire-reservations` every 5 min). Reserved BEFORE gateway invoice (Rule 9, `PaymentCheckoutHandler.php:81-91`).
2. **Claim/targeting lifecycle** (`coupon_targetings`, `coupon_claims` + `2026_09_14` claim-lifecycle migration): `POST coupons/{id}/claim` → `CouponClaimService::claim` (locked, reason-coded `CouponClaimException` → 409); `require_claim` gate in the Orchestrator (F-16 REDEEMED handling); ACTIVE-claim requirement; `ExpireCouponClaims` hourly; `MarkCouponClaimRedeemed` on `PaymentSucceeded`.
3. **Fail-closed completion consumption** (`recordCouponUsage`, `OrderService.php:1492-1637`): INV-02/INV-03/CP-04/CP-08/CP-09 policies, POLICY 1/4/5, reservation revalidation, targeting-mode routing (assignment vs dynamic vs assignment_or_dynamic), discovery-cache invalidation.
4. **Distribution pipeline** (outbox + runs + recipients + user states + candidate selection + triggers + DLQ/consumer retry + tree-hash dedupe; `coupons:publish-outbox` every minute; detection commands; `CouponActivated/TargetingChanged → StartCouponDistribution`, `Disabled/Expired → CancelCouponDistribution`).
5. **Discovery** (`GET coupons/mine`, `GET coupons/available` advisory-only owner-safe shells, `CouponDiscoveryCache` + policy, admin filter) + machine-readable rejection codes (P2-4) + canonical case-insensitive codes (CP-09, `Coupon::byCode`) + `is_public` flag + saved-address area eligibility (AREA_IN remediation; request-supplied governorate never trusted for eligibility).
6. Manual P3-C4 fixed: `AssignedCouponConsumed → SendUserCouponUsedNotification` (`EventServiceProvider.php:201-203`).

## 6. Architecture

```
DISCOVERY (advisory): available/mine → CouponDiscoveryPolicy/Cache (shells only, no codes/counters)
CLAIM (targeting): POST claim → CouponClaimService (locked, reason-coded) → ACTIVE claim (TTL) → hourly expiry
APPLY: POST apply → CouponService::addCouponToCart → Orchestrator::validateByCode
         (targeting gate → assignment validator → rule validator incl. area engine)
       → code on cart (still NO quota held — by design)
CHECKOUT: locked revalidation, clear-if-invalid (+refresh); totals via CouponCalculator
PAYMENT WINDOW: reserve (Rule 9, before invoice) → release on initiation failure
COMPLETION (fail-closed): recordCouponUsage
   coupon lock → reservation revalidate/reacquire (POLICY 4) → mode routing
   → assigned: assignment lock → quota/exhaustion/already-used guards → increments + audit row + consume + afterCommit event
   → public/dynamic: firstOrCreate single-use → increment + consume
   → coupon_consumed=true + discovery-cache invalidate
   ANY refusal → CouponConsumptionException → whole completion rolls back (INV-03)
CANCEL/FAILURE: release (CP-08, idempotent delete-by-order); quota NEVER returns (POLICY 5)
EXPIRY/RECONCILE: coupons:expire-reservations (5m), expire-claims (1h), reconcile (1h, surfaces paid-at-gateway/pending-local)
DISTRIBUTION (async): triggers → runs (startOrJoin dedupe) → outbox → publisher → consumers → assignments/notifications; DLQ + recovery
```

## 7. Complete Execution Flow

1. **Discover**: `GET coupons/available` (auth) returns eligible shells; `GET coupons/mine` returns assignments + claims with owner-visible codes (`CouponController.php:115-227`). Advisory — all gates revalidated downstream.
2. **Claim**: `POST coupons/{id}/claim` → `CouponClaimService::claim` under lock: no-targeting → reject; claim-not-required → reject; already claimed (active) → 409; max claims → 409; eligibility engine (rules incl. area/metrics) → 409 with reason; else ACTIVE claim row with TTL. Rejection carries only `reason` publicly (F-11), diagnostics logged (`CouponController.php:173-227`).
3. **Apply**: `POST coupons/apply` (`code` validated) → `CouponService::addCouponToCart` → Orchestrator: canonical lookup → targeting `require_claim` check (ACTIVE claim required; REDEEMED → already_used) → assignment branch → rule branch → calculator → cart stores code. Machine-readable `COUPON_<REASON>` codes on rejection (P2-4). Marvel `CouponRepository::addCouponToCart` is a dead route path kept consistent (CP-06).
4. **Checkout**: locked coupon row revalidation; invalid → cleared + refreshed; FREE_SHIPPING zeroes shipping; totals embed coupon snapshot on the order.
5. **Payment initiation**: `reserve()` (idempotent per order; capacity = `used + live reservations < limiter`); initiation failure → `release()`.
6. **Completion**: `recordCouponUsage` as diagrammed; refusal aborts completion (order stays pending, reconcile surfaces).
7. **Post-payment**: `MarkCouponClaimRedeemed` (PaymentSucceeded listener) flips ACTIVE→REDEEMED; `SendUserCouponUsedNotification` (AssignedCouponConsumed); discovery cache invalidated.
8. **Cancel/fail**: reservation released exactly once (CP-08); usage rows retained (POLICY 5 — never returned, including refunds).
9. **Distribution**: activation/targeting-change events → run → outbox → per-minute publisher → consumers create assignments/notifications; disable/expire cancels runs; prune-events monthly.

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | Quota consumed at payment success, NEVER returned (anti-abuse) | `recordCouponUsage`; POLICY 5; no restore on cancel/refund | No |
| R2 | Completion without committed usage is impossible (fail-closed) | `CouponConsumptionException` rolls back completion (INV-03/CP-04) | No |
| R3 | Same-order repeats are no-ops | `coupon_consumed` flag + unique-keyed usage rows (INV-02) | No |
| R4 | Stale/missing reservation at completion triggers full revalidation, never fail-open | `revalidateAndReacquireReservation` (`OrderService.php:1648-1709`); POLICY 4 | No |
| R5 | Targeting `require_claim` coupons need a live ACTIVE claim at apply AND checkout | Orchestrator F-16 gate; claim TTL + hourly expiry | No |
| R6 | Eligibility area derives from saved addresses, never request input | AREA_IN remediation (Orchestrator/EligibilityEngine/RuleTree) | No |
| R7 | Reservation holds global-limiter capacity for the 30-min payment window; released on any non-completion | `CouponReservationService` + call sites (Rule 9, CP-08) | No |
| R8 | Public/dynamic coupons are single-use per user (unique row); assigned coupons bounded by per-user quota and prior-public-use block (POLICY 1) | `CouponUsage.firstOrCreate` unique; assignment guards (`OrderService.php:1531-1564`) | No |
| R9 | Discovery/advisory data never grants anything; every gate revalidates | `available` docblock ("advisory only"); checkout/completion revalidation | No |
| R10 | Codes canonicalized case-insensitively everywhere | CP-09 `Coupon::byCode` at apply/checkout/completion | No |

## 9. Source of Truth / Authorities

- **Eligibility**: `CouponOrchestrator::validate/validateByCode` (sole entry; targeting → assignment → rules → area engine).
- **Pricing effect**: `CouponCalculator::calculate` (single calculator; checkout totals + order snapshot).
- **Window capacity**: `CouponReservationService` (reserve/consume/release; global-limiter scope — see F-03).
- **Consumption**: `recordCouponUsage` (sole writer of usage rows/counters/`coupon_consumed`).
- **Claims**: `CouponClaimService` (claim/redeem/expire lifecycle).
- **Distribution**: `DistributionService` + run service + outbox (never touches capacity — docblock contract).
- **Discovery cache**: `CouponDiscoveryCache` (invalidated on consumption).
- **Reconciliation**: `ReconcileCouponState` + `coupons:reconcile` (paid-at-gateway/pending-local surfacing).

## 10. Database Impact

`coupons` (+`is_public`, canonical-code migration `2026_09_27_000001`); `coupon_assignments` + `coupon_assignment_usages` (+non-null order_id `2026_09_11_000002`, check constraint `2026_09_11_000003`); `coupon_usages` (unique `(coupon_id,user_id)`); `coupon_reservations` (`2026_08_31_120100`, 30-min TTL rows); `coupon_targetings` (+TTL `2026_09_14_000002`, extended modes `2026_09_14_000003`, SQLite parity `2026_09_26_000002`); `coupon_claims` (+lifecycle `2026_09_14_000001`); outbox/event-log/distribution runs/recipients/user-states (`2026_09_22_100001-100008` + indexes); orders coupon snapshot columns + `coupon_consumed`. No destructive migrations; all additive.

## 11. API Surface

| Method | URI | Auth | Controller | Validation | Responses |
|---|---|---|---|---|---|
| GET | `/coupons` | public? (Marvel index) | `CouponController::index` | filter | list (no codes per contract) |
| GET | `/coupons/mine` | sanctum | `CouponController::myCoupons` | — | 200 assignments + claims (owner codes) |
| GET | `/coupons/available` | sanctum | `CouponController::available` | page/limit | 200 advisory shells |
| POST | `/coupons/apply` | sanctum + throttle | `CouponController::applyCoupon` | code required | 200 applied/already-applied; 400 + `COUPON_<REASON>` |
| POST | `/coupons/{id}/claim` | sanctum | `CouponController::claim` | `ClaimCouponRequest` | 201; 409 reason; 404 |
| GET | `/coupons/rules-metadata` (admin) | admin | (targeting admin) | — | rule schema for builders |

## 12. Authentication & Authorization

Apply/claim/mine/available require `auth:sanctum` (group middleware, `routes/api.php:143-147`); `coupons/add-to-cart` legacy route carries `auth:sanctum` (historical BUG C fixed). Codes exposed only to owners (`mine`); public surfaces never expose codes/counters/rules. Admin coupon configuration behind admin permission surface (Phase 13). Claim rejections avoid user-enumeration (reason codes only, no existence differential beyond 404 on unknown id — acceptable).

## 13. Validation

Apply: code required ≤191. Claim: `ClaimCouponRequest`. Orchestrator gates (current): coupon exists (canonical), status, dates, global limiter, public single-use, product restriction, assignment existence/expiry/quota, targeting mode, require_claim + ACTIVE claim, area engine on saved addresses, customer-metrics rules. Checkout revalidates under lock; completion revalidates once more (POLICY 4). Unknown `flow_values`-style keys rejected in adjacent domains; coupon rule-tree validated by `RuleTreeValidator`.

## 14. Transactions

`reserve()` single transaction with deadlock retry ×3 (idempotent per order — safe to retry); `claim()` locked transaction; `startOrJoin` run dedupe in transaction; consumption inside the completion transaction (rolls back wholly on refusal); `AssignedCouponConsumed` via `afterCommit`; expiry commands are bulk deletes (idempotent, re-runnable); outbox publish/consume transactional with DLQ isolation.

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Concurrent claims, same coupon+user | locked claim check + unique posture (`CouponClaimService:49-122`) | STRONGLY REASONED |
| Concurrent reservations vs limiter | coupon `lockForUpdate` + locked reservation count (`CouponReservationService:36-80`) | STRONGLY REASONED |
| Concurrent completions, same coupon | coupon + assignment `lockForUpdate`; public path unique row (`OrderService:1499-1628`) | STRONGLY REASONED |
| Per-assignment quota race | serialized on assignment lock; loser fails closed (QUOTA_EXHAUSTED → stuck paid order → reconcile) | STRONGLY REASONED (safe, not lossless — F-03) |
| Reservation vs completion interleaving | revalidation inside completion lock (POLICY 4) | STRONGLY REASONED |
| True parallel proof | `ForUpdateLockTest`, `MultiConnectionLockTest`, `MySqlConcurrencyTest` exist | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

Lifecycle events (`CouponActivated/Disabled/Expired/TargetingChanged`, `CouponAssigned/Created`, `AssignedCouponConsumed`, `CustomerMetricsUpdated`, `UserAddressChanged`) drive distribution, notifications, and claim redemption. Distribution runs on the outbox (per-minute publisher, consumer retry + DLQ, recovery/reconciliation tests). Listeners: `StartCouponDistribution`, `CancelCouponDistribution`, `StartUserCouponDistribution`, `MarkCouponClaimRedeemed`, coupon notification senders, `CouponObserver`. Scheduler: expire-reservations 5m, expire-claims 1h, reconcile 1h, publish-outbox 1m, detect-activations/expiry/public, prune-events monthly (`Kernel.php:33-87`).

## 17. Error Handling

Reason-coded rejections at every gate (`claim_required`, `already_used`, `not_assigned`, `quota_exhausted`, `not_eligible`, `no_capacity`) with public-safe messages and logged diagnostics. Completion refusal → rollback + visible failed marking + reconcile (never silent). Distribution failures isolated per-recipient with DLQ + recovery. Expiry/reconcile commands are re-runnable and report counts.

## 18. Security

- No coupon capability via discovery (shells only); codes owner-only.
- Claim/apply require auth; no IDOR (all scoped to `request->user()`).
- Area eligibility never trusts client governorate (saved addresses only).
- Rejection messages avoid oracle leakage (reason codes, F-11 log-only diagnostics).
- `is_public` discoverability flag is separate from usability gates (commit `d3f6e0a`); public coupons still single-use + validated.
- Mass assignment: claim/usage rows built from server-side models, not request data.

## 19. Performance

Reservation hot path: 1 coupon lock + 1 reservation lookup + 1 counted locked select — bounded. Discovery cached with invalidation on consumption. Distribution fanned out via outbox + chunked consumers (never synchronous fan-out per docblock). Claim/apply are single-row locked transactions. Expiry via bulk deletes. No N+1 on mine/available (eager `coupon`).

## 20. Tests & Verification

35 test files across `Coupon/`, `CouponAssignment/`, `CouponDistribution/` (apply/validate/calculate, assignment API, claim lifecycle/integration, checkout revalidation, eligibility lifecycle, rules metadata, audience matrix, notification queue, outbox service, distribution pipeline/recovery/triggers, candidate selection, tree-hash, envelope, production-chain simulation, readiness regression, business-delay, lock tests `ForUpdateLockTest`/`MultiConnectionLockTest`/`MySqlConcurrencyTest`, `RabbitMqIntegrationTest` — legacy). **None executed** (environment). Manual R3-5 (concurrent quota test) appears addressed statically by the lock/concurrency suites — runtime proof missing.

## 21. Edge Cases

Covered: coupon invalidated between apply and checkout (cleared, proceeds); between checkout and payment (POLICY 4 revalidation or fail-closed block + reconcile); global limiter hit during window (reserve refuses 422 before gateway call); assignment quota hit at completion (fail-closed stuck-pending + reconcile); user deleted before completion (fail-closed); REDEEMED claim re-presented (already_used, F-16); expired ACTIVE claim (does not satisfy require_claim; hourly expiry); coupon row deleted (COUPON_MISSING refusal); targeting row missing in legacy/test schemas (assignment-mode fallback with `Schema::hasTable` guards); distribution for non-dynamic coupons (refused `NonDistributableCouponException`); disable/expire mid-run (cancel listener).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The manual predates the reservation, claim/targeting, distribution, discovery, canonical-code, and area-eligibility subsystems entirely. Its "Quota is NOT reserved" posture (manual §Reservation, P3-C3) is now false for the payment window; its consumption-caller list (three direct callers) is now a single funnel via `changeOrderStatus`; its rule-table line references are stale.
#### Evidence
`CouponReservationService.php` (full); `CouponOrchestrator.php:38-90` (targeting/claim gates); `DistributionService.php` docblock; `OrderService.php:1401-1467` (mark-paid delegates); manual §§Reservation/Consumption/Validation Rule Table.
#### Why it matters
The manual's headline warning (unreserved quota race) is fixed, but readers cannot tell; new subsystems have no phase-manual home.
#### Current behavior
Correct implementation; stale manual.
#### Recommended future action
Rewrite Phase 3 manual around reserve→consume + claim lifecycle (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: LOW
- Type: BUSINESS LOGIC (UX gap, previously recommended)
- Status: PROVEN
#### Finding
Manual recommendations R3-1 (warning on silent clear) and R3-2 (cart-page revalidation) remain unimplemented: invalid coupons are still cleared silently at checkout with no `warning` field, and the cart page still displays stale coupons until a price action.
#### Evidence
`OrderService.php:238-247` (silent clear); manual §§P3-C1/P3-C2/R3-1/R3-2 (no contrary code found by search for a checkout warning payload).
#### Why it matters
Customer confusion/abandonment at higher-than-expected totals — the exact impact the manual predicted.
#### Current behavior
Silent clear; stale display.
#### Recommended future action
Add the `warning` payload + cart-fetch revalidation endpoint; test both.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: CONCURRENCY
- Status: STRONGLY REASONED
#### Finding
Reservation capacity accounts only for the GLOBAL `limiter`, not per-assignment quotas. Two concurrent completions for the same user's assigned coupon (quota 1) both hold reservations, serialize on the assignment lock, and the loser fails closed (`QUOTA_EXHAUSTED` → `CouponConsumptionException` → order stuck pending after gateway payment, surfaced via reconcile).
#### Evidence
`CouponReservationService.php:68-80` (limiter-only check); `OrderService.php:1531-1538` (assignment quota throw); callback coupon-blocked recovery (`OrderController.php:508-563`).
#### Why it matters
Safe (no over-consumption) but lossy in UX: a paid order that cannot complete without ops intervention.
#### Current behavior
Fail-closed; reconcile-visible.
#### Recommended future action
Extend reservation capacity to assignment quotas where cheap, or document the stuck-order runbook; add a same-user concurrent-completion test.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Manual P3-C4 (listener-less `AssignedCouponConsumed`) is fixed: `SendUserCouponUsedNotification` is registered (`EventServiceProvider.php:201-203`), and `MarkCouponClaimRedeemed` closes the claim lifecycle on `PaymentSucceeded` (`:173-180`).
#### Evidence
As cited; manual §Event + §P3-C4/R3-4.
#### Why it matters
Closes a documented gap; record for regression history.
#### Current behavior
Correct wiring.
#### Recommended future action
None (record only).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-05
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
The strongest concurrency claims (claim races, reservation races, completion races) rest on statically-verified lock/concurrency suites (`ForUpdateLockTest`, `MultiConnectionLockTest`, `MySqlConcurrencyTest`) that were NOT executed here. TRUE PARALLEL CONCURRENCY NOT PROVEN.
#### Evidence
Test file listing verified; execution impossible in this environment (MySQL-only).
#### Why it matters
Coupon quota is financial state; race-induced over/under-consumption is the top coupon risk.
#### Current behavior
Well-constructed protections; missing proof.
#### Recommended future action
Run the coupon concurrency suites against real MySQL in CI and record results.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Limiter hit mid-window**: reserve refuses 422 before any gateway interaction — customer retries later; no money moved.
- **Reservation row survives crash**: 30-min TTL + 5-min sweeper bounds the leak; completion revalidates regardless.
- **Claim expires mid-payment**: POLICY 4 revalidation refuses completion fail-closed; reconcile surfaces paid/pending divergence.
- **Distribution trigger storm**: run dedupe (`startOrJoin`) + tree-hash prevents duplicate runs; outbox + DLQ isolate poison recipients.
- **Clock skew on TTLs**: expiry comparisons use server `now()` consistently; skew across app servers bounded by NTP assumption (standard).
- **Coupon disabled mid-window**: checkout/completion revalidation fails closed; reserve itself does not check status (relies on prior validation within the same attempt — narrow window, acceptable).

## 24. Documentation Drift

Manual accurate for: assigned/public models, never-return policy, validation rule semantics, calculator role, `coupon_consumed` idempotency, audit tables, FREE_SHIPPING handling, silent-clear mechanics. Drifted/obsolete: reservation posture (P3-C3 fixed via payment-window reservations, not apply-time as R3-3 mused); consumption callers (single funnel now); `AssignedCouponConsumed` listeners (P3-C4 fixed); all line references; missing subsystems (claims, targeting, distribution, discovery, canonical codes, area eligibility, reconcile, machine-readable rejections).

## 25. Dependencies

- **Depends on**: Phase 01 (checkout/completion call sites), Phase 02 (cart coupon cell), Phase 05 (flow/status authority), Phase 06 (payment initiation + reconcile job), user/address/metrics subsystems.
- **Consumed by**: Phase 01 (totals, reservation, consumption), Phase 10 (quota-retained-on-refund policy), Phase 12 (coupon notifications/discovery), Phase 15 (timeline coupon stages).
- **Shared tables**: `carts.coupon`, `orders` coupon snapshot + `coupon_consumed`, `transactions` (reservation-adjacent failure marking), coupon family tables.
- **Shared services**: `CouponOrchestrator/Validator/Calculator/Reservation/Claim/Discovery/Distribution`, `OrderService.recordCouponUsage`.

## 26. Out of Scope

Admin coupon/targeting CRUD and distribution-run ops console (Phase 13), promotion stacking precedence (Phase 04 — note: coupon applies after promotion in `calculateCheckoutTotals`; stacking policy itself belongs to Phase 04), gateway specifics (Phase 06), invoice/tax interplay of discounts (Phase 07).

## 27. Residual Risks

1. Runtime concurrency unproven (F-05).
2. Per-assignment reservation gap → stuck paid orders (F-03).
3. Silent-clear UX gaps open (F-02).
4. Manual substantially incomplete for five subsystems (F-01).
5. Clock/TTL assumptions across schedulers (standard, unmeasured).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-03-COUPON-LIFECYCLE.md` (314 lines, temp extract). Code: `app/Http/Controllers/Api/General/CouponController.php` (`:68-114` apply, `:115-171` mine, `:173-227` claim, `:229+` available); `app/Services/General/CouponService.php` (apply orchestration); `app/Services/Coupon/CouponOrchestrator.php` (`:13-90` gates); `CouponValidator/CouponCalculator/CouponAssignmentValidator/CouponClaimRequirement/CouponRuleMetadata/RuleTreeValidator`; `CouponReservationService.php` (full); `CouponClaimService.php` (`:39-217`); `Eligibility/EligibilityEngine.php`; `Audience/CouponAudienceResolver.php`; `Discovery/*` (policy/cache/filter); `Distribution/*` (service, runs, outbox, selection, triggers); `app/Models/CouponReservation.php` (+ distribution models); Marvel `Coupon.php` (`:307-378` targeting/scopes/byCode), `CouponAssignment/CouponUsage/CouponAssignmentUsage/CouponClaim` models; `CouponRepository.php:143+` (dead path); `OrderService.php:230-248,521-542,1492-1726` (checkout/consume); `PaymentCheckoutHandler.php:81-91,107,115,144-155,178-188` (reserve/release); `OrderController.php:508-563,710-752` (coupon-blocked recovery); `EventServiceProvider.php` (coupon wiring `:141,179,201-226`); `MarkCouponClaimRedeemed`; coupon notification listeners; commands (`Coupons/*`, `ExpireCouponClaims/Reservations`, `ReconcileCouponState`); `Kernel.php:33-87` (coupon schedule); `ClaimCouponRequest`, `UpsertTargetingRequest`; `CouponClaimResource/CouponTargetingResource`. Tests (35 files, listed, not executed). Migrations: coupon family (`2026_06_17`, `2026_07_12_000002`, `2026_07_15_000003/4`, `2026_08_31_120100`, `2026_09_10_000001-4`, `2026_09_11_000002/3`, `2026_09_14_000001-3`, `2026_09_22_100001-8`, `2026_09_26_000002`, `2026_09_27_000001`, `2026_09_27_000002`, `2026_09_28_000001`).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The coupon lifecycle is functionally rich and financially careful: fail-closed consumption, reservation-guarded payment windows, claim-gated targeting, audited usage, and reconciled failures — all verified in current code. The manual's core contract description holds, but five subsystems postdate it and the headline race gap is fixed, so the manual misleads by omission. Verdict is capped by missing runtime concurrency proof and the per-assignment reservation gap.
