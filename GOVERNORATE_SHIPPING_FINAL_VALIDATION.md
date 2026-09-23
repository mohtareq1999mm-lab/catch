# GOVERNORATE / SHIPPING — FINAL VALIDATION (Phase 15)

Status: **IMPLEMENTATION VERIFIED** (all 10 criteria proven below; production-count caveat noted)
Date: 2026-09-23

## Proof table

| # | Statement | Proof |
|---|---|---|
| 1 | Governorate existence independent from shipping availability | `governorates` table has no shipping columns; `shipping_prices` is a separate UNIQUE-FK table; address validation `exists:governorates,id` with no shipping predicate; `GovernorateShippingDecouplingTest::test_address_accepts_shipping_disabled_governorate` passes |
| 2 | Address selection independent from shipping | Same validation + `allActive()` status-only list; disabled/inactive ids persist (`governorate_id` stored, test asserts `fresh()->governorate_id`) |
| 3 | Shipping config independently controls availability + price | `shipping_prices.status`/presence ⇒ price (matrix in DISCOVERY §4); `test_disabling_shipping_keeps_address_and_coupon_eligibility` (price 25→0, address/coupon stable); `test_shipping_price_change_does_not_affect_address_or_coupon` |
| 4 | `area_in` uses address governorate independently | `evalAreaIn` zero shipping reads (prior audit + `EligibilityNewRulesTest` 23/23 green); toggle-shipping tests assert verdict stability |
| 5 | Price change doesn't affect addresses | Case C test (95→99.99 invisible to address/coupon) |
| 6 | Disabling shipping doesn't invalidate selection | Case D test (row `status=false` ⇒ address row + coupon match intact) |
| 7 | Coupon eligibility independent of shipping price/availability | Cases C+D verdict stability + engine has no shipping input (grep-verified) |
| 8 | Historical orders unchanged | No migration; `OrderCreationService` snapshot-once + retry fallback (`$order->governorate_id`); Case F test (address change ⇒ `order.governorate_id` intact); full checkout regression 13/13 |
| 9 | Existing shipping behavior semantically unchanged | Price math untouched; active+priced path byte-identical (existing tests green); unshippable still price-0-proceed (G2 accepted); fast-shipping errors untouched |
| 10 | No hidden coupling remains | Repo-wide grep: only `allActive()` + fixed `resolveShippingPrice` read governorate/shipping at runtime; legacy items classified KEEP/ACCEPTED with evidence |

## Case matrix (runtime-verified by new suite)

- A enabled+priced ⇒ saved + shipped as before + coupon works ✅
- B exists + shipping disabled ⇒ saved, selectable, shipping 0, coupon matches ✅
- C price changed ⇒ address/coupon untouched, only price moves ✅
- D enabled→disabled ⇒ addresses valid, selection stored, shipping 0, coupon on governorate ✅
- E NULL address ⇒ `area_in` false (fail-closed) ✅
- F old order ⇒ snapshot bytes unchanged ✅

## Caveats (non-blocking)

- Production row counts unverified (no DB access); nothing in this change depends on them.
- G2 (no scheduled "unavailable" error) is an accepted product-level contract, flagged for future decision.

**IMPLEMENTATION VERIFIED.**
