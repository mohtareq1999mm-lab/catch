# Bug Report - Fast Shipping Feature

## Issue 1 (CRITICAL): Cross-Channel Cache Pollution

- **Location:** `app/Services/General/HomeService.php`
- **Description:** HomeService caches product data without channel context in cache keys. First request populates cache regardless of channel. Subsequent requests from different channel get wrong data.
- **Impact:** HOME channel may get fast-filtered products; FAST_SHIPPING channel may get all products.
- **Severity:** Critical

## Issue 2 (HIGH): Checkout Product Lock Under FastShippingScope

- **Location:** `app/Services/General/CartInventoryService::lockInventoryRow()` line 349
- **Description:** Uses `Product::query()->whereKey($id)->lockForUpdate()`. Under fast-shipping channel, the global `FastShippingScope` adds `WHERE is_fast_shipping_available = 1`. If admin toggled the flag between cart-add and checkout, `lockForUpdate()` throws `ModelNotFoundException`.
- **Impact:** Users get 500 error on checkout if product eligibility changed.
- **Fix:** Use `Product::withoutGlobalScope(FastShippingScope::class)`.

## Issue 3 (MEDIUM): Promotion Gift Product Lookup

- **Location:** `PromotionApplicator::applyOutcome()` line 143
- **Description:** Same scope issue as BUG-2 for promotion gift products.
- **Fix:** Same as BUG-2.

## Issue 4 (MEDIUM): FREE_SHIPPING Coupon in Fast Checkout

- **Location:** `FastShippingService::calculateCheckoutTotals()`
- **Description:** FREE_SHIPPING coupons are ignored in fast checkout flow.
- **Status:** Partial fix in `OrderService`. Verify end-to-end.

## Issue 5 (LOW): Missing English Translations

- **Location:** `resources/lang/en/message.php`
- **Description:** 5 fast shipping message keys exist in Arabic but not English:
  - `MESSAGE.FAST_SHIPPING_NOT_AVAILABLE`
  - `MESSAGE.FAST_SHIPPING_OUTSIDE_HOURS`
  - `MESSAGE.FAST_SHIPPING_GOVERNORATE_DISABLED`
  - `MESSAGE.FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE`
  - `MESSAGE.FAST_SHIPPING_MIXED_CART`
- **Impact:** English users see raw key strings.

## Issue 6 (LOW): Redundant WHERE Clause

- **Location:** `FastShippingRepository::areProductsFastEligible()` line 63
- **Description:** Double `WHERE is_fast_shipping_available = 1` under fast-shipping channel (manual + global scope).
- **Impact:** Minor performance, no functional issue.
