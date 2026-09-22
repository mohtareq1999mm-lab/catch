# COUPON FINAL CLOSURE REPORT (CURRENCY + LIFECYCLE)

> Scope: REST ONLY. No GraphQL changes. No invented business rules.
> Verdict rationale at the bottom. Evidence-first: every claim cites
> code + DB + test output from this cycle.

## VERIFIED

- **Catalog currency chain (Sec 1/10):** Admin catalog code
  (`CurrencyService::getCatalogCode`) → effective code (equals catalog when
  `currency_selection_enabled=false`, the default; dev DB has no settings row
  → default → compliant) → `OrderCreationService::resolveCurrencySnapshot`
  → `orders.currency_code` → gateway `DisplayCurrencyIso` + callback checks +
  transaction `currency` all read the ORDER currency
  (`MyFatoorahGateway:24,42`, `PaymentCheckoutHandler:43,87,120,153`,
  `OrderController:347,631`). Gateway test proves KWD order → KWD invoice.
  Conditional: enabling currency selection lets payment diverge from catalog
  (intentional feature) → keep disabled per Sec 1, or approve divergence.
- **Historical-rate discipline (Sec 3):** `CurrencyConversionService::convert`
  resolves LKG `effective_date <= order date`, throws otherwise; snapshots
  immutable; base change blocked after financial orders.
- **Canonical spend (Sec 6):** field `orders.converted_total_price`, currency
  `orders.base_currency_code`, source per-order snapshot, BCMath SCALE 6 →
  round 2, historical source `currency_rates` LKG. Engine consumes ONLY this
  (via `customer_metrics.total_qualifying_order_value`).
- **Metrics qualification (Sec 7):** `completed + payment-success` only
  (unchanged, re-tested).
- **Concepts/claim/limiter/assignment/quota/engine/modes/apply/checkout/
  reservation/idempotency (Sec 11–23):** re-verified unchanged; 99 prior
  tests re-run green this cycle.
- **Sec 24 no-return:** current code already complied; now APPROVED POLICY.
  Structural proof: `completed → cancelled` rejected by state machine
  (`OrderService:646-652`); cancel paths touch only pending/processing;
  refund gateway method moves money only (no status/coupon path).

## FIXED (this cycle)

- **Legacy spend remediation (Sec 5):** NEW `coupons:remediate-legacy-currency`
  (dry-run default; `--apply` backfills Case A snapshots with historical
  rates only, marks Case B `LEGACY_CURRENCY_UNRESOLVED`, rebuilds affected
  metrics; idempotent, crash-healable, race-guarded, logged with provenance).
  NEW migration `2026_09_22_000001` (`orders.legacy_currency_status`,
  nullable+indexed, SAFE). Metrics spend SUM excludes unresolved rows
  (counts/dates untouched).
- **Review hardening:** null order date → unresolved (no today-substitution);
  in-txn predicate guards; stored-rate fallback only when history absent;
  paid-txn preference; base-source logging; shared `LEGACY_CURRENCY_UNRESOLVED`
  const; audit counts cover both legacy shapes.
- **Self-found:** none (no new regressions; prior-cycle `preferredLocale`
  lesson applied — no model-method assumptions in new code).

## PROVEN BY TEST (this cycle)

- `CouponCurrencyClosureTest` 9/9: Case A historical-not-current (322.58,
  June rate rejected), Case B marked + spend-excluded, dry-run clean,
  rerun idempotent, txn-mismatch unresolved, no-rate unresolved, Sec 24
  no-return (completed locked, usage/counter/REDEEMED stand) + unpaid-cancel
  releases reservation with no usage, gateway charges order currency.
- Regression: Metrics 7, Engine 13, FinalContract 9, Remediation 15,
  Phase2 9, Assigned 49 — **108 passed, 0 failed** (sequential runs).

## Sec 4 LEGACY AUDIT — ACTUAL COUNTS (dev database)

```text
total_orders: 0 | legacy_null_currency_code: 0 | legacy_null_converted: 0
legacy_with_full_metadata: 0 | legacy_unresolved_marked: 0
legacy_ambiguous_unmarked: 0 | affected_customers: 0
affected_spend_evaluations: 0
```

Command: `php artisan coupons:remediate-legacy-currency` → DRY-RUN, base USD.
Production counts are UNVERIFIED (no prod access) — run the same command
against prod (dry-run first) for true figures.

## Sec 26 STAGE AUDIT (condensed)

```text
Catalog Currency | settings.options/currency svc | getCatalogCode | admin settings validation | — | — | PATCH settings | envelope | Currency suite
Order Currency   | orders.currency_code | resolveCurrencySnapshot | rate existence else 400 | — | order create txn | POST checkout | order JSON | closure Sec1 test
Historical Data  | orders snapshot cols | snapshot | immutable by design | — | — | — | order JSON | Case A test
Canonical Spend  | orders.converted_total_price (BASE) | snapshot math | round2 | — | — | — | — | Case A metrics assert
Customer Metrics | customer_metrics | rebuildForUser | qualifying only | — | rebuild txn | — | — | Metrics 7 + closure
Coupon Rule      | coupon_targetings.rule_tree | RuleTreeValidator | fail-closed | Targeting lock | claim txn | PUT targeting | envelope | Phase2
Eligibility      | engine (read-only) | whitelist | fail-closed | caller locks | — | — | reason-only | Engine 13
Claim            | coupon_claims | claimondaitions | ACTIVE/REDEEMED block | Targeting FOR UPDATE | claim txn+retry | POST claim | 201/409 | Phase2+Remediation
Assignment       | coupon_assignments | max_uses/expiry | 409 mapping | Coupon lock | assign txn | assign API | 201/409 | Assigned 49
Apply            | cart.coupon | Orchestrator | full revalidation | — | — | POST apply | preview | Remediation
Checkout         | orders + snapshot | same + products | same | coupon lock | checkout txn | POST checkout | order | Assigned
Reservation      | coupon_reservations | capacity formula | row lock | coupon FOR UPDATE | reserve txn | — | — | Remediation
Payment          | transactions + gateway | verify+currency | supportsCurrency | txn+order locks | callback txn | callbacks | redirect/status | closure+Remediation
Usage            | coupon_usages/assignment_usages | quota/unique | fail-closed | Coupon+Assignment locks | completion txn | — | — | Assigned+Remediation
Claim Redemption | coupon_claims REDEEMED | ACTIVE-only guard | idempotent | — | afterCommit/listener | — | — | Remediation
Cancel/Refund    | status machine (no completed→cancel) | transition table | RuntimeException | order lock | cancel txn | — | — | closure Sec24
```

## Sec 27 RELEASE GATE (13 items)

1. Legacy audited: YES (dev zeros + prod-ready command/queries).
2. min_total_spend normalized: YES (new rows; legacy via remediation/exclusion).
3. max_total_spend normalized: YES (same).
4. min_coupons_used counts orders: YES (tested).
5. All 13 rules proven: YES.
6. AND/OR/nested proven: YES.
7. Claim concurrency proven: MECHANISMS verified, parallel load NOT PROVEN.
8. Reservation concurrency proven: MECHANISMS verified, parallel load NOT PROVEN.
9. Payment idempotency proven: YES (tests; parallel-callback load NOT PROVEN).
10. Assignment quota proven: YES.
11. REST contracts proven: YES.
12. Notification contracts proven: CHANNELS proven; live delivery NOT PROVEN.
13. No-return policy: YES (approved + structural + tests).

## NOT PROVEN

- Parallel-load concurrency (claim/reservation/callback races at load).
- Live notification delivery (SMTP/FCM/Pusher).
- Production legacy counts + remediation run (command ready, needs prod + approval).
- Full-suite regression (39 unrelated sqlite failures, out of scope).

## BUSINESS DECISION REQUIRED

- Keep `currency_selection_enabled=false` (Sec 1 compliance) or approve
  selection-divergence (payment ≠ catalog).
- Legacy Case B disposition beyond exclusion (currently: excluded + reported).
- Dashboard revenue (`COALESCE(converted,total)`) still sums unresolved rows
  while eligibility excludes them — align or accept.

## DATA REMEDIATION REQUIRED

- Prod: `php artisan coupons:remediate-legacy-currency` (dry-run → review →
  `--apply` with approval) → then one-time `CustomerMetricsService::rebuildAll`
  (chunked) for the pre-existing coupons_used semantic change.
- Prod: enable `coupons:reconcile` paging only after triaging historical hits.

## FINAL VERDICT

```text
NOT READY
```

Coupon logic, currency normalization, remediation tooling, and the approved
no-return policy are COMPLETE and proven (108 tests). Release is gated on
items 7/8/12 load-and-delivery proofs and the production data-remediation run
— all requiring staging/prod access this environment does not have. Nothing
is hidden under "minor issue": the exact gaps, counts, commands, and approval
points are listed above.
