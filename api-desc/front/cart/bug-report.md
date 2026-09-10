# Bug Report — Cart Module (Authenticated API)

---

## BUG-CART-001: `index()` Returns Duplicate `current_page` Field

**Severity:** Low

**Component:** `packages/marvel/src/Http/Controllers/CartController.php` (lines 44-45)

**Description:** The response payload includes `current_page` twice — once from `$cartData['meta']['current_page']` and once from the same value. This is a harmless duplication but indicates copy-paste error.

**Code Location:** `CartController.php` — lines 42-56

---

## BUG-CART-002: Manual Pagination Extraction Instead of Resource Collection

**Severity:** Low

**Component:** `CartController.php` (lines 39-56)

**Description:** The `index()` method manually extracts pagination metadata from `CartResource::collection(...)->response()->getData(true)` and reconstructs a flat structure with duplicated keys. Should use Laravel's built-in paginated resource response instead.

---

## BUG-CART-003: `deleteItemFromCart()` Returns Same Error for Auth Failure and Item Not Found

**Severity:** Medium

**Component:** `CartController.php` (lines 93-116)

**Description:** Both authorization failure (`user_id` mismatch) and item-not-found cases return the same generic message `DELETE_CART_ITEM_FAILED` with 400. This makes debugging difficult for frontend and masks the actual issue.

---

## BUG-CART-004: `pluckItemsToCart()` Clones and Mutates `$request`

**Severity:** Low

**Component:** `CartController.php` (lines 139-182)

**Description:** The method clones the request object inside a loop and replaces its data. This creates unnecessary overhead and could interfere with middleware that reads request data after the controller returns.

---

## BUG-CART-005: No Tests for Cart Endpoints

**Severity:** Medium

**Component:** `tests/`

**Description:** No feature tests exist for any of the 7 cart API endpoints. Cart operations involve critical inventory reservation and pricing logic that should be tested.

---

## BUG-CART-006: `revalidatePromotion()` Aggressively Clears All Promotion Data

**Severity:** Low

**Component:** `CartRepository.php` (lines 19-46)

**Description:** On every cart mutation (add/update/delete), `revalidatePromotion()` runs two queries to check and reset any items with `promotion_id` or `discount_amount > 0`. This means any cart change clears ALL promotion discounts, even unrelated ones. Should be scoped to affected items only.

---

## BUG-CART-007: No Inventory Release on `destroy()` Without Coupon Confirm

**Severity:** Medium

**Component:** `CartController.php` (lines 118-137)

**Description:** When the cart has a coupon and `confirm` is not `true`, `destroy()` returns a warning but does NOT release inventory. The items remain reserved with a 3-day TTL. If the user never confirms, reserved stock is stranded until TTL expiry.
