# Bug Report — Brand Module (Public API)

---

## BUG-BRAND-001: `brands-products` Route Has Inconsistent Naming

**Severity:** Low

**Component:** `routes/api.php` (line 47)

**Description:** The third brand endpoint uses the URI `brands-products` while all other public endpoints follow the pattern `{resource}/{param}` (e.g., `brands/{slug}`). This is inconsistent with REST conventions and could cause confusion. It should ideally be `brands/products` or a query parameter on `GET /brands`.

**Current Route:**
```php
Route::get('brands-products', [BrandController::class, 'getBrandsProductsByQtySet']);
```

**Impact:** Low — works correctly but deviates from URL conventions.

---

## BUG-BRAND-002: `index()` Method Has Implicit Slug Route Logic

**Severity:** Low

**Component:** `app/Http/Controllers/Api/General/BrandController.php` (line 24)

**Description:** The `index()` method checks for a `slug` query parameter and, if present, delegates to `getBrandBySlug()` instead of returning a list. This means `GET /api/v1/general/brands?slug=nike` returns a single brand resource, not a collection. This behavior is undocumented at the route level.

**Code Location:** `app/Http/Controllers/Api/General/BrandController.php` — lines 22-29

```php
public function index(Request $request)
{
    if ($slug = $request->query('slug')) {
        return $this->getBrandBySlug($slug);
    }
    $brands = $this->brandService->getBrands($request);
    return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, BrandResource::collection($brands));
}
```

**Recommendation:** Either document this behavior explicitly or remove the implicit slug lookup from the index method.

---

## BUG-BRAND-003: No Tests for Public Brand Endpoints

**Severity:** Medium

**Component:** Tests

**Description:** The existing test files (`BrandApiTest.php`, `BrandProductionHardenTest.php`) only cover the **admin** endpoints (`/api/v1/brands`). The public endpoints (`/api/v1/general/brands`, `/api/v1/general/brands/{slug}`, `/api/v1/general/brands-products`) have no dedicated test coverage.

**Impact:** Regressions and breaking changes to the public brand API cannot be detected automatically.

---

## BUG-BRAND-004: `getBrandBySlug` Accepts Both Slug and ID Through Route

**Severity:** Low

**Component:** `routes/api.php` (line 46)

**Description:** The route `brands/{slug}` — the parameter is named `slug` but the controller method also accepts it from the `index()` method via `$request->query('slug')`. The admin route `GET /api/v1/brands/{id}` accepts an ID, but the public route accepts a slug. There is no type enforcement.

**Impact:** Low — the controller uses `search('slug', ...)` to look up the brand, so it works correctly.
