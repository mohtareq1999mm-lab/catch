# COUPON FINAL FLOW (ADMIN → CUSTOMER → CHECKOUT → PAYMENT → USAGE)

## Happy path

```text
ADMIN
 ↓ Create coupon            POST /api/v1/coupons (discount, dates, limiter; used/code stripped)
 ↓ Configure targeting      PUT /api/v1/admin/coupons/{id}/targeting
 │                            (mode, require_claim, max_claims, claim_ttl_hours, rule_tree)
 ↓ Assign (optional)        POST /api/v1/coupons/{id}/assignments
 ↓ Notification             CouponAssigned → database + broadcast(Pusher) + fcm(Firebase) + mail
USER
 ↓ Discover                 GET /api/v1/general/coupons (public, NO codes)
 ↓ Claim                    POST /api/v1/general/coupons/{id}/claim → 201 ACTIVE (+TTL)
 │                            guards: targeting lock → capacity → eligibility → insert
 ↓ My Coupons               GET /api/v1/general/coupons/mine (assignments + claims, owner codes)
 ↓ Apply                    POST /api/v1/general/coupons/apply {code}
 │                            Orchestrator: status/dates/eligibility/claim/assignment/
 │                            usage-limits/product-gate → cart.coupon preview (NO consumption)
 ↓ Checkout                 POST /api/v1/general/checkout (or fast-shipping/checkout)
 │                            FULL revalidation (never trusts Apply)
 ↓ Revalidation             status, dates, eligibility, claim, assignment, limits,
 │                          reservation capacity, product conditions, pricing
 ↓ Payment start            reserve: used + active_reservations < limiter (coupon FOR UPDATE,
 │                          unique per order, 30-min TTL) → gateway invoice (effective ccy)
 ↓ Payment success          callback verify → locks + idempotency_key + status +
 │                          amount/currency check → commit inventory → finalize promotion →
 │                          changeOrderStatus(completed) → recordCouponUsage
 ↓ Usage                    assigned path (assignment lock, quota, usage row, counters,
 │                          reservation consume, AssignedCouponConsumed) or public path
 │                          (coupon_usages firstOrCreate, counters, reservation consume)
 ↓ Claim redeemed           PaymentSucceeded → MarkCouponClaimRedeemed ACTIVE→REDEEMED
 ↓ Order completed          status completed + payment-success + invoice + notifications
 ↓ Notify                   coupon.used (database+broadcast+fcm) + order/payment updates
```

## Failure flow

```text
Apply → Checkout → Reservation → Payment FAILED (gateway/cancel/timeout/
error-callback/duplicate-error/processing-failure)
 ↓ Reservation RELEASED (idempotent delete-by-order)
 ↓ Transaction → failed, PaymentFailed emitted
 ↓ NO usage, NO counters, NO claim transition, order stays actionable
```

## Duplicate callback flow

```text
Success Callback #1 → token set + status paid + consume → usage committed
Success Callback #2 → token present → no-op (NO duplicate consume)
Guaranteed by: idempotency_key + order status + coupon_consumed flag +
unique(coupon_usages) / unique(assignment,order) — DB-level, not app-only.
```

## Cancellation (APPROVED POLICY Sec 24: coupon does NOT return)

```text
Cancel unpaid (pending/processing) → reservation released, usage untouched (none), promotion decremented
Completed → cancelled              → REJECTED by state machine (no path exists)
Refund (money only)                → usage + counters + REDEEMED stand; inventory restores per cancel rules
```

## Schedulers keeping the flow safe

- `coupons:expire-reservations` every 5 min (stale holds die)
- `coupons:expire-claims` hourly (ACTIVE→EXPIRED, capacity freed)
- `coupons:reconcile` hourly (9 detectors, TOTAL 0 at last run)
- `orders:cancel-unpaid` every 5 min (abandoned checkouts release holds)
