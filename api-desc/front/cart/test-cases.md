# Test Coverage — Cart Module (Authenticated API)

---

## Existing Tests

**No tests exist for cart endpoints.**

---

## Recommended Tests

### Feature Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_carts_empty` | Feature | No cart → empty paginated response |
| 2 | `test_list_carts_with_items` | Feature | Cart with items → paginated response |
| 3 | `test_add_simple_product` | Feature | Simple product added successfully |
| 4 | `test_add_variable_product_with_variant` | Feature | Variable product with variant |
| 5 | `test_add_variable_product_without_variant` | Feature | Variable product without variant → 400 |
| 6 | `test_add_stock_exceeded` | Feature | quantity > stock → 400 |
| 7 | `test_add_fast_shipping_ineligible` | Feature | FAST on non-eligible product → 400 |
| 8 | `test_add_same_product_twice_additive` | Feature | Same product added twice (add mode) → quantity summed |
| 9 | `test_bulk_add_all_succeed` | Feature | Multiple items, all valid → 201 |
| 10 | `test_bulk_add_one_fails` | Feature | One invalid → 400, rollback all |
| 11 | `test_show_cart` | Feature | GET /cart/{id} → 200 |
| 12 | `test_show_another_users_cart` | Feature | Other user's cart → 403 |
| 13 | `test_update_quantity_set_mode` | Feature | PUT /cart/update-item → quantity replaced |
| 14 | `test_update_preserves_shipping_method` | Feature | Without shipping_method → old method kept |
| 15 | `test_delete_item` | Feature | DELETE /cart/delete-item/{id} → 200 |
| 16 | `test_delete_item_not_found` | Feature | Invalid itemId → 400 |
| 17 | `test_delete_another_users_item` | Feature | Other user's item → 400 |
| 18 | `test_clear_cart` | Feature | DELETE /cart/delete-items → 200 |
| 19 | `test_clear_cart_with_coupon_no_confirm` | Feature | Coupon applied, no confirm → warning |
| 20 | `test_clear_cart_with_coupon_confirmed` | Feature | Coupon applied, confirm=true → cleared |

### Authentication Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_unauthenticated_get` | Feature | No token → 401 |
| 2 | `test_unauthenticated_post` | Feature | No token → 401 |
| 3 | `test_unauthenticated_put` | Feature | No token → 401 |
| 4 | `test_unauthenticated_delete` | Feature | No token → 401 |
| 5 | `test_expired_token` | Feature | Expired token → 401 |

### Validation Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_store_missing_item` | Feature | No item field → 422 |
| 2 | `test_store_missing_product_id` | Feature | No product_id → 422 |
| 3 | `test_store_missing_quantity` | Feature | No quantity → 422 |
| 4 | `test_store_missing_shipping_method` | Feature | No shipping_method → 422 |
| 5 | `test_store_invalid_shipping_method` | Feature | Invalid enum value → 422 |
| 6 | `test_store_quantity_zero` | Feature | quantity = 0 → 422 |
| 7 | `test_bulk_items_not_array` | Feature | items not array → 422 |

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_index_response_structure` | Feature | status, message, success, data, pagination |
| 2 | `test_cart_resource_structure` | Feature | CartResource fields |
| 3 | `test_cart_item_structure` | Feature | CartItemResource fields |
| 4 | `test_store_response_structure` | Feature | 201, CartResource |

### Coupon Discount Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_coupon_discount_zero_when_no_coupon` | Feature | No coupon → coupon_discount = 0 |
| 2 | `test_coupon_discount_percentage` | Feature | 10% coupon → coupon_discount = 10% of subtotal |
| 3 | `test_coupon_discount_fixed_rate` | Feature | $5 coupon → coupon_discount = 5.00 |
| 4 | `test_coupon_discount_capped_by_max` | Feature | 50% with max $10 → discount capped at $10 |
| 5 | `test_coupon_discount_free_shipping` | Feature | free_shipping type → coupon_discount = 0 (no price discount) |
| 6 | `test_total_after_coupon_never_negative` | Feature | Discount > subtotal → total_after_coupon = 0 |
| 7 | `test_coupon_discount_updates_when_coupon_removed` | Feature | Remove coupon → coupon_discount resets to 0 |
| 8 | `test_coupon_resource_structure_in_cart` | Feature | coupon object present when coupon applied |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_inventory_released_on_item_delete` | Feature | Stock restored after delete |
| 2 | `test_cart_total_recalculated` | Feature | Total matches sum of items |
| 3 | `test_cart_ttl_set_on_add` | Feature | expires_at = now + 3 days |
| 4 | `test_expired_cart_cleanup` | Feature | expireCarts() releases stock |
| 5 | `test_gift_item_has_zero_price` | Feature | is_gift items have price=0 |
