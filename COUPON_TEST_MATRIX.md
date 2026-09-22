# COUPON TEST MATRIX

> Philosophy: a test passes only when it proves the business invariant
> (state transition + exactly-once effects), not when an HTTP call succeeds.
> SQLite proves logic; MySQL proves concurrency (P2). Sequential SQLite tests
> MUST NOT be cited as concurrency evidence.

## Claim (INV-01, INV-12, POLICY 2/3)

- [ ] single claim ACTIVE with TTL (`CouponClaimService::claim`)
- [ ] duplicate ACTIVE claim rejected (same user/coupon)
- [ ] concurrent claim `max_claims=1`, 10 workers → exactly 1 success (MySQL, P2)
- [ ] `max_claims` counts ACTIVE+REDEEMED, excludes EXPIRED
- [ ] `max_claims=NULL` allows N claims; per-user usage still enforced
- [ ] expired claim allows re-claim; sweeper transitions ACTIVE→EXPIRED
- [ ] REDEEMED claim blocks re-claim-as-active; `markRedeemed` on non-ACTIVE is safe no-op/error without mutation
- [ ] wrong user cannot redeem another user's claim (CP-01 regression)
- [ ] wrong coupon claim never redeemed for an order
- [ ] claim → pay → claim REDEEMED exactly once; duplicate listener run is no-op

## Reservation (INV-04, INV-05, INV-06, POLICY 4)

- [ ] reserve creates 30m hold; duplicate reserve same order returns same row (idempotent)
- [ ] release×3 → exactly one logical release (row gone, capacity unchanged)
- [ ] consume×2 → single logical consume
- [ ] expiry sweeper deletes only expired; completion after expiry revalidates + reacquires (POLICY 4)
- [ ] expiry-vs-payment race follows POLICY 4 (P2, MySQL)
- [ ] cancel-vs-payment race: no illegal completed+cancelled, no double usage (P2, MySQL)
- [ ] every pre-payment cancel path releases (customer/admin/timeout/failure/API/service)

## Usage (INV-02, INV-03, INV-07)

- [ ] first usage creates exactly one usage row + counter +1
- [ ] repeated completion call → no additional mutation (`coupon_consumed` guard)
- [ ] duplicate payment callbacks A/B/C → 1 payment mutation, 1 usage, 1 counter, 1 redemption, 1 completion
- [ ] quota-exhausted at completion → order NOT completed (fail-closed, CP-04 regression)
- [ ] public-use history blocks assigned-branch reuse (POLICY 1 regression)
- [ ] counters never negative; reconciliation detects counter≠rows drift (P3 detectors)

## Pricing (INV-10, INV-11, POLICY 7/8)

- [ ] coupon only (percentage w/ cap, fixed, free-shipping)
- [ ] promotion only (engine authority untouched)
- [ ] promotion + coupon (coupon on post-promotion net)
- [ ] promotion + coupon + tax (allocation on discounted base, deterministic)
- [ ] promotion + coupon + shipping (free-shipping zeroes shipping)
- [ ] currency variations (snapshot authoritative; preview display-only; KWD 3dp documented)
- [ ] rounding determinism (same inputs → same outputs)

## Order lifecycle

- [ ] pending → paid → completed with coupon (happy path)
- [ ] checkout revalidation clears stale coupon; cart mutation re-checks (SCHEDULED + FAST parity)
- [ ] payment retry succeeds once; cancel before completion releases reservation, never restores quota
- [ ] cancel after completion / full / partial refund → quota unchanged (POLICY 5)
- [ ] admin edit after completion → snapshot immutable; pending order revalidated

## Admin / mass assignment (INV-13, POLICY 6)

- [ ] valid update persists allowed fields only
- [ ] `used`/counters rejected via generic input (CP-11 regression)
- [ ] invalid configuration rejected at save (no warn-only; CP-05 matrix)
- [ ] coupon edit after claim/reservation/completion preserves history

## Schema parity (P0)

- [ ] two `completed` orders same user succeed (partial-index regression)
- [ ] second `pending` order same user rejected (partial semantics preserved)
