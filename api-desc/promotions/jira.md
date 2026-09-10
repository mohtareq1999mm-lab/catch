# Promotion Module — Jira Tasks

---

## Task 1: Implement `CartRepository::revalidatePromotion()`

**Priority:** High
**Component:** Cart Repository
**Effort:** Medium
**Files:**
- `packages/marvel/src/Database/Repositories/CartRepository.php` (new method)
- `app/Services/General/PromotionService.php`

**Description:** Create a `revalidatePromotion()` method in `CartRepository` that serves as the single orchestration point for promotion revalidation after any cart modification. It should call `PromotionService::applySelectedPromotion()` with the current `promotion_id` from cart items.

**Acceptance Criteria:**
- [ ] Method exists on `CartRepository`
- [ ] Loads the cart with items
- [ ] Gets the `promotion_id` from cart items (if any)
- [ ] Calls `PromotionService::applySelectedPromotion()` with the current promotion
- [ ] Returns updated cart with recalculated totals
- [ ] Test verifies revalidation after cart modification

---

## Task 2: Wire Promotion Revalidation Into Cart Controller

**Priority:** High
**Component:** Cart Controller
**Effort:** Medium
**Files:**
- `packages/marvel/src/Http/Controllers/CartController.php`

**Description:** Add promotion revalidation calls in the cart controller's modification routes. When a user adds, updates, or removes a cart item, the applied promotion should be re-validated.

**Affected Methods:**
- `store()` / `update()` → after `syncItems()` in `persistCart()`
- `deleteItemFromCart()` → after `releaseItem()`
- `destroy()` → handled by `releaseCart()` (already clears all items)

**Acceptance Criteria:**
- [ ] Adding a new cart item re-validates the applied promotion
- [ ] Updating cart item quantity re-validates
- [ ] Removing a cart item re-validates
- [ ] If promotion is no longer eligible, it is cleared from cart
- [ ] Tests verify each modification path

---

## Task 3: Expose Promotion Fields in `CartItemResource`

**Priority:** Medium
**Component:** Cart Item Resource
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Resources/CartItemResource.php`

**Description:** Add `promotion_id`, `discount_amount`, and `is_gift` fields to the `CartItemResource` response. These fields already exist in the database but are not serialized.

**Acceptance Criteria:**
- [ ] `promotion_id` (int|null) is included
- [ ] `discount_amount` (float) is included
- [ ] `is_gift` (boolean) is included
- [ ] Existing cart tests still pass
- [ ] Frontend can read these fields

---

## Task 4: Add `has_eligible_promotion` to `CartResource`

**Priority:** Medium
**Component:** Cart Resource
**Effort:** Medium
**Files:**
- `packages/marvel/src/Http/Resources/CartResource.php`
- `packages/marvel/src/Http/Controllers/CartController.php`

**Description:** Enrich the cart response with a `has_eligible_promotion` boolean field. The controller should call `PromotionService::hasEligiblePromotion()` before serialization, and the resource should include this field.

**Acceptance Criteria:**
- [ ] Cart response includes `has_eligible_promotion` (boolean)
- [ ] Controller calls `hasEligiblePromotion()` and enriches the cart model
- [ ] Value is false for empty carts
- [ ] Value is true when eligible promotions exist
- [ ] Test verifies the field is present and accurate

---

## Task 5: Remove Redundant `matchedEligibility()` Call

**Priority:** Low
**Component:** Promotion Service
**Effort:** Small
**Files:**
- `app/Services/General/PromotionService.php`

**Description:** Remove the redundant `resolver->matchedEligibility()` call at line 93 of `PromotionService::applySelectedPromotion()`. The resolver's `resolve()` method already calls `matchedEligibility()` internally, so the second call is unnecessary.

**Acceptance Criteria:**
- [ ] Redundant call removed
- [ ] All existing promotion tests still pass
- [ ] No change in behavior or output

---

## Task 6: Fix `UpdatePromotionRequest` Unique Name Validation

**Priority:** Medium
**Component:** Update Promotion Request
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Requests/UpdatePromotionRequest.php`

**Description:** Add `->ignore($id)` to the unique name validation rule in `UpdatePromotionRequest` so that updating a promotion without changing its name does not incorrectly fail validation.

**Acceptance Criteria:**
- [ ] `name.*` uses `UniqueTranslationRule::for('promotions', 'name')->ignore($this->route('promotion'))`
- [ ] Updating promotion with same name passes validation
- [ ] Updating to another promotion's name fails validation
- [ ] Test verifies the fix

---

## Task 7: Add Comprehensive Promotion Test Suite

**Priority:** High
**Component:** Tests
**Effort:** Large
**Files:**
- `tests/Feature/PromotionCrudTest.php` (new — admin CRUD)
- `tests/Feature/PromotionCheckoutTest.php` (new — checkout integration)
- `tests/Unit/PromotionStrategyTest.php` (new — unit tests for strategies)

**Description:** Create comprehensive test coverage for the promotion module covering CRUD, validation, authorization, edge cases, and checkout integration.

**Acceptance Criteria:**
- [ ] Admin CRUD test file with create/read/update/delete tests
- [ ] Validation tests for both create and update requests
- [ ] Authorization tests (guest, different permissions)
- [ ] Checkout integration tests (apply promotion, clear promotion, revalidate)
- [ ] Edge case tests (expired, used up, out of stock gifts)
- [ ] Gift product flow tests (variant selection, inventory reservation)
- [ ] All tests pass
