# Coupon Business Flow (source-verified)

`VIEW → CLAIM → MY COUPONS → APPLY → CHECKOUT → REVALIDATION → PROMOTION → COUPON → TAX → SHIPPING → SNAPSHOT → PAYMENT → RESERVATION → SUCCESS → USAGE → REDEEMED`. Failure: `PAYMENT FAILURE → RESERVATION RELEASE → NO USAGE → NO REDEMPTION`. Consumed coupons NEVER return on cancel/refund.

## Ownership
- Marvel (storage): `coupons`, CRUD, `coupon_assignments`, `coupon_usages`, `coupon_products`.
- `app/` (runtime-authoritative customer layer): `CouponOrchestrator`, `EligibilityEngine`, `RuleTreeValidator`, targeting, claim, apply preview, checkout revalidation, `CouponReservationService` (30-min TTL), payment gating (`recordCouponUsage`), metrics (`CustomerMetricsService`), notifications, `coupons:reconcile` + expiry sweepers.

## Assignment lifecycle
Admin selects coupon+user+`max_uses`+optional `expires_at` → `CouponAssignmentRepository::assignCoupon` (unique `(coupon,user)` arbiter, 409 on duplicate) → commit → `CouponAssigned` event. Quota: `remaining = max_uses - used`; third attempt after 2/2 rejected (`usage_quota_exceeded`). Completion locks `Coupon FOR UPDATE` + `Assignment FOR UPDATE`; usage idempotent via `coupon_assignment_usages(assignment,order)` + `coupon_consumed` flag.

## Claim lifecycle
`NO CLAIM → ACTIVE → REDEEMED`; `ACTIVE → EXPIRED` (TTL sweeper). `max_claims = ACTIVE(unexpired) + REDEEMED` under `CouponTargeting FOR UPDATE`; expired releases capacity. Concurrent claims serialize on parent row (deadlock retry 3). `REDEEMED` blocks re-claim.

## Apply vs checkout vs payment
- APPLY: preview only (validates claim/assignment/tree/static/product; writes `cart.coupon`; no counters/reservation).
- CHECKOUT: authoritative revalidation with STRICT area context; invalid coupon silently stripped (`cart.coupon=null`, order proceeds without coupon — frontend detects via `order.coupon==null` → `COUPON_NO_LONGER_ELIGIBLE` UI).
- RESERVATION: `Coupon FOR UPDATE`, `used + active_reservations < limiter`, per-order idempotent, 30-min TTL. Created BEFORE gateway invoice (online/cod/cashier).
- PAYMENT SUCCESS: lock transaction → idempotency → verify amount/currency → commit inventory → finalize promotion → complete order → `recordCouponUsage` (revalidates ONLY if hold stale/missing; live hold = commitment) → delete reservation → `coupon_consumed=true` → `PaymentSucceeded` → claim `ACTIVE→REDEEMED`. FAILURE: release reservation, no usage/redemption/counters.

## Money
Promotion → Coupon → Tax → Shipping. Spend rules aggregate `orders.converted_total_price` in immutable base/catalog currency; historical rates preserved; `LEGACY_CURRENCY_UNRESOLVED` excluded from SUM (counts/dates included). Spend comparisons decimal-safe (`bccomp` 2dp). `CouponCalculator`: percentage (capped by `max_discount_amount`), fixed (capped by price), free-shipping flag; rounding 2dp.

## Targeting modes
`assignment` (usable assignment), `dynamic` (tree), `assignment_and_dynamic` (both), `assignment_or_dynamic` (either; assignment path counts only when coupon grants assignments AND user holds valid one). Legacy no-targeting = assignment fallback (backward compat). Targeting DELETE widens access — forbidden on live coupons.

## 17 rules
See `docs/api/COUPON_API_CONTRACT.md` §5. Validator (`RuleTreeValidator`) and runtime (`EligibilityEngine`) share grammar: leaf `{type,value}`, group `{operator:AND|OR, rules:[...]}` nested ≤10, fail-closed on unknown/malformed/empty/depth>10. P2 fixes: `has_assignment` = usable only; `claimed` = ACTIVE|REDEEMED (expired = not claimed); spend = decimal-safe.
