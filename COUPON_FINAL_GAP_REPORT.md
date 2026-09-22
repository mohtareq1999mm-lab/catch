# COUPON FINAL GAP REPORT

## P0 — Blocking

NONE in coupon scope. (All Sec 27/28 known risks resolved: coupons_used
fixed to COUNT; code-normalization migration verified idempotent and
sequential — see P3 note.)

## P1 — Critical

### P1-1 Legacy multi-currency spend rows — TOOLING COMPLETE, PROD RUN REQUIRED
- Evidence: `2026_08_10_000004_add_currency_columns_to_orders_table.php:20-22`
  backfills `converted_total_price = total_price` for pre-feature orders.
- Fixed this cycle: `coupons:remediate-legacy-currency` (dry-run default)
  recovers Case A rows with historical LKG rates (never today's rate,
  txn-amount guard, idempotent, crash-healable, race-guarded, logged with
  provenance); Case B marked `LEGACY_CURRENCY_UNRESOLVED` and excluded from
  spend SUM. Migration `2026_09_22_000001` adds the marker (SAFE, applied).
  Dev counts: 0 legacy rows (proven by command run).
- Status: DATA REMEDIATION REQUIRED — dry-run → review → `--apply`
  (approval required) on production, then chunked `rebuildAll()` (P1-3).

### P1-2 Refund/restore policy — APPROVED + IMPLEMENTED (closure Sec 24)
- Approved: coupon does NOT return. The code already complied; closure proved
  it structurally (completed→cancelled forbidden by the state machine; cancel
  paths touch only pre-consumption orders; refund moves money only) and by
  test (`CouponCurrencyClosureTest` Sec 24 pair).
- Status: CLOSED.

### P1-3 One-time customer_metrics rebuild required on deploy
- Evidence: `CustomerMetricsService::getMetrics()` returns cached rows;
  `coupons_used` semantic changed distinct→COUNT this cycle, so existing rows
  keep stale values until rebuilt (users qualify sooner after rebuild:
  SAVE10×2+SAVE20 goes 1→3). No artisan command exists; `rebuildAll()` must
  run once via tinker/admin job on deploy (chunked for large datasets).
- Impact: wrong min/max_coupons_used eligibility until rebuilt.
- Status: rollout step REQUIRED (documented here; no code change — same
  staleness design already governs completed_orders/spend).

## P2 — Important

### P2-1 True parallel concurrency NOT PROVEN at load
- Evidence: serialization via parent-row lock (claims), coupon FOR UPDATE
  (reservations), assignment FOR UPDATE + unique rows (usage), token+status
  (callbacks) — all verified by code + sequential/duplicate tests
  (49 Assigned + 15 Remediation + 9 Phase2 + 6 FinalContract).
- Missing: parallel-process hammer tests (2 users × last claim slot / last
  capacity / same-order completion / duplicate callbacks / assignment race /
  claim+expiry / reservation+expiry). Prior `CouponConcurrencyProofTest` has 0
  isolated cases; order-wide suites time out on sqlite.
- Status: NOT PROVEN (mechanisms verified, load proof outstanding).

### P2-2 Broad regression signal broken (unrelated)
- Evidence: full suite has 39 unrelated sqlite failures
  (`order_analytics_hourly` view vs `orders_processing_new` RENAME) and
  `--filter=Coupon` broad runs time out; a sqlite-incompatible
  `product_locations ... ADD CONSTRAINT` migration breaks isolated runs.
- Impact: release cannot be gated on the full suite; coupon scope gated on
  99 focused tests (all PASS).
- Status: NOT PROVEN at suite level (out of coupon scope to fix).

### P2-3 Reconcile paging on historical rows
- `coupons:reconcile` exits 1 on any detector hit; pre-enforcement history may
  page after enabling. Add `--since` window + triage runbook before paging.
- Status: operational follow-up.

## P3 — Improvement

### P3-1 Migration filename uses a future date
- `2026_09_27_000001_normalize_coupon_codes_canonical.php` is dated 5 days
  after branch HEAD date. Verified INTENTIONAL + SAFE: it sorts last (backfill
  must run after all coupon tables), is idempotent, already applied in dev
  (`migrate --force` → DONE), and renaming now would double-run. DO NOT rename.
- Status: documented, no action.

### P3-2 Rounding/precision note
- Conversion uses BCMath SCALE 6 → round 2; `converted_total_price` column is
  decimal(10,3) but writers store 2-decimal values; metrics SUM uses float.
  Boundary `=X` comparisons could differ by a fraction of a minor unit across
  many orders. No live incident; consider decimal-safe aggregation later.
- Status: accepted risk, monitor.

### P3-3 Marvel `CouponController::show` code lookup bypass
- Public show uses `orWhere(code)` instead of canonical `queryByCode`.
  Low risk (listing omits codes; apply path canonical). Align when touching.
- Status: accepted, no action now.

## BUSINESS DECISION REQUIRED

1. Keep `currency_selection_enabled=false` (Sec 1 payment=catalog compliance)
   or approve selection-divergence.
2. Legacy Case B disposition beyond spend-exclusion (currently excluded +
   reported via remediation command).
3. Payment/product/refund/demographic targeting rules (explicitly NOT
   SUPPORTED — need semantics + metrics pipeline + approval).
4. Limiter visibility (contract forbids `99/100`; any change needs API approval).
5. Dashboard revenue (`COALESCE(converted,total)`) still sums unresolved rows
   while eligibility excludes them — align or accept.

## NOT PROVEN

- Parallel-concurrency at load (P2-1).
- Full-suite regression (P2-2).
- Production gateway retry + prod-data reconciliation (no prod access).
- Live mail delivery for assignment emails (array/log driver in tests; mail
  infra exists for auth mailers — coupon mail uses same stack).

## Resolved this cycle (were gaps, now fixed + tested)

- Sec 2 coupons_used distinct→COUNT (FIXED, 2 tests).
- Sec 6 assignment email missing (FIXED, 2 tests; conditional on email).
- Sec 3/6 My Coupons undiscoverable (FIXED, new endpoint + 2 tests).
- Self-caused regression during fix (`preferredLocale` on Marvel User →
  assignment 400): found via probe, fixed (app locale), Phase2 9/9 restored.

## Resolved in closure cycle (currency + lifecycle)

- P1-1 legacy spend: remediation command + marker migration + spend exclusion
  (9 closure tests); prod dry-run/apply outstanding (DATA REMEDIATION REQUIRED).
- P1-2 refund policy: APPROVED no-return, structural proof + tests (CLOSED).
- Review hardening: crash-healable metrics rebuild, in-txn race guards,
  null-date rejection, stored-rate fallback discipline, paid-txn preference,
  base-source logging, rate provenance logging.
