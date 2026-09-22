# COUPON REMEDIATION PLAN (CORRECTED, APPROVED)

> Supersedes the original P0–P3 plan. Phase order is mandatory:
> P0 → P1 → BUSINESS POLICY LOCK → P2 → P3 → FINAL VALIDATION.
> No P1→P2 transition before the policy lock. Smallest safe change; no pricing-engine rewrite.

## P0 — Test & contract hardening (DONE when)

- `AssignedCouponSystemTest` 2 reds classified: **schema/test-parity defect** (SQLite table
  rebuild in `2026_09_09` tax migration strips the partial-index predicate created by
  `2026_08_31`, leaving plain `UNIQUE(orders.user_id)`; production MySQL unaffected —
  native ALTER preserves the generated-column unique). Fix: driver-aware repair migration
  (MySQL no-op; SQLite/Postgres drop + recreate partial) + regression tests
  (two completed same-user succeed; second pending same-user rejected). No production
  behavior change.
- Weak assertions replaced with invariant proofs (ACTIVE→REDEEMED exactly once;
  duplicate execution → no additional mutation; exactly-one usage row/counter).
- Acceptance: coupon subset green on SQLite; MySQL-capable harness prepared for P2.

## P1 — Correctness & financial integrity

| ID | Change | Files |
|----|--------|-------|
| CP-01 | Redeem via authoritative order coupon code + exact user/coupon/claim, row-locked, idempotent; never blind first-match | `MarkCouponClaimRedeemed`, `CouponClaimService::markRedeemed` |
| CP-02 | Remove `code` from public coupon resource; audit index/apply/claim/validation/error responses; assignment/claim data stays auth-gated | `Coupons\CouponResource`, response audit |
| CP-04 | Fail-closed atomic completion: usage commit (with POLICY 4 revalidation) precedes `completed`; consumption failure throws → rollback, order stays pending | `OrderService::changeOrderStatus/recordCouponUsage`, `PaymentCheckoutHandler` error path |
| CP-05 | Field-by-field enforcement matrix (enforced/historical/admin-only/deprecated/unused); required constraints throw at save, never warn-only | `Coupon` model, `CouponRequest`, `CouponRepository`, `CouponConfigurationController` |
| CP-06/07 | Unify FAST with Orchestrator (claim + assignment branches); scheduled/queued/callback/admin/cancel paths share invariants; promotion→coupon→tax→shipping integration tests | `FastShippingService`, pipeline tests |
| CP-08 | Release reservation on EVERY pre-payment cancel path (delete-by-order, structurally idempotent); release×3 test | `changeOrderStatus` cancelled branch, cancel paths audit |
| CP-09 | Canonical normalization `UPPER(trim())` on create/update/lookup (case-insensitive match, no history rewrite) | `Coupon` model + lookup helper, Orchestrator/Validator/Service call sites |
| CP-11 | Lock down mass assignment: `used` (and other system fields) not fillable/writable via generic input; repository uses `validated()` | `Coupon` fillable, `CouponRequest`, `CouponRepository` (+ assignment/claim/targeting fillable audit) |
| POLICY 1 | Enforce public-use history in assigned branch (Orchestrator + recordCouponUsage) | `CouponOrchestrator`, `CouponValidator`, `OrderService` |

P1 integration validation: coupon-only, promotion-only, promotion+coupon(+tax)(+shipping),
cart mutation, checkout revalidation, payment retry, currency variations (matrix).

## BUSINESS POLICY LOCK

`COUPON_BUSINESS_POLICY.md` frozen (POLICY 1–8 as approved). `COUPON_INVARIANTS.md`
(INV-01–14 + state machines + lock order) and `COUPON_TEST_MATRIX.md` agree with it.
No contradiction between policy / state machine / constraints / services / tests.

## P2 — Concurrency & state machines (only after lock)

- Claim ACTIVE→REDEEMED/EXPIRED + reservation RESERVED→CONSUMED/RELEASED/EXPIRED:
  transactional, state-checked, idempotent, concurrency-safe.
- Authoritative sources documented; denormalized counters updated atomically under lock
  + reconciliation detection (P3).
- Atomic capacity: read → lock authoritative row → re-check → write → commit.
- MySQL proofs: parallel claim (1/10), parallel checkout, duplicate callbacks A/B/C,
  expiry-vs-payment, cancel-vs-payment, bounded deadlock retry (idempotent).
- DB guard for claims ONLY after state model + data audit. **If a migration/backfill is
  required: STOP, DOCUMENT, REPORT, WAIT FOR APPROVAL.** No destructive migration,
  no silent repair.

## P3 — Legacy & operational hardening

- Classify every legacy coupon path (ACTIVE/DEPRECATED/READ-ONLY/MIGRATION-ONLY/DEAD);
  compatibility coverage where coexisting; legacy cannot bypass new invariants.
- Read-only reconciliation detectors (REDEEMED w/o usage, usage w/o order, consumed
  reservation w/o order, completed w/o usage, counter mismatch, invalid transitions,
  orphans). Detectors NEVER repair silently; repairs are explicit/auditable/idempotent.

## FINAL VALIDATION

- PASS 1 functional (every CP fixed with evidence), PASS 2 concurrency/transactional,
  PASS 3 regression/integration (promotion/coupon/tax/currency/shipping/order/payment/
  notifications/admin/legacy).
- `COUPON_REMEDIATION_FINAL_REPORT.md` per finding: finding, root cause, fix, files,
  DB changes, tests, concurrency + regression evidence, status
  (FIXED / PARTIALLY FIXED / NOT FIXED / ACCEPTED RISK).

## STOP conditions

Destructive migration, data-loss risk, incompatible production data, uncovered business
ambiguity, MySQL/TiDB incompatibility, impossible state, production behavior conflicting
with policy, or out-of-scope security issue → STOP and report. No silent workarounds.
