<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Tests\TestCase;

class ProductFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_endpoint_filters_listings_accurately()
    {
        $brandApple = Brand::create(['name' => ['en' => 'Apple'], 'status' => 1]);
        $brandSamsung = Brand::create(['name' => ['en' => 'Samsung'], 'status' => 1]);

        $sizeAttr = Attribute::create(['name' => ['en' => 'Size'], 'slug' => 'size']);
        $colorAttr = Attribute::create(['name' => ['en' => 'Color'], 'slug' => 'color']);

        $sizeSmall = AttributeValue::create(['value' => ['en' => 'Small'], 'slug' => 'small', 'attribute_id' => $sizeAttr->id]);
        $sizeLarge = AttributeValue::create(['value' => ['en' => 'Large'], 'slug' => 'large', 'attribute_id' => $sizeAttr->id]);
        $colorRed = AttributeValue::create(['value' => ['en' => 'Red'], 'slug' => 'red', 'attribute_id' => $colorAttr->id]);

        // Product A: Apple, Small, Red, Price 100, Weight 10
        $productA = Product::create([
            'name' => ['en' => 'Product A'],
            'slug' => 'product-a',
            'price' => 100.00,
            'status' => 1,
            'in_stock' => 1,
            'weight' => 10.0,
        ]);
        $productA->brands()->attach($brandApple->id);
        $variantA = ProductVariant::create(['product_id' => $productA->id, 'price' => 100.00, 'quantity' => 5]);
        $variantA->attributeProducts()->create(['attribute_value_id' => $sizeSmall->id]);
        $variantA->attributeProducts()->create(['attribute_value_id' => $colorRed->id]);

        // Product B: Samsung, Large, Red, Price 300, Weight 20
        $productB = Product::create([
            'name' => ['en' => 'Product B'],
            'slug' => 'product-b',
            'price' => 300.00,
            'status' => 1,
            'in_stock' => 1,
            'weight' => 20.0,
        ]);
        $productB->brands()->attach($brandSamsung->id);
        $variantB = ProductVariant::create(['product_id' => $productB->id, 'price' => 300.00, 'quantity' => 5]);
        $variantB->attributeProducts()->create(['attribute_value_id' => $sizeLarge->id]);
        $variantB->attributeProducts()->create(['attribute_value_id' => $colorRed->id]);

        // 1. Filter by brand (corrected URL path prefix to /api/v1/general)
        $response = $this->getJson('/api/v1/general/products?brand=apple');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Product A', $response->json('data.data.0.name'));
        
        // 2. Filter by size (attribute)
        $response = $this->getJson('/api/v1/general/products?size=large');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Product B', $response->json('data.data.0.name'));

        // 3. Filter by price range
        $response = $this->getJson('/api/v1/general/products?minPrice=150&maxPrice=350');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Product B', $response->json('data.data.0.name'));

        // 4. Combined brand & size
        $response = $this->getJson('/api/v1/general/products?brand=samsung&size=large');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Product B', $response->json('data.data.0.name'));

        // 5. Combined brand & non-matching size
        $response = $this->getJson('/api/v1/general/products?brand=apple&size=large');
        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));

        // 6. Filter by dimension column (weight)
        $response = $this->getJson('/api/v1/general/products?weight=20');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Product B', $response->json('data.data.0.name'));

        // 7. Assert that single product response does NOT include per-product filters (detail view)
        $response = $this->getJson('/api/v1/general/products/' . $productA->slug);
        $response->assertOk();
        $this->assertArrayNotHasKey('filters', $response->json('data'));
    }

    public function test_attribute_filter_works_with_any_locale_value()
    {
        app()->setLocale('en');
        $colorAttr = Attribute::create(['name' => ['en' => 'Color'], 'slug' => 'color']);
        $sizeAttr = Attribute::create(['name' => ['en' => 'Size'], 'slug' => 'lmk-s']);

        $colorRed = AttributeValue::create(['value' => ['en' => 'Red', 'ar' => 'أحمر'], 'slug' => 'red', 'attribute_id' => $colorAttr->id]);
        $sizeSmall = AttributeValue::create(['value' => ['en' => 'Small', 'ar' => 'صغير'], 'slug' => 'small', 'attribute_id' => $sizeAttr->id]);

        $product = Product::create([
            'name' => ['en' => 'Test Product'],
            'slug' => 'test-product',
            'price' => 100.00,
            'status' => 1,
            'in_stock' => 1,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'price' => 100.00, 'quantity' => 5]);
        $variant->attributeProducts()->create(['attribute_value_id' => $colorRed->id]);
        $variant->attributeProducts()->create(['attribute_value_id' => $sizeSmall->id]);

        // Filter by English slug
        $response = $this->getJson('/api/v1/general/products?color=red');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));

        // Filter by English name
        $response = $this->getJson('/api/v1/general/products?color=Red');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));

        // Filter attribute with dash in slug (lmk-s) by English name
        $response = $this->getJson('/api/v1/general/products?lmk-s=Small');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));

        // Non-matching combo
        $response = $this->getJson('/api/v1/general/products?color=red&lmk-s=large');
        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }
}
