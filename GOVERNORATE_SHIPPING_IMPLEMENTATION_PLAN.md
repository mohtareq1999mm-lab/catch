# GOVERNORATE / SHIPPING — IMPLEMENTATION PLAN (Phase 10)

Status: READY (all prior phases documented; delta = 1 method + 1 test file)
Date: 2026-09-23

## Step 1 — Code: `resolveShippingPrice` snapshot fix (G1)

- File: `app/Services/General/OrderService.php`, method `resolveShippingPrice` (~lines 419-443).
- Current: single query `where(id).where(status,true)`; miss ⇒ null triple (conflates nonexistent + inactive).
- New: (a) lookup by id alone → miss ⇒ null triple (UNCHANGED, FK safety); (b) hit but `status=false` ⇒ `{price:0, free:null, governorate_id:ID}` (id preserved, no shipping); (c) hit+active ⇒ existing shipping-row branch UNCHANGED.
- Why: snapshot truth = selected delivery area; existence ≠ shippability.
- Risk: LOW (verified no null-for-inactive consumers; display-only downstream; no test pins old behavior).
- Rollback: revert method (git), no migration involved.
- Touches NOTHING else: no signature change (private method, same return shape), `calcInvoicePrice`/checkout/fast paths inherit fix via shared method.

## Step 2 — Address contract (Phase 4): NO CHANGE (verify only)

- Validation already `nullable|exists:governorates,id` (both requests). Selection already `allActive()` (status-only). Keep. Verify via new tests (address save with shipping-disabled + inactive governorates succeeds at validation level).

## Step 3 — Shipping decoupling (Phase 5): NO ENGINE CHANGE

- Engine untouched; only its input classification fixed (Step 1). Availability/price/threshold semantics byte-identical.

## Step 4 — Coupon area_in (Phase 6): NO CHANGE

- Verified zero shipping reads; fail-closed on NULL preserved. New test pins independence (shipping row toggled ⇒ verdict unchanged).

## Step 5 — Data (Phase 7): NO MIGRATION/BACKFILL

- Report seeder/test evidence; production counts UNVERIFIED (no DB access) — flagged, not blocking (no migration depends on counts).

## Step 6 — API/frontend contract (Phase 8): DOC ONLY

- Address dropdown = `GET general/governorates` (active master list) — already correct; frontend must NOT filter by embedded `shipping_price`. Checkout with unshippable area = price 0 proceed (accepted G2); fast-shipping reports explicit errors (unchanged).

## Step 7 — Order safety (Phase 9): VERIFIED, no change

- Snapshots immutable (`createOrder` writes once; update falls back to `$order->governorate_id`; retries preserve). Historical rows untouched (no migration).

## Step 8 — Tests (Phase 11 cases A–F)

- NEW `tests/Feature/GovernorateShippingDecouplingTest.php`: A (enabled+priced ⇒ price+id), B (disabled/no-row ⇒ price 0 + id KEPT — the fix), C (price change ⇒ snapshot price changes, address/coupon untouched), D (enable→disable ⇒ address valid, coupon still matches, shipping 0), E (NULL address ⇒ area_in false), F (order snapshot preserved on address change; historical orders untouched — assert update path keeps `$order->governorate_id`).

## Step 9 — Legacy/full search (Phases 13-14) + final validation (Phase 15)

- Grep `governorate|shipping_price|area_in|governorate_id` over app+packages+tests; classify KEEP/ACCEPTED (expected: all keep except G1-fixed).
- Write EXECUTION_REPORT + FINAL_VALIDATION with the 10 proofs.
