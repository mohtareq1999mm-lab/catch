# Flash Sale Module — Backend Jira Tasks

---

## Task 1: Add `valid()` Scope to `getFlashSaleBySlug()`

**Priority:** High
**Component:** FlashSaleService
**Effort:** Trivial
**Files:**
- `app/Services/General/FlashSaleService.php`

**Description:** The `getFlashSaleBySlug()` method does not apply the `valid()` scope, allowing expired/inactive flash sales to be accessed by direct slug.

**Acceptance Criteria:**
- [ ] `FlashSale::valid()->search(...)->first()` used instead of `FlashSale::search(...)->first()`
- [ ] Expired flash sales return 404
- [ ] Inactive flash sales return 404

---

## Task 2: Fix `discription` Typo in FlashSaleResource

**Priority:** Medium
**Component:** FlashSaleResource
**Effort:** Trivial
**Files:**
- `app/Http/Resources/FlashSale/FlashSaleResource.php`

**Description:** The response key `discription` is misspelled. Change to `description`.

**Acceptance Criteria:**
- [ ] Response key changed from `discription` to `description`
- [ ] Frontend team notified of breaking change
- [ ] Tests updated

---

## Task 3: Add Feature Tests for Public Flash Sale Endpoints

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/PublicFlashSaleApiTest.php` (new)

**Description:** Create tests for all 5 public flash sale endpoints.

**Acceptance Criteria:**
- [ ] `test_list_flash_sales` — 200, paginated, only valid
- [ ] `test_get_flash_sale_by_slug` — 200 with products
- [ ] `test_get_flash_sale_by_slug_expired` — 404
- [ ] `test_get_flash_sale_by_slug_not_found` — 404
- [ ] `test_flash_sale_products_by_qty` — 200 with products
- [ ] `test_flash_sale_products_ending_this_week` — 200
- [ ] `test_flash_sale_products_ending_today` — 200
- [ ] `test_list_flash_sales_empty` — No valid flash sales returns `[]`
- [ ] `test_list_flash_sales_response_structure` — Validates JSON keys

---

## Task 4: Remove Implicit Slug Lookup From `index()` Method

**Priority:** Low
**Component:** FlashSaleController
**Effort:** Small
**Files:**
- `app/Http/Controllers/Api/General/FlashSaleController.php`

**Description:** Same pattern as brands/banners — undocumented `?slug=x` behavior in `index()`.

---

## Task 5: Add Cache to Public Flash Sale Endpoints

**Priority:** Low
**Component:** FlashSaleService
**Effort:** Small
**Files:**
- `app/Services/General/FlashSaleService.php`

**Description:** Flash sale listing is paginated but not cached. Add caching with short TTL (60s) since flash sales are time-sensitive.
