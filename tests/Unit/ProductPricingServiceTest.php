<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Enums\DiscountType;
use Marvel\Enums\FlashSaleType;
use Marvel\Services\Pricing\ProductPricingService;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ProductPricingServiceTest extends TestCase
{
    private ProductPricingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductPricingService::class);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $product = new Product();
        $product->price = $overrides['price'] ?? 100.0;
        $product->has_discount = $overrides['has_discount'] ?? false;
        $product->discount_type = $overrides['discount_type'] ?? DiscountType::PERCENTAGE;
        $product->discount_amount = $overrides['discount_amount'] ?? 0;
        $product->discount_status = $overrides['discount_status'] ?? null;
        $product->has_flash_sale = $overrides['has_flash_sale'] ?? false;
        $product->start_date = $overrides['start_date'] ?? null;
        $product->end_date = $overrides['end_date'] ?? null;
        return $product;
    }

    private function makeFlashSale(array $overrides = []): FlashSale
    {
        $flashSale = new FlashSale();
        $flashSale->type = $overrides['type'] ?? FlashSaleType::PERCENTAGE;
        $flashSale->discount = $overrides['discount'] ?? 10;
        $flashSale->status = $overrides['status'] ?? true;
        $flashSale->max_discount_amount = $overrides['max_discount_amount'] ?? null;
        $flashSale->start_date = $overrides['start_date'] ?? Carbon::yesterday();
        $flashSale->end_date = $overrides['end_date'] ?? Carbon::tomorrow();
        return $flashSale;
    }

    /** @test */
    public function calculates_base_price_when_no_discount_or_flash_sale(): void
    {
        $product = $this->makeProduct();

        $pricing = $this->service->calculateProductPricing($product);

        $this->assertSame(100.0, $pricing['base_price']);
        $this->assertNull($pricing['price_after_discount']);
        $this->assertNull($pricing['price_after_flash_sale']);
        $this->assertSame(100.0, $pricing['final_price']);
    }

    /** @test */
    public function calculates_discount_when_flash_sale_is_null(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 20,
        ]);

        $pricing = $this->service->calculateProductPricing($product);

        $this->assertSame(100.0, $pricing['base_price']);
        $this->assertSame(80.0, $pricing['price_after_discount']);
        $this->assertNull($pricing['price_after_flash_sale']);
        $this->assertSame(80.0, $pricing['final_price']);
    }

    /** @test */
    public function flash_sale_takes_priority_over_product_discount(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 50,
        ]);
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10,
        ]);

        $pricing = $this->service->calculateProductPricing($product, $flashSale);

        $this->assertSame(100.0, $pricing['base_price']);
        $this->assertNull($pricing['price_after_discount']);
        $this->assertSame(90.0, $pricing['price_after_flash_sale']);
        $this->assertSame(90.0, $pricing['final_price']);
    }

    /** @test */
    public function percentage_flash_sale_calculates_correctly(): void
    {
        $product = $this->makeProduct(['price' => 200.0]);
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 25,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 200.0);

        $this->assertSame(150.0, $price);
    }

    /** @test */
    public function percentage_flash_sale_respects_max_discount_cap(): void
    {
        $product = $this->makeProduct(['price' => 200.0]);
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 50,
            'max_discount_amount' => 30,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 200.0);

        $this->assertSame(170.0, $price);
    }

    /** @test */
    public function fixed_rate_flash_sale_discounts_by_fixed_amount(): void
    {
        $product = $this->makeProduct(['price' => 100.0]);
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::FIXED_RATE,
            'discount' => 25,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(75.0, $price);
    }

    /** @test */
    public function final_price_flash_sale_sets_exact_price(): void
    {
        $product = $this->makeProduct(['price' => 100.0]);
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::FINAL_PRICE,
            'discount' => 39.99,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(39.99, $price);
    }

    /** @test */
    public function final_price_flash_sale_above_base_clamps_to_base(): void
    {
        $product = $this->makeProduct(['price' => 50.0]);
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::FINAL_PRICE,
            'discount' => 999,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 50.0);

        $this->assertSame(50.0, $price);
    }

    /** @test */
    public function flash_sale_fixed_rate_discount_larger_than_price_returns_zero(): void
    {
        $product = $this->makeProduct(['price' => 30.0]);
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::FIXED_RATE,
            'discount' => 50,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 30.0);

        $this->assertSame(0.0, $price);
    }

    /** @test */
    public function expired_flash_sale_returns_null(): void
    {
        $product = $this->makeProduct(['price' => 100.0]);
        $flashSale = $this->makeFlashSale([
            'start_date' => Carbon::now()->subDays(10),
            'end_date' => Carbon::now()->subDay(),
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertNull($price);
    }

    /** @test */
    public function inactive_flash_sale_returns_null(): void
    {
        $product = $this->makeProduct(['price' => 100.0]);
        $flashSale = $this->makeFlashSale([
            'status' => false,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertNull($price);
    }

    /** @test */
    public function null_flash_sale_returns_null(): void
    {
        $price = $this->service->calculateFlashSalePrice(null, 100.0);

        $this->assertNull($price);
    }

    /** @test */
    public function product_discount_suppressed_when_flash_sale_expired_and_discount_valid(): void
    {
        $product = $this->makeProduct([
            'price' => 100.0,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 20,
        ]);
        $flashSale = $this->makeFlashSale([
            'end_date' => Carbon::yesterday(),
        ]);

        $pricing = $this->service->calculateProductPricing($product, $flashSale);

        $this->assertNull($pricing['price_after_flash_sale']);
        $this->assertSame(80.0, $pricing['price_after_discount']);
        $this->assertSame(80.0, $pricing['final_price']);
    }

    /** @test */
    public function product_discount_inactive_when_has_discount_is_false(): void
    {
        $product = $this->makeProduct(['has_discount' => false]);

        $pricing = $this->service->calculateProductPricing($product);

        $this->assertNull($pricing['price_after_discount']);
        $this->assertSame(100.0, $pricing['final_price']);
    }

    /** @test */
    public function product_discount_inactive_when_discount_status_is_false(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 20,
            'discount_status' => false,
        ]);

        $pricing = $this->service->calculateProductPricing($product);

        $this->assertNull($pricing['price_after_discount']);
        $this->assertSame(100.0, $pricing['final_price']);
    }

    /** @test */
    public function product_discount_fixed_rate_subtracts_fixed_amount(): void
    {
        $discounted = $this->service->calculateDiscountedPrice(100.0, DiscountType::FIXED_RATE, 30);

        $this->assertSame(70.0, $discounted);
    }

    /** @test */
    public function product_discount_fixed_rate_does_not_go_below_zero(): void
    {
        $discounted = $this->service->calculateDiscountedPrice(10.0, DiscountType::FIXED_RATE, 50);

        $this->assertSame(0.0, $discounted);
    }

    /** @test */
    public function product_discount_percentage_does_not_exceed_100_percent(): void
    {
        $discounted = $this->service->calculateDiscountedPrice(100.0, DiscountType::PERCENTAGE, 200);

        $this->assertSame(0.0, $discounted);
    }

    /** @test */
    public function calculate_product_current_price_uses_final_price(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
        ]);

        $currentPrice = $this->service->calculateProductCurrentPrice($product);

        $this->assertSame(90.0, $currentPrice);
    }

    /** @test */
    public function calculate_product_current_price_returns_base_when_no_pricing(): void
    {
        $product = $this->makeProduct();

        $currentPrice = $this->service->calculateProductCurrentPrice($product);

        $this->assertSame(100.0, $currentPrice);
    }

    /** @test */
    public function flash_sale_with_zero_discount_returns_base_price_for_percentage(): void
    {
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 0,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(100.0, $price);
    }

    /** @test */
    public function flash_sale_with_zero_discount_returns_base_price_for_fixed_rate(): void
    {
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::FIXED_RATE,
            'discount' => 0,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(100.0, $price);
    }

    /** @test */
    public function null_base_price_returns_null_for_flash_sale(): void
    {
        $flashSale = $this->makeFlashSale();

        $price = $this->service->calculateFlashSalePrice($flashSale, null);

        $this->assertNull($price);
    }

    /** @test */
    public function flash_sale_with_percentage_100_returns_zero(): void
    {
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 100,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(0.0, $price);
    }

    /** @test */
    public function percentage_max_discount_cap_applied_when_percentage_discount_exceeds_cap(): void
    {
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 60,
            'max_discount_amount' => 25,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(75.0, $price);
    }

    /** @test */
    public function fixed_rate_flash_sale_works_with_decimal_amounts(): void
    {
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::FIXED_RATE,
            'discount' => 15.50,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(84.50, $price);
    }

    /** @test */
    public function final_price_flash_sale_works_with_decimal_amounts(): void
    {
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::FINAL_PRICE,
            'discount' => 49.95,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(49.95, $price);
    }

    /** @test */
    public function all_three_prices_present_when_both_flash_sale_and_discount_but_flash_sale_active(): void
    {
        $product = $this->makeProduct([
            'price' => 100.0,
            'has_discount' => true,
            'discount_type' => DiscountType::FIXED_RATE,
            'discount_amount' => 40,
        ]);
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10,
        ]);

        $pricing = $this->service->calculateProductPricing($product, $flashSale);

        $this->assertSame(100.0, $pricing['base_price']);
        $this->assertNull($pricing['price_after_discount']);
        $this->assertSame(90.0, $pricing['price_after_flash_sale']);
        $this->assertSame(90.0, $pricing['final_price']);
    }

    /** @test */
    public function calculate_product_pricing_from_data_without_flash_sale(): void
    {
        $data = [
            'price' => 200.0,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 25,
        ];

        $pricing = $this->service->calculateProductPricingFromData($data);

        $this->assertSame(200.0, $pricing['base_price']);
        $this->assertSame(150.0, $pricing['price_after_discount']);
        $this->assertNull($pricing['price_after_flash_sale']);
        $this->assertSame(150.0, $pricing['final_price']);
    }

    /** @test */
    public function calculate_product_pricing_from_data_with_flash_sale(): void
    {
        $data = [
            'price' => 200.0,
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 50,
        ];
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::FIXED_RATE,
            'discount' => 30,
        ]);

        $pricing = $this->service->calculateProductPricingFromData($data, $flashSale);

        $this->assertSame(200.0, $pricing['base_price']);
        $this->assertNull($pricing['price_after_discount']);
        $this->assertSame(170.0, $pricing['price_after_flash_sale']);
        $this->assertSame(170.0, $pricing['final_price']);
    }

    /** @test */
    public function exception_in_pricing_returns_fallback_base_price(): void
    {
        $product = $this->makeProduct(['price' => 50.0]);

        $pricing = $this->service->calculateProductPricing($product, null);

        $this->assertSame(50.0, $pricing['base_price']);
        $this->assertNull($pricing['price_after_discount']);
        $this->assertNull($pricing['price_after_flash_sale']);
        $this->assertSame(50.0, $pricing['final_price']);
    }

    /** @test */
    public function flash_sale_discount_with_only_start_date_and_no_end_date(): void
    {
        $flashSale = $this->makeFlashSale([
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 15,
            'end_date' => null,
        ]);

        $price = $this->service->calculateFlashSalePrice($flashSale, 100.0);

        $this->assertSame(85.0, $price);
    }

    /** @test */
    public function empty_string_price_returns_null(): void
    {
        $price = $this->service->calculateFlashSalePrice(null, '');

        $this->assertNull($price);
    }

    /** @test */
    public function product_discount_not_applied_when_discount_status_null_and_has_discount_true(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 10,
            'discount_status' => null,
            'start_date' => null,
            'end_date' => null,
        ]);

        $pricing = $this->service->calculateProductPricing($product);

        $this->assertSame(90.0, $pricing['final_price']);
    }

    /** @test */
    public function fixed_rate_discount_with_exceptionally_large_amount_caps_to_zero(): void
    {
        $discounted = $this->service->calculateDiscountedPrice(100.0, DiscountType::FIXED_RATE, 1e9);

        $this->assertSame(0.0, $discounted);
    }

    // ─── ProductPricingService::isDiscountActive tests ─────────────────────────

    /** @test */
    public function discount_active_false_when_has_discount_is_false(): void
    {
        $product = $this->makeProduct(['has_discount' => false]);

        $this->assertFalse($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_false_when_discount_status_explicitly_disabled(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_status' => false,
        ]);

        $this->assertFalse($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_true_when_discount_status_explicitly_enabled(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_status' => true,
        ]);

        $this->assertTrue($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_false_when_start_date_is_in_the_future(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'start_date' => Carbon::now()->addDay(),
            'end_date' => Carbon::now()->addDays(10),
        ]);

        $this->assertFalse($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_false_when_end_date_has_passed(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'start_date' => Carbon::now()->subDays(10),
            'end_date' => Carbon::now()->subDay(),
        ]);

        $this->assertFalse($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_true_when_within_valid_date_range(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'start_date' => Carbon::now()->subDay(),
            'end_date' => Carbon::now()->addDay(),
        ]);

        $this->assertTrue($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_true_when_no_dates_and_has_discount_is_true(): void
    {
        $product = $this->makeProduct(['has_discount' => true]);

        $this->assertTrue($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_true_when_only_start_date_in_past_and_no_end_date(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'start_date' => Carbon::now()->subDay(),
            'end_date' => null,
        ]);

        $this->assertTrue($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_false_when_discount_status_false_overrides_dates(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_status' => false,
            'start_date' => Carbon::now()->subDay(),
            'end_date' => Carbon::now()->addDay(),
        ]);

        $this->assertFalse($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_false_when_has_discount_false_overrides_everything(): void
    {
        $product = $this->makeProduct([
            'has_discount' => false,
            'discount_status' => true,
            'start_date' => Carbon::now()->subDay(),
            'end_date' => Carbon::now()->addDay(),
        ]);

        $this->assertFalse($this->service->isDiscountActive($product));
    }

    /** @test */
    public function discount_active_false_when_null_discount_status_and_future_start_date(): void
    {
        $product = $this->makeProduct([
            'has_discount' => true,
            'discount_status' => null,
            'start_date' => Carbon::now()->addDay(),
            'end_date' => Carbon::now()->addDays(10),
        ]);

        $this->assertFalse($this->service->isDiscountActive($product));
    }

    // ─── ProductPricingService::resolveActiveFlashSale tests ───────────────────

    /** @test */
    public function flash_sale_active_false_when_has_flash_sale_is_false(): void
    {
        $product = $this->makeProduct(['has_flash_sale' => false]);

        $this->assertNull($this->service->resolveActiveFlashSale($product));
    }

    /** @test */
    public function flash_sale_active_false_when_has_flash_sale_true_but_no_active_sales(): void
    {
        $product = $this->makeProduct(['has_flash_sale' => true]);

        $this->assertNull($this->service->resolveActiveFlashSale($product));
    }

    /** @test */
    public function flash_sale_active_true_when_active_flash_sale_exists(): void
    {
        $activeFlashSale = $this->makeFlashSale();
        $service = $this->createMock(ProductPricingService::class);
        $service->method('resolveActiveFlashSale')->willReturn($activeFlashSale);
        app()->instance(ProductPricingService::class, $service);

        $product = $this->makeProduct(['has_flash_sale' => true]);

        $this->assertNotNull($service->resolveActiveFlashSale($product));
    }
}
