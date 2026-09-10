# Flash Sale Module — Jira Tasks

---

## Task 1: Extract Inline Validation Into FlashSaleReorderRequest

**Priority:** Medium
**Component:** Flash Sale Controller
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/FlashSaleController.php`
- `packages/marvel/src/Http/Requests/FlashSaleReorderRequest.php` (new)

**Description:** The `reorder()` method uses `$request->validate([...])` inline instead of a dedicated Form Request. Extract to `FlashSaleReorderRequest` for consistency with brand module's `BrandsReorderRequest`.

**Acceptance Criteria:**
- [ ] `FlashSaleReorderRequest` created with `flash_sales` => `required|array`, `flash_sales.*` => `required|integer|exists:flash_sales,id`
- [ ] `FlashSaleController::reorder()` type-hints `FlashSaleReorderRequest $request`
- [ ] Validation errors return 422 with proper field names
- [ ] Existing reorder tests pass

---

## Task 2: Change `updateFlashSale()` and `deleteFlashSale()` Visibility to Private

**Priority:** Low
**Component:** Flash Sale Controller
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Controllers/FlashSaleController.php`

**Description:** Both `updateFlashSale()` and `deleteFlashSale()` are `public` but only called internally. Change to `private`.

**Acceptance Criteria:**
- [ ] `updateFlashSale()` changed from `public` to `private`
- [ ] `deleteFlashSale()` changed from `public` to `private`
- [ ] `update()` can still call `updateFlashSale()`
- [ ] `destroy()` can still call `deleteFlashSale()`

---

## Task 3: Add Missing English Translation Keys

**Priority:** High
**Component:** Translations
**Effort:** Small
**Files:**
- `resources/lang/en/message.php`

**Description:** Add missing English translations for flash sale success messages:
- `MESSAGE.CREATE_FLASH_SALE_SUCCESSFULLY`
- `MESSAGE.UPDATE_FLASH_SALE_SUCCESSFULLY`
- `MESSAGE.DELETE_FLASH_SALE_SUCCESSFULLY`
- `MESSAGE.FLASH_SALE_REORDERED_SUCCESSFULLY`

**Acceptance Criteria:**
- [ ] All four keys added to `resources/lang/en/message.php`
- [ ] API responses show proper English messages

---

## Task 4: Wrap `reorder()` in Database Transaction

**Priority:** Low
**Component:** Flash Sale Repository
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Database/Repositories/FlashSaleRepository.php`

**Description:** The `reorder()` method calls `setNewOrder()` without `DB::transaction()`. Partial failure could leave flash sales in inconsistent order.

**Acceptance Criteria:**
- [ ] `DB::transaction()` wraps the `setNewOrder()` call
- [ ] Failed reorder rolls back all order changes
- [ ] Error is re-thrown as `HttpException(500)`

---

## Task 5: Fix `getFlashSaleInfoByProductID()` to Return Proper Resource

**Priority:** Medium
**Component:** Flash Sale Controller
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/FlashSaleController.php`

**Description:** The method returns raw `$product->flash_sales` without a resource wrapper and returns 200 with empty array for missing products instead of 404.

**Acceptance Criteria:**
- [ ] Returns `FlashSaleResource` collection (not raw data) when flash sales found
- [ ] Returns proper 404 with error message when product not found
- [ ] Returns 200 with empty data array when product has no flash sales

---

## Task 6: Add Defensive Comment for Route Ordering

**Priority:** Low
**Component:** Routes
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Rest/Routes.php`

**Description:** Add comment explaining that `PUT flash-sale/reorder` must be defined before `apiResource('flash-sale', ...)` to prevent route shadowing.

**Acceptance Criteria:**
- [ ] Comment explains the ordering requirement
- [ ] Test verifies reorder hits the correct method

---

## Task 7: Add Comprehensive Flash Sale Test Suite

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/FlashSales/FlashSaleApiTest.php`
- `tests/Feature/FlashSales/FlashSaleReorderTest.php`
- `tests/Feature/FlashSales/FlashSaleProductionHardenTest.php`

**Description:** Expand test coverage for edge cases:
- Reorder with invalid flash sale IDs
- Create/update with invalid image types
- Duplicate title validation
- Price recalculation verification
- Date range validation (end_date before start_date)

**Acceptance Criteria:**
- [ ] Reorder with non-existent ID returns 422
- [ ] Create with invalid image mime type returns 422
- [ ] Duplicate title returns 422
- [ ] Price recalculation on product after flash sale update
- [ ] Vendor request approve/disapprove flow
