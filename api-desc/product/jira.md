# Product Module — Backend JIRA Tasks

## B-001: Make internal helper methods private

**Priority:** Low
**Story Points:** 1
**Labels:** refactoring, consistency
**File:** `ProductController.php`

**Description:** `ProductStore()`, `updateProduct()`, and `destroyProduct()` are all `public` but are internal helpers only called from controller methods. Make them `private` to match the Brand/Category/Attribute pattern.

**Acceptance Criteria:**
- `ProductStore()` → `private`
- `updateProduct()` → `private`
- `destroyProduct()` → `private`

---

## B-002: Wrap `updateProduct()` repository method in DB transaction

**Priority:** High
**Story Points:** 2
**Labels:** bug, data-integrity
**File:** `ProductRepository.php`

**Description:** `updateProduct()` does not use a DB transaction, while `storeProduct()` does. Multiple writes happen (delete variants, create variants, update images, sync relations). A partial failure can corrupt data.

**Acceptance Criteria:**
- `DB::beginTransaction()` at start
- `DB::commit()` after all operations
- `DB::rollBack()` in catch block
- All operations inside try/catch

---

## B-003: Add missing English translation keys

**Priority:** Medium
**Story Points:** 1
**Labels:** bug, i18n
**File:** `resources/lang/en/message.php`

**Description:** Four product CRUD translation keys exist in Arabic but are missing in English. When the app falls back to English, users see the key string instead of a readable message.

**Acceptance Criteria:**
- `MESSAGE.CREATE_PRODUCT_SUCCESSFULLY` → "Product created successfully"
- `MESSAGE.UPDATE_PRODUCT_SUCCESSFULLY` → "Product updated successfully"
- `MESSAGE.DELETE_PRODUCT_SUCCESSFULLY` → "Product deleted successfully"
- `MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY` → "Products deleted successfully"

---

## B-004: Type-hint helper methods with specific FormRequests

**Priority:** Low
**Story Points:** 1
**Labels:** refactoring, type-safety
**File:** `ProductController.php`

**Description:** `ProductStore()` and `updateProduct()` type-hint generic `Request`. Change to `ProductCreateRequest` and `ProductUpdateRequest` for type safety and IDE support.

**Acceptance Criteria:**
- `ProductStore(ProductCreateRequest $request)`
- `updateProduct(ProductUpdateRequest $request)`

---

## B-005: Add /products test suite

**Status:** ✅ **Done** — `tests/Feature/ProductCrudTest.php` (62 tests, all passing)

Covers: Products CRUD + bulk-delete + destroy-all + import routes + reviews CRUD + toggle-approve

## B-006: Add review & import route tests

**Priority:** High
**Story Points:** 3
**Labels:** testing, coverage
**Files:** `tests/Feature/ProductCrudTest.php`

**Description:** The following routes are covered in the existing 62-test file:

- `POST /products/bulk-delete` (auth, success, validation)
- `DELETE /products/all` (auth, success)
- `POST /products/import` (guest auth)
- `GET /products/import/{id}` (auth, status, nonexistent)
- `POST /products/import/{id}/cancel` (auth, cancel, nonexistent)
- `GET /products/import/{id}/download-errors` (auth, nonexistent)
- `GET /reviews` (auth, list, validation)
- `POST /reviews` (guest auth)
- `GET /reviews/{id}` (auth, show, nonexistent)
- `PUT /reviews/{id}` (guest auth)
- `DELETE /reviews/{id}` (auth, delete, nonexistent)
- `PATCH /reviews/{id}/toggle-approve` (auth, success, nonexistent)

**Remaining gaps:**
- Review create validation (missing rating, comment)
- Review update via API
- Has_discount true but missing discount_type → 422
- Has_flash_sale true but missing flash_sale_id → 422

---

## B-007: Document tags in product CRUD

**Priority:** Low
**Story Points:** 1
**Labels:** documentation
**Files:** `api-desc/product/*.md`

**Description:** All product documentation files have been updated to reflect tags support:
- `api.md`: Added `tags` field to POST request body, PUT notes
- `frontend.md`: Added Tags section in Key Considerations
- `backend.md`: Updated ProductFilter, repository, permissions for tags
- `README.md`: Added TagController/TagRepository/TagResource to Key Files, tag permissions, tag routes, tags filter param
- `flow.md`: Added tags to sync relations in create/update flows
- `changelog.md`: Full tags support entry
- `test-cases.md`: Added tag-related test cases
- `qa.md`: Added tag coverage items
- `bug-report.md`: Updated all BUG-PRD statuses to ✅ Fixed
- `database.md`: Added tags table documentation
