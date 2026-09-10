# Bug Report — Navigation Bar Module

---

## BUG-NAV-001: `level` Query Parameter Has No Effect on Database Query Depth

**Severity:** Low

**Component:** `app/Services/General/HomeService.php` (line 49)

**Description:** The `level` query parameter is accepted by the controller and passed to `getNavData()`, but it only affects the cache key, not the database query. The `getCategoryWithChildren()` method always fetches 3 levels of children regardless of the `level` parameter. The `level` parameter only controls rendering depth in `CategoryNavbarResource` (which limits to `$request->query('level', 3)`). This means if a client requests `level=1`, the database still queries 3 levels but only outputs 1, wasting resources.

**Code Location:** `app/Services/General/HomeService.php` — lines 43-51

**Current Behavior:**
```php
public function getNavData(?int $level = null)
{
    $cacheKey = $level !== null
        ? $this->cacheKey("home-nav-bar:level:{$level}")
        : $this->cacheKey('home-nav-bar');

    return Cache::remember($cacheKey, 120, function () {
        return CategoryNavbarResource::collection($this->getCategoryWithChildren());
    });
}
```

The `$level` variable is captured in the closure scope but never used.

**Recommendation:** Either pass `$level` to `getCategoryWithChildren()` to limit the depth dynamically, or remove the `level` parameter entirely if it is not intended to be configurable.

---

## BUG-NAV-002: No Existing Tests

**Severity:** Medium

**Component:** Tests

**Description:** There are no feature tests for the `GET /api/v1/general/nav-data` endpoint. The home endpoint (`/general/home`) is tested in `ChannelContextTest.php` and `FastShippingControllerTest.php`, but the nav-data endpoint has zero coverage.

**Impact:** Validation, caching behavior, channel filtering, and response structure are untested. Regressions cannot be detected automatically.

**Recommendation:** Create a dedicated test file `NavDataTest.php` covering success, cache behavior, channel filtering, level parameter, and response structure.

---

## BUG-NAV-003: Missing Cache Invalidation for Level-Prefixed Keys

**Severity:** Medium

**Component:** `app/Services/General/HomeService.php`

**Description:** The `clearCache()` method in HomeService defines cache keys including `home-nav-bar`, but dynamic level-prefixed keys (`home-nav-bar:level:1`, `home-nav-bar:level:2`, etc.) are NOT included in the invalidation. If a client has cached `home-nav-bar:level:2`, it will not be cleared by `clearCache()`.

**Code Location:** `app/Services/General/HomeService.php` — lines 412-440

**Recommendation:** Either use a cache tag system or add a wildcard/pattern-based clear for level-prefixed keys, or use a fixed namespace for all nav-bar caches.
