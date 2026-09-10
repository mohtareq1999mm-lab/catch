# Bug Report — Checkout Module (Public + Authenticated API)

---

## BUG-CHK-001: `eligiblePromotions` Returns 400 for Both "No Cart" and "Cart Empty"

**Severity:** Low

**Component:** `app/Services/General/OrderService.php` (lines 233-241)

**Description:** `eligiblePromotionsForUser()` returns null both when the cart doesn't exist and when the cart has no items. The controller maps both to `CART_NOT_FOUND` with 400.

---

## BUG-CHK-002: `checkoutCallback` and `checkoutErrorCallback` Share Large Duplicated Logic

**Severity:** Medium

**Component:** `OrderController.php` (lines 169-398)

**Description:** Both callback methods contain nearly identical logic (~230 lines duplicated) for verifying payment, updating transactions, releasing cart, and redirecting.

---

## BUG-CHK-003: Checkout Does Not Lock FAST Items Atomically

**Severity:** Low

**Component:** `OrderService.php` (lines 146-231)

**Description:** `addItemsInOrder()` locks the cart but only loads SCHEDULED items. FAST items are not in scope — a concurrent fast-shipping checkout could interfere.

---

## BUG-CHK-004: `getTransactionQr` Returns 404 for Missing UUID but 403 for Unauthorized

**Severity:** Low

**Component:** `OrderController.php` (lines 150-166)

**Status:** RESOLVED (2026-08-18) — QR code feature removed.

**Description:** Null transaction → 404. Valid transaction with deleted order → 403. But deleted order on valid transaction → 404.

**Resolution:** The `getTransactionQr()` method, the `GET /checkout/transaction-qr/{uuid}` route, the `CashierQrService`, and the `Transaction::byUuid` scope were removed. Pay at Cashier now returns only `order_id` and is settled via `/cashier/{orderId}/mark-paid`, so the inconsistent 404/403 mapping no longer exists.

---

## BUG-CHK-005: Order Status Transitions Hardcoded

**Severity:** Low

**Component:** `OrderService.php` (lines 446-452)

**Description:** Allowed transitions are in a private static array. New statuses require code changes. No revert possible from completed → processing.

---

## BUG-CHK-006: Callback Redirects Always Use `app_locale`

**Severity:** Low

**Component:** `OrderController.php` (lines 230, 277, 319, 393)

**Description:** Callback redirects use `app()->getLocale()` but the payment gateway callback may not preserve the user's session locale.

---

## BUG-CHK-007: `refreshCartItemPrices` Only Sees SCHEDULED Items

**Severity:** Low

**Component:** `OrderService.php` (lines 369-398)

**Description:** `refreshCartItemPrices()` iterates `$cart->items` but the cart only has SCHEDULED items loaded. FAST items' prices are stale.

---

## BUG-CHK-008: Global `minimumOrderAmount` Setting Not Enforced in New Checkout Flow

**Severity:** HIGH
**Status:** FIXED (2026-07-23)

**Component:** `app/Services/General/OrderService.php::addItemsInOrder()` (line ~197)

**Root Cause:** The global `minimumOrderAmount` setting was only validated in the old Marvel checkout flow (`CheckoutRepository::verify()`). The new `POST /api/v1/general/checkout` endpoint (`OrderService::addItemsInOrder()`) had zero checks against this setting — customers could checkout with any cart value regardless of the configured minimum.

**Fix:** Added a check after `calculateCheckoutTotals()` that reads `settings.options.minimumOrderAmount` and compares it against `$checkoutTotals->subtotal` (the pre-discount total). If `subtotal < minimumOrderAmount`, the transaction is rolled back and an `InvalidArgumentException` is thrown with a translated message.

**Why subtotal?** The minimum order amount should reflect the raw cart value before any discounts reduce it. A customer with 90 EGP of products and a 20 EGP coupon should still meet a 100 EGP minimum because the minimum is about order value, not final payment.
