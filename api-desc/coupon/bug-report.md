# Bug Report — Coupon Module

---

## BUG-CP-001: Coupon Already_Used Check Blocks Guest Checkout

**Severity:** Medium

**Component:** `CouponValidator::validate()`

**Description:** When a guest user applies a coupon without being logged in, the `already_used` check throws an error because `user` is null. The validator should skip the `already_used` check when user is not provided.

**Code Location:** `app/Services/Coupon/CouponValidator.php` — already_used logic

**Status:** ✅ Fixed — The validator checks `if ($user)` before querying `CouponUsage`.

---

## BUG-CP-002: CouponAssignmentValidator Null User

**Severity:** Medium

**Component:** `CouponAssignmentValidator::validate()`

**Description:** When called without a user (guest flow), the validator throws an error when checking assignments. Should skip assignment checks when user is null and treat as public coupon.

**Code Location:** `app/Services/Coupon/CouponAssignmentValidator.php`

**Status:** ✅ Fixed — Handles null user gracefully by skipping assignment validation.

---

## BUG-CP-003: Free Shipping Type Not in Enum Initially

**Severity:** Low

**Component:** `CouponType` enum + Migration

**Description:** The original `CouponType` enum only had `FIXED_COUPON` and `PERCENTAGE_COUPON`. Free shipping type was added via migration `2026_07_12_000002_add_free_shipping_to_coupons_discount_type.php`.

**Code Location:** `packages/marvel/src/Enums/CouponType.php`, `packages/marvel/src/Enums/DiscountType.php`

**Status:** ✅ Fixed — Enum and migration updated.

---

## BUG-CP-004: Cart `coupon` Field Not Cleared on Coupon Deletion

**Severity:** Low

**Component:** Cart model

**Description:** When a coupon is deleted, carts that have the coupon code stored in their `coupon` field still reference the deleted coupon. On checkout, the coupon validation fails.

**Code Location:** Cart model `coupon` field

**Status:** ❌ Open — Consider adding a foreign key or cleanup job, or handle gracefully in checkout.

---

## BUG-CP-005: Coupon Usage Counter Not Atomic Under High Concurrency

**Severity:** Medium

**Component:** `CouponUsage` creation

**Description:** Under high concurrency, two simultaneous checkouts could both pass the `limiter` check before either increments `used`. This could result in the coupon being used more times than the limiter allows.

**Code Location:** Coupon usage recording flow

**Status:** ⚠️ Mitigated — Uses `DB::transaction` and atomic `increment()`. For full safety, consider `lockForUpdate()` on the coupon row during usage recording.

---

## BUG-CP-006: Apply Coupon Returns Success Even When Cart Has No Items

**Severity:** Low

**Component:** `CouponController::addCouponToCart()`

**Description:** When the user applies a coupon with an empty cart, the endpoint returns success but no discount is applied. Should return an error message indicating the cart is empty.

**Code Location:** `packages/marvel/src/Http/Controllers/CouponController.php` — `addCouponToCart()`

**Status:** ❌ Open — Consider adding cart validation before applying coupon.
