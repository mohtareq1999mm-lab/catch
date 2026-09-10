# Product Module — Changelog

## v1.0.0 (Current)

### Endpoints
- 5 CRUD endpoints: list, create, show, update, delete
- Additional: bulk-delete, destroy-all, toggle-fast-shipping
- 4 product import endpoints (import, status, cancel, download-errors)
- 6 review endpoints (CRUD + toggle-approve)
- Public read-only for index+show
- Authenticated for store/update/destroy

### Architecture
- Controller → Repository → Model pattern
- Separate FormRequests for create/update (ProductCreateRequest, ProductUpdateRequest)
- ProductResource with 40+ response fields
- ProductPricingService for discount/flash sale/variant pricing
- ProductFilter for advanced query filtering
- Soft deletes (deleted_at)

### Bug Fixes
- **BUG-ATT-001/002/003/004** — Attribute module: private helpers, DB transaction, unique validation fix, `sometimes` rules (all fixed)
- **BUG-PRD-001/002/003** — Product helpers made private (fixed)
- **BUG-PRD-005** — Missing English translation keys added (fixed)
- **BUG-PRD-006** — FormRequest type-hints for `ProductStore()` and `updateProduct()` (fixed)
- **BUG-PRD-004** — Confirmed: `updateProduct()` in repository already has DB transaction (false alarm)

### Tags Support
- **ProductCreateRequest** — Added `tags` validation (nullable array, exists:tags,id)
- **ProductController.show** — `fetchSingleProduct()` now eager-loads `tags` relation
- **ProductMiniResource** — Added `tags` field using `TagResource::collection()`
- **ProductFilter** — Added `tags` filter param (supports slug or numeric ID, AND logic)
- **TagController** — Full CRUD with permission middleware (view/create/update/delete-tags)
- **TagResource** — Refactored: removed language/type/details, added media URLs for image/icon
- **Tag model** — Now uses `HasTranslations`, `InteractsWithMedia`; removed type/language
- **TagRepository** — Added image/icon upload support in updateTag
- **Routes** — Tags upgraded from public index+show to full `apiResource` with auth
- **Translations** — Added tag messages: `TAG_CREATED/UPDATED/DELETED_SUCCESSFULLY`, `ERROR.TAG_NOT_FOUND`
- **Tests** — `ProductTagTest.php` expanded: create with tags, validation, admin show includes tags

### Test Coverage
- **File:** `tests/Feature/ProductCrudTest.php` — 62 tests covering all product, import, and review routes
- Combined with existing `ProductAdminTest` (17) + `ProductProductionHardenTest` (28) + `AttributesProductionHardenTest` (32) = **139 total tests, all passing**

### Known Limitations
- No restore endpoint for soft-deleted products
- No product policy (authorization via middleware permissions only)
- No DTOs or Action classes
- `ProductStore()` method name uses PascalCase (inconsistent with camelCase convention)
