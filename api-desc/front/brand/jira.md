# Brand Module — Backend Jira Tasks

---

## Task 1: Add Feature Tests for Public Brand Endpoints

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/PublicBrandApiTest.php` (new)

**Description:** The three public brand endpoints have zero test coverage. Create feature tests covering listing, slug lookup, brands-products, filtering, and error states.

**Acceptance Criteria:**
- [ ] `test_list_brands` — 200, returns brands, respects limit/order
- [ ] `test_list_brands_filter_by_brandsId` — Filters by comma-separated IDs
- [ ] `test_list_brands_date_filters` — Filters by start_date/end_date
- [ ] `test_list_brands_with_slug_param` — `?slug=nike` returns single brand
- [ ] `test_get_brand_by_slug` — Returns brand + products
- [ ] `test_get_brand_by_slug_not_found` — Returns 404
- [ ] `test_brands_products_by_qty` — Returns products with correct limits
- [ ] `test_list_brands_empty` — No active brands returns `[]`
- [ ] `test_list_brands_response_structure` — Validates JSON keys

---

## Task 2: Add Cache to Public Brand Endpoints

**Priority:** Medium
**Component:** BrandService
**Effort:** Small
**Files:**
- `app/Services/General/BrandService.php`

**Description:** The brand listing endpoint is not cached. For storefront performance, add caching with a TTL of 120 seconds, scoped by query parameters and channel.

**Acceptance Criteria:**
- [ ] `getBrands()` response cached for 120s
- [ ] Cache key includes query params (limit, order, brandsId, dates)
- [ ] Cache key includes channel prefix
- [ ] Cache invalidation on brand create/update/delete (via BrandObserver)

---

## Task 3: Rename `brands-products` Route to `brands/products`

**Priority:** Low
**Component:** Routes
**Effort:** Trivial
**Files:**
- `routes/api.php`

**Description:** The URI `brands-products` is inconsistent with REST conventions. Rename to `brands/products` for consistency with other resource sub-routes.

**Acceptance Criteria:**
- [ ] Route changed from `brands-products` to `brands/products`
- [ ] Controller and service method unchanged
- [ ] Frontend team notified of URL change
- [ ] Tests updated to use new URL

---

## Task 4: Remove Implicit Slug Lookup From `index()` Method

**Priority:** Low
**Component:** BrandController
**Effort:** Small
**Files:**
- `app/Http/Controllers/Api/General/BrandController.php`

**Description:** The `index()` method has undocumented behavior where passing `?slug=nike` returns a single brand instead of a list. Either document it or remove it in favor of explicit route usage.

**Acceptance Criteria:**
- [ ] Option A: Remove slug check, require explicit `GET /brands/{slug}`
- [ ] Option B: Add PHPDoc documenting the slug query param behavior
- [ ] Update tests accordingly
