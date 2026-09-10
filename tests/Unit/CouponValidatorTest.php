<?php

namespace Tests\Unit;

use App\Services\Coupon\CouponValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

class CouponValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_coupon(): void
    {
        $coupon = Coupon::create([
            'code' => 'VALID10',
            'slug' => 'valid-10',
            'name' => 'Valid Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'limiter' => null,
            'used' => 0,
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['reason']);
        $this->assertNull($result['message']);
        $this->assertSame($coupon->id, $result['coupon']->id);
    }

    public function test_disabled_coupon(): void
    {
        $coupon = Coupon::create([
            'slug' => 'disabled',
            'name' => 'Disabled Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => false,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertFalse($result['valid']);
        $this->assertSame('disabled', $result['reason']);
        $this->assertSame(__('coupon.disabled'), $result['message']);
    }

    public function test_expired_coupon(): void
    {
        $coupon = Coupon::create([
            'code' => 'EXPIRED',
            'slug' => 'expired',
            'name' => 'Expired Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['reason']);
        $this->assertSame(__('coupon.expired'), $result['message']);
    }

    public function test_not_yet_active(): void
    {
        $coupon = Coupon::create([
            'code' => 'FUTURE',
            'slug' => 'future',
            'name' => 'Future Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->addWeek(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertFalse($result['valid']);
        $this->assertSame('not_active', $result['reason']);
        $this->assertSame(__('coupon.not_yet_active'), $result['message']);
    }

    public function test_usage_limit_reached(): void
    {
        $coupon = Coupon::create([
            'code' => 'LIMITED',
            'slug' => 'limited',
            'name' => 'Limited Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'limiter' => 5,
            'used' => 5,
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertFalse($result['valid']);
        $this->assertSame('usage_limit_reached', $result['reason']);
        $this->assertSame(__('coupon.usage_limit_reached'), $result['message']);
    }

    public function test_null_limiter_allows_unlimited_usage(): void
    {
        $coupon = Coupon::create([
            'code' => 'UNLIMITED',
            'slug' => 'unlimited',
            'name' => 'Unlimited Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'limiter' => null,
            'used' => 999,
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertTrue($result['valid']);
    }

    public function test_already_used_by_user(): void
    {
        $user = User::factory()->create(['type' => 'user']);
        $coupon = Coupon::create([
            'code' => 'USED',
            'slug' => 'used',
            'name' => 'Used Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'used_at' => now(),
        ]);

        $result = CouponValidator::validate($coupon, $user);

        $this->assertFalse($result['valid']);
        $this->assertSame('already_used', $result['reason']);
        $this->assertSame(__('coupon.already_used'), $result['message']);
    }

    public function test_not_used_by_different_user(): void
    {
        $userWhoUsed = User::factory()->create(['type' => 'user']);
        $currentUser = User::factory()->create(['type' => 'user']);
        $coupon = Coupon::create([
            'code' => 'OTHERS',
            'slug' => 'others',
            'name' => 'Others Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userWhoUsed->id,
            'used_at' => now(),
        ]);

        $result = CouponValidator::validate($coupon, $currentUser);

        $this->assertTrue($result['valid']);
    }

    public function test_product_restriction_not_met(): void
    {
        $coupon = Coupon::create([
            'code' => 'RESTRICTED',
            'slug' => 'restricted',
            'name' => 'Restricted Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $allowedProduct = Product::create([
            'name' => 'Allowed Product',
            'slug' => 'allowed-product',
            'price' => 100,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        $cartProduct = Product::create([
            'name' => 'Cart Product',
            'slug' => 'cart-product',
            'price' => 50,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        $coupon->products()->attach($allowedProduct->id);

        $items = collect([
            (object) ['product_id' => $cartProduct->id],
        ]);

        $result = CouponValidator::validate($coupon, null, $items);

        $this->assertFalse($result['valid']);
        $this->assertSame('product_not_eligible', $result['reason']);
        $this->assertSame(__('coupon.product_not_eligible'), $result['message']);
    }

    public function test_product_restriction_met(): void
    {
        $coupon = Coupon::create([
            'code' => 'MATCH',
            'slug' => 'match',
            'name' => 'Match Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $product = Product::create([
            'name' => 'Product',
            'slug' => 'product',
            'price' => 100,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        $coupon->products()->attach($product->id);

        $items = collect([
            (object) ['product_id' => $product->id],
        ]);

        $result = CouponValidator::validate($coupon, null, $items);

        $this->assertTrue($result['valid']);
    }

    public function test_product_restriction_skipped_when_no_items(): void
    {
        $coupon = Coupon::create([
            'code' => 'SKIP',
            'slug' => 'skip',
            'name' => 'Skip Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $product = Product::create([
            'name' => 'Product',
            'slug' => 'product',
            'price' => 100,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        $coupon->products()->attach($product->id);

        $result = CouponValidator::validate($coupon, null, null);

        $this->assertTrue($result['valid']);
    }

    public function test_product_restriction_skipped_when_empty_items(): void
    {
        $coupon = Coupon::create([
            'code' => 'EMPTY',
            'slug' => 'empty',
            'name' => 'Empty Items',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $product = Product::create([
            'name' => 'Product',
            'slug' => 'product',
            'price' => 100,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        $coupon->products()->attach($product->id);

        $result = CouponValidator::validate($coupon, null, collect([]));

        $this->assertTrue($result['valid']);
    }

    public function test_validateByCode_not_found(): void
    {
        $result = CouponValidator::validateByCode('NONEXISTENT');

        $this->assertFalse($result['valid']);
        $this->assertSame('not_found', $result['reason']);
        $this->assertSame(__('coupon.not_found'), $result['message']);
        $this->assertNull($result['coupon']);
    }

    public function test_validateByCode_valid(): void
    {
        $coupon = Coupon::create([
            'slug' => 'bycode',
            'name' => 'By Code',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $coupon->update(['code' => 'BYCODE']);

        $result = CouponValidator::validateByCode('BYCODE');

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['coupon']);
        $this->assertSame('BYCODE', $result['coupon']->code);
    }

    public function test_free_shipping_passes_validation(): void
    {
        $coupon = Coupon::create([
            'slug' => 'freeship',
            'name' => 'Free Shipping',
            'discount_type' => 'free_shipping',
            'discount' => 0,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertTrue($result['valid']);
        $this->assertSame('free_shipping', $result['coupon']->discount_type);
    }

    public function test_returns_expected_structure_for_valid(): void
    {
        $coupon = Coupon::create([
            'code' => 'STRUCT',
            'slug' => 'struct',
            'name' => 'Struct',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('coupon', $result);
    }

    public function test_returns_expected_structure_for_invalid(): void
    {
        $coupon = Coupon::create([
            'slug' => 'invstruct',
            'name' => 'Invalid Struct',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => false,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponValidator::validate($coupon);

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('coupon', $result);
        $this->assertNull($result['coupon']);
    }
}
