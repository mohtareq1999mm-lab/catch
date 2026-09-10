# Test Coverage — Promotion Module

---

## Test Files

| File | Lines | Focus |
|------|-------|-------|
| `tests/Feature/PromotionFlowTest.php` | 565 | Integration flow: gift variant, reservation, shipping, cart modification, usage decrement, eligible promotion checks, clear/apply |
| `tests/Feature/PromotionProductionHardenTest.php` | 1346 | Production scenarios: percentage/fixed/gift calculations, rounding, capping, stock validation, simple+variant gifts, eligibility (expired, future, disabled, product-restricted, minimum_order), usage limits, concurrent usage, checkout integration, order snapshots, schema matching, regression tests |
| `tests/Unit/PromotionEligibilityResolverTest.php` | 245 | Unit tests: specific products discount, apply_to_all, gift items, stock filtering, original line total math, variant payload, minimum order |

---

## PromotionFlowTest.php Coverage

### Gift Variant Payload

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_gift_variant_payload` | Feature | Gift with variant returns correct variant payload |

### Gift Reservation

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 2 | `test_gift_reservation` | Feature | Gift item reservation creates cart item with is_gift=true |

### Shipping Method

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 3 | `test_gift_shipping_method` | Feature | Gift item gets correct shipping method |

### Cart Modification Clears Promotion

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 4 | `test_cart_modification_clears_promotion_data` | Regression | Modifying cart item clears promotion_id and discount_amount |

### Usage Decrement

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 5 | `test_usage_decrement` | Feature | Cancelling order decrements promotion usage |
| 6 | `test_double_cancel_does_not_double_decrement` | Regression | Double cancel does not double decrement |

### Eligible Promotion Checks

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 7 | `test_has_eligible_promotion` | Feature | hasEligiblePromotion returns true when eligible promotions exist |
| 8 | `test_has_eligible_promotion_empty_cart` | Edge Case | hasEligiblePromotion returns false for empty cart |

### Clear Promotion

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 9 | `test_clear_promotion_from_cart` | Feature | Clear promotion resets cart items and removes gifts |
| 10 | `test_clear_promotion_removes_gift_items` | Feature | Gifts removed when promotion cleared |
| 11 | `test_clear_promotion_recalculates_totals` | Feature | Cart totals recalculated after clear |

### Null Promotion

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 12 | `test_apply_selected_promotion_with_null_clears_promotion` | Feature | Null promotionId clears promotion |
| 13 | `test_apply_selected_promotion_with_null_returns_zero_discount` | Feature | Null promotionId returns zero discount |

### Framework Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 14 | Promotion exists test | Framework | Promotion seeder works |
| 15 | Setup verification | Framework | Test environment is configured |

---

## PromotionProductionHardenTest.php Coverage

### Percentage/Fixed/Gift Calculations

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_percentage_promotion_calculation` | Feature | 20% off 100 = 20 discount |
| 2 | `test_percentage_promotion_capped` | Feature | 40% off 1000, max=200 = 200 discount |
| 3 | `test_fixed_rate_promotion_calculation` | Feature | 50 off 100 = 50 discount |
| 4 | `test_fixed_rate_capped_at_price` | Feature | 100 off 30 = 30 discount (not negative) |
| 5 | `test_gift_promotion_discount_amount` | Feature | Gift = 0 discount amount |
| 6 | `test_gift_promotion_simple_product` | Feature | Simple gift with no variant |
| 7 | `test_gift_promotion_with_variant` | Feature | Gift with variant payload |
| 8 | `test_gift_variant_out_of_stock_filtered` | Edge Case | Out of stock variant filtered out |
| 9 | `test_gift_simple_product_out_of_stock_filtered` | Edge Case | Out of stock product filtered out |
| 10 | `test_percentage_rounding_precision` | Feature | Correct 2-decimal rounding |
| 11 | `test_fixed_rate_rounding_precision` | Feature | Correct 2-decimal rounding |

### Eligibility (Expired, Future, Disabled, Product-Restricted, Minimum Order)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 12 | `test_expired_promotion_not_eligible` | Validation | Past end_at not eligible |
| 13 | `test_future_promotion_eligible` | Validation | Future start_at still valid |
| 14 | `test_disabled_promotion_not_eligible` | Validation | status=false not eligible |
| 15 | `test_product_restricted_promotion_matching` | Feature | Matching product is eligible |
| 16 | `test_product_restricted_promotion_no_match` | Feature | Non-matching product not eligible |
| 17 | `test_minimum_order_not_met` | Validation | Below minimum not eligible |
| 18 | `test_minimum_order_met` | Feature | Above minimum is eligible |

### Usage Limits

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 19 | `test_usage_limit_reached` | Validation | usage >= limiter not eligible |
| 20 | `test_increment_usage_on_order` | Feature | Order placement increments usage |
| 21 | `test_decrement_usage_on_cancel` | Feature | Order cancellation decrements usage |

### Concurrent Usage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 22 | `test_concurrent_usage_increment` | Concurrency | LockForUpdate prevents over-usage |

### Checkout Integration

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 23 | `test_apply_promotion_to_cart` | Feature | Apply promotion updates cart items |
| 24 | `test_apply_null_promotion_clears` | Feature | Null promotionId clears |
| 25 | `test_apply_ineligible_promotion_throws` | Validation | Ineligible promotion throws |
| 26 | `test_apply_gift_promotion` | Feature | Gift promotion creates gift item |
| 27 | `test_select_specific_gift_product` | Feature | Specific gift product selection |
| 28 | `test_select_nonexistent_gift_throws` | Validation | Invalid gift throws |
| 29 | `test_clear_promotion_from_cart` | Feature | Clear resets all items |
| 30 | `test_remove_gift_on_promotion_clear` | Feature | Gifts removed on clear |

### Order Snapshots

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 31 | `test_order_promotion_snapshot` | Feature | Order stores promotion snapshot |

### Schema Matching

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 32 | `test_promotion_result_schema` | Feature | PromotionResult matches expected schema |
| 33 | `test_cart_item_promotion_fields` | Feature | Cart items have promotion_id, discount_amount, is_gift |
| 34 | `test_checkout_totals_schema` | Feature | CheckoutTotals has expected fields |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 35+ | Additional regression tests | Regression | Edge cases and bug regression |

---

## PromotionEligibilityResolverTest.php Coverage

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_specific_products_discount` | Unit | Product-restricted promotion resolves correctly |
| 2 | `test_apply_to_all_products` | Unit | All-products promotion matches all items |
| 3 | `test_gift_items` | Unit | Gift promotion resolves gift items |
| 4 | `test_stock_filtering` | Unit | Out of stock items filtered from gifts |
| 5 | `test_original_line_total_math` | Unit | Uses price * quantity, not total_price |
| 6 | `test_variant_payload` | Unit | Gift variant includes attributes |
| 7 | `test_minimum_order` | Unit | Minimum order amount check works |

---

## Coverage Summary

| Category | Count |
|----------|-------|
| Feature Tests (Success) | ~25 |
| Validation Tests | ~10 |
| Regression Tests | ~6 |
| Unit Tests | ~7 |
| Edge Case Tests | ~8 |
| Concurrency Tests | ~1 |
| Schema Tests | ~3 |
| **Total (estimate)** | ~57 |

---

## Missing Tests (Recommended for New Test File)

### Admin CRUD Tests

- [ ] `test_admin_list_promotions` — GET /promotions returns paginated list
- [ ] `test_admin_list_promotions_search` — Search by name/code/type
- [ ] `test_admin_list_promotions_filter` — Filter by status, type, type_amount
- [ ] `test_admin_create_percentage_promotion` — Create with percentage type
- [ ] `test_admin_create_fixed_rate_promotion` — Create with fixed_rate type
- [ ] `test_admin_create_gift_promotion` — Create with gift type and gift_products
- [ ] `test_admin_show_promotion` — GET /promotions/{id}
- [ ] `test_admin_update_promotion` — PUT /promotions/{id}
- [ ] `test_admin_delete_promotion` — DELETE /promotions/{id}
- [ ] `test_admin_create_validation` — 422 on missing required fields
- [ ] `test_admin_update_validation` — 422 on invalid data

### Authorization Tests

- [ ] `test_guest_cannot_create` — 401
- [ ] `test_guest_cannot_update` — 401
- [ ] `test_guest_cannot_delete` — 401
- [ ] `test_user_without_permission_cannot_view` — 403
- [ ] `test_user_without_permission_cannot_create` — 403
- [ ] `test_user_without_permission_cannot_update` — 403
- [ ] `test_user_without_permission_cannot_delete` — 403
- [ ] `test_public_endpoints_work_without_auth` — 200

### Public API Tests

- [ ] `test_public_list_valid_promotions` — GET /general/promotions
- [ ] `test_public_list_filters` — Date, ID filters
- [ ] `test_public_get_by_slug` — GET /general/promotions/{slug}
- [ ] `test_public_get_by_slug_404` — Non-existent slug
- [ ] `test_checkout_eligible_promotions` — GET /checkout/promotions

### Additional Edge Cases

- [ ] **Value/discount sync** — Creating with value only still sets discount
- [ ] **Code auto-generation** — ALL prefix for all_products, PRO for specific_products
- [ ] **Slug auto-generation** — From English name
- [ ] **Update with same name** — Should not fail (BUG-PR-009)
- [ ] **Update with duplicate name** — Should fail
- [ ] **Gift variant belongs to product validation** — Wrong variant for product → error
- [ ] **Image upload validation** — Invalid mime type → 422
- [ ] **Concurrent cart modifications** — Race condition with promotion
- [ ] **Promotion with null start_at** — Always active from beginning
- [ ] **Promotion with null end_at** — Always active indefinitely
- [ ] **Promotion with null limiter** — Unlimited usage
- [ ] **Mass assignment** — Injecting id/slug fields is ignored
- [ ] **JSON response structure** — Exact field match for all endpoints
