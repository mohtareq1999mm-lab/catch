# COUPON RULE CAPABILITY MATRIX (VERIFIED)

> Scope: REST ONLY. Each rule verified against code + DB + runtime + tests.
> Source: `app/Services/Coupon/Eligibility/EligibilityEngine.php`, `CustomerMetricsService.php`, `CouponClaimService.php`, migrations, `EligibilityEngineTest.php`, `CouponRemediationPhase2Test.php`.

| Rule | Implemented | Data Exists | Source Table | Correct Query | AND/OR Compatible | Tested | Production Ready |
| ---- | ----------- | ----------- | ------------ | ------------- | ----------------- | ------ | ---------------- |
| min_completed_orders | YES | YES | `orders` via `customer_metrics` | `status=completed AND payment_status=payment-success` count, `converted_total_price` sum | YES (nested) | YES | YES |
| max_completed_orders | YES | YES | `orders` via `customer_metrics` | same as above | YES (nested) | YES | YES |
| min_total_spend | YES | YES | `orders.converted_total_price` via `customer_metrics.total_qualifying_order_value` | sum of qualifying orders, float compare | YES (nested) | YES | YES |
| max_total_spend | YES | YES | same | same | YES (nested) | YES | YES |
| first_order_after | YES | YES | `orders.created_at` min via `customer_metrics.first_order_at` | `isAfter(value)`, fails if no orders | YES (nested) | YES | YES |
| first_order_before | YES | YES | same | `isBefore(value)` | YES (nested) | YES | YES |
| last_order_after | YES | YES | `orders.created_at` max via `customer_metrics.last_order_at` | `isAfter(value)` | YES (nested) | YES | YES |
| last_order_before | YES | YES | same | `isBefore(value)` | YES (nested) | YES | YES |
| min_coupons_used | YES | YES | `orders.coupon` COUNT (orders, NOT distinct — Final Contract Sec 2) via `customer_metrics.coupons_used` | qualifying orders with coupon not null | YES (nested) | YES | YES |
| max_coupons_used | YES | YES | same | same | YES (nested) | YES | YES |
| claimed | YES | YES | `coupon_claims` | `exists(coupon_id,user_id)` any status | YES (nested) | YES | YES |
| not_claimed | YES | YES | `coupon_claims` | NO ACTIVE-unexpired AND NO REDEEMED (F-16 aligned) | YES (nested) | YES | YES |
| has_assignment | YES | YES | `coupon_assignments` | `exists(coupon_id,user_id)` | YES (nested) | YES | YES |
| area_in | YES | YES | `orders.governorate_id` → `governorates.id` (active only) | context `governorate_id` IN allowed list; absent context defers to checkout; null/unknown/inactive fail closed | YES (nested) | YES | YES |
| has_email | YES | YES | `users.email` | strict presence (trimmed + RFC-valid); `false` inverts; verification ignored | YES (nested) | YES | YES |
| registered_after | YES | YES | `users.created_at` (UTC) | `isAfter(cutoff)` exclusive; null fails closed | YES (nested) | YES | YES |
| registered_before | YES | YES | `users.created_at` (UTC) | `isBefore(cutoff)` exclusive; null fails closed | YES (nested) | YES | YES |

## AND/OR/Nested

- `AND`, `OR`, nested groups, single rule, deep nesting (max 10) VERIFIED.
- Empty group, unknown operator, unknown rule, malformed node → FAIL CLOSED (ineligible).
- Combined modes: `assignment_and_dynamic` (AND), `assignment_or_dynamic` (OR) VERIFIED.

## Customer data semantics (VERIFIED)

- Qualifying order = `status=completed AND payment_status=payment-success`.
- Spend = `converted_total_price` sum (no currency conversion; single-currency assumption documented).
- Excludes refunded/cancelled/pending (they are not completed+success).
- `coupons_used` = COUNT of qualifying orders carrying a coupon snapshot (Final Contract Sec 2: SAVE10+SAVE10+SAVE20 = 3, NOT distinct). Fixed from distinct-count; proven by `CouponFinalContractTest::test_sec2_coupons_used_counts_orders_not_distinct_codes`.
- Spend semantics: `converted_total_price` SUM in BASE currency (see COUPON_FINAL_GAP_REPORT P1 legacy-backfill note).
- Metrics cached in `customer_metrics`, rebuilt via `CustomerMetricsService::rebuildForUser`.

## NOT SUPPORTED (explicit)

```text
payment_method
NOT SUPPORTED
Reason: No canonical historical payment-method source. transactions.payment_method exists per order but gateway naming is inconsistent (myfatoorah/cod/pay_at_cashier vs gateway names), no currency-normalized aggregation, and no approved business semantics for targeting by gateway.
```

```text
products_purchased / categories_purchased / brands_purchased
NOT SUPPORTED
Reason: order_items + product relations exist, but no approved rule semantics (any vs all, quantity thresholds, time window) and no metrics pipeline. Would require new CustomerMetrics fields + backfill + policy approval.
```

```text
refund_history / cancelled_orders / country / city
NOT SUPPORTED
Reason: Data exists in orders/users tables but no approved targeting semantics, no metrics source of truth, and refund/cancel would need policy (count vs amount, window). Area targeting is covered at governorate granularity via area_in; city-level would need an order↔city column that does not exist. Not implemented to avoid inventing business rules.
```

```text
cash/COD usage / Stripe / MyFatoorah / PayPal specific counts
NOT SUPPORTED
Reason: Same as payment_method — no canonical aggregation, gateway identifiers vary, no policy.
```
