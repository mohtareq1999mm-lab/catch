# COUPON FINAL BUSINESS CONTRACT (APPROVED SEMANTICS)

> Scope: REST ONLY. GraphQL OUT OF SCOPE (untouched).
> Each semantic below is traced to code + DB + tests. Items that cannot be
> guaranteed are marked GAP and detailed in `COUPON_FINAL_GAP_REPORT.md`.

## 1. Multi-currency (Sec 1)

- Canonical order value: `orders.converted_total_price` (decimal 10,3),
  denominated in `orders.base_currency_code` (settings `base_currency_code`,
  default `shop.default_currency`; base change BLOCKED once qualifying orders
  exist — `CurrencyService::setBaseCurrency` immutability guard).
- Catalog rule (APPROVED): Admin catalog currency → checkout currency →
  payment currency. Enforced when `currency_selection_enabled=false`
  (default; dev compliant). If selection is enabled, payment follows the
  selected currency — keep disabled per Sec 1 or approve the divergence.
- `orders.total_price` is the EFFECTIVE (customer/pay) currency amount
  (`orders.currency_code`); payment gateways and callback amount checks use
  the effective value. Rule engine and spend metrics use the base value.
- Exchange rate per order is snapshotted immutably:
  `currency_rate` + `currency_rate_date` + `catalog_currency_code`, sourced
  from `currency_rates` daily rows (LKG ≤ date) via BCMath SCALE 6, rounded 2.
  Historical snapshots are never rewritten by rate sync
  (`docs/CURRENCY_EXCHANGE_RATE_SYSTEM.md` Sec 14; `OrderCreationService`).
- GAP CLOSED (P1): pre-currency-feature orders were backfilled
  `converted_total_price = total_price` (mixed currencies, NOT normalized).
  Closure remediation: `coupons:remediate-legacy-currency` recovers Case A
  rows with historical rates and marks Case B `LEGACY_CURRENCY_UNRESOLVED`
  (excluded from spend SUM, counted in counts/dates). See
  COUPON_FINAL_CLOSURE_REPORT Sec 4 (dev counts: 0 legacy rows) and GAP REPORT.

## 2. min_coupons_used (Sec 2)

FINAL: number of QUALIFYING orders carrying a coupon snapshot.
`SAVE10 + SAVE10 + SAVE20` over 3 qualifying orders = **3** (NOT distinct).
Implemented in `CustomerMetricsService::rebuildForUser` as COUNT (no distinct).
Fixed from distinct-count this cycle; proven by
`CouponFinalContractTest::test_sec2_coupons_used_counts_orders_not_distinct_codes`.
Same semantics for `max_coupons_used`. Only
`status=completed AND payment_status=payment-success` orders count.

## 3. Customer coupon UX (Sec 3)

`View Coupon → Claim → My Coupons → Apply`. Backend REST supports every step:
`GET /api/v1/general/coupons` (no codes), `POST .../coupons/{id}/claim`
(201/409 reason-only/404), `GET /api/v1/general/coupons/mine` (NEW: owner
assignments + claims, codes exposed ONLY to owner),
`POST .../coupons/apply` (preview, no consumption). All Sec 3 states are
reachable reason codes (Sec 12 of FRONTEND contract); no rule diagnostics leak.

## 4. Claim semantics (Sec 4)

`max_claims = ACTIVE(unexpired) + REDEEMED`. EXPIRED releases capacity.
Concurrency: parent-row `CouponTargeting FOR UPDATE` serializes claims;
capacity counted inside the same lock; deadlock retry ×3. Two simultaneous
claims cannot exceed max_claims (lock-serialized; reconcile detector
`duplicate_active_claims` as backstop).

## 5. Coupon limiter (Sec 5)

Frontend MUST NOT display `used/limiter`. Backend enforces
`used + active_reservations < limiter` under coupon `FOR UPDATE` at
reservation creation. Reservation ≠ usage.

## 6. Assignment (Sec 6)

`Admin → assign → persisted → user notified` via `CouponAssigned` event →
in-app (database) + realtime (broadcast/Pusher) + push (fcm/Firebase) + email
(mail, NEW this cycle, skipped only when user has no email address).
Discoverable via `GET /api/v1/general/coupons/mine` and
`Coupon::assignments / CouponAssignment::coupon+user+usages` relations.
No coupon data duplication (assignment references coupon row).

## 7. Four separate concepts (Sec 7)

Assignment (granted?) ≠ Claim (activated?) ≠ Reservation (capacity held?) ≠
Usage (permanently consumed?). Separate tables, separate lifecycles, separate
reason codes. Never collapsed.

## 8. Assignment max uses (Sec 8)

`remaining = max_uses - used`, decremented atomically under
Coupon FOR UPDATE → Assignment FOR UPDATE inside the completion transaction;
third use after 2/2 fails with `quota_exhausted`; usage row unique per
(assignment, order) + idempotency check. Proven by AssignedCouponSystemTest
(multi-use, quota, isolation).

## 9-10. Combined modes (Sec 9-10)

`assignment_and_dynamic` = assignment AND dynamic (both must pass).
`assignment_or_dynamic` = either passes (unassigned + rich customer passes).
Evaluated through Admin REST → `coupon_targetings` → rule_tree →
EligibilityEngine → Apply → Checkout revalidation. Proven by Phase2 tests.

## 11. Rules (Sec 11)

All 13 rules verified end-to-end (REST config → persist → evaluate →
checkout → tests): see `COUPON_FINAL_CAPABILITY_MATRIX.md`.

## 12. AND/OR/nested (Sec 12)

Single/AND/OR/nested/AND-in-OR/OR-in-AND/deep(≤10) verified; empty group,
unknown operator/rule, malformed node, depth>10 → fail closed. Admin REST
`RuleTreeValidator` enforces the same grammar the engine evaluates — no
transformation loss (JSON → request → validator → DB JSON → engine).

## 13. Claim lifecycle (Sec 13)

`No claim → ACTIVE → REDEEMED`; `ACTIVE → EXPIRED` (TTL sweeper hourly).
ACTIVE/REDEEMED block re-claim; EXPIRED allows re-claim. Races serialized by
parent-row lock.

## 14. Apply (Sec 14)

Eligibility + discount + cart preview. No usage, no reservation, no counters.

## 15. Checkout revalidation (Sec 15)

Checkout never trusts Apply: revalidates status, dates, eligibility, claim,
assignment, usage limits, reservation capacity, product conditions, pricing.

## 16. Reservation (Sec 16)

Checkout → payment start → reservation (30 min TTL, unique per order,
idempotent refresh) → payment. Capacity test `limiter=100/used=99`: A allowed,
B blocked; A-fail releases (B may retry); A-success consumes → usage + used=100.

## 17. Payment failure (Sec 17)

Gateway failure / cancel / timeout / error callback / duplicate error /
processing failure → reservation released, NO usage, transaction failed,
`PaymentFailed` emitted. Error callback mirrors success-path guards (F-01).

## 18. Payment success (Sec 18)

Success → completion → atomic idempotent consumption → usage persisted →
claim REDEEMED. Guards: `idempotency_key` + status + row locks + amount/
currency check + `coupon_consumed` + unique usage rows.

## 19. Duplicate callbacks (Sec 19)

Callback #2 is a no-op: token + status + consumed-flag + unique constraints
(DB-level, not app-only).

## 20. Cancellation/refund (Sec 20/24 — APPROVED POLICY)

APPROVED: coupon does NOT return after cancellation or refund.

```text
Coupon consumed → order later cancelled/refunded → coupon remains consumed.
```

Do NOT restore usage, counters, or redeemed claims. Reservation is still
released if never converted into usage. Structural enforcement: the order
state machine forbids `completed → cancelled`
(`OrderService::$allowedOrderTransitions`); cancel paths touch only
pending/processing (pre-consumption) orders; the refund gateway method moves
money only. Proven by `CouponCurrencyClosureTest` (Sec 24 pair).
