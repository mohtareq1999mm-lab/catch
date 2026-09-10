# Cart Module — QA Test Cases

> Source of truth: `CartController.php`, `CartRepository.php`, `CartInventoryService.php`, `CartCreateRequest.php`, `CartUpdateRequest.php`, `CartResource.php` (verified on 2026-08-04, Revision 4).

## Test Files

- `tests/Feature/CartApiTest.php` — 80 test methods
- `tests/Feature/CartExpirationTest.php` — 8 test methods

> **Run status (2026-08-04):** suite could not execute in the audit session — bootstrap error `Class "Role" not found` at `Routes.php:699`. Last recorded pass: 61/65 (CartApiTest) + 8/8 (CartExpirationTest). Re-verify after fixing the test-bootstrap issue.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List carts (empty) | GET /cart with no cart | 200, empty data |
| F2 | List carts (with items) | GET /cart with existing cart | 200, cart with items |
| F3 | Add item (simple product) | POST /cart with valid product | 201, item created |
| F4 | Add item (variant product) | POST /cart with variant_id | 201, variant item created |
| F5 | Add item (FAST shipping) | POST /cart with FAST | 201, item in fast_items |
| F6 | Add same product twice | POST /cart twice, same product+variant+shipping | 201, quantity accumulated |
| F7 | Show cart by ID | GET /cart/1 | 200, cart data |
| F8 | Update item quantity | PUT /update-item with operation increment | 200, quantity updated |
| F9 | Decrement item quantity | PUT /update-item with operation decrement | 200, quantity reduced |
| F10 | Delete single item | DELETE /delete-item/1 | 200, item removed |
| F11 | Clear entire cart | DELETE /delete-items (no coupon) | 200, cart empty |
| F12 | Bulk add items | POST /bulk-items with 2+ valid items | 201, all added |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Add without product_id | Missing required field | 422 |
| V2 | Add with zero quantity | quantity = 0 | 422 |
| V3 | Add with negative quantity | quantity = -1 | 422 |
| V4 | Add with invalid product_id | Non-existent product | 422 |
| V5 | Add with invalid shipping_method | Wrong string | 422 |
| V6 | Add with non-existent variant_id | Invalid variant | 422 |
| V7 | Add variable product without variant_id | `product_type=variable`, no variant | **400** `INVALID_ITEM_DATA` (runtime, not 422) |
| V8 | Update without `operation` | Missing required operation | **422** |
| V9 | Update with invalid `operation` | e.g., `operation=replace` | **422** |
| V10 | Update with zero quantity | quantity = 0 | 422 |
| V11 | Bulk add with empty items array | items = [] | 422 (`required|array` — empty array passes `array`; add `min:1` if desired) |
| V12 | Bulk add missing product_id in one item | Partial invalid data | 422 (whole request) |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest cannot list carts | No auth token | 401 |
| A2 | Guest cannot add item | No auth token | 401 |
| A3 | Guest cannot update item | No auth token | 401 |
| A4 | Guest cannot delete item | No auth token | 401 |
| A5 | Guest cannot clear cart | No auth token | 401 |
| A6 | User cannot view another user's cart | GET /cart/{other_id} | 403 |
| A7 | User cannot delete another user's item | Ownership mismatch | 400 |
| A8 | Rate limit exceeded | >20 req/min | 429 |

---

## Business Logic Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| B1 | Add item reserves inventory | Check `reserved_quantity` incremented | Stock reserved |
| B2 | Delete item releases inventory | Check `reserved_quantity` decremented | Stock released |
| B3 | Increment quantity reserves more | operation=increment | Delta reserved |
| B4 | Decrement quantity releases excess | operation=decrement | Delta released |
| B5 | Decrement to < 1 deletes item | operation=decrement removes last | Item deleted, stock released |
| B6 | Add item with quantity > stock | Qty exceeds available | 400 stock exceeded |
| B7 | Increment to > stock | Delta exceeds available | 400 |
| B8 | Clear cart releases all inventory | All stock released | Stock correct |
| B9 | Cart total recalculated after operations | Add/update/delete | Total matches sum |
| B10 | Same product, different shipping = separate items | SCHEDULED + FAST | 2 lines |
| B11 | Update preserves shipping when omitted | No shipping_method in PUT | Existing method kept |
| B12 | Delete last item clears coupon | Last item deleted | Coupon null |
| B13 | One cart per user | Second add reuses existing cart row | Same cart id |

---

## Shipping Method Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Lowercase shipping_method normalized | `scheduled` → `SCHEDULED` | Stored uppercase |
| S2 | FAST on non-eligible product | `is_fast_shipping_available=false` | **400** `FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE` |
| S3 | Items in correct section | SCHEDULED → normal_items, FAST → fast_items | Correct split |
| S4 | Bulk shipping_method optional | omitted → SCHEDULED | Default applied |

---

## Variant Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| VR1 | Add variable product with variant_id | Valid variant | 201 |
| VR2 | Add variable product without variant_id | Missing variant | 400 `INVALID_ITEM_DATA` |
| VR3 | Same product, different variants | Two variants of same product | Separate lines |
| VR4 | Update variant item | Quantity change on variant | 200 |
| VR5 | Delete variant item | Remove variant line | Variant stock released |
| VR6 | Gift item reserved | `reserveGiftItem` price = 0 | is_gift=true, price 0 |

---

## Coupon Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| C1 | Apply coupon to cart | Valid coupon code (via coupons/add-to-cart) | Coupon stored |
| C2 | Clear cart with coupon without confirm | Coupon applied, no confirm | **200 + success:true** + warning message |
| C3 | Clear cart with coupon with confirm | `{"confirm": true}` | 200, cart cleared |
| C4 | Delete last item clears coupon | Last item removed | Coupon null |
| C5 | Coupon discount computed | `coupon_discount`, `total_after_coupon` | Correct math (round 2 dp) |

---

## Expiration Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Cart expires after TTL | `expires_at` passed | status = expired, items deleted |
| E2 | Cart within TTL not expired | `expires_at` in future | status = active |
| E3 | Stock released on expiry | Expired cart | Reserved stock freed |
| E4 | Add item to expired cart creates fresh | Old cart expired | Fresh cart + reservation |
| E5 | Cart without expires_at skipped | null TTL | Not expired |

---

## Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| EC1 | Show cart with non-existent ID | GET /cart/99999 | 404 |
| EC2 | Show another user's cart | Wrong owner | 403 |
| EC3 | Delete non-existent item | DELETE /delete-item/99999 | 400 |
| EC4 | Clear cart when no cart exists | No cart | 404 |
| EC5 | Product soft-deleted after add | `CartItem::product()` uses withTrashed | product null in response |
| EC6 | Coupon deleted after applied | No Coupon row for code | coupon null in response |
| EC7 | Gift items | price=0, is_gift=true | Correct structure |
| EC8 | Stock consistency add/remove cycles | Multiple cycles | Stock returns to original |
| EC9 | Stock consistency after quantity updates | Increase/decrease | Stock matches expected |
| EC10 | Cart with no items | All items deleted | total_items=0, total_price=0 |
| EC11 | Update non-existent cart item | PUT for product not in cart | Creates new line (set mode) |
| EC12 | All-fail bulk add | Every item fails | 201, `cart` null, failed_items full |
| EC13 | Reserved boundary | desired == available | 201 succeeds |
| EC14 | Concurrency: two users same stock | Reservations respected | No oversell |

---

## Performance / Concurrency Checks

- [ ] Bulk add of 50 items → no N+1 explosion on the final cart serialization (per-item storeCart does its own transactions — expect 50 small transactions)
- [ ] Cart list with many lines → eager loads prevent product/variant N+1; thumbnail media still triggers 1 query per line (`getFirstMediaUrl`)
- [ ] Concurrent add + expire → row locks serialize; verify no double-release
- [ ] Concurrent finalize → second caller fails cleanly (no double deduct)

## Security Checks

- [ ] Ownership enforced on show (403), delete-item (400), destroy (400)
- [ ] Auth required on all 7 routes (401)
- [ ] Rate limit enforced (429)
- [ ] Mass-assignment safe (fillable guarded; `promotion_id`/`discount_amount` not settable by client via requests)
- [ ] Soft-deleted product filtering in bulk (no ghost adds)
- [ ] No sensitive data leakage (resource exposes cart-owned fields only)

---

## Missing Coverage

- [ ] **Concurrent add (race)** — two simultaneous requests for same product (partially covered by `concurrent_users_increment_respects_available_stock`)
- [ ] **Concurrent finalize** — two simultaneous checkouts
- [ ] **FAST ineligibility via API** — direct POST /cart assertion (logic verified in source only)
- [ ] **Price re-validation at checkout** — BUG-CART-003 regression
- [ ] **Finalize-by-shipping preserves other group** — regression for BUG-CART-002 (current tests assert delete behavior)
- [ ] **Expired/checked_out cart expire guard** — regression for BUG-CART-007
- [ ] **Max quantity overflow** — BUG-CART-009 boundary
- [ ] **Very large bulk payload** — memory/transaction behavior
