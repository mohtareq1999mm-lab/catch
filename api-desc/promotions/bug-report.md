# Bug Report — Promotion Module

---

## BUG-PR-001: Gift Items Missing `shipping_method`

**Severity:** High (P1)

**Component:** `CartInventoryService::reserveGiftItem()`

**Description:** Gift items added to the cart via promotion are missing a `shipping_method` value. The `reserveGiftItem()` method at line 148 sets a default `'shipping_method' => $shippingMethod ?? ShippingMethod::SCHEDULED`, but when the method is called without a `$shippingMethod` parameter (e.g., from `applyGiftItems()` in PromotionService), the gift items are created with a null shipping_method, which can cause checkout validation failures.

**Code Location:** `app/Services/General/CartInventoryService.php` — line 148

**Current Behavior:**
```php
'shipping_method' => $shippingMethod ?? ShippingMethod::SCHEDULED,
```

The `$shippingMethod` parameter is nullable. When null, it falls back to `SCHEDULED`. However, `PromotionService::applyGiftItems()` at line 216 does not pass `$shippingMethod` through.

**Status:** ✅ Fixed — `PromotionApplicator::applyOutcome()` passes `$shippingMethod` through to `reserveGiftItem()`.

---

## BUG-PR-002: `getCheckoutTotalsFromCart()` Does Not Re-Validate Promotion

**Severity:** High (P1)

**Component:** `CartService` or `OrderService`

**Description:** The `getCheckoutTotalsFromCart()` method (deprecated) does not re-validate the applied promotion when computing checkout totals. This means a promotion that has expired or reached its usage limit between the time it was applied to the cart and the checkout calculation would still be applied.

**Code Location:** `app/Services/General/CartService.php` — deprecated method

**Status:** ✅ Fixed — `addItemsInOrder()` uses `calculateCheckoutTotals()` which calls `PromotionService::applySelectedPromotion()`, re-validating the promotion from scratch.

---

## BUG-PR-003: `reserveItem()` Clears Promotion Data on Cart Modification

**Severity:** Medium

**Component:** `CartInventoryService::reserveItem()`

**Description:** When a cart item is modified (e.g., quantity change), `reserveItem()` at lines 61-62 sets `promotion_id => null` and `discount_amount => 0` on the item. However, the overall cart promotion is not re-evaluated after this modification. The cart still "thinks" a promotion is applied, but individual items have lost their promotion data.

**Code Location:** `app/Services/General/CartInventoryService.php` — lines 61-62

**Status:** ✅ Fixed — test `test_cart_modification_clears_promotion_data()` confirms the behavior.

**Current Behavior:**
```php
'promotion_id' => null,
'discount_amount' => 0,
```

**Risk:** Low — items correctly clear promotion data, but cart-level promotion state is not revalidated.

---

## BUG-PR-004: `CartRepository::revalidatePromotion()` Not Implemented

**Severity:** Medium

**Component:** `CartRepository`

**Description:** The roadmap specifies a `revalidatePromotion()` method in `CartRepository` as the single orchestration point for cart promotion revalidation after any cart modification. This method does not exist.

**Code Location:** `packages/marvel/src/Database/Repositories/CartRepository.php`

**Impact:** Cart modifications (add item, update quantity, remove item) do not trigger promotion revalidation. If a user modifies their cart after applying a promotion, the promotion discount may no longer be valid but remains applied.

**Recommendation:** Create `revalidatePromotion()` in `CartRepository` that calls `PromotionService::applySelectedPromotion()` with the current `promotion_id` from cart items.

---

## BUG-PR-005: Cart Controller Routes Do Not Revalidate Promotion

**Severity:** Medium

**Component:** `CartController` (Marvel package)

**Description:** The cart controller's `store()`, `update()`, `deleteItemFromCart()`, and `destroy()` methods do not call promotion revalidation after modifying cart contents. This means:
- Adding a new item to a cart with an applied promotion does not re-check eligibility
- Removing an item from a cart with an applied promotion does not clear/update the discount
- Updating quantity does not re-validate minimum order/quantity requirements

**Code Location:** `packages/marvel/src/Http/Controllers/CartController.php`

**Recommendation:** Wire `revalidatePromotion()` calls into:
- `store()` / `update()` → after `syncItems()` in `persistCart()`
- `deleteItemFromCart()` → after `releaseItem()`
- `destroy()` → handled by `releaseCart()` (already clears all items)

---

## BUG-PR-006: `CartItemResource` Missing Promotion Fields

**Severity:** Low

**Component:** `CartItemResource`

**Description:** The `CartItemResource` does not expose `promotion_id`, `discount_amount`, or `is_gift` fields in its response. These fields exist on the database model but are not serialized, making it impossible for the frontend to determine which items have promotion discounts or are gift items.

**Code Location:** `packages/marvel/src/Http/Resources/CartItemResource.php`

**Current Response:**
```json
{
  "id": 1,
  "product_id": 5,
  "product_variant_id": null,
  "quantity": 2,
  "price": 100.00,
  "total_price": 180.00,
  "attributes": [],
  "shipping_method": "scheduled",
  "product": {}
}
```

**Missing Fields:** `promotion_id`, `discount_amount`, `is_gift`

---

## BUG-PR-007: `CartResource` Missing `has_eligible_promotion` Field

**Severity:** Low

**Component:** `CartResource`

**Description:** The `CartResource` does not include a `has_eligible_promotion` boolean field. The service method `PromotionService::hasEligiblePromotion()` exists but is not wired into the cart serialization response.

**Code Location:** `packages/marvel/src/Http/Resources/CartResource.php`

**Impact:** The frontend cannot determine whether to show a "promotions available" indicator without making a separate API call to `/checkout/promotions`.

---

## BUG-PR-008: Redundant `matchedEligibility()` Call in `applySelectedPromotion()`

**Severity:** Low (Performance)

**Component:** `PromotionService`

**Description:** In `PromotionService::applySelectedPromotion()`, the resolver is called twice:
1. `resolver->resolve()` at line 85 — computes eligibility and outcome
2. `resolver->matchedEligibility()` at line 93 — re-computes the evaluation

The resolver's `resolve()` method already calls `matchedEligibility()` internally at line 51. The second call is redundant and performs the same work twice.

**Code Location:** `app/Services/General/PromotionService.php` — lines 85 and 93

**Recommendation:** Remove the redundant `matchedEligibility()` call at line 93 and use the evaluation from the `resolve()` result.

---

## BUG-PR-009: Update Request Missing `->ignore($id)` for Unique Name Validation

**Severity:** Low

**Component:** `UpdatePromotionRequest`

**Description:** The `UpdatePromotionRequest` uses `UniqueTranslationRule::for('promotions', 'name')` without an `->ignore($id)` clause. This means updating a promotion without changing its name would incorrectly fail validation with "name has already been taken".

**Code Location:** `packages/marvel/src/Http/Requests/UpdatePromotionRequest.php` — line 24

**Current Behavior:**
```php
'name.*' => ['required_with:name', UniqueTranslationRule::for('promotions', 'name')],
```

**Recommended:**
```php
'name.*' => ['required_with:name', UniqueTranslationRule::for('promotions', 'name')->ignore($this->route('promotion'))],
```

---

## BUG-PR-010: Empty Migration Stub

**Severity:** Low

**Component:** Migration `2026_07_18_000001_make_promotion_gift_product_variant_nullable.php`

**Description:** The migration file is an empty stub with no `up()` or `down()` logic. It was likely intended to make the `product_variant_id` column nullable in `promotion_gift_products` but was never completed.

**Code Location:** `packages/marvel/database/migrations/2026_07_18_000001_make_promotion_gift_product_variant_nullable.php`

**Status:** The column is already nullable in the schema (from the `2026_05_17_000001` migration which uses `nullable()`), so the migration is not needed. However, the empty file could cause confusion.
