<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class CategoryProductsCountConsistencyTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1/general';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        if (!Schema::hasTable('products')) {
            $this->createAllTestTables();
        }
    }

    public function test_products_count_matches_products_array_length(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Test Category'],
            'slug' => 'test-category',
        ]);

        $normalProduct = Product::create([
            'name' => ['en' => 'Normal Product'],
            'slug' => 'normal-product',
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => false,
        ]);

        $fastProductA = Product::create([
            'name' => ['en' => 'Fast Product A'],
            'slug' => 'fast-product-a',
            'price' => 150.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 5,
            'is_fast_shipping_available' => true,
        ]);

        $fastProductB = Product::create([
            'name' => ['en' => 'Fast Product B'],
            'slug' => 'fast-product-b',
            'price' => 200.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 3,
            'is_fast_shipping_available' => true,
        ]);

        $category->products()->attach([
            $normalProduct->id,
            $fastProductA->id,
            $fastProductB->id,
        ]);

        $response = $this->getJson(self::PREFIX . '/categories/test-category');

        $response->assertOk();
        $response->assertJsonPath('data.products_count', 1);

        $products = $response->json('data.products');
        $this->assertCount(1, $products);
        $this->assertEquals($normalProduct->id, $products[0]['id']);
    }

    public function test_products_count_matches_when_all_products_qualify(): void
    {
        $category = Category::create([
            'name' => ['en' => 'All Normal'],
            'slug' => 'all-normal',
        ]);

        $productA = Product::create([
            'name' => ['en' => 'Product A'],
            'slug' => 'product-a',
            'price' => 50.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => false,
        ]);

        $productB = Product::create([
            'name' => ['en' => 'Product B'],
            'slug' => 'product-b',
            'price' => 75.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 5,
            'is_fast_shipping_available' => false,
        ]);

        $category->products()->attach([$productA->id, $productB->id]);

        $response = $this->getJson(self::PREFIX . '/categories/all-normal');

        $response->assertOk();
        $response->assertJsonPath('data.products_count', 2);

        $products = $response->json('data.products');
        $this->assertCount(2, $products);
    }

    public function test_products_count_is_zero_when_all_products_are_fast_shipping_only(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Only Fast'],
            'slug' => 'only-fast',
        ]);

        $fastProduct = Product::create([
            'name' => ['en' => 'Fast Only'],
            'slug' => 'fast-only',
            'price' => 300.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => true,
        ]);

        $category->products()->attach([$fastProduct->id]);

        $response = $this->getJson(self::PREFIX . '/categories/only-fast');

        $response->assertOk();
        $response->assertJsonPath('data.products_count', 0);
        $this->assertArrayNotHasKey('products', $response->json('data'));
    }

    public function test_products_count_is_zero_when_no_products_attached(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Empty'],
            'slug' => 'empty',
        ]);

        $response = $this->getJson(self::PREFIX . '/categories/empty');

        $response->assertOk();
        $response->assertJsonPath('data.products_count', 0);
        $this->assertArrayNotHasKey('products', $response->json('data'));
    }
}
