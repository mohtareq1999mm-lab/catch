<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Tests\TestCase;

class CategoryPivotUniqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function createCategory(): Category
    {
        return Category::create([
            'name' => ['en' => 'Test Category'],
            'slug' => 'test-category',
        ]);
    }

    private function createProduct(): Product
    {
        return Product::create([
            'name' => ['en' => 'Test Product'],
            'slug' => 'test-product',
            'price' => 100.00,
            'status' => 1,
            'in_stock' => 1,
        ]);
    }

    public function test_sync_does_not_create_duplicates(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        $category->products()->sync([$product->id]);
        $this->assertDatabaseCount('category_product', 1);

        $category->products()->sync([$product->id]);
        $this->assertDatabaseCount('category_product', 1);

        $category->products()->sync([$product->id]);
        $this->assertDatabaseCount('category_product', 1);
    }

    public function test_sync_from_product_side_does_not_create_duplicates(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        $product->categories()->sync([$category->id]);
        $this->assertDatabaseCount('category_product', 1);

        $product->categories()->sync([$category->id]);
        $this->assertDatabaseCount('category_product', 1);
    }

    public function test_direct_insert_duplicate_violates_unique_constraint(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        DB::table('category_product')->insert([
            'category_id' => $category->id,
            'product_id' => $product->id,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/UNIQUE|Integrity constraint|duplicate/i');

        DB::table('category_product')->insert([
            'category_id' => $category->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_attach_after_sync_respects_constraint(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        $category->products()->sync([$product->id]);
        $this->assertDatabaseCount('category_product', 1);

        $category->products()->sync([$product->id]);
        $this->assertDatabaseCount('category_product', 1);
    }

    public function test_different_categories_can_share_same_product(): void
    {
        $categoryA = $this->createCategory();
        $categoryA->name = ['en' => 'Category A'];
        $categoryA->slug = 'category-a';
        $categoryA->save();

        $categoryB = $this->createCategory();
        $categoryB->name = ['en' => 'Category B'];
        $categoryB->slug = 'category-b';
        $categoryB->save();

        $product = $this->createProduct();

        $categoryA->products()->sync([$product->id]);
        $categoryB->products()->sync([$product->id]);

        $this->assertDatabaseCount('category_product', 2);
    }

    public function test_category_force_delete_cascades_to_pivot(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        $category->products()->sync([$product->id]);
        $this->assertDatabaseCount('category_product', 1);

        $category->forceDelete();

        $this->assertDatabaseCount('category_product', 0);
    }

    public function test_product_force_delete_cascades_to_pivot(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        $category->products()->sync([$product->id]);
        $this->assertDatabaseCount('category_product', 1);

        $product->forceDelete();

        $this->assertDatabaseCount('category_product', 0);
    }

    public function test_multiple_product_category_assignments(): void
    {
        $category = $this->createCategory();
        $productA = $this->createProduct();
        $productB = Product::create([
            'name' => ['en' => 'Product B'],
            'slug' => 'product-b',
            'price' => 200.00,
            'status' => 1,
            'in_stock' => 1,
        ]);

        $category->products()->sync([$productA->id, $productB->id]);
        $this->assertDatabaseCount('category_product', 2);

        $category->products()->sync([$productA->id, $productB->id]);
        $this->assertDatabaseCount('category_product', 2);
    }

    public function test_soft_delete_keeps_pivot_intact(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        $category->products()->sync([$product->id]);
        $this->assertDatabaseCount('category_product', 1);

        $category->delete();
        $this->assertDatabaseCount('category_product', 1);

        $category->restore();
        $this->assertDatabaseCount('category_product', 1);

        $product->delete();
        $this->assertDatabaseCount('category_product', 1);

        $product->restore();
        $this->assertDatabaseCount('category_product', 1);
    }
}
