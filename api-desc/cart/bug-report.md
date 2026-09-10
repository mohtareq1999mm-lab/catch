# Bug Report — Cart Module

> Verification basis: source code read on 2026-08-04 (Revision 4). Each bug lists its exact code location and verification status. Only production issues are listed (style/format/preferences excluded).

---

## BUG-CART-001 (BUG-INV-018): Dual Inventory Systems — No Coordination

**Severity:** Critical
**Component:** `app/Services/General/CartInventoryService.php` + `packages/marvel/src/Database/Repositories/OrderRepository.php`

**Description:** Two independent inventory paths operate on the same columns (`stock_quantity`, `reserved_quantity`, `sold_quantity`) with no coordination:
1. **CartInventoryService** — reserve → release → `finalizeStock` (reserved-then-sold)
2. **Legacy path** — `CartInventoryService::deductStockForOrder()` (lines 343-398) directly decrements `stock_quantity`, updates `reserved_quantity`, `sold_quantity`

If an item is reserved via the cart and later deducted via the order path without going through `finalizeCart()`, inventory state can become inconsistent.

**Code Location:**
- `CartInventoryService.php:343-398` — `deductStockForOrder()` still present
- `packages/marvel/src/Database/Repositories/OrderRepository.php` — `deductStock()`

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04)

**Impact:** Critical — can cause overselling or incorrect inventory counts.

**Recommendation:** Standardize on a single inventory flow. Deprecate `deductStockForOrder()` and route all order finalization through `CartInventoryService::finalizeCart()`.

---

## BUG-CART-002 (BUG-INV-004): `finalizeItemsByShippingMethod()` Deletes Non-Finalized Items

**Severity:** High
**Component:** `app/Services/General/CartInventoryService.php`

**Description:** `finalizeItemsByShippingMethod($cart, $method)` finalizes one shipping group, then for the **remaining items** (the other shipping group) it **releases the reserved stock AND deletes the items** — so the other group is lost instead of preserved for later checkout.

**Code Location:** `CartInventoryService.php:300-341`

**Current Behavior (bug):**
```php
foreach ($remainingItems as $item) {
    if ($item->reserved_quantity > 0) {
        $this->releaseStock($stock, (int) $item->reserved_quantity);
    }
    $item->delete();   // BUG: non-finalized group items are deleted
}
```

**Expected Behavior:**
```php
foreach ($remainingItems as $item) {
    // release reserved stock but KEEP the item in the cart
    $item->update(['reserved_quantity' => 0]);
}
```

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04). Note: `finalize_scheduled_items_only_keeps_fast_items` / `finalize_fast_items_only_keeps_scheduled_items` tests currently **assert this buggy delete behavior** — they must be updated when fixed.

**Impact:** High — users lose items from the non-finalized shipping group during partial checkout.

---

## BUG-CART-003 (BUG-INV-001): Price Snapshotted at Reservation, Not Checkout

**Severity:** High
**Component:** `app/Services/General/CartInventoryService.php`, `packages/marvel/src/Services/Pricing/ProductPricingService.php`

**Description:** Prices are snapshotted in `reserveItem()` (line 109-111) via `ProductPricingService::calculateProductCurrentPrice()` / `calculateVariantCurrentPrice()`. If a flash sale ends or price changes between add-to-cart and checkout, the user pays the stale snapshot. No re-validation at checkout.

**Code Location:** `CartInventoryService.php:109-111`

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04). Note: per the **frozen** `docs/architecture/runtime-pricing-architecture.md`, `ProductPricingService` is the single pricing authority — any fix must keep cart pricing consistent with that ADR.

**Impact:** High — users may pay stale prices (higher or lower). Business decision required.

---

## BUG-CART-004 (CONC-7): `total_price` Without Rounding

**Severity:** Low (resolved)
**Component:** `app/Services/General/CartInventoryService.php`

**Description:** Line-level `total_price` was previously un-rounded. Now `round($price * $desiredQuantity, 2)` is applied in `reserveItem()` (line 123), and `revalidatePromotion()` uses `DB::raw('ROUND(price * quantity, 2)')`.

**Verification Status:** ✅ **FIXED** — rounding applied in all locations (source read, 2026-08-04).

---

## BUG-CART-005: Hardcoded `CART_TTL_DAYS = 3`

**Severity:** Low
**Component:** `app/Services/General/CartInventoryService.php`

**Description:** `private const CART_TTL_DAYS = 3` (line 20) hardcoded. Used by `touchCartReservation()` (line 595). Not configurable without a code change.

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04)

**Impact:** Low — 3 days is reasonable; but per-business requirements (e.g., 24h flash sales) require code changes.

**Recommended Fix:** Move to `config/cart.php` with env override.

---

## BUG-CART-006: Expire Chunk Query Has No Global Lock

**Severity:** Medium
**Component:** `app/Services/General/CartInventoryService.php`

**Description:** `expireCarts()` (lines 400-416) uses `chunkById(100, ...)` on the expiry query with **no lock** on the chunk query. `expireCart()` (line 480) double-checks `expires_at->isFuture()`, which mitigates but does not fully eliminate the race.

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04)

**Impact:** Medium — a cart refreshed between chunk fetch and expire could be incorrectly expired under heavy load. Mitigated by the in-transaction double-check.

---

## BUG-CART-007: `expireCart()` Doesn't Check `status !== 'active'`

**Severity:** Medium
**Component:** `app/Services/General/CartInventoryService.php`

**Description:** `expireCart()` (lines 475-499) checks only `expires_at` future/expired — it does **not** verify the cart `status` is `active`. If called for a `checked_out`/`expired` cart, it would release reserved stock again (double-release). Reachable via `expireSingleCart()` / `ExpireCarts` if a stale cart is passed. The main `expireCarts()` query filters `status='active'`, so impact is limited to direct single-cart calls.

**Code Location:** `CartInventoryService.php:475-499`

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04)

**Recommended Fix:**
```php
if ($cart->status !== 'active') {
    return;
}
```

---

## BUG-CART-008: Two Duplicate Cart Expire Commands

**Severity:** Low
**Component:** `app/Console/Commands/ExpireCarts.php`, `app/Console/Commands/ExpireAbandonedCarts.php`

**Description:** Two commands exist: `carts:expire` (`ExpireCarts.php`) and `cart:expire` (`ExpireAbandonedCarts.php`). Only `carts:expire` is registered in `Kernel.php` (line 17, scheduled every 5 min with `withoutOverlapping`). `ExpireAbandonedCarts` is an unscheduled orphan class.

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04)

**Recommendation:** Remove the orphan `ExpireAbandonedCarts` command; keep `carts:expire`.

---

## BUG-CART-009: No Max Quantity Validation

**Severity:** Low
**Component:** `packages/marvel/src/Http/Requests/CartCreateRequest.php`, `CartUpdateRequest.php`

**Description:** `quantity` validates only `min:1`; there is no upper bound (`max`). `desiredQuantity` in `reserveItem()`/`incrementItem()` could overflow or cause excessive reservation work.

**Code Location:** `CartCreateRequest.php:23`, `CartUpdateRequest.php:24`

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04)

**Recommended Fix:** Add `max:9999` (or a product-specific cap) to the quantity rules in both requests and in `reserveItem()`.

---

## BUG-CART-010 (INFO): `destroy()` Coupon Warning Returns HTTP 200 + `success: true`

**Severity:** Info (behavioral contract, not a defect)
**Component:** `packages/marvel/src/Http/Controllers/CartController.php`

**Description:** When a coupon is applied and `confirm` is not sent, `destroy()` (lines 131-133) returns `apiResponse(COUPON_DELETE_CART_WARNING, 200, true)` — **HTTP 200 with `success: true`** and a warning message. Clients that branch on HTTP status alone will treat the warning as success.

**Code Location:** `CartController.php:131-133`

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04). Documented in `api.md`/`frontend.md`/`jira.md`; frontend must detect by message/coupon presence.

**Recommendation (optional):** Return 409 or 422 for the warning so clients can branch on status. This is a contract change — coordinate with the frontend team.

---

## BUG-CART-011 (INFO): CartResource Executes Business Logic at Serialization

**Severity:** Info (design observation)
**Component:** `packages/marvel/src/Http/Resources/CartResource.php`

**Description:** `CartResource::toArray()` resolves the coupon (`Coupon::where('code', ...)`), computes `coupon_discount` via `CouponCalculator`, and checks promotion eligibility via `PromotionService` at serialization time. This adds per-serialization queries and couples the resource to services (resource-purity deviation).

**Code Location:** `CartResource.php:26-58`

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04). Bounded impact: `has_eligible_promotion` + coupon resolution run once per cart serialization; acceptable at current scale. Not a pricing-ADR violation (the frozen ADR governs `ProductPricingService`, not resource enrichment).

---

## BUG-CART-012 (INFO): Product Thumbnail Media N+1

**Severity:** Info (performance observation)
**Component:** `packages/marvel/src/Http/Resources/CartItemResource.php`

**Description:** `CartItemResource::toArray()` calls `$this->product->getFirstMediaUrl('products')` (line 29). Media is not in the cart eager-load set (`items.product`, not `items.product.media`), so this issues one media query per product line during serialization.

**Code Location:** `CartItemResource.php:29`; eager-load sets in `CartController.php:32,72`

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04). Low severity — bounded by line count. Fix: eager-load `items.product.media` (Spatie MediaLibrary collection).

---

## BUG-CART-013 (INFO): Repository 401 Converted to 400 by Controller

**Severity:** Info (behavioral nuance)
**Component:** `packages/marvel/src/Database/Repositories/CartRepository.php`, `CartController.php`

**Description:** `persistCart()` throws `HttpException(401, ...)` when `$request->user()?->id` is null. The controller's `catch (\Exception $e)` in `store()`/`update()` returns `apiResponse($e->getMessage(), 400, false)`, converting the 401 into a 400. Practically unreachable via routes because `auth:sanctum` middleware authenticates first.

**Code Location:** `CartRepository.php:83-86,115-121`; `CartController.php:65-67,88-90`

**Verification Status:** ✅ **VERIFIED** (source read, 2026-08-04). No production impact via the API.

---

## Summary

| ID | Severity | Status | Fixed? |
|----|----------|--------|--------|
| BUG-CART-001 | Critical | VERIFIED | No |
| BUG-CART-002 | High | VERIFIED | No |
| BUG-CART-003 | High | VERIFIED | No |
| BUG-CART-004 | Low | VERIFIED | **Yes** |
| BUG-CART-005 | Low | VERIFIED | No |
| BUG-CART-006 | Medium | VERIFIED | No |
| BUG-CART-007 | Medium | VERIFIED | No |
| BUG-CART-008 | Low | VERIFIED | No |
| BUG-CART-009 | Low | VERIFIED | No |
| BUG-CART-010 | Info | VERIFIED | n/a (contract) |
| BUG-CART-011 | Info | VERIFIED | n/a (observation) |
| BUG-CART-012 | Info | VERIFIED | n/a (observation) |
| BUG-CART-013 | Info | VERIFIED | n/a (observation) |

**Open production blockers:** BUG-CART-001, BUG-CART-002, BUG-CART-003, BUG-CART-006, BUG-CART-007.
