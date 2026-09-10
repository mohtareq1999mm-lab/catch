# Test Coverage — Coupon Module (Public API)

---

## Existing Tests

**Files:** `tests/Feature/CouponSystemTest.php`, `tests/Feature/AssignedCouponSystemTest.php`

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_apply_valid_coupon` | Feature | Valid percentage coupon applied |
| 2 | `test_apply_same_coupon_twice` | Feature | Already applied detected |
| 3 | `test_apply_expired_coupon` | Feature | Past end_date → 400 |
| 4 | `test_apply_disabled_coupon` | Feature | status=false → 400 |
| 5 | `test_apply_usage_limit_reached` | Feature | limiter exhausted → 400 |
| 6 | `test_apply_nonexistent_coupon` | Feature | Unknown code → 400 |
| 7 | `test_apply_unauthenticated` | Feature | No token → 401 |
| 8 | `test_apply_without_cart` | Feature | User has no cart → 400 |
| 9 | `test_apply_restricted_product` | Feature | Product not in coupon's allowed list → 400 |
| 10 | `test_apply_fixed_rate` | Feature | Fixed discount applied |
| 11 | `test_apply_free_shipping` | Feature | Free shipping flag returned |
| 12 | `test_assigned_coupon_validation` | Feature | User-specific assignments |
| 13 | `test_coupon_cart_total_calculation` | Feature | Total price correctly updated |

---

## Recommended Additional Tests

### List Coupons Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_coupons` | Feature | 200, valid coupons |
| 2 | `test_list_coupons_with_search` | Feature | Search by name |
| 3 | `test_list_coupons_filter_by_ids` | Feature | `?couponsId=1,2` |
| 4 | `test_list_coupons_excludes_expired` | Feature | Expired excluded |
| 5 | `test_list_coupons_excludes_usage_limit_reached` | Feature | Full usage excluded |
| 6 | `test_list_coupons_empty` | Feature | No valid → `[]` |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_response_structure` | Feature | Top-level keys |
| 2 | `test_coupon_object_structure` | Feature | id, name, slug, image, borderColor, borderless |
| 3 | `test_apply_response_structure` | Feature | total_price, coupon_discount, free_shipping |

### Channel Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_coupons_channel_header` | Feature | X-Channel header works |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_apply_percentage_with_max_cap` | Feature | Max discount amount enforced |
| 2 | `test_apply_zero_discount` | Feature | Zero discount handled |
| 3 | `test_coupon_border_styling` | Feature | borderColor and borderless returned |
