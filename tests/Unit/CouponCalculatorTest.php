<?php

namespace Tests\Unit;

use App\Services\Coupon\CouponCalculator;
use Marvel\Database\Models\Coupon;
use Marvel\Enums\DiscountType;
use Tests\TestCase;

class CouponCalculatorTest extends TestCase
{
    public function test_percentage_discount(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::PERCENTAGE;
        $coupon->discount = 10;
        $coupon->max_discount_amount = null;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertSame(10.0, $result['discountAmount']);
        $this->assertSame(90.0, $result['finalPrice']);
        $this->assertSame(DiscountType::PERCENTAGE, $result['discountType']);
        $this->assertFalse($result['freeShipping']);
    }

    public function test_percentage_discount_with_max_cap(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::PERCENTAGE;
        $coupon->discount = 50;
        $coupon->max_discount_amount = 20.0;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertSame(20.0, $result['discountAmount']);
        $this->assertSame(80.0, $result['finalPrice']);
    }

    public function test_percentage_discount_respects_max_cap_when_discount_is_lower(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::PERCENTAGE;
        $coupon->discount = 10;
        $coupon->max_discount_amount = 50.0;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertSame(10.0, $result['discountAmount']);
        $this->assertSame(90.0, $result['finalPrice']);
    }

    public function test_fixed_rate_discount(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::FIXED_RATE;
        $coupon->discount = 15;
        $coupon->max_discount_amount = null;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertSame(15.0, $result['discountAmount']);
        $this->assertSame(85.0, $result['finalPrice']);
    }

    public function test_zero_discount_returns_same_price(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::PERCENTAGE;
        $coupon->discount = 0;
        $coupon->max_discount_amount = null;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertSame(0.0, $result['discountAmount']);
        $this->assertSame(100.0, $result['finalPrice']);
    }

    public function test_discount_cannot_make_price_negative(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::FIXED_RATE;
        $coupon->discount = 200;
        $coupon->max_discount_amount = null;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertSame(100.0, $result['discountAmount'], 'Fixed discount capped to price');
        $this->assertSame(0.0, $result['finalPrice']);
    }

    public function test_free_shipping_is_always_false(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::PERCENTAGE;
        $coupon->discount = 10;
        $coupon->max_discount_amount = null;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertFalse($result['freeShipping']);

        $coupon->discount_type = DiscountType::FIXED_RATE;
        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertFalse($result['freeShipping']);
    }

    public function test_free_shipping_returns_same_price(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::FREE_SHIPPING;
        $coupon->discount = 0;
        $coupon->max_discount_amount = null;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertSame(0.0, $result['discountAmount']);
        $this->assertSame(100.0, $result['finalPrice']);
        $this->assertSame(DiscountType::FREE_SHIPPING, $result['discountType']);
        $this->assertTrue($result['freeShipping']);
    }

    public function test_free_shipping_with_high_discount_value(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::FREE_SHIPPING;
        $coupon->discount = 999;
        $coupon->max_discount_amount = null;

        $result = CouponCalculator::calculate($coupon, 100.0);

        $this->assertSame(0.0, $result['discountAmount']);
        $this->assertSame(100.0, $result['finalPrice']);
        $this->assertTrue($result['freeShipping']);
    }

    public function test_returns_expected_structure(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::PERCENTAGE;
        $coupon->discount = 10;
        $coupon->max_discount_amount = null;

        $result = CouponCalculator::calculate($coupon, 50.0);

        $this->assertArrayHasKey('discountAmount', $result);
        $this->assertArrayHasKey('finalPrice', $result);
        $this->assertArrayHasKey('discountType', $result);
        $this->assertArrayHasKey('freeShipping', $result);
    }
}
