# Coupon Module — Backend Jira Tasks

---

## Task 1: Add Validation for `code` Field in `applyCoupon()`

**Priority:** Medium
**Component:** CouponController
**Effort:** Trivial
**Files:**
- `app/Http/Controllers/Api/General/CouponController.php`

**Description:** Validate that `code` is present and is a non-empty string before passing to the service.

**Acceptance Criteria:**
- [ ] Missing `code` returns 422 with validation error
- [ ] Empty `code` returns 422
- [ ] Valid `code` works as before

---

## Task 2: Add Pagination to Coupon Listing

**Priority:** Low
**Component:** CouponService
**Effort:** Small
**Files:**
- `app/Services/General/CouponService.php`

**Description:** Change `->limit($limit)->get()` to `->paginate($limit)` for proper pagination support.

**Acceptance Criteria:**
- [ ] `?page=2` returns next page
- [ ] Pagination metadata included in response
- [ ] Backward compatible

---

## Task 3: Add Missing Fields to CouponResource

**Priority:** Low
**Component:** CouponResource
**Effort:** Trivial
**Files:**
- `app/Http/Resources/Coupons/CouponResource.php`

**Description:** The public resource omits `discount_type`, `discount`, `code`, `start_date`, and `end_date`. Add these fields so the frontend can display discount info and validity dates.

**Acceptance Criteria:**
- [ ] `discount_type` returned
- [ ] `discount` value returned
- [ ] `code` returned
- [ ] `start_date` and `end_date` returned
- [ ] Existing fields unchanged

---

## Task 4: Add Cache to Coupon Listing

**Priority:** Low
**Component:** CouponService
**Effort:** Small
**Files:**
- `app/Services/General/CouponService.php`

**Description:** Coupon listing is not cached. Add 120s TTL cache.

**Acceptance Criteria:**
- [ ] `getCoupons()` cached with channel-scoped key
- [ ] Cache cleared on coupon create/update/delete
