# Coupon Final Validation (remediation closure)

## P1
- P1-1 Email out of scope: FIXED. `UserCouponAssignedNotification::via()` = database+fcm+broadcast only; `toMail` dormant. Evidence: `app/Notifications/UserCouponAssignedNotification.php:20-33`. Test: assignment flow green (`CouponAssignmentApiTest` 29 pass, `AssignedCouponSystemTest` 49 pass). No SMTP required.
- P1-2 Snapshot leak: FIXED. `CouponClaimResource` returns `id,coupon_id,code,status,claimed_at,expires_at,redeemed_at` only; no `eligibility_snapshot`/`user_id`. Evidence: resource file + `CouponClaimTest::test_authenticated_user_can_claim_eligible_coupon` asserts missing snapshot (12 pass). DB still stores snapshot for audit (proven by `test_eligibility_snapshot_captured_at_claim_time`).
- P1-3 Metrics staleness: FIXED. `OrderService::changeOrderStatus(completed)` rebuilds metrics when `status=completed AND payment_status=payment-success` (idempotent, try/catch, never blocks completion). Evidence: `OrderService.php` completed branch + `CustomerMetricsServiceTest` 7 pass. NOT rebuilt on cart/apply/failed/unpaid/reservation/cancelled-unpaid by construction (only completed branch).
- P1-4 30-min commitment: VERIFIED + DOCUMENTED (no semantic change). Matrix in `COUPON_API_CONTRACT.md` §4. Live hold = commitment; full revalidation when missing/expired (`revalidateAndReacquireReservation`). Evidence: `PaymentCheckoutHandler` reserve-before-invoice + `OrderService:1144-1202`.

## P2
- P2-1 has_assignment: FIXED. Engine (`evaluateAssignmentMode` + `evalHasAssignment`) enforces usable (exists + !expired + used<max), parity with `CouponAssignmentValidator`. Evidence: Eligibility files; `EligibilityEngineTest` 13 pass, `EligibilityNewRulesTest` 19 pass, `CouponCheckoutRevalidationTest` 15 pass.
- P2-2 claimed/not_claimed: FIXED. `evalClaimed` = ACTIVE(unexpired) OR REDEEMED; EXPIRED = not claimed (parity with `evalNotClaimed`). Evidence: engine file; lifecycle tests 18 pass.
- P2-3 spend precision: FIXED. `bccomp` 2dp (`moneyString/moneyGte/moneyLte`), cents fallback. `100.00>=100.00` deterministic. Evidence: engine file; metrics/spend tests green.
- P2-4 apply stripping: FIXED (apply) + DOCUMENTED (checkout). Apply 400 now carries `{reason, code:COUPON_<REASON>}` in envelope; checkout silent-strip preserved, frontend detects via `order.coupon==null`. Evidence: `CouponService` + `CouponController::applyCoupon`; `CouponSystemTest` 21 pass, harden apply 8 pass (backward compat: status assertions unchanged).
- P2-5 claim uniqueness: VERIFIED (no schema change, intentional). Parent-row `CouponTargeting FOR UPDATE` + ACTIVE/REDEEMED checks + `coupons:reconcile duplicate_active_claims` detector. MySQL race NOT runtime-proven here (sqlite env) — ACCEPTED RISK, see Risks.
- P2-6 combined modes: VERIFIED. Fresh DB: `2026_09_10_000001` (assignment,dynamic) → `2026_09_14_000003` (+and/or, mysql ALTER + sqlite rebuild) → `2026_09_26_000002` parity repair (UNIQUE + defaults). All four modes supported fresh + production. Evidence: migration files; combined-mode tests in `CouponCheckoutRevalidationTest` pass.

## P3
Cleanup audited; no gratuitous changes. `calcPrice/isValid/verify/addCouponToCart`/GraphQL/fillable/pagination/show-by-code/reconcile-cap/comments/middleware left untouched except required P2-4 `addCouponToCart` reason return (safe, tests green). GraphQL untouched.

## Rules / targeting / limiter / assignment / notifications / currency / promotion order / product validation
All 17 rules validator↔runtime parity verified (unit 13+19 green). Targeting truth table tested (AND/OR/nested/depth). Limiter enforced `used+active_reservations<limiter` under `Coupon FOR UPDATE`. Assignment quota under lock, idempotent. Notifications DB+Pusher+FCM verified by code + channel auth (`routes/channels.php`); email excluded. Multi-currency `converted_total_price` + historical rate + unresolved exclusion preserved. Promotion→Coupon→Tax→Shipping ordering preserved (`AssignedCouponSystemTest::promotion_is_evaluated_before_coupon` pass). Product active/pricing gate at checkout + completion revalidation preserved.

## Routes
`route:list --path=coupons` 21 routes, no duplicate method+URI; static `mine/apply` before dynamic `{id}/claim` + `whereNumber`; admin helpers under `/api/v1/coupons/{id}/...` (actual, NOT `/api/v1/admin/...` — contract documents actual). Checkout callbacks public with throttle. VERIFIED.

## Security
No snapshot/rule-tree/limiter/used exposure to customers (public list minimal; claim safe shape; mine owner-scoped). Owner isolation for assignments/claims/codes. Pusher/FCM owner-scoped. Admin perms enforced (coupon + assignment + targeting write split). No secrets in repo.

## Tests (this run, post-review)
EligibilityEngine 13 PASS; NewRules 19 PASS; CheckoutRevalidation 15 PASS; CouponClaim 12 PASS; ClaimIntegration 8 PASS; ClaimLifecycle 18 PASS; CustomerMetrics 7 PASS; AssignedCouponSystem 49 PASS; Remediation 15 PASS; CouponSystem 21 PASS; AssignmentApi+Configuration 29 PASS (legacy alias restored); Harden-apply 8 PASS; RemediationPhase2 9 PASS; SecurityRemediation-coupon 3 PASS; FinalContract 9 PASS (mail-excluded). `php -l` clean.

## Review fixes
- BLOCKER routes: legacy `/api/v1/admin/coupons/*` aliases restored in `routes/api.php` (canonical `/api/v1/coupons/*` kept); `route:list` shows both; `CouponConfigurationTest` 8 PASS.
- BLOCKER mail test: `CouponFinalContractTest::test_sec6_*` updated to never-mail policy (9 PASS).
- Metrics lock: rebuild deferred to `DB::afterCommit` (no extended FOR UPDATE hold).
- Money: non-numeric thresholds fail closed in engine (validator already 422s).
- Marvel claim: `loadMissing('coupon:id,code')` parity; claim leak test uses `assertJsonMissingPath` + code value.

## Remaining risks / NOT VERIFIED
- MySQL concurrency races (claim/limiter/assignment-usage/duplicate callback under load) logic-correct but NOT load-proven here (sqlite; concurrency suites skip without MySQL/shared storage).
- Live Pusher/FCM delivery, queue workers (`high`), scheduler running, prod SMTP absence (N/A), gateway verify internals, prod legacy counts/dry-runs, MySQL migration run, `APP_URL_FRONTEND` value — config/env dependent, UNVERIFIED in this env.
- Full suite has 39 unrelated sqlite failures (`order_analytics_hourly: no such table` on RENAME) — pre-existing, unrelated to coupons.

## Statuses
P1-1 FIXED; P1-2 FIXED; P1-3 FIXED; P1-4 VERIFIED (documented commitment); P2-1 FIXED; P2-2 FIXED; P2-3 FIXED; P2-4 FIXED/DOCUMENTED; P2-5 VERIFIED (ACCEPTED RISK on load proof); P2-6 VERIFIED; P3 VERIFIED (minimal).
