<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Http\Resources\Product\ProductResource;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;

class ProductCurrencyTest extends CurrencyTestCase
{
    private function makeProduct(float $price = 100.0, string $type = 'simple'): Product
    {
        return Product::create([
            'name' => 'Currency Product',
            'slug' => 'currency-product-' . Str::uuid(),
            'price' => $price,
            'product_type' => $type,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'in_stock' => true,
            'status' => true,
        ]);
    }

    private function resourceArray(Product $product): array
    {
        return ProductResource::make($product)->toArray(request());
    }

    /** @test */
    public function catalog_price_is_preserved_when_base_equals_catalog(): void
    {
        $this->seedCurrencyData();
        $product = $this->makeProduct(100.0);

        $data = $this->resourceArray($product);

        $this->assertSame(100.0, $data['price']);
        $this->assertSame(100.0, $data['current_price']);
        $this->assertArrayNotHasKey('converted_current_price', $data);
        $this->assertSame('USD', $data['currency']['code']);
    }

/** @test */
    public function current_price_is_converted_to_the_effective_currency(): void
    {
        $this->seedCurrencyData();

        $this->createCustomerWithCurrencyPreference('KWD');

        $product = $this->makeProduct(100.0);

        $data = $this->resourceArray($product);

        $this->assertSame(22.1, $data['price']);
        $this->assertSame(22.1, $data['current_price']);
        $this->assertArrayNotHasKey('converted_current_price', $data);
        $this->assertSame('KWD', $data['currency']['code']);
    }

    /** @test */
    public function currency_metadata_includes_translations_and_icon(): void
    {
        $this->seedCurrencyData();

        $product = $this->makeProduct(100.0);

        $data = $this->resourceArray($product);

        $this->assertSame('USD', $data['currency']['code']);
        $this->assertSame(['en' => 'USD Currency', 'ar' => 'USD'], $data['currency']['name']);
        $this->assertSame(['en' => '$', 'ar' => '$'], $data['currency']['symbol']);
        $this->assertSame('usd', $data['currency']['icon']);
        $this->assertArrayHasKey('id', $data['currency']);
    }

/** @test */
    public function variant_prices_are_converted_alongside_the_product(): void
    {
        $this->seedCurrencyData();

        $this->createCustomerWithCurrencyPreference('KWD');

        $product = $this->makeProduct(100.0, ProductType::VARIABLE);
        ProductVariant::create(['product_id' => $product->id, 'price' => 100.0, 'quantity' => 5]);
        $product->load('variations');

        $data = $this->resourceArray($product);

        $this->assertCount(1, $data['variants']);
        $variant = $data['variants'][0];

        $this->assertSame(22.1, $variant['price']);
        $this->assertSame(22.1, $variant['current_price']);
        $this->assertArrayNotHasKey('converted_current_price', $variant);
    }

    /** @test */
    public function product_without_price_returns_null_conversion(): void
    {
        $this->seedCurrencyData();

        $product = $this->makeProduct(0.0);

        $product->update(['price' => null]);

        $data = $this->resourceArray($product->fresh());

        $this->assertNull($data['current_price']);
        $this->assertArrayNotHasKey('converted_current_price', $data);
    }

/** @test */
    public function fixed_rate_discount_amount_is_converted_to_the_effective_currency(): void
    {
        $this->seedCurrencyData();

        $this->createCustomerWithCurrencyPreference('KWD');

        $product = $this->makeProduct(100.0);
        $product->update([
            'has_discount' => true,
            'discount_type' => DiscountType::FIXED_RATE,
            'discount_amount' => 20.0,
        ]);

        $data = $this->resourceArray($product->fresh());

        $this->assertSame(22.1, $data['price']);
        $this->assertSame(4.42, $data['discount_amount']);
    }

/** @test */
    public function percentage_discount_amount_is_not_converted_to_money(): void
    {
        $this->seedCurrencyData();

        $this->createCustomerWithCurrencyPreference('KWD');

        $product = $this->makeProduct(100.0);
        $product->update([
            'has_discount' => true,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 20.0,
        ]);

        $data = $this->resourceArray($product->fresh());

        $this->assertSame(20.0, $data['discount_amount']);
    }

/** @test */
    public function conversion_updates_when_the_exchange_rate_changes(): void
    {
        $this->seedCurrencyData();

        $this->createCustomerWithCurrencyPreference('KWD');

        $product = $this->makeProduct(100.0);
        $this->assertSame(22.1, $this->resourceArray($product)['current_price']);

        \App\Models\CurrencyRate::query()
            ->whereHas('currency', fn ($query) => $query->where('code', 'KWD'))
            ->whereDate('effective_date', now()->toDateString())
            ->update(['exchange_rate' => '0.2500000000']);

        app()->forgetInstance(CurrencyService::class);

        $this->assertSame(25.0, $this->resourceArray($product->fresh())['current_price']);
    }
}
