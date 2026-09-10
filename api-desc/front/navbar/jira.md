# Navigation Bar — Jira Tasks

---

## Task 1: Add Feature Tests for Nav-Data Endpoint

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/NavDataTest.php` (new)

**Description:** The `GET /api/v1/general/nav-data` endpoint has zero test coverage. Feature tests must be created to cover success cases, caching behavior, channel filtering, level parameter, empty states, and response structure validation.

**Acceptance Criteria:**
- [ ] `NavDataTest.php` created in `tests/Feature/`
- [ ] Test `test_fetch_nav_data_success` — 200 response with valid structure
- [ ] Test `test_nav_data_cached` — Second request returns cached data
- [ ] Test `test_nav_data_level_parameter` — `level=1` returns only top-level categories
- [ ] Test `test_nav_data_channel_scoped` — Different channels use different cache
- [ ] Test `test_nav_data_empty` — No active categories returns `[]`
- [ ] Test `test_nav_data_response_structure` — Validates JSON keys and types
- [ ] Test `test_nav_data_translation` — Category names returned in correct locale

---

## Task 2: Wire Nav-Data Cache Invalidation to Category Observer

**Priority:** Medium
**Component:** Category Observer / HomeService
**Effort:** Small
**Files:**
- `app/Observers/CategoryObserver.php`
- `app/Services/General/HomeService.php`

**Description:** When categories are created, updated, deleted, or have their status/featured toggled, the navbar cache should be invalidated. Currently `HomeService::clearCache()` exists but is not called from any observer. The `CategoryObserver` should call `HomeService::clearCache()` on relevant events.

**Acceptance Criteria:**
- [ ] `CategoryObserver::saved()` calls `HomeService::clearCache()`
- [ ] `CategoryObserver::deleted()` calls `HomeService::clearCache()`
- [ ] Nav-data cache is cleared within 1 second of category CRUD operations
- [ ] Existing tests still pass

---

## Task 3: Remove Unused `level` Parameter or Implement Dynamic Query Depth

**Priority:** Low
**Component:** HomeService
**Effort:** Small
**Files:**
- `app/Services/General/HomeService.php`
- `app/Http/Resources/Category/CategoryNavbarResource.php`

**Description:** The `level` query parameter is accepted by the controller but has no effect on the database query depth. The service always fetches 3 levels of children. The parameter only controls rendering depth in the resource. Either:
- (Option A) Accept the parameter and pass it to `getCategoryWithChildren()` to limit the query
- (Option B) Remove the parameter from the controller and resource to simplify

**Acceptance Criteria:**
- [ ] Either option A or B is implemented
- [ ] Documentation updated to match actual behavior
- [ ] Tests updated if behavior changed

---

## Task 4: Verify Response Translation Locale

**Priority:** Low
**Component:** CategoryNavbarResource
**Effort:** Trivial
**Files:**
- `app/Http/Resources/Category/CategoryNavbarResource.php`

**Description:** The resource uses `app()->getLocale()` for name translation. This should be verified to work correctly with the `Accept-Language` header mechanism. If the resource is serialized during cache storage, the locale at cache-write time will be frozen, potentially serving wrong-language names to subsequent requests from different locales.

**Acceptance Criteria:**
- [ ] Investigation complete with documented finding
- [ ] If bug found, fix implemented (e.g., cache raw data, translate at response time)
