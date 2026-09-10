# Test Coverage — Cart Module

> Source of truth: `tests/Feature/CartApiTest.php` (80 test methods) + `tests/Feature/CartExpirationTest.php` (8 test methods) = **88 total**. Verified against source on 2026-08-04 (Revision 4).

---

## Test Files

| File | Test Methods | Focus |
|------|-------------|-------|
| `tests/Feature/CartApiTest.php` | 80 | CRUD, inventory, variants, shipping, bulk, delta reserve, expiration, finalization, edge cases |
| `tests/Feature/CartExpirationTest.php` | 8 | TTL-based cart expiration lifecycle |

---

## CartApiTest.php Coverage (80 tests)

### Authentication (7)
| # | Test | Type | Expected |
|---|------|------|----------|
| 1 | `test_cart_index_requires_auth` | Auth | 401 |
| 2 | `test_add_item_requires_auth` | Auth | 401 |
| 3 | `test_update_item_requires_auth` | Auth | 401 |
| 4 | `test_delete_item_requires_auth` | Auth | 401 |
| 5 | `test_clear_cart_requires_auth` | Auth | 401 |
| 6 | `test_cart_show_requires_auth` | Auth | 401 |
| 7 | `test_guest_cannot_access_cart` | Auth | 401 on all endpoints |

### Index (1)
| # | Test | Type | Expected |
|---|------|------|----------|
| 8 | `test_cart_index_returns_empty_when_no_cart` | Feature | 200, empty data |

### Add Item (6)
| # | Test | Type | Expected |
|---|------|------|----------|
| 9 | `test_add_item_creates_cart` | Feature | 201, cart created |
| 10 | `test_add_item_reserves_inventory` | Feature | `reserved_quantity` incremented |
| 11 | `test_add_item_rejects_excessive_quantity` | Validation | 400 stock exceeded |
| 12 | `test_add_item_rejects_nonexistent_product` | Validation | 422 |
| 13 | `test_add_multiple_items_accumulates_in_cart` | Feature | Separate lines |
| 14 | `multiple_adds_accumulate_quantity` | Feature | Same product+variant+shipping sums qty |

### Update Item — operation field (4 + 2)
| # | Test | Type | Expected |
|---|------|------|----------|
| 15 | `test_update_item_changes_quantity` | Feature | 200, quantity changed |
| 16 | `test_update_item_adjusts_reserved_quantity` | Feature | Reserved matches new quantity |
| 17 | `test_update_item_rejects_excessive_quantity` | Validation | 400 |
| 18 | `update_requires_operation_field` | Validation | 422 (missing `operation`) |
| 19 | `update_rejects_invalid_operation` | Validation | 422 (invalid operation) |

### Delete Item (3)
| # | Test | Type | Expected |
|---|------|------|----------|
| 20 | `test_delete_item_releases_inventory` | Feature | Stock released |
| 21 | `test_delete_item_removes_item_from_cart` | Feature | Line removed |
| 22 | `test_delete_item_returns_400_for_nonexistent_item` | Edge | 400 |

### Clear Cart (3)
| # | Test | Type | Expected |
|---|------|------|----------|
| 23 | `test_clear_cart_releases_all_inventory` | Feature | All stock released |
| 24 | `test_destroy_returns_404_when_no_cart` | Edge | 404 |
| 25 | `clear_cart_without_confirm_and_no_coupon_succeeds` | Feature | 200, cleared |
| 26 | `clear_cart_with_coupon_without_confirm_returns_warning` | Feature | **200 + success:true** + coupon warning |

### Show Cart (3)
| # | Test | Type | Expected |
|---|------|------|----------|
| 27 | `test_cart_show_returns_cart` | Feature | 200, cart data |
| 28 | `test_cart_show_rejects_other_user_cart` | Auth | 403 |
| 29 | `cart_show_rejects_nonexistent_cart` | Edge | 404 |

### Bulk Add (5)
| # | Test | Type | Expected |
|---|------|------|----------|
| 30 | `test_bulk_add_items` | Feature | 201, all added |
| 31 | `test_bulk_add_skips_nonexistent_products` | Feature | 201 + `skipped_product_ids` |
| 32 | `test_bulk_add_mixed_valid_and_nonexistent_skips_invalid` | Feature | 201, valid added, invalid skipped |
| 33 | `test_bulk_add_skips_stock_failures_and_continues` | Feature | 201 + `failed_items` |
| 34 | `test_bulk_add_skips_all_failures_returns_empty_cart` | Feature | 201, `cart` null |
| 35 | `bulk_add_mixed_shipping_methods` | Feature | 201, correct sections |

### Shipping Method (3)
| # | Test | Type | Expected |
|---|------|------|----------|
| 36 | `test_shipping_method_lowercase_is_normalized_to_uppercase` | Validation | stored SCHEDULED |
| 37 | `test_shipping_method_uppercase_is_stored_as_is` | Validation | stored SCHEDULED |
| 38 | `test_cart_sections_return_items_in_correct_section` | Feature | correct normal/fast split |

### Same Product / Different Shipping (3)
| # | Test | Type | Expected |
|---|------|------|----------|
| 39 | `same_product_different_shipping_creates_separate_items` | Feature | 2 lines |
| 40 | `update_cart_item_preserves_shipping_method` | Feature | method preserved when omitted |
| 41 | `update_with_explicit_shipping_method_updates_correct_item` | Feature | correct line updated |

### Variants (5)
| # | Test | Type | Expected |
|---|------|------|----------|
| 42 | `add_variant_product_to_cart` | Feature | 201 with variant |
| 43 | `update_variant_item_preserves_shipping_method` | Feature | method preserved |
| 44 | `delete_variant_item_releases_variant_stock` | Feature | variant stock released |
| 45 | `same_product_different_variants_create_separate_items` | Feature | 2 lines |
| 46 | `gift_item_attribute_not_exposed_in_item_resource` | Feature | gift item structure clean |

### Stock Consistency / Delta Reserve (12)
| # | Test | Type | Expected |
|---|------|------|----------|
| 47 | `stock_consistency_after_multiple_add_remove_cycles` | Regression | stock returns to original |
| 48 | `stock_consistency_after_quantity_update` | Regression | stock matches expected |
| 49 | `decrement_removes_last_item` | Feature | line deleted at <1 |
| 50 | `delta_new_item_rejected_when_exceeds_stock` | Edge | 400 |
| 51 | `delta_increase_succeeds_when_delta_within_available_stock` | Feature | 201 |
| 52 | `delta_increase_rejected_when_delta_exceeds_available` | Edge | 400 |
| 53 | `delta_decrease_releases_stock` | Feature | stock released |
| 54 | `repeated_increments_accumulate` | Feature | qty sums |
| 55 | `delta_add_mode_increases_correctly` | Feature | correct add mode |
| 56 | `delta_boundary_available_equals_delta_succeeds` | Edge | 201 (boundary) |
| 57 | `delta_variant_increase_succeeds` | Feature | variant reserve |
| 58 | `delta_variant_increase_rejected_when_exceeds` | Edge | 400 |
| 59 | `delta_variant_decrease_releases_stock` | Feature | variant release |
| 60 | `delta_concurrent_users_respect_reservations` | Concurrency | reservations respected |
| 61 | `repeated_decrements_reduce_quantity` | Feature | qty reduces |
| 62 | `concurrent_users_increment_respects_available_stock` | Concurrency | no oversell |

### Totals / Coupon (3)
| # | Test | Type | Expected |
|---|------|------|----------|
| 63 | `cart_total_price_updated_on_item_operations` | Feature | total recalculated |
| 64 | `test_apply_coupon_to_cart` | Feature | coupon stored |
| 65 | `delete_last_item_clears_coupon` | Feature | coupon null |

### Expiration / Reservation / Finalization (8)
| # | Test | Type | Expected |
|---|------|------|----------|
| 66 | `recently_refreshed_cart_not_expired` | Feature | active before TTL |
| 67 | `expired_cart_status_correct_after_expiry` | Feature | status = expired |
| 68 | `add_item_reactivates_expired_cart_and_re_reserves_stock` | Feature | fresh reservation |
| 69 | `release_cart_without_delete_releases_stock_but_keeps_items` | Feature | stock released, items kept |
| 70 | `ensure_cart_reservation_syncs_quantities` | Feature | reservation synced |
| 71 | `finalize_all_items_marks_cart_checked_out` | Feature | status = checked_out |
| 72 | `finalize_scheduled_items_only_keeps_fast_items` | Feature | only FAST remain (documents BUG-CART-002 behavior) |
| 73 | `finalize_fast_items_only_keeps_scheduled_items` | Feature | only SCHEDULED remain |

### Response Structure / Translation / Rate Limit (4)
| # | Test | Type | Expected |
|---|------|------|----------|
| 74 | `cart_response_structure_is_correct` | Feature | JSON structure matches |
| 75 | `test_english_cart_messages_are_readable` | Translation | EN messages resolved |
| 76 | `cart_rate_limiter_enforces_limit` | Security | 429 over 20/min |
| 77 | `cart_response_handles_soft_deleted_product` | Edge | product null in response |
| 78 | `cart_response_handles_deleted_coupon` | Edge | coupon null in response |
| 79 | `add_regular_item_does_not_overwrite_gift_item` | Feature | gift line preserved |
| 80 | `update_non_existent_cart_item_creates_new_item` | Edge | creates new line |

---

## CartExpirationTest.php Coverage (8 tests)

| # | Test | Type | Expected |
|---|------|------|----------|
| 1 | `expire_carts_releases_reserved_stock` | Feature | stock released after TTL |
| 2 | `expire_carts_marks_cart_as_expired` | Feature | status = expired |
| 3 | `expire_carts_deletes_cart_items` | Feature | lines removed |
| 4 | `active_cart_not_expired_before_ttl` | Feature | not expired within TTL |
| 5 | `expire_carts_skips_carts_without_expires_at` | Edge | null expires_at skipped |
| 6 | `cart_receives_3day_ttl_on_touch` | Feature | 3-day TTL set |
| 7 | `expired_carts_can_be_recreated` | Feature | new cart after expiry |
| 8 | `cart_expired_before_finalize_releases_stock` | Edge | stock freed before checkout |

---

## Coverage Summary

| Category | Count |
|----------|-------|
| Authentication / Authorization | 8 |
| Feature (success) | ~44 |
| Validation | ~9 |
| Edge Case | ~11 |
| Regression (stock consistency) | 2 |
| Concurrency (delta) | 2 |
| Security (rate limit) | 1 |
| Translation | 1 |
| Expiration (CartExpirationTest) | 8 |
| **Total** | **88** |

---

## Test Status (as of 2026-08-04)

- **Last recorded run (2026-07-29):** `CartApiTest` 61/65 pass, **4 pre-existing failures** (gift promotion, finalization, resource structure — unrelated to cart API surface). Bulk subset: 6/6 pass (34 assertions). CartExpirationTest: 8/8.
- **Environment blocker (2026-08-04):** the suite could not be executed in the audit session — every test errors during bootstrap with `Class "Role" not found` raised while registering routes at `packages/marvel/src/Rest/Routes.php:699`. This is a global test-bootstrap/autoload issue, **not** a cart defect. Full results must be re-verified after the bootstrap is fixed.
- **Production-status claim:** `docs/production-status.md` lists Cart as "65/65 (269 assertions)" — this refers to the last verified subset and does not account for the 88 current methods; the discrepancy should be reconciled in the next test run.

---

## Missing Tests (Recommended)

- [ ] **FAST ineligibility via API** — `POST /cart` with FAST on a product with `is_fast_shipping_available=false` → 400 `FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE` (logic verified in source; no direct API test found)
- [ ] **Bulk add with `failed_items` reason assertions** — assert the exact `reason` string returned
- [ ] **`limit` query param** — verify pagination uses `limit` (not `per_page`)
- [ ] **Coupon-warning HTTP status** — assert `clear_cart_with_coupon_without_confirm_returns_warning` returns **HTTP 200 + success:true**
- [ ] **Race: two simultaneous add requests** — no oversell (row-lock path; partially covered by `concurrent_users_increment_respects_available_stock`)
- [ ] **Race: simultaneous finalize** — one succeeds, one fails
- [ ] **Price re-validation at checkout** — BUG-CART-003 regression test
- [ ] **`finalizeItemsByShippingMethod` keeps non-finalized group** — regression for BUG-CART-002 (current tests assert the buggy delete behavior)
- [ ] **`expireCart` on `checked_out`/`expired` status** — regression for BUG-CART-007
- [ ] **Max quantity boundary** — BUG-CART-009 (no max rule today)
- [ ] **Empty `items: []` bulk add** — assert behavior (validated 422 by `required|array`? — `[]` passes `array`, verify)
- [ ] **Large quantity overflow** — `MAX_INT` behavior
- [ ] **Cart with mixed SCHEDULED + FAST finalize via `finalizeItemsByShippingMethod`** — verify totals/items after partial finalize
