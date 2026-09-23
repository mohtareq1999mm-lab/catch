# GOVERNORATE / SHIPPING — COUPLING MAP (Phase 1)

Status: COMPLETE (read-only)
Date: 2026-09-23

Headline: the architecture is **already ~90% decoupled**. The schema separates master (`governorates`) from config (`shipping_prices`); address validation, customer listing, coupon `area_in`, and checkout validation never consult shipping. **Two gaps** found (one to fix, one accepted), detailed below.

## Dependency graph (actual)

```text
governorates (status, is_fast_shipping_enabled)
 ├──→ customer_addresses.governorate_id   [nullable FK nullOnDelete; validation exists-only]  ✅ DECOUPLED
 ├──→ shipping_prices.governorate_id      [UNIQUE, FK cascade; status/price per row]          ✅ SEPARATE TABLE
 ├──→ cities.governorate_id               [FK cascade]                                        (geography, n/a)
 ├──→ orders.governorate_id               [nullable FK nullOnDelete; snapshot]                ⚠️ G1 (see below)
 ├──→ GET general/governorates            [status=true only]                                  ✅ DECOUPLED
 ├──→ EligibilityEngine::evalAreaIn       [address ∩ status=true; zero shipping reads]        ✅ DECOUPLED
 └──→ OrderService::resolveShippingPrice  [status-gated lookup; price-only output]            ⚠️ G1
```

## G1 — FIX: inactive governorate erases the order snapshot area (unwanted coupling)

- File/Class/Method: `app/Services/General/OrderService.php` → `resolveShippingPrice()`, lines 425-428.
- Table/Column: `orders.governorate_id` (snapshot) ← return value `governorate_id`.
- Current: existent-but-`status=false` governorate ⇒ `{price:0, free:null, governorate_id:null}` — the customer's selected delivery area is silently dropped from the order.
- Why coupling: master `status` (geography lifecycle) destroys snapshot truth; existence and shippability are conflated in one branch. (Nonexistent id ⇒ null is CORRECT and must stay — FK integrity.)
- Target: existent ⇒ keep id (price 0 when unshippable); nonexistent ⇒ null triple (unchanged).
- Risk: LOW. Readers of `orders.governorate_id`: `OrderCreationService` pass-through/fallback only (51/130/164); no pricing/eligibility branch on null found; display-only downstream. No test pins the nulling (existing tests cover active-only). Rollback: revert 4-line branch.

## G2 — ACCEPTED (no change): scheduled checkout has no "shipping unavailable" error

- Current: unshippable governorate (no row / row inactive / governorate inactive) ⇒ price 0, checkout PROCEEDS. Fast-shipping checkout DOES report explicit unavailability (`validateCheckout` errors).
- Why accepted: introducing a blocking error = breaking API change to live checkout + contradicts "preserve existing shipping behavior/prices" and "existing unavailable behavior" (which for scheduled checkout IS price-0-proceed). Changing it requires a product decision + frontend handling; explicitly out of this task's minimal scope.
- Documented, not fixed. Revisit trigger: product decision that unshippable areas must block scheduled checkout, with frontend error UX.

## Accepted co-locations (verified harmless, KEEP)

- C1: Governorate admin create/update accepts nested `shipping_price` (repository writes TWO rows transactionally). Admin convenience; rows stay separate; customer/coupon paths unaffected. KEEP.
- C2: `GovernorateResource` embeds `shippingPrice` when loaded; customer `index` (`allActive`) eager-loads it (display info, not a gate). KEEP. (Frontend must not treat presence as eligibility — contract note.)
- C3: Governorate `delete` blocked only when cities exist; shipping row cascades (correct — config cannot outlive master); addresses/orders null-out (by design, documented on the migration). Verified safe; KEEP, no guard change (blocking more deletes would alter admin contract for zero integrity gain — FKs already enforce safety).
- C4: Seeder creates 1:1 shipping rows (data convenience, not structural). KEEP.
- C5: `is_fast_shipping_enabled` lives ON governorates (fast-shipping availability flag on master row). Touched only by fast-shipping subsystem + admin toggle; never read by address validation or coupons. KEEP (moving it would be redesign, explicitly forbidden).

## What is NOT coupling (verified, do not touch)

- Address `exists:governorates,id` without status check: intentional (validation accepts any existing row; coupon engine applies the active-gate at evaluation). Changing validation to require status=true would couple address validity to master lifecycle — REJECTED.
- `area_in` active-governorate intersect: master-data gate, not shipping. KEEP (fail-closed).
- `orders.governorate_id` nullable + nullOnDelete: snapshot safety on master delete. KEEP.
