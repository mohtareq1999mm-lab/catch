<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Tests\TestCase;

class DimensionFilterTest extends TestCase
{
    use RefreshDatabase;

    private function createProducts(): array
    {
        $brand = Brand::create(['name' => ['en' => 'TestBrand'], 'status' => 1]);

        $productA = Product::create([
            'name' => ['en' => 'Dimension Product A'],
            'slug' => 'dim-product-a',
            'price' => 100.00,
            'product_type' => 'simple',
            'sku' => 'DIM-SKU-A',
            'status' => 1,
            'in_stock' => 1,
            'height' => 10.00,
            'width' => 20.50,
            'length' => 30.00,
            'weight' => 5.75,
        ]);
        $productA->brands()->attach($brand->id);

        $productB = Product::create([
            'name' => ['en' => 'Dimension Product B'],
            'slug' => 'dim-product-b',
            'price' => 200.00,
            'product_type' => 'simple',
            'sku' => 'DIM-SKU-B',
            'status' => 1,
            'in_stock' => 1,
            'height' => 15.00,
            'width' => 25.00,
            'length' => 35.50,
            'weight' => 10.00,
        ]);
        $productB->brands()->attach($brand->id);

        $productC = Product::create([
            'name' => ['en' => 'Dimension Product C'],
            'slug' => 'dim-product-c',
            'price' => 300.00,
            'product_type' => 'simple',
            'sku' => 'DIM-SKU-C',
            'status' => 1,
            'in_stock' => 1,
            'height' => null,
            'width' => null,
            'length' => null,
            'weight' => 0,
        ]);
        $productC->brands()->attach($brand->id);

        $productD = Product::create([
            'name' => ['en' => 'Dimension Product D'],
            'slug' => 'dim-product-d',
            'price' => 400.00,
            'product_type' => 'variable',
            'sku' => 'DIM-SKU-D',
            'status' => 1,
            'in_stock' => 1,
            'height' => 0,
            'width' => 0,
            'length' => 0,
            'weight' => 0,
        ]);
        $productD->brands()->attach($brand->id);

        $variantD = ProductVariant::create([
            'product_id' => $productD->id,
            'price' => 400.00,
            'quantity' => 5,
            'height' => '12.5',
            'width' => '22.0',
            'length' => '32.0',
            'weight' => '8.0',
        ]);


        return [
            'productA' => $productA,
            'productB' => $productB,
            'productC' => $productC,
            'productD' => $productD,
            'variantD' => $variantD,
        ];
    }

    public function test_dimension_fields_return_correct_json_type(): void
    {
        $products = $this->createProducts();

        $response = $this->getJson('/api/v1/general/products/' . $products['productA']->slug);
        $response->assertOk();

        $data = $response->json('data');

        $this->assertArrayHasKey('height', $data);
        $this->assertArrayHasKey('width', $data);
        $this->assertArrayHasKey('length', $data);
        $this->assertArrayHasKey('weight', $data);
    }

    public function test_exact_match_height_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height=10');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-a', $slugs);
    }

    public function test_exact_match_width_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?width=20.50');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-a', $slugs);
    }

    public function test_exact_match_length_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?length=30');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-a', $slugs);
    }

    public function test_exact_match_weight_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?weight=5.75');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-a', $slugs);
    }

    public function test_exact_match_zero_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?weight=0');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-c', $slugs);
        $this->assertContains('dim-product-d', $slugs);
    }

    public function test_range_height_min_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height_min=12');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-b', $slugs);
    }

    public function test_range_height_max_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height_max=12');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-a', $slugs);
        $this->assertContains('dim-product-d', $slugs);
    }

    public function test_range_height_min_max_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height_min=5&height_max=12');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-a', $slugs);
    }

    public function test_range_weight_filter(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?weight_min=5&weight_max=9');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-a', $slugs);
    }

    public function test_multiple_exact_match_dimensions(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height=15&width=25');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-b', $slugs);
    }

    public function test_combined_exact_and_range_dimensions(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height=10&weight_min=5&weight_max=6');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-a', $slugs);
    }

    public function test_dimension_filter_excludes_when_no_match(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height=99');
        $response->assertOk();
        $products = $response->json('data.data');
        $this->assertCount(0, $products);
    }

    public function test_dimension_filter_with_decimal_variant_value(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height=12.5');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertContains('dim-product-d', $slugs);
    }

    public function test_dimension_filter_with_null_values(): void
    {
        $this->createProducts();

        $response = $this->getJson('/api/v1/general/products?height=NULL');
        $response->assertOk();
        $products = $response->json('data.data');
        $slugs = collect($products)->pluck('slug')->toArray();
        $this->assertNotContains('dim-product-c', $slugs);
    }

    public function test_single_product_dimension_types(): void
    {
        $products = $this->createProducts();

        $response = $this->getJson('/api/v1/general/products/' . $products['productA']->slug);
        $response->assertOk();

        $data = $response->json('data');
        $this->assertEquals(10, $data['height']);
        $this->assertEquals(20.5, $data['width']);
        $this->assertEquals(30, $data['length']);
        $this->assertEquals(5.75, $data['weight']);
    }
}
