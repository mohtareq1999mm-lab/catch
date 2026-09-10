# Cart Module — QA Test Cases (Authenticated API)

## Test Files

**No existing tests for cart endpoints.**

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List carts | GET /cart | 200, paginated cart list |
| F2 | List empty cart | User with no cart | 200, empty data |
| F3 | Add simple product | POST /cart {item} | 201, cart created |
| F4 | Add variable product without variant | Variable product | 400, invalid item data |
| F5 | Add variable product with variant | {item.product_variant_id} | 201, variant in cart |
| F6 | Add stock exceeded | quantity > available_stock | 400 |
| F7 | Add fast shipping on ineligible product | Product with is_fast_shipping_available=false | 400 |
| F8 | Bulk add items | POST /cart/bulk-items | 201, all items added |
| F9 | Bulk add with one invalid | One item has invalid product_id | 400, rollback all |
| F10 | Show cart | GET /cart/{id} | 200 |
| F11 | Show another user's cart | Different user's cart id | 403 |
| F12 | Update item quantity | PUT /cart/update-item | 200 |
| F13 | Update without shipping (preserve existing) | shipping_method omitted | 200, old method kept |
| F14 | Delete single item | DELETE /cart/delete-item/{id} | 200, inventory released |
| F15 | Delete non-existent item | Invalid itemId | 400 |
| F16 | Clear cart | DELETE /cart/delete-items | 200, all inventory released |
| F17 | Clear cart with coupon (no confirm) | Coupon applied, no ?confirm | 200, warning message |
| F18 | Clear cart with coupon (confirmed) | Coupon applied, ?confirm=true | 200, cart cleared |
| F19 | Unauthenticated | No token | 401 |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Index top-level | status, message, success, data | Correct keys |
| S2 | Index pagination | page, current_page, total, per_page, etc. | Correct types |
| S3 | Cart object | id, user_id, coupon, total_price, normal_items, fast_items, etc. | Correct types |
| S4 | Cart object coupon fields | subtotal, coupon_discount, total_after_coupon, coupon_code, coupon | Correct types and values |
| S5 | CartItem object | id, product_id, quantity, price, total_price, shipping_method, product | Correct types |
| S6 | Product in item | id, name, slug, thumbnail | Correct types |
| S7 | Store response | 201, CartResource in data | Correct |
| S8 | Coupon discount zero when no coupon | No coupon applied | coupon_discount = 0, total_after_coupon = subtotal |
| S9 | Coupon discount positive with percentage coupon | 10% off coupon applied | coupon_discount = 10% of subtotal |
| S10 | Coupon discount capped by max_discount_amount | 50% off with max $10 | coupon_discount <= 10 |
| S11 | Coupon discount with fixed_rate coupon | $5 off coupon applied | coupon_discount = 5.00 |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Store without item | Missing item field | 422 |
| V2 | Store without product_id | item missing product_id | 422 |
| V3 | Store without quantity | item missing quantity | 422 |
| V4 | Store without shipping_method | Missing shipping_method | 422 |
| V5 | Store invalid shipping_method | "express" not in enum | 422 |
| V6 | Store quantity 0 | min:1 | 422 |
| V7 | Bulk items not array | items not array | 422 |
| V8 | Bulk items missing fields | Each item valdated individually | 422 |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Add then update then delete | Full lifecycle | All succeed |
| R2 | Cart TTL expiry | expires_at set to +3 days | Correct timestamp |
| R3 | Inventory release on item delete | Stock restored | Available stock increases |
| R4 | Cart total recalculated after update | Change quantity | total_price updated |
| R5 | Same product added twice (add mode) | quantity incremented | Correct total |
| R6 | Same product updated (set mode) | quantity replaced | Correct total |

---

## Security Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| SC1 | Access without token | No Bearer token | 401 |
| SC2 | Access with invalid token | Expired/random token | 401 |
| SC3 | View another user's cart | Different user_id | 403 |
| SC4 | Delete another user's item | Different user_id | 400 |
