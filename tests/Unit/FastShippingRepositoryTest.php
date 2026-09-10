<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Settings;
use Marvel\Database\Repositories\FastShippingRepository;
use Tests\TestCase;

class FastShippingRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private FastShippingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->createTestTables();

        $this->repository = app(FastShippingRepository::class);
    }

    private function createTestTables(): void
    {
        if (Schema::hasTable('settings')) {
            return;
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->boolean('is_fast_shipping_enabled')->default(true);
            $table->timestamps();
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
            $table->string('product_type')->default('simple');
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /** @test */
    public function validate_checkout_returns_translated_error_when_disabled()
    {
        Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);

        $governorate = Governorate::create([
            'country_id' => 1,
            'name' => 'Cairo',
            'status' => true,
            'is_fast_shipping_enabled' => true,
        ]);

        $errors = $this->repository->validateCheckout($governorate, new Collection());

        $this->assertNotEmpty($errors);
        $this->assertEquals('Fast shipping is not available at this time.', $errors[0]);
    }

    /** @test */
    public function validate_checkout_returns_translated_error_for_empty_cart()
    {
        Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);

        Settings::create([
            'language' => 'en',
            'options' => [
                'fast_shipping' => [
                    'enabled' => true,
                    'duration_minutes' => 120,
                    'fee' => 25,
                    'start_hour' => '00:00',
                    'end_hour' => '23:59',
                ],
            ],
        ]);

        $governorate = Governorate::create([
            'country_id' => 1,
            'name' => 'Cairo',
            'status' => true,
            'is_fast_shipping_enabled' => true,
        ]);

        $errors = $this->repository->validateCheckout($governorate, new Collection());

        $this->assertNotEmpty($errors);
        $this->assertEquals('Cart is empty', end($errors));
    }

    // ========== Cache tests ==========

    /** @test */
    public function fast_shipping_settings_use_cache()
    {
        Settings::create([
            'language' => 'en',
            'options' => [
                'fast_shipping' => [
                    'enabled' => true,
                    'duration_minutes' => 120,
                    'fee' => 25,
                    'start_hour' => '08:00',
                    'end_hour' => '22:00',
                ],
            ],
        ]);

        Cache::forget('fast_shipping_settings');

        $this->assertFalse(Cache::has('fast_shipping_settings'));

        $firstCall = $this->repository->getSettings();

        $this->assertTrue(Cache::has('fast_shipping_settings'));
        $this->assertTrue($firstCall['enabled']);
        $this->assertEquals(25, $firstCall['fee']);

        $settings = Settings::first();
        $options = $settings->options;
        $options['fast_shipping']['fee'] = 50;
        $settings->update(['options' => $options]);

        $secondCall = $this->repository->getSettings();

        $this->assertEquals(25, $secondCall['fee'], 'Should return cached value (25), not DB value (50)');
    }

    /** @test */
    public function fast_shipping_settings_cache_invalidated_after_update()
    {
        Settings::create([
            'language' => 'en',
            'options' => [
                'fast_shipping' => [
                    'enabled' => true,
                    'duration_minutes' => 120,
                    'fee' => 25,
                    'start_hour' => '08:00',
                    'end_hour' => '22:00',
                ],
            ],
        ]);

        Cache::forget('fast_shipping_settings');

        $this->repository->getSettings();
        $this->assertTrue(Cache::has('fast_shipping_settings'));

        $this->repository->updateSettings([
            'fee' => 50,
        ]);

        $this->assertFalse(Cache::has('fast_shipping_settings'), 'Cache should be cleared after update');

        $fresh = $this->repository->getSettings();
        $this->assertEquals(50, $fresh['fee']);
    }

    /** @test */
    public function settings_update_is_transaction_safe()
    {
        Settings::create([
            'language' => 'en',
            'options' => [
                'fast_shipping' => [
                    'enabled' => true,
                    'duration_minutes' => 120,
                    'fee' => 25,
                    'start_hour' => '08:00',
                    'end_hour' => '22:00',
                ],
            ],
        ]);

        $result = $this->repository->updateSettings([
            'enabled' => false,
            'fee' => 30,
        ]);

        $this->assertFalse($result->options['fast_shipping']['enabled']);
        $this->assertEquals(30, $result->options['fast_shipping']['fee']);

        $this->assertArrayHasKey('duration_minutes', $result->options['fast_shipping']);
        $this->assertArrayHasKey('start_hour', $result->options['fast_shipping']);
        $this->assertArrayHasKey('end_hour', $result->options['fast_shipping']);
    }
}
