# Test Cases - Promotion Feature

## Current Coverage

**5 test files, ~2,534 lines:**

| Test File | Lines | Focus |
|-----------|-------|-------|
| `tests/Unit/PromotionEligibilityResolverTest.php` | 244 | Unit: percentage, fixed, gift eligibility, math, minimum order |
| `tests/Feature/PromotionCrudTest.php` | 162 | Feature: CRUD, validation, auth |
| `tests/Feature/PromotionCheckoutTest.php` | 216 | Feature: checkout integration, cart resources, public listing |
| `tests/Feature/PromotionFlowTest.php` | 565 | Feature: gift variant, usage, cart modification, eligibility |
| `tests/Feature/PromotionProductionHardenTest.php` | 1,347 | Production hardening: calculations, stock, limits, checkout, regression |

---

## Existing Test Methods

### PromotionEligibilityResolverTest (Unit)

| # | Test | Description |
|---|------|-------------|
| 1 | `test_specific_products_discount_applies_to_matched_subtotal` | Percentage on specific products |
| 2 | `test_apply_to_all_applies_to_full_subtotal` | All products scope |
| 3 | `test_gift_promotion_returns_gift_items_and_no_discount` | Gift items, discount=0 |
| 4 | `test_gift_promotion_returns_only_available_gift_items` | Excludes out-of-stock gifts |
| 5 | `test_promotion_math_uses_original_line_total_not_discounted_total_price` | Math uses original price |
| 6 | `test_gift_promotion_includes_variant_payload_when_configured` | Variant in gift payload |
| 7 | `test_promotion_not_eligible_when_minimum_order_not_met` | Minimum order check |

### PromotionCrudTest (Feature)

| # | Test | Description |
|---|------|-------------|
| 1 | `test_admin_can_list_promotions` | GET /promotions → 200 |
| 2 | `test_admin_can_show_promotion` | GET /promotions/{id} → 200 |
| 3 | `test_admin_can_update_promotion` | PUT /promotions/{id} → 200 |
| 4 | `test_admin_can_delete_promotion` | DELETE /promotions/{id} → 200 |
| 5 | `test_create_validation_fails_without_required_fields` | Empty → 422 |
| 6 | `test_update_validation_fails_with_invalid_type` | Invalid type → 422 |
| 7 | `test_unauthenticated_user_cannot_create_promotion` | No auth → 401 |

### PromotionCheckoutTest (Feature)

| # | Test | Description |
|---|------|-------------|
| 1 | `test_eligible_promotions_endpoint_returns_promotions` | GET checkout/promotions |
| 2 | `test_cart_item_resource_contains_promotion_fields` | Cart item has promotion_id, discount_amount, is_gift |
| 3 | `test_cart_resource_contains_has_eligible_promotion` | Cart has has_eligible_promotion flag |
| 4 | `test_cart_resource_has_eligible_promotion_false_when_none` | False when no promotions |
| 5 | `test_public_promotion_listing_returns_active_promotions` | Public listing |
| 6 | `test_promotion_by_slug_returns_promotion` | By slug endpoint |

### PromotionFlowTest (Feature)

| # | Test | Description |
|---|------|-------------|
| 1 | `test_checkout_promotions_returns_gift_variant_payload` | Gift variant |
| 2 | `test_apply_selected_gift_promotion_reserves_variant` | Variant reservation |
| 3 | `test_gift_item_shipping_method_from_checkout_context` | Shipping context |
| 4 | `test_cart_modification_clears_promotion_data` | Cart mod clears promo |
| 5 | `test_revalidate_promotion_on_add_items_in_order` | Re-validate on add |
| 6 | `test_gift_item_defaults_to_scheduled_when_no_shipping_context` | Default shipping |
| 7 | `test_decrement_usage_decreases_count` | Usage decrement |
| 8 | `test_decrement_usage_never_goes_below_zero` | Floor at 0 |
| 9 | `test_decrement_usage_with_null_promotion_id_is_noop` | Null safety |
| 10 | `test_has_eligible_promotion_returns_true_when_eligible` | Has eligible |
| 11 | `test_has_eligible_promotion_returns_false_for_empty_cart` | Empty cart |
| 12 | `test_has_eligible_promotion_returns_false_when_no_valid_promotions` | No valid promotions |
| 13 | `test_clear_promotion_from_cart_removes_promotion_data` | Clear promotion |
| 14 | `test_apply_selected_promotion_with_null_clears_promotion` | Null clears |
| 15 | `test_increment_usage_does_not_exceed_limiter` | Usage limit |

### PromotionProductionHardenTest (Feature) — Key Coverage

| # | Test Area | Description |
|---|-----------|-------------|
| 1 | Percentage calculations | Correct discount, rounding, max cap, zero value |
| 2 | Fixed calculations | Correct amount, not exceeding total |
| 3 | Gift promotions | Creation, stock, simple gifts, variant gifts, mixed |
| 4 | Eligibility | Expired, future, disabled, product-restricted, minimum order |
| 5 | Usage limits | Enforcement, duplicate blocking, concurrency |
| 6 | Checkout integration | Promotion-before-coupon, order snapshots, client override prevention |
| 7 | Bug regression | add_items_in_order uses product_id, image field naming, schema matches model, migration null variant, simple gift inventory, variant overwrite prevention |
| 8 | Edge cases | Clear promotion, null promo ID, decrement floor, empty cart, negative prices, required quantity type |

---

## Recommended Additional Tests

### Feature Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Public promotion listing with products | `?with_product=true` flag |
| FT-002 | Public promotion by slug returns 404 | Invalid slug |
| FT-003 | Create percentage promotion with max_discount | Cap verified |
| FT-004 | Create gift promotion with multiple gift products | All synced |
| FT-005 | Create promotion with specific products | Products associated |
| FT-006 | Update promotion changes type | Type updated |
| FT-007 | Update promotion without changing images | Images preserved |
| FT-008 | Delete then create with same code | Unique constraint |
| FT-009 | Admin listing with type filter | Filtered results |

### Integration Tests

| # | Test | Description |
|---|------|-------------|
| IT-001 | Full checkout flow with percentage promotion | End-to-end |
| IT-002 | Full checkout flow with gift promotion | End-to-end |
| IT-003 | Promotion + coupon together | Stacking order |
| IT-004 | Order cancellation decrements usage | Usage counter integrity |
| IT-005 | Invoice snapshot contains promotion data | Audit trail |

### Edge Case Tests

| # | Test | Description |
|---|------|-------------|
| EC-001 | Promotion with identical start/end date | Single-day promotion |
| EC-002 | Promotion with no end date (open-ended) | Valid indefinitely |
| EC-003 | limiter = 0 (0 max uses) | Never eligible |
| EC-004 | usage exceeds limiter due to race condition | DB-level enforcement |
| EC-005 | Gift product with no variants (simple product) | Works without variant |
| EC-006 | All gift products out of stock | Empty gift eligibility |
| EC-007 | Required quantity type with huge number | Not eligible |
| EC-008 | Promotion applies to zero cart items | Not eligible |

### API Contract Tests

| # | Test | Description |
|---|------|-------------|
| CT-001 | Admin resource has all expected fields | Structure matches Resource class |
| CT-002 | Public resource omits internal fields | No code, usage, limiter, etc. |
| CT-003 | Eligible promotions response has is_eligible | Boolean field |
| CT-004 | Gift promotions include gift_items in payload | Array of GiftItem |

---

## Test Implementation Notes

```php
// Example test for percentage promotion calculation
class PromotionPercentageTest extends TestCase
{
    /** @test */
    public function test_percentage_discount_capped_at_max_discount_amount()
    {
        $promotion = Promotion::factory()->create([
            'type' => 'price',
            'type_amount' => 'percentage',
            'discount' => 50,
            'max_discount_amount' => 200,
            'apply_to' => 'all_products',
            'minimum_order_amount' => 0,
        ]);

        $cart = $this->createCartWithSubtotal(100000); // 1000 EGP in cents
        $resolver = app(PromotionEligibilityResolver::class);
        $result = $resolver->eligible($cart, collect([$promotion]), 100000);

        $this->assertCount(1, $result);
        $this->assertEquals(20000, $result->first()->discount); // capped at 200 EGP
    }

    /** @test */
    public function test_gift_promotion_excludes_out_of_stock_items()
    {
        $inStock = Product::factory()->create(['quantity' => 10]);
        $outOfStock = Product::factory()->create(['quantity' => 0]);

        $promotion = Promotion::factory()->create([
            'type_amount' => 'gift',
            'apply_to' => 'all_products',
        ]);
        $promotion->giftProducts()->attach([
            $inStock->id => ['quantity' => 1],
            $outOfStock->id => ['quantity' => 1],
        ]);

        $cart = $this->createCartWithSubtotal(50000);
        $resolver = app(PromotionEligibilityResolver::class);
        $result = $resolver->eligible($cart, collect([$promotion]), 50000);

        $this->assertCount(1, $result[0]->giftItems);
        $this->assertEquals($inStock->id, $result[0]->giftItems[0]->productId);
    }
}
```
