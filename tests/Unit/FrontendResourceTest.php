<?php

namespace Tests\Unit;

use App\Enums\FrontendResource;
use Tests\TestCase;

class FrontendResourceTest extends TestCase
{
    /** @test */
    public function it_has_all_expected_resources()
    {
        $values = FrontendResource::values();

        $this->assertContains('products', $values);
        $this->assertContains('categories', $values);
        $this->assertContains('brands', $values);
        $this->assertContains('flash_sales', $values);
        $this->assertContains('promotions', $values);
        $this->assertContains('settings', $values);
        $this->assertContains('coupons', $values);
        $this->assertContains('faqs', $values);
        $this->assertContains('sliders', $values);
        $this->assertContains('banners', $values);
        $this->assertContains('tags', $values);
        $this->assertContains('content_pages', $values);
        $this->assertContains('pickup_locations', $values);
        $this->assertContains('fast_shipping_settings', $values);
        $this->assertContains('sections', $values);
    }

    /** @test */
    public function it_has_exactly_fifteen_resources()
    {
        $this->assertCount(15, FrontendResource::cases());
    }

    /** @test */
    public function it_returns_plural_lowercase_values()
    {
        foreach (FrontendResource::cases() as $case) {
            $this->assertEquals(strtolower($case->value), $case->value);
        }
    }

    /** @test */
    public function it_uses_snake_case_for_multi_word_resources()
    {
        $this->assertEquals('flash_sales', FrontendResource::FLASH_SALES->value);
        $this->assertEquals('content_pages', FrontendResource::CONTENT_PAGES->value);
        $this->assertEquals('pickup_locations', FrontendResource::PICKUP_LOCATIONS->value);
        $this->assertEquals('fast_shipping_settings', FrontendResource::FAST_SHIPPING_SETTINGS->value);
    }
}
