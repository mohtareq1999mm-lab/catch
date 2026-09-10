# Bug Report — Coupon Module (Public API)

---

## BUG-COUPON-001: `applyCoupon` Doesn't Validate `code` Field

**Severity:** Medium

**Component:** `app/Http/Controllers/Api/General/CouponController.php` (line 28)

**Description:** The `applyCoupon` method uses `$request->get('code')` without any validation that the field exists or is a non-empty string. An empty or missing `code` will pass through to `CouponOrchestrator::validateByCode('')`, which will return `not_found` with a 400 response, but it would be cleaner to validate at the controller level.

**Code Location:** `app/Http/Controllers/Api/General/CouponController.php` — lines 26-40

---

## BUG-COUPON-002: `index()` Method Doesn't Support Pagination

**Severity:** Low

**Component:** `app/Services/General/CouponService.php` (line 37)

**Description:** The `getCoupons()` method uses `->limit($limit)->get()` instead of `->paginate($limit)`. This returns a flat collection, not a paginated response. All valid coupons are returned up to the limit, but there's no pagination metadata.

---

## BUG-COUPON-003: No `order` Parameter on Routes File for `coupons/apply`

**Severity:** Low

**Component:** `routes/api.php` (line 62)

**Description:** The `coupons/apply` route has the `auth:sanctum` middleware applied inline, while the `index` route is public. If the `POST /coupons/apply` route is ever accessed without authentication, it returns a 401. This is correct behavior, but the route ordering could be fragile if mixed with other coupon routes.

---

## BUG-COUPON-004: CouponResource Omits Valuable Fields

**Severity:** Low

**Component:** `app/Http/Resources/Coupons/CouponResource.php`

**Description:** The public CouponResource only returns: id, name, slug, image, borderColor, borderless. Useful fields like `discount_type`, `discount` value, `start_date`, `end_date`, and `code` are omitted. Frontend cannot display discount percentage/amount or validity dates without additional API calls.
