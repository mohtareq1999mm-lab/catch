# Wishlist Module — QA Test Cases (Authenticated API)

## Test Files

**`tests/Feature/WishlistApiTest.php`** — 36 tests, 106 assertions. All passing.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List wishlist | GET /wishlists | 200, product list for current user |
| F2 | List empty wishlist | User with no saved items | 200, empty data |
| F3 | List user scoping | Other user has items | 200, current user's items only |
| F4 | List pagination | ?limit=2 | 200, 2 items, more in DB |
| F5 | Add simple product | POST /wishlists {product_id} | 200, row created |
| F6 | Add duplicate product | Same product again | 400, translated duplicate message |
| F7 | Add variable product without variant | Variable product, no variant | 422 |
| F8 | Add variable product with variant | {product_id, product_variant_id} | 200, row created |
| F9 | Add same product, different variants | Variant A then variant B | 200, 200, 2 rows |
| F10 | Toggle add | Not in wishlist | 200, added |
| F11 | Toggle remove | Already in wishlist | 200, removed |
| F12 | Toggle no duplicates | Toggle twice on simple product | 200, 1 row only |
| F13 | Toggle variable without variant | Variable product | 422 |
| F14 | Remove simple product | DELETE /wishlists/{id} | 200, row removed |
| F15 | Remove variant item | DELETE with ?product_variant_id | 200, variant row removed |
| F16 | Remove simple from variant item | DELETE without variant on variant item | 404, variant row kept |
| F17 | In-wishlist check (guest) | No auth | 200, data=false |
| F18 | In-wishlist check (in list) | Product saved | 200, data=true |
| F19 | In-wishlist check (not in list) | Product not saved | 200, data=false |
| F20 | In-wishlist ignores other users | Saved by other user | 200, data=false |
| F21 | My-wishlists empty | No saved items | 200, empty data |
| F22 | My-wishlists products | Saved items | 200, products + meta |
| F23 | My-wishlists scoping | Other user's items | 200, current user's only |
| F24 | My-wishlists pagination | ?limit=2 | 200, 2 items, meta.total/per_page |
| F25 | show route | GET /wishlists/{id} | 405 (not registered) |
| F26 | update route | PUT /wishlists/{id} | 405 (not registered) |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Index top-level | status, message, success, data | Correct keys |
| S2 | Index data array | Flat array of WishlistResource | Products only for current user |
| S3 | Store success | 200, success=true, translated message | Correct |
| S4 | Store duplicate | 400, success=false, translated message | Correct |
| S5 | Toggle added | 200, "Added to wishlist successfully" | Correct |
| S6 | Toggle removed | 200, "Removed from wishlist successfully" | Correct |
| S7 | My-wishlists shape | data / meta / links (standard paginated) | Correct |
| S8 | My-wishlists meta | meta.total, meta.per_page | Correct values |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Store missing product_id | Empty body | 422 |
| V2 | Store nonexistent product_id | product_id=999999 | 422 |
| V3 | Store variable product without variant | requiredIf(product has variations) | 422 |
| V4 | Store variable product with invalid variant | product_variant_id not existing | 422 |

> Note: `product_variant_id` rules must NOT use `sometimes` with `Rule::requiredIf` — the combination silently skips all rules for absent fields (BUG-WISH-006).

---

## Security Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| SC1 | GET /wishlists without token | No Bearer token | 401 |
| SC2 | POST /wishlists without token | No Bearer token | 401 |
| SC3 | POST /wishlists/toggle without token | No Bearer token | 401 |
| SC4 | DELETE /wishlists/{id} without token | No Bearer token | 401 |
| SC5 | GET /my-wishlists without token | No Bearer token | 401 |
| SC6 | Index cross-user | Other user's wishlist | Not leaked to current user |
| SC7 | In-wishlist cross-user | Saved by other user | data=false for current user |
| SC8 | Destroy cross-user | Product only in other user's wishlist | 404, row not deleted |
| SC9 | Remove variant without variant id | Variant item | 404, variant row kept |

---

## Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Destroy nonexistent product | product_id=999999 | 404 |
| E2 | Toggle twice on simple product | Two consecutive toggles | Item added then removed, no duplicates |
| E3 | Same product, different variants | Two variants of one product | Both saved independently |
| E4 | Guest in-wishlist | No auth | data=false, no exception |
