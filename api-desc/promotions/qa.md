# Promotion Module — QA Test Cases

## Test Files

- `tests/Feature/PromotionFlowTest.php` — 565 lines, 15 tests
- `tests/Feature/PromotionProductionHardenTest.php` — 1346 lines, extensive tests
- `tests/Unit/PromotionEligibilityResolverTest.php` — 245 lines, 7 unit tests

---

## API Functionality Tests (Admin CRUD)

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List promotions | GET /promotions returns paginated list | 200, pagination structure |
| F2 | Create promotion | POST /promotions with valid data (percentage) | 201, promotion returned |
| F3 | Create promotion with fixed rate | POST /promotions with fixed_rate type | 201, correct discount_type |
| F4 | Create promotion with gift | POST /promotions with gift type_amount | 201, gift_products synced |
| F5 | Show promotion by ID | GET /promotions/{id} | 200, promotion data |
| F6 | Update promotion | PUT /promotions/{id} with valid data | 200, updated promotion |
| F7 | Update promotion change type | PUT /promotions/{id} change type_amount | 200, type updated |
| F8 | Delete promotion | DELETE /promotions/{id} | 200, deleted |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Create without name | Missing name field | 422 |
| V2 | Create without image-desktop | Missing image file | 422 |
| V3 | Create with invalid image type | Non-image file | 422 |
| V4 | Create with invalid type | Not in price/quantity | 422 |
| V5 | Create with invalid type_amount | Not in fixed_rate/percentage/gift | 422 |
| V6 | Create gift without gift_products | type_amount=gift, no gift_products | 422 |
| V7 | Create gift with invalid product_id | Non-existent product in gift_products | 422 |
| V8 | Create percentage without max_discount_amount | type_amount=percentage, no max | 422 |
| V9 | Create with duplicate code | Existing code value | 422 or auto-generated unique |
| V10 | Create with end_at before start_at | Invalid date range | 422 |
| V11 | Update with non-existent ID | PUT /promotions/99999 | 404 |
| V12 | Create with invalid product_id in product_ids | Non-existent product | 422 |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest cannot create | No auth token | 401 |
| A2 | Guest cannot update | No auth token | 401 |
| A3 | Guest cannot delete | No auth token | 401 |
| A4 | Guest cannot view admin list | No auth token | 401 |
| A5 | User without view-promotion permission | No permission | 403 |
| A6 | User without create-promotion permission | No permission | 403 |
| A7 | User without update-promotion permission | No permission | 403 |
| A8 | User without delete-promotion permission | No permission | 403 |
| A9 | Guest can view public list | No auth | 200 |
| A10 | Guest can view public promotion by slug | No auth | 200 |

---

## Promotion Engine Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Percentage calculation | 20% off 100 EGP = 20 EGP discount | Correct amount |
| E2 | Percentage with max cap | 40% off 1000 EGP, max=200 → 200 EGP discount | Correct capped amount |
| E3 | Fixed rate discount | 50 EGP off 100 EGP = 50 EGP discount | Correct amount |
| E4 | Fixed rate capped at price | 100 EGP off 30 EGP = 30 EGP discount | Not negative |
| E5 | Gift promotion — no discount amount | type_amount=gift, discount=0 | 0 discount |
| E6 | Gift promotion — simple product | Gift product without variant | GiftItem with variantId=null |
| E7 | Gift promotion — with variant | Gift product with specific variant | GiftItem with variant payload |
| E8 | Gift promotion — out of stock variant | Variant has no available stock | Variant filtered out |
| E9 | Gift promotion — simple product out of stock | Product has no available stock | Product filtered out |
| E10 | Percentage rounding | 33.33% of 100 → 33.33 | Correct 2-decimal precision |
| E11 | Fixed rate rounding | 10.50 EGP off | Correct 2-decimal precision |

---

## Eligibility Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| L1 | Expired promotion not eligible | end_at in past | Not in valid() scope |
| L2 | Future promotion eligible | start_at in future | In valid() scope |
| L3 | Disabled promotion not eligible | status=false | Not in valid() scope |
| L4 | Usage limit reached | usage >= limiter | Not in valid() scope |
| L5 | Product-restricted promotion — matching product | Cart has the required product | Eligible |
| L6 | Product-restricted promotion — no matching product | Cart has different products | Not eligible |
| L7 | Minimum order amount not met | subtotal < minimum_order_amount | Not eligible |
| L8 | Minimum order amount met | subtotal >= minimum_order_amount | Eligible |
| L9 | Required quantity not met | cart qty < required_quantity_type | Not eligible |
| L10 | Required quantity met | cart qty >= required_quantity_type | Eligible |
| L11 | All products promotion | apply_to=all_products | All cart items matched |

---

## Checkout Integration Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| C1 | Apply promotion to cart | Select valid promotion | Cart items get promotion_id |
| C2 | Apply promotion with null (clear) | Pass null promotionId | Promotion cleared from cart |
| C3 | Apply ineligible promotion | Promotion no longer valid | Throws InvalidArgumentException |
| C4 | Apply gift promotion | Select gift promotion | Gift item added to cart, price=0 |
| C5 | Select specific gift product | Pass selectedGiftProductId | Correct gift reserved |
| C6 | Select non-existent gift product | Invalid giftProductId | Throws InvalidArgumentException |
| C7 | Clear promotion from cart | Remove applied promotion | All items promotion_id=null, discount=0 |
| C8 | Remove gift items on promotion clear | Gift items removed | Inventory released |
| C9 | Cart modification clears promotion | Update cart item quantity | Item promotion_id=null, discount=0 |
| C10 | Usage incremented on order | Place order | promotion.usage incremented |
| C11 | Usage decremented on cancel | Cancel order | promotion.usage decremented |
| C12 | Double-cancel guard | Cancel already-cancelled order | Usage not decremented twice |
| C13 | Concurrent usage increment | Two simultaneous orders | LockForUpdate prevents over-usage |
| C14 | Checkout totals with promotion | Apply promotion, get totals | Correct subtotal, discount, final |
| C15 | Checkout totals with gift | Apply gift promotion | Gift items excluded from final total |
| C16 | Eligible promotions for cart | GET /checkout/promotions | Only eligible promotions returned |

---

## Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| X1 | Empty cart promotion eligibility | No cart items | hasEligiblePromotion = false |
| X2 | Promotions with no products loaded | apply_to=specific_products, no products | Not eligible (empty scope) |
| X3 | Gift promotion — variant payload | Gift with variant attributes | Variant payload includes attributes |
| X4 | Gift promotion — original line total | Price used from `price` column not `total_price` | Correct base calculation |
| X5 | Null shipping method defaults | reserveGiftItem without shipping method | Defaults to SCHEDULED |
| X6 | Zero or negative discount | amountCents <= 0 | Returns zero discount |
| X7 | Discount exceeding subtotal | amountCents > subtotalCents | Capped to subtotal |
| X8 | Proportional allocation rounding | Uneven discount distribution | Largest remainder handles remainders |
| X9 | Empty gift items array | No available gift products | GiftOutcome with empty array |

---

## Schema Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Admin resource JSON structure | Response fields match expected | All fields present |
| S2 | Public resource JSON structure | Response fields match expected | All fields present |
| S3 | PromotionResult toArray structure | Check eligible_promotions payload | id, type, title, code, discount, gift_items |
| S4 | CheckoutTotals structure | Check promotion response | subtotal, promotionDiscount, finalTotal, promotion, giftItems |
| S5 | GiftItem toArray structure | Check gift item payload | All fields present |

---

## Missing Coverage

- [ ] Admin CRUD create/read/update/delete for promotions (not covered in existing test files — only flow and production harden tests exist)
- [ ] Validation tests for both PromotionRequest and UpdatePromotionRequest
- [ ] Authorization tests for all 4 promotion permissions
- [ ] Image upload tests (create with images, update images)
- [ ] Code auto-generation tests (ALL prefix, PRO prefix)
- [ ] Slug auto-generation and regeneration
- [ ] value/discount sync on create and update
- [ ] Product sync (replaces all, does not append)
- [ ] Gift product sync with variant validation
- [ ] Promotion observer activity logging
- [ ] Public API tests (list valid promotions, get by slug)
- [ ] Checkout API tests (eligible promotions endpoint)
- [ ] Concurrent promotion application (race conditions)
- [ ] Cart modification triggers promotion revalidation (known missing feature)
- [ ] Promotion with minimum_order_amount=0 edge case
- [ ] Promotion with limiter=null (unlimited usage) edge case
- [ ] Promotion with no date range (always valid) edge case
- [ ] Mass assignment protection test
- [ ] Translation fallback when locale not provided
