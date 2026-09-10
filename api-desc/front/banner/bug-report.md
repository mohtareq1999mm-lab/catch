# Bug Report — Banner Module (Public API)

---

## BUG-BANNER-001: `index()` Method Has Implicit Slug Route Logic

**Severity:** Low

**Component:** `app/Http/Controllers/Api/General/BannerController.php` (line 23)

**Description:** The `index()` method checks for a `slug` query parameter and delegates to `getBannerBySlug()` if present. This means `GET /api/v1/general/banners?slug=summer-sale` returns a single banner instead of a list. This behavior is undocumented at the route level.

**Code Location:** `app/Http/Controllers/Api/General/BannerController.php` — lines 21-28

```php
public function index(Request $request)
{
    if ($slug = $request->query('slug')) {
        return $this->getBannerBySlug($slug, $request);
    }
    $banners = $this->bannerService->getBanners($request);
    return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, BannerResource::collection($banners));
}
```

---

## BUG-BANNER-002: `with_products` Query Param Has Inverted Truthiness Check

**Severity:** Medium

**Component:** `app/Services/General/BannerService.php` (line 41)

**Description:** The `with_products` parameter is checked with `$with_products !== 'false'`, meaning the string `'false'` is treated as false but no other falsy value works. Passing `with_products=0`, `with_products=false` (as boolean), or omitting the param all result in products being loaded. Only the literal string `'false'` disables product loading.

**Code Location:** `app/Services/General/BannerService.php` — lines 38-46

```php
public function getBannerBySlug($slug, $with_products = false)
{
    $banner = Banner::active()->search('slug', $slug, app()->getLocale())->first();
    if ($banner && $with_products !== 'false') {
        $banner->load(['products' => fn($q) => $this->applyChannelHomeFilter($q)]);
        app(ProductService::class)->enrichCollectionWithPricing($banner->products);
    }
    return $banner;
}
```

**Recommendation:** Use proper boolean coercion: `filter_var($with_products, FILTER_VALIDATE_BOOLEAN)`.

---

## BUG-BANNER-003: No Tests for Public Banner Endpoints

**Severity:** Medium

**Component:** Tests

**Description:** The public banner endpoints have zero dedicated test coverage. Only a single reference exists in `FastShippingControllerTest.php` which tests the channel header on `/general/banners`.

---

## BUG-BANNER-004: No Cache on Banner Endpoints

**Severity:** Low

**Component:** BannerService

**Description:** Banner endpoints hit the database on every request. Given that banners change infrequently and are displayed on every page load (hero section), caching would significantly improve performance.
