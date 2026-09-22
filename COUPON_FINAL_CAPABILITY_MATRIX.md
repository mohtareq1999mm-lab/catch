# COUPON FINAL CAPABILITY MATRIX

> Per-rule full chain: REST configurable → persisted → evaluated →
> checkout verified → tested → concurrency verified.
> Scope: REST ONLY.

| Rule | Business Meaning | Input | Canonical Source | DB Query | Op | AND | OR | Nested | Admin REST | Persist | Eval | Checkout | +Test | −Test | Boundary | Status |
| ---- | ---------------- | ----- | ---------------- | -------- | -- | --- | -- | ------ | ---------- | ------- | ---- | -------- | ----- | ----- | -------- | ------ |
| min_completed_orders | qualifying completed orders ≥ N | int ≥ 0 | `customer_metrics.completed_orders` ← orders(status completed + pay success) | count | ≥ | YES | YES | YES | rule_tree leaf | rule_tree JSON | EligibilityEngine | revalidated | YES | YES | =N pass | READY |
| max_completed_orders | qualifying completed orders ≤ N | int ≥ 0 | same | count | ≤ | YES | YES | YES | leaf | JSON | engine | revalidated | YES | YES | =N pass | READY |
| min_total_spend | base-currency qualifying spend ≥ X | numeric ≥ 0 | `customer_metrics.total_qualifying_order_value` ← SUM(converted_total_price, BASE ccy) | sum float | ≥ | YES | YES | YES | leaf | JSON | engine | revalidated | YES | YES | =X pass | READY* |
| max_total_spend | base-currency qualifying spend ≤ X | numeric ≥ 0 | same | sum float | ≤ | YES | YES | YES | leaf | JSON | engine | revalidated | YES | YES | =X pass | READY* |
| first_order_after | first qualifying order strictly after T | datetime | `customer_metrics.first_order_at` ← MIN(created_at) | isAfter | > | YES | YES | YES | leaf | JSON | engine | revalidated | YES | YES | =T fails | READY |
| first_order_before | first qualifying order strictly before T | datetime | same | isBefore | < | YES | YES | YES | leaf | JSON | engine | revalidated | YES | YES | =T fails | READY |
| last_order_after | last qualifying order strictly after T | datetime | `customer_metrics.last_order_at` ← MAX(created_at) | isAfter | > | YES | YES | YES | leaf | JSON | engine | revalidated | YES | YES | =T fails | READY |
| last_order_before | last qualifying order strictly before T | datetime | same | isBefore | < | YES | YES | YES | leaf | JSON | engine | revalidated | YES | YES | =T fails | READY |
| min_coupons_used | qualifying coupon-carrying orders ≥ N (Sec 2: NOT distinct) | int ≥ 0 | `customer_metrics.coupons_used` ← COUNT(qualifying orders, coupon NOT NULL) | count | ≥ | YES | YES | YES | leaf | JSON | engine | revalidated | YES (Sec2=3) | YES | =N pass | READY |
| max_coupons_used | qualifying coupon-carrying orders ≤ N | int ≥ 0 | same | count | ≤ | YES | YES | YES | leaf | JSON | engine | revalidated | YES | YES | =N pass | READY |
| claimed | user has ANY claim row for coupon | — | `coupon_claims(coupon_id,user_id)` | exists | — | YES | YES | YES | leaf | claims table | engine | revalidated | YES | YES | — | READY |
| not_claimed | NO ACTIVE-unexpired AND NO REDEEMED (F-16) | — | `coupon_claims` status+expires_at | !exists ×2 | — | YES | YES | YES | leaf | claims table | engine | revalidated | YES | YES | expired→pass | READY |
| has_assignment | assignment row exists for user+coupon | — | `coupon_assignments(coupon_id,user_id)` | exists | — | YES | YES | YES | leaf (+assign API) | assignments table | engine | revalidated + quota/expiry | YES | YES | — | READY |

READY* = spend rules are READY for post-currency-feature orders. Legacy
backfilled rows are handled by closure remediation: Case A recovered with
historical rates (`coupons:remediate-legacy-currency --apply`), Case B marked
`LEGACY_CURRENCY_UNRESOLVED` and excluded from the spend SUM (counts/dates
still include them). No code change required for new orders.

## Engine operators

Single / AND / OR / nested AND / nested OR / AND-containing-OR /
OR-containing-AND / deep ≤10: PASS (Phase2Test). Empty group / unknown
operator / unknown rule / malformed node / depth>10: FAIL CLOSED (PASS).
Admin `RuleTreeValidator` grammar == engine grammar (no transformation loss).

## Concurrency per rule

Rules read point-in-time `customer_metrics` + claims/assignments tables.
Claim-gating rules (`claimed/not_claimed/has_assignment`) are evaluated
inside the parent-row lock at claim time and revalidated at consumption
under Coupon FOR UPDATE; no stale-read consumption path exists.

## Explicitly NOT SUPPORTED (unchanged)

payment_method / products / categories / brands / refund_history /
cancelled_orders / registration_date / country / region / gateway-specific
counts — no canonical source + no approved semantics. NOT IMPLEMENTED by
design (see prior MATRIX for reasons).
