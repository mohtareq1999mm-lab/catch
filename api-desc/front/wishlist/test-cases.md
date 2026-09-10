# Test Coverage — Wishlist Module (Authenticated API)

---

## Existing Tests

**`tests/Feature/WishlistApiTest.php`** — 36 tests, 106 assertions. **All passing.**

> Test setup uses `DatabaseTransactions + CreatesTestTables`. It conditionally creates the `wishlists` table and the **`attribute_product`** pivot table (needed because `ProductVariant::attributeProducts()` joins the singular table name).

---

## Authentication Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_index_requires_auth` | Feature | GET /wishlists without token → 401 |
| 2 | `test_store_requires_auth` | Feature | POST /wishlists without token → 401 |
| 3 | `test_toggle_requires_auth` | Feature | POST /wishlists/toggle without token → 401 |
| 4 | `test_destroy_requires_auth` | Feature | DELETE /wishlists/{id} without token → 401 |
| 5 | `test_my_wishlists_requires_auth` | Feature | GET /my-wishlists without token → 401 |

---

## In-Wishlist (Public, Guest-Safe) Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_in_wishlist_guest_returns_false` | Feature | Guest → data=false |
| 2 | `test_in_wishlist_returns_true_when_product_in_wishlist` | Feature | Saved product → data=true |
| 3 | `test_in_wishlist_returns_false_when_product_not_in_wishlist` | Feature | Not saved → data=false |
| 4 | `test_in_wishlist_ignores_other_users_wishlist` | Feature | Saved by other user → data=false |

---

## Index (GET /wishlists) Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_index_returns_empty_when_no_wishlist` | Feature | Empty → 200, data empty |
| 2 | `test_index_returns_only_current_users_wishlist` | Feature | Other user's items not returned |
| 3 | `test_index_returns_wishlist_products` | Feature | 1 saved item → 1 product |
| 4 | `test_index_is_paginated` | Feature | limit=2 → 2 items returned |
| 5 | `test_index_response_structure` | Feature | status/message/success/data keys |

---

## Store (POST /wishlists) Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_store_adds_simple_product` | Feature | Simple product → 200, DB row |
| 2 | `test_store_duplicate_returns_400_with_translated_message` | Feature | Duplicate → 400 translated, 1 row only |
| 3 | `test_store_missing_product_id_returns_422` | Feature | Empty body → 422 |
| 4 | `test_store_nonexistent_product_returns_422` | Feature | product_id=999999 → 422 |
| 5 | `test_store_variable_product_without_variant_returns_422` | Feature | Variable, no variant → 422 |
| 6 | `test_store_variable_product_with_variant_succeeds` | Feature | Variable + variant → 200, DB row |
| 7 | `test_store_allows_same_product_with_different_variants` | Feature | 2 variants → 2 rows |

---

## Toggle (POST /wishlists/toggle) Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_toggle_adds_product_when_not_in_wishlist` | Feature | Add path → 200 "Added..." |
| 2 | `test_toggle_removes_product_when_in_wishlist` | Feature | Remove path → 200 "Removed..." |
| 3 | `test_toggle_simple_product_does_not_create_duplicates` | Feature | Toggle twice → 1 row (no dup) |
| 4 | `test_toggle_variable_product_without_variant_returns_422` | Feature | Variable, no variant → 422 |

---

## Destroy (DELETE /wishlists/{product_id}) Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_destroy_removes_simple_product` | Feature | Simple → 200, row gone |
| 2 | `test_destroy_nonexistent_product_returns_404` | Feature | product_id=999999 → 404 |
| 3 | `test_destroy_when_product_not_in_users_wishlist_returns_404` | Feature | Other user's item → 404, row kept |
| 4 | `test_destroy_variant_item_with_variant_id_query` | Feature | ?product_variant_id → 200, variant row gone |
| 5 | `test_destroy_simple_item_without_variant_does_not_delete_variant_item` | Feature | No variant param on variant item → 404, row kept |

---

## My-Wishlists (GET /my-wishlists) Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_my_wishlists_returns_empty` | Feature | Empty → 200, data empty |
| 2 | `test_my_wishlists_returns_products_for_current_user` | Feature | Saved item → 1 product, meta.total=1 |
| 3 | `test_my_wishlists_ignores_other_users_wishlist` | Feature | Other user's items excluded |
| 4 | `test_my_wishlists_is_paginated` | Feature | limit=2 → 2 items, meta.total=3, per_page=2 |

---

## apiResource Guard Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_show_route_is_not_registered` | Feature | GET /wishlists/{id} → 405 |
| 2 | `test_update_route_is_not_registered` | Feature | PUT /wishlists/{id} → 405 |

---

## Recommended Additional Tests (Not Yet Written)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_store_variable_product_with_invalid_variant_returns_422` | Feature | product_variant_id not existing → 422 |
| 2 | `test_toggle_variable_product_with_variant_succeeds` | Feature | Variable + variant toggle add/remove |
| 3 | `test_store_with_invalid_product_variant_type` | Feature | product_variant_id = "abc" → 422 |
| 4 | `test_translations_ar_locale` | Feature | Duplicate message in ar locale resolves |
| 5 | `test_destroy_variant_item_without_variant_id_returns_404` | Feature | Variant item removed without variant id → 404 |
| 6 | `test_index_with_invalid_limit` | Feature | limit=0 or negative → falls back / sanitized |
