# Banner Module — Backend Jira Tasks

---

## Task 1: Add Feature Tests for Public Banner Endpoints

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/PublicBannerApiTest.php` (new)

**Description:** Create feature tests for both public banner endpoints covering listing, slug lookup, filtering, with_products parameter, and error states.

**Acceptance Criteria:**
- [ ] `test_list_banners` — 200, returns banners, respects limit/order
- [ ] `test_list_banners_filter_by_ids` — Filters by `?bannersId=1,2`
- [ ] `test_list_banners_date_filters` — Filters by start_date/end_date
- [ ] `test_list_banners_with_slug_param` — `?slug=x` returns single banner
- [ ] `test_get_banner_by_slug` — Returns banner with products
- [ ] `test_get_banner_by_slug_without_products` — `?with_products=false` excludes products
- [ ] `test_get_banner_by_slug_not_found` — Returns 404
- [ ] `test_list_banners_empty` — No active banners returns `[]`
- [ ] `test_list_banners_response_structure` — Validates JSON keys

---

## Task 2: Fix `with_products` Boolean Coercion

**Priority:** Medium
**Component:** BannerService
**Effort:** Trivial
**Files:**
- `app/Services/General/BannerService.php`

**Description:** The `with_products !== 'false'` check only treats the literal string `'false'` as falsy. Use `filter_var($with_products, FILTER_VALIDATE_BOOLEAN)` to properly handle `0`, `'0'`, `'false'` (boolean), `'no'`, etc.

**Acceptance Criteria:**
- [ ] `with_products=0` disables product loading
- [ ] `with_products=false` disables product loading
- [ ] `with_products=1` enables product loading
- [ ] `with_products=true` enables product loading
- [ ] Missing param defaults to loading products (backward compatible)

---

## Task 3: Add Cache to Public Banner Endpoints

**Priority:** Low
**Component:** BannerService
**Effort:** Small
**Files:**
- `app/Services/General/BannerService.php`

**Description:** Banner data changes infrequently but is fetched on every page load. Add caching with 300-second TTL scoped by channel and query parameters.

**Acceptance Criteria:**
- [ ] `getBanners()` response cached for 300s
- [ ] Cache invalidation on banner create/update/delete
- [ ] Channel-scoped cache keys

---

## Task 4: Remove Implicit Slug Lookup From `index()` Method

**Priority:** Low
**Component:** BannerController
**Effort:** Small
**Files:**
- `app/Http/Controllers/Api/General/BannerController.php`

**Description:** Same pattern as brands — the `index()` method has undocumented slug query param behavior. Either document or remove it.
