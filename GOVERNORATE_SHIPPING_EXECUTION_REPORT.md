# GOVERNORATE / SHIPPING — EXECUTION REPORT (Phases 10–11)

Status: IMPLEMENTED + VERIFIED
Date: 2026-09-23
Scope: 1 method fix + 1 new test file + 7 docs. No migrations, no contract changes.

## Changes

| # | File | Change | Why | Risk | Rollback |
|---|---|---|---|---|---|
| 1 | `app/Services/General/OrderService.php::resolveShippingPrice` | split the miss branch: nonexistent id ⇒ null triple (unchanged); existent-but-inactive ⇒ `{price:0, free:null, governorate_id:ID}` (id preserved) | snapshot truth = selected delivery area; existence ≠ shippability (G1) | LOW (no null-for-inactive consumers found; display-only downstream) | revert method |
| 2 | `tests/Feature/GovernorateShippingDecouplingTest.php` (NEW, 7 tests / 25 assertions) | Cases A–F: price+id / unshippable-keeps-id (+nonexistent+null safety) / address accepts disabled / price-change isolation / disable isolation + coupon stability / NULL fail-closed / snapshot immutability | regression lock for the decoupling contract | none (new file) | delete file |
| 3-7 | `GOVERNORATE_SHIPPING_{DISCOVERY,COUPLING_DISCOVERY,ARCHITECTURE,DATABASE_PLAN,IMPLEMENTATION_PLAN}.md` + this file + `FINAL_VALIDATION` | required deliverables | traceability | none | n/a |

Unchanged by design: schema, routes, controllers, requests, resources, models, shipping engine/prices, coupon engine, order flow, API contracts, seeders.

## Verification

- NEW suite: 7/7 pass (25 assertions).
- Regression: `EligibilityNewRulesTest` 23/23; shipping-focused filter set 4/4 (`governorate_shipping_resolved_correctly`, threshold, coupon-override, order-total); `CheckoutApiTest` 13/13.
- Static search (`governorate|shipping_price|area_in|governorate_id` over app+packages+tests): only runtime readers are `allActive()` (status-only list) and fixed `resolveShippingPrice`; fast-shipping flag reads isolated to its subsystem. No hidden coupling.
- Legacy classification: C1 nested admin payload KEEP; C2 resource embedding KEEP (display-only); C3 delete/cascade/null-out KEEP (FK-safe); C4 1:1 seeding KEEP; C5 fast flag on master KEEP. G2 silent-0 ACCEPTED (breaking-change guard).

## Remaining risks

- Production data counts (NULL addresses, shipping-less governorates) UNVERIFIED — no DB access; no migration depends on them (non-blocking).
- G2 accepted behavior (silent-0 scheduled checkout) needs a product decision if blocking is ever wanted (would be a breaking API change).
- `:coupon_name` interpolation edge (from prior audit) untouched by this task.
