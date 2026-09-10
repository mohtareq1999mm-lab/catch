# Cart Module — Backend Jira Tasks

---

## Task 1: Remove Duplicate `current_page` in Index Response

**Priority:** Low
**Component:** CartController
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Controllers/CartController.php`

**Description:** Line 44 and 45 both set `current_page`. Remove the duplicate.

**Acceptance Criteria:**
- [ ] `current_page` appears exactly once in response
- [ ] All other pagination fields unchanged

---

## Task 2: Use Paginated Resource Instead of Manual Extraction

**Priority:** Low
**Component:** CartController
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/CartController.php`

**Description:** Replace manual pagination metadata extraction with Laravel's `CartResource::collection($carts)` returning a standard paginated response structure.

**Acceptance Criteria:**
- [ ] Response uses standard paginated JSON structure
- [ ] Frontend backward compatible (add old fields as aliases or update frontend)

---

## Task 3: Differentiate Auth Error vs Item Not Found in `deleteItemFromCart`

**Priority:** Medium
**Component:** CartController
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/CartController.php`

**Description:** Return different messages/status codes for "cart belongs to another user" vs "item not found".

**Acceptance Criteria:**
- [ ] Unauthorized access returns 403 with specific message
- [ ] Item not found returns 404 with specific message
- [ ] Successful delete still returns 200

---

## Task 4: Write Feature Tests for All Cart Endpoints

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/Cart/` (new test files)

**Description:** Add comprehensive tests covering all 7 cart endpoints.

**Acceptance Criteria:**
- [ ] Test list carts
- [ ] Test add item (simple product, variable product, with variant)
- [ ] Test add item stock exceeded
- [ ] Test bulk add
- [ ] Test update item (set quantity, change shipping)
- [ ] Test delete single item
- [ ] Test clear cart with and without coupon
- [ ] Test unauthorized access (no token)
- [ ] Test cart of another user returns 403

---

## Task 5: Scope `revalidatePromotion()` to Affected Items Only

**Priority:** Low
**Component:** CartRepository
**Effort:** Small
**Files:**
- `packages/marvel/src/Database/Repositories/CartRepository.php`

**Description:** Instead of resetting all items with promotions, only reset the item being mutated.

**Acceptance Criteria:**
- [ ] Adding/updating item A does not clear promotion on item B
- [ ] Deleting item A clears promotion only on item A

---

## Task 6: Release Inventory on Coupon Warning Path in `destroy()`

**Priority:** Medium
**Component:** CartController / CartInventoryService
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/CartController.php`

**Description:** When `destroy()` returns a coupon warning, the items remain reserved. Consider either releasing inventory on warning or adding a softer reservation expiry.

**Acceptance Criteria:**
- [ ] Coupon warning path reduces reservation TTL (e.g., 1 hour) instead of full 3 days
- [ ] Or items are soft-released (marked but restorable)
