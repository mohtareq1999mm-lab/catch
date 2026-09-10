<?php

namespace Tests\Feature;

use App\Services\General\HomeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Repositories\FlashSaleRepository;
use Marvel\Enums\FlashSaleType;
use Tests\TestCase;

class PricingCacheInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    private FlashSaleRepository $flashSaleRepository;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Config::set('scout.driver', 'null');

        if (!Schema::hasTable('flash_sales')) {
            $this->createAllTables();
        }

        $this->flashSaleRepository = app(FlashSaleRepository::class);
    }

    private function createAllTables(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('type')->default('user');
            $table->boolean('is_active')->default(true);
            $table->string('phone_number')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->boolean('in_stock')->default(true);
            $table->integer('stock_quantity')->default(10);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('is_fast_shipping_available')->default(false);
            $table->boolean('has_discount')->default(false);
            $table->boolean('has_flash_sale')->default(false);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->boolean('discount_status')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->decimal('price_after_discount', 10, 2)->nullable();
            $table->decimal('price_after_flash_sale', 10, 2)->nullable();
            $table->string('product_type')->default('simple');
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->string('type')->default('percentage');
            $table->boolean('status')->default(true);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->integer('sold')->default(0);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flash_sale_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained('flash_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description')->nullable();
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->string('event')->nullable()->index();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->text('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function test_clear_cache_removes_all_home_pricing_keys(): void
    {
        $keys = [
            'home:home-flash-sales',
            'home:home-discount-products-end-today',
            'home:home-flash-sale-products',
            'home:home-weekly-products',
            'home:home-all-discount-products',
            'home:home-flash-sales-after-9',
        ];

        foreach ($keys as $key) {
            Cache::put($key, 'stale-data-' . $key, 120);
            $this->assertTrue(Cache::has($key), "Key $key should exist before clear");
        }

        HomeService::clearCache();

        foreach ($keys as $key) {
            $this->assertFalse(Cache::has($key), "Key $key should be removed after clearCache()");
        }
    }

    public function test_flash_sale_store_clears_cache(): void
    {
        Cache::put('home-flash-sale-products', 'stale', 120);
        Cache::put('home-flash-sales', 'stale', 120);
        $this->assertTrue(Cache::has('home-flash-sale-products'));

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . Str::uuid(),
            'price' => 100,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
        ]);

        $request = new \Illuminate\Http\Request([
            'title' => 'Test Flash Sale',
            'slug' => 'test-flash-sale-' . Str::uuid(),
            'type' => FlashSaleType::PERCENTAGE,
            'start_date' => Carbon::now()->subHour(),
            'end_date' => Carbon::now()->addDay(),
            'status' => true,
            'discount' => 20,
            'products' => [$product->id],
        ]);

        $this->flashSaleRepository->storeFlashSale($request);

        $this->assertFalse(Cache::has('home-flash-sale-products'));
        $this->assertFalse(Cache::has('home-flash-sales'));
        $product = Product::with(['flash_sales' => fn($q) => $q->valid()])->find($product->id);
        $this->assertEquals(80.0, (float) $product->current_price);
        $this->assertTrue((bool) $product->has_flash_sale);
    }

    public function test_flash_sale_update_clears_cache(): void
    {
        $flashSale = FlashSale::create([
            'title' => 'Old Sale',
            'slug' => 'old-sale-' . Str::uuid(),
            'type' => FlashSaleType::PERCENTAGE,
            'start_date' => Carbon::now()->subHour(),
            'end_date' => Carbon::now()->addDay(),
            'status' => true,
            'discount' => 10,
        ]);

        Cache::put('home-flash-sale-products', 'stale', 120);
        Cache::put('home-flash-sales', 'stale', 120);

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . Str::uuid(),
            'price' => 100,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'has_flash_sale' => true,
        ]);

        $flashSale->products()->attach($product->id);

        $request = new \Illuminate\Http\Request([
            'title' => 'Updated Sale',
            'type' => FlashSaleType::PERCENTAGE,
            'start_date' => Carbon::now()->subHour(),
            'end_date' => Carbon::now()->addDay(),
            'status' => true,
            'discount' => 25,
            'products' => [$product->id],
        ]);

        $this->flashSaleRepository->updateFlashSale($request, $flashSale->id);

        $this->assertFalse(Cache::has('home-flash-sale-products'));
        $this->assertFalse(Cache::has('home-flash-sales'));
        $product = Product::with(['flash_sales' => fn($q) => $q->valid()])->find($product->id);
        $this->assertEquals(75.0, (float) $product->current_price);
    }

    public function test_flash_sale_delete_resets_product_and_clears_cache(): void
    {
        $product = Product::create([
            'name' => 'Flash Sale Product',
            'slug' => 'flash-product-' . Str::uuid(),
            'price' => 100,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'has_flash_sale' => true,
            'price_after_flash_sale' => 80,
        ]);

        $flashSale = FlashSale::create([
            'title' => 'Sale To Delete',
            'slug' => 'delete-sale-' . Str::uuid(),
            'type' => FlashSaleType::PERCENTAGE,
            'start_date' => Carbon::now()->subHour(),
            'end_date' => Carbon::now()->addDay(),
            'status' => true,
            'discount' => 20,
        ]);

        $flashSale->products()->attach($product->id);

        Cache::put('home-flash-sale-products', 'stale', 120);
        Cache::put('home-flash-sales', 'stale', 120);

        $this->flashSaleRepository->deleteFlashSale($flashSale->id);

        $this->assertFalse(Cache::has('home-flash-sale-products'));
        $this->assertFalse(Cache::has('home-flash-sales'));

        $product = Product::with(['flash_sales' => fn($q) => $q->valid()])->find($product->id);
        $this->assertEquals(100.0, (float) $product->current_price);
        $this->assertFalse((bool) $product->has_flash_sale);
    }

    public function test_flash_sale_delete_with_no_products(): void
    {
        $flashSale = FlashSale::create([
            'title' => 'Empty Sale',
            'slug' => 'empty-sale-' . Str::uuid(),
            'type' => FlashSaleType::PERCENTAGE,
            'start_date' => Carbon::now()->subHour(),
            'end_date' => Carbon::now()->addDay(),
            'status' => true,
            'discount' => 20,
        ]);

        Cache::put('home-flash-sale-products', 'stale', 120);

        $result = $this->flashSaleRepository->deleteFlashSale($flashSale->id);

        $this->assertTrue($result);
        $this->assertFalse(Cache::has('home-flash-sale-products'));
        $this->assertNull(FlashSale::find($flashSale->id));
    }

    public function test_inactive_flash_sale_sets_product_price_to_null(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . Str::uuid(),
            'price' => 100,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'has_flash_sale' => true,
        ]);

        $flashSale = FlashSale::create([
            'title' => 'Inactive Sale',
            'slug' => 'inactive-sale-' . Str::uuid(),
            'type' => FlashSaleType::PERCENTAGE,
            'start_date' => Carbon::now()->subHour(),
            'end_date' => Carbon::now()->addDay(),
            'status' => true,
            'discount' => 20,
        ]);

        $flashSale->products()->attach($product->id);

        $request = new \Illuminate\Http\Request([
            'title' => 'Inactive Sale',
            'type' => FlashSaleType::PERCENTAGE,
            'start_date' => Carbon::now()->subHour(),
            'end_date' => Carbon::now()->addDay(),
            'status' => false,
            'discount' => 20,
            'products' => [$product->id],
        ]);

        $this->flashSaleRepository->updateFlashSale($request, $flashSale->id);

        $product->refresh();
        $this->assertNull($product->price_after_flash_sale);
    }
}
