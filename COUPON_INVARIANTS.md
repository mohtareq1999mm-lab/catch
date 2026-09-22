# COUPON INVARIANTS

> Frozen with `COUPON_BUSINESS_POLICY.md`. Every invariant has an authoritative source
> and at least one test in `COUPON_TEST_MATRIX.md`. Counters are denormalized caches;
> usage/claim/reservation rows are authoritative (reconciliation detectors in P3).

| ID | Invariant | Authoritative source | Enforced by |
|----|-----------|----------------------|-------------|
| INV-01 | A claim cannot be redeemed twice | `coupon_claims.status` (ACTIVE→REDEEMED once, row-locked) | `CouponClaimService::markRedeemed` + `MarkCouponClaimRedeemed` |
| INV-02 | A duplicate payment callback cannot create duplicate coupon usage | `coupon_usages` unique + `coupon_assignment_usages` unique + `orders.coupon_consumed` flag | `recordCouponUsage` idempotency guards |
| INV-03 | A completed coupon order cannot exist without required coupon usage state | completion transaction (usage commit precedes `completed`) | `changeOrderStatus` throws on consumption failure (no fail-open) |
| INV-04 | Reservation release is idempotent | `coupon_reservations` row existence (delete = single logical release) | `CouponReservationService::release` on every pre-payment cancel path |
| INV-05 | Reservation consumption is idempotent | `coupon_reservations` row existence (delete once) | `consume()` delete-by-order; no counters touched |
| INV-06 | Limited coupon capacity cannot be over-allocated | `coupons.used` + active reservations + usages under `lockForUpdate` | `reserve()` capacity check; completion recheck (POLICY 4) |
| INV-07 | Counters cannot become negative | `used >= 0` (increment-only; never decremented automatically) | no decrement path exists; reconciliation detects drift |
| INV-08 | Public coupon lookup cannot expose assigned-only information | public resource shape (no code, no assignment/claim data) | `Coupons\CouponResource` (no `code`), auth-gated assignment endpoints |
| INV-09 | Historical order coupon data cannot change with config changes | `orders` snapshot columns | snapshot-only writes; admin edits never touch `orders` |
| INV-10 | Promotion is evaluated before Coupon | `calculateCheckoutTotals` order | pipeline preserved; integration tests |
| INV-11 | Coupon discount and tax calculations are deterministic | `CouponCalculator` + `withTaxes` + snapshot | same inputs → same outputs; rounding documented |
| INV-12 | Concurrent requests cannot create conflicting terminal states | row locks + uniques + state-checked transitions | claim parent-lock, cart/order locks, P2 MySQL proof |
| INV-13 | Unauthorized admin input cannot mutate internal counters/state | request validation + fillable lockdown | `CouponRequest::validated()`, `used` not fillable |
| INV-14 | All authoritative state transitions are transactional | DB transactions around claim/reserve/consume/complete | services; `ShouldDispatchAfterCommit` for post-commit events |

## State machines

```text
Claim:       ACTIVE ──→ REDEEMED (terminal)
             ACTIVE ──→ EXPIRED  (terminal)
             REDEEMED/EXPIRED: no outgoing transitions

Reservation: RESERVED ──→ CONSUMED (terminal, row deleted)
             RESERVED ──→ RELEASED (terminal, row deleted)
             RESERVED ──→ EXPIRED  (sweeper deletes; POLICY 4 revalidation at completion)
```

## Lock order (documented, P2-audited)

```text
Cart → Coupon → CouponTargeting → CouponClaim → CouponReservation → CouponAssignment → Usage rows → Order
```

All participating services acquire locks in this order. Deadlock retries (bounded, P2) remain
idempotent because every guarded write is a state-checked transition or unique-keyed insert.
