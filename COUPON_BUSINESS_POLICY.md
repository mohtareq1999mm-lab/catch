# COUPON BUSINESS POLICY (FROZEN)

> Status: APPROVED. Frozen before P2. Any change requires explicit re-approval.
> Scope: Coupon Engine of the Laravel monolith (`app/` canonical, `packages/marvel` owns `coupons` table + admin CRUD).
> Source of truth order on conflict: this file > `COUPON_INVARIANTS.md` > code > older docs.

## POLICY 1 — PUBLIC vs ASSIGNED

Eligibility is evaluated against authoritative usage/claim history. Public and assigned
access paths MUST NOT bypass the user's lifetime/usage restrictions for the same logical coupon.

- If the user consumed the coupon publicly (`coupon_usages` row), an assigned copy MUST NOT
  reset that history: the public-use check applies in the assigned branch too.
- Assigned coupons MAY add assignment-specific eligibility (`CouponAssignmentValidator`:
  assignment exists, unexpired, `used < max_uses`), but MUST NOT weaken global anti-abuse rules.
- Enforcement points: `CouponOrchestrator::validate` (both branches), `OrderService::recordCouponUsage`.

## POLICY 2 — max_claims

`max_claims` (on `coupon_targetings`) = maximum number of claims consuming coupon capacity.

- Count toward the limit: `ACTIVE` (unexpired) + `REDEEMED`.
- Do NOT count: `EXPIRED` (capacity released at expiration), time-expired ACTIVE rows
  (`expires_at <= now()` treated as releasable).
- Reservations are temporary holds; they MUST NOT inflate the historical claim count.
- `max_claims` is enforced under the parent `CouponTargeting` row lock (`lockForUpdate`)
  + eventual DB guard (P2). Concurrent requests MUST NOT exceed it.

## POLICY 3 — max_claims = NULL

`NULL` = unlimited **claim capacity** only. It does NOT imply: unlimited per-user usage,
unlimited reservations, unlimited redemptions, or bypass of assignment restrictions.
Per-user usage (`coupon_usages` unique, assignment quota) is enforced independently.

## POLICY 4 — RESERVATION EXPIRY vs PAYMENT

Coupon reservation TTL = 30 minutes. Orders may stay `pending` longer (24h online/cashier, 7d COD).

- If the reservation expired before payment success: completion MUST revalidate coupon
  eligibility + capacity and acquire a fresh reservation BEFORE consuming.
- If the coupon is no longer eligible/available at completion: consumption MUST NOT be
  silently skipped. Completion MUST fail atomically (order stays `pending`, caller retries
  or fails visibly). No fail-open completion.

## POLICY 5 — CANCEL / REFUND

Coupon quota is NEVER automatically restored after cancellation, completion, refund,
or payment reversal (full, partial, item return, gateway reversal, admin reversal).

- Rationale: anti-abuse (cancel/re-order farming). Documented in `OrderService::recordCouponUsage`.
- Any future manual reversal MUST be explicit, authorized, auditable, idempotent, and
  separate from automatic lifecycle processing. Not implemented in this remediation.

## POLICY 6 — ADMIN EDITS

- Historical order snapshots (`orders.coupon/coupon_discount/*`, taxes, currency) are IMMUTABLE.
  Current coupon config changes MUST NOT rewrite them.
- Pending orders: eligibility MUST be revalidated at checkout/completion (authoritative lifecycle);
  stale cart discounts MUST NOT be honored.
- Admin writes pass server-side validation (`CouponRequest::validated()`); internal
  counters/state/ownership (`used`, assignment `used`, claim status, reservation rows)
  MUST NOT be writable via generic mass assignment.

## POLICY 7 — STACKING

Authoritative pipeline (unchanged):

```text
Base Price → Promotion → Coupon → Tax → Shipping → Order Snapshot
```

- Coupon operates on the post-promotion net. Multiple coupons per order are NOT allowed
  (single `carts.coupon` / `orders.coupon`).
- Multiple promotions remain governed by the existing Promotion Engine (untouched authority).
- Product restriction (`coupon_product`) is an all-or-nothing cart gate, not pro-rata
  (existing contract, preserved).

## POLICY 8 — CURRENCY / ROUNDING

Existing financial contract preserved (no minor-unit rewrite):

- Percentage: `price × pct`, capped by `max_discount_amount`; fixed: `min(discount, price)`;
  free-shipping: discount 0 + flag. All `round(..., 2)`, floored at 0. Totals never negative.
- Coupon is allocated per order line pre-tax; product tax applies to the discounted base;
  shipping is never taxable. Payment gateway amounts use `×1000` millis consistently.
- KWD 3-decimal behavior: stored `discount decimal(8,3)`, computed at 2dp (existing contract,
  documented, unchanged). Snapshot values are authoritative; cart preview is display-only.
