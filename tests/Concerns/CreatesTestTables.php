<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesTestTables
{
    protected function createAllTestTables(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
            $table->decimal('minimum_order_amount', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone_code')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->constrained('governorates')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone_number')->nullable();
            $table->string('type')->default('user');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
        });

Schema::create('social_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index('expires_at');
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency_code', 3)->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->json('name');
            $table->json('symbol')->nullable();
            $table->json('country_name')->nullable();
            $table->string('numeric_code', 3)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->decimal('exchange_rate', 20, 10);
            $table->date('effective_date');
            $table->timestamps();
            $table->unique(['currency_id', 'effective_date']);
            $table->index('effective_date');
        });


        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('status', 30)->default('publish');
            $table->boolean('in_stock')->default(true);
            $table->integer('quantity')->default(0);
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
            $table->string('item_type')->default('PHYSICAL');
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->unsignedInteger('pieces')->nullable();
            $table->integer('in_flash_sale')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->timestamps();
        });

Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('details')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('level')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['category_id', 'product_id'], 'cat_prod_unique');
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->text('name');
            $table->string('slug');
            $table->text('details')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('brand_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('in_stock')->default(true);
            $table->string('height')->nullable();
            $table->string('width')->nullable();
            $table->string('length')->nullable();
            $table->string('weight')->nullable();
            $table->timestamps();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('banner_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('sliders', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('slug');
            $table->integer('order');
            $table->boolean('status')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('slider_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slider_id')->constrained('sliders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->string('event')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('discount_type');
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('status')->default(true);
            $table->integer('limiter')->nullable();
            $table->integer('used')->default(0);
            $table->timestamps();
        });

        Schema::create('coupon_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['coupon_id', 'user_id']);
        });

        Schema::create('coupon_assignment_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_assignment_id')->constrained('coupon_assignments')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();

            $table->index('coupon_assignment_id');
            $table->index('created_at');
            $table->index(['coupon_assignment_id', 'created_at']);
        });

        Schema::create('coupon_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->timestamp('reserved_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['coupon_id', 'expires_at']);
            $table->unique(['order_id']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name')->default('web');
            $table->string('display_name')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index('model_id');
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
            $table->index('model_id');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->boolean('is_fast_shipping_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->constrained('governorates')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('estimated_days')->nullable();
            $table->decimal('free_shipping_over', 10, 2)->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->unique('governorate_id');
        });

        Schema::create('pickup_locations', function (Blueprint $table) {
            $table->id();
            $table->string('store_name');
            $table->text('address');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->json('working_hours')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('display_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('coupon')->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('reserved_quantity')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->text('attributes')->nullable();
            $table->string('shipping_method')->default('SCHEDULED');
            $table->boolean('is_gift')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->string('name')->nullable();
            $table->string('user_phone')->nullable();
            $table->string('user_email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('shipping_method')->default('SCHEDULED');
            $table->string('fulfillment_type', 20)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_gateway', 50)->nullable();
            $table->string('status')->default('pending');
            $table->string('payment_status')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->boolean('coupon_consumed')->default(false);
            $table->boolean('promotion_consumed')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            // Production parity: per-order FX snapshot columns (see the
            // 2026_08 currency migrations). converted_total_price is the
            // order total denominated in the store base currency.
            $table->string('currency_code', 3)->nullable();
            $table->string('base_currency_code', 3)->nullable();
            $table->string('catalog_currency_code', 3)->nullable();
            $table->decimal('currency_rate', 20, 10)->nullable();
            $table->date('currency_rate_date')->nullable();
            $table->decimal('converted_total_price', 10, 3)->nullable();
            $table->decimal('shipping_price', 10, 2)->nullable();
            $table->string('coupon')->nullable();
            $table->decimal('coupon_discount', 10, 2)->nullable();
            $table->string('coupon_discount_type')->nullable();
            $table->decimal('coupon_discount_max_amount', 10, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->string('promotion_type')->nullable();
            $table->decimal('promotion_discount', 10, 2)->nullable();
            $table->timestamp('expected_delivery_at')->nullable();
            $table->decimal('fast_shipping_fee', 10, 2)->default(0);
            $table->unsignedBigInteger('pickup_location_id')->nullable();
            $table->string('pickup_location_name')->nullable();
            $table->text('pickup_location_address')->nullable();
            $table->string('pickup_location_phone')->nullable();
            $table->string('pickup_location_coordinates')->nullable();
            $table->timestamp('inventory_restored_at')->nullable();
                                    $table->string('inventory_state', 16)->default('none');
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->index(['status', 'reservation_expires_at']);
            $table->timestamps();
            $table->softDeletes();
        });

        // Partial unique index for pending order race protection (production: 2026_08_31_130000)
        // SQLite supports WHERE clause partial indexes.
        try {
            \Illuminate\Support\Facades\DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_user_pending_unique ON orders(user_id) WHERE status = 'pending'");
        } catch (\Throwable $e) {
            // Index creation failed on this engine — log but don't block tests
            \Illuminate\Support\Facades\Log::warning('Could not create pending order unique index in test DB: '.$e->getMessage());
        }

        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_sku')->nullable();
            $table->text('attributes')->nullable();
            $table->integer('product_quantity')->default(1);
            $table->decimal('product_price', 10, 2)->default(0);
            $table->decimal('product_total_price', 10, 2)->default(0);
            $table->decimal('product_discount_price', 10, 2)->nullable();
            $table->decimal('product_flash_sale_price', 10, 2)->nullable();
            $table->boolean('is_gift')->default(false);
            $table->string('item_type', 16)->default('PHYSICAL');
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->decimal('promotion_discount_amount', 10, 2)->default(0);
            $table->timestamps();
        });

        // W8 queue-standardization parity: database queue driver tables so
        // runtime queue-routing tests can dispatch and inspect real jobs.
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // W8 parity: device tokens exist in production (2026_08_23_073810)
        // and are swept by notification/listener paths.
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('client', 32);
            $table->string('platform', 16)->default('android');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'client']);
        });

        Schema::create('digital_assets', function (Blueprint $table) {            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 20)->default('FILE');
            $table->string('disk', 30)->default('private');
            $table->string('path')->nullable();
            $table->text('external_url')->nullable();
            $table->string('original_name');
            $table->string('display_name')->nullable();
            $table->string('mime', 100);
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->text('secret')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Mirrors production migration contract (W3).
            $table->index(['product_id', 'sort_order'], 'digital_assets_product_sort_idx');
            $table->index(['product_id', 'status'], 'digital_assets_product_status_idx');
        });

        Schema::create('digital_entitlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_product_id')->unique()->constrained('order_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('download_limit')->default(5);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('digital_license_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->text('encrypted_key');
            $table->string('status', 20)->default('available');
            $table->foreignId('allocated_entitlement_id')
                ->nullable()
                ->constrained('digital_entitlements')
                ->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status'], 'digital_license_keys_asset_status_idx');
            $table->index('allocated_entitlement_id', 'digital_license_keys_allocation_idx');
        });

        Schema::create('digital_asset_entitlement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_entitlement_id')->constrained('digital_entitlements')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->timestamp('granted_at')->useCurrent();
        });

        Schema::create('digital_download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_id')->constrained('digital_entitlements')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->string('ua_hash', 64)->nullable();
            $table->timestamp('downloaded_at');
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('invoice_id')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->string('gateway_transaction_id', 255)->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('qr_code_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->text('name');
            $table->string('language', 10)->default('en');
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->text('value');
            $table->string('language', 10)->default('en');
            $table->timestamps();
        });

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('status')->default(true);
            $table->string('type', 30)->default('percentage');
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
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

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('rating')->default(0);
            $table->text('comment')->nullable();
            $table->boolean('approved')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->string('series', 10);
            $table->year('sequence_year');
            $table->bigInteger('last_sequence')->unsigned()->default(0);
            $table->timestamps();
            $table->primary(['series', 'sequence_year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->after('id');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('correction_to_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('invoice_number', 50);
            $table->string('invoice_series', 10)->default('INV');
            $table->bigInteger('sequence_number')->unsigned();
            $table->year('sequence_year');
            $table->decimal('subtotal', 10, 3)->default(0);
            $table->decimal('shipping_price', 10, 3)->default(0);
            $table->decimal('coupon_discount', 10, 3)->default(0);
            $table->decimal('promotion_discount', 10, 3)->default(0);
            $table->decimal('total_discount', 10, 3)->default(0);
            $table->decimal('total', 10, 3)->default(0);
            $table->decimal('amount_paid', 10, 3)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_gateway', 50)->nullable();
            $table->string('status', 20)->default('generated');
            $table->json('data');
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('verification_hash', 64)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamp('pdf_regenerated_at')->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->string('pdf_checksum', 64)->nullable();
            $table->tinyInteger('generation_attempts')->unsigned()->default(0);
            $table->text('last_generation_error')->nullable();
            $table->boolean('is_correction')->default(false);
            $table->string('correction_reason', 500)->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('generated_at')->useCurrent();
            $table->string('generated_by', 50)->nullable()->default('system');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->unsignedSmallInteger('verify_count')->default(0);
            $table->timestamps();
            $table->index('order_id', 'idx_invoices_order_id');
            $table->unique('invoice_number', 'uq_invoices_invoice_number');
            $table->index('user_id', 'idx_invoices_user_id');
            $table->index('status', 'idx_invoices_status');
        });

        if (!Schema::hasTable('invoice_timeline')) {
            Schema::create('invoice_timeline', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('event', 50);
                $table->string('old_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->string('actor_type')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();
                $table->index('invoice_id');
                $table->index('event');
                $table->index(['actor_type', 'actor_id']);
            });
        }

        if (!Schema::hasTable('credit_notes')) {
            Schema::create('credit_notes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('credit_note_number', 50);
                $table->string('credit_note_series', 10)->default('CN');
                $table->bigInteger('sequence_number')->unsigned();
                $table->year('sequence_year');
                $table->string('reason', 500);
                $table->string('type', 30)->default('refund');
                $table->decimal('amount', 10, 3)->default(0);
                $table->string('currency', 3)->default('EGP');
                $table->foreignId('refund_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('line_items')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('issued_at')->useCurrent();
                $table->timestamps();
                $table->unique('credit_note_number', 'uq_credit_notes_number');
                $table->index('invoice_id');
            });
        }

        if (!Schema::hasTable('debit_notes')) {
            Schema::create('debit_notes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('debit_note_number', 50);
                $table->string('debit_note_series', 10)->default('DN');
                $table->bigInteger('sequence_number')->unsigned();
                $table->year('sequence_year');
                $table->string('reason', 500);
                $table->string('type', 30)->default('adjustment');
                $table->decimal('amount', 10, 3)->default(0);
                $table->string('currency', 3)->default('EGP');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('line_items')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('issued_at')->useCurrent();
                $table->timestamps();
                $table->unique('debit_note_number', 'uq_debit_notes_number');
                $table->index('invoice_id');
            });
        }

        if (!Schema::hasTable('shipments')) {
            Schema::create('shipments', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('tracking_number', 100)->nullable();
                $table->string('courier', 100)->nullable();
                $table->string('status', 30)->default('pending');
                $table->string('shipping_method', 30)->nullable();
                $table->decimal('shipping_cost', 10, 2)->default(0);
                $table->string('currency', 3)->default('EGP');
                $table->json('origin_address')->nullable();
                $table->json('destination_address')->nullable();
                $table->json('items')->nullable();
                $table->decimal('total_weight', 10, 2)->nullable();
                $table->string('weight_unit', 10)->default('kg');
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('estimated_delivery_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index('order_id');
                $table->index('status');
                $table->index('tracking_number');
            });
        }

        Schema::create('attribute_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['attribute_value_id', 'product_variant_id'], 'attribute_product_value_variant_unique');
            $table->index(['product_variant_id', 'attribute_value_id'], 'idx_attr_prod_variant_value');
        });

        Schema::create('coupon_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['coupon_id', 'product_id']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id', 'user_id']);
            $table->unique(['coupon_id', 'user_id']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('code')->unique();
            $table->string('type');
            $table->string('type_amount');
            $table->decimal('value', 10, 2);
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->integer('required_quantity_type')->nullable();
            $table->decimal('minimum_order_amount', 10, 2)->default(0);
            $table->string('apply_to')->default('specific_products');
            $table->integer('limiter')->nullable();
            $table->integer('usage')->default(0);
            $table->date('start_at')->nullable();
            $table->date('end_at')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->index(['status', 'start_at', 'end_at'], 'promotions_validity_index');
            $table->index(['usage', 'limiter'], 'promotions_usage_limiter_index');
        });

        Schema::create('promotion_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['promotion_id', 'product_id']);
        });

        Schema::create('promotion_gift_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['promotion_id', 'product_id']);
            $table->index('product_id');
            $table->index('product_variant_id');
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

        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Payment reconciliation results (dashboard/reconciliation endpoint).
        Schema::create('payment_reconciliation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->foreignId('order_id')->constrained('orders');
            $table->string('gateway', 50);
            $table->string('mismatch_type', 50);
            $table->text('expected_value')->nullable();
            $table->text('actual_value')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index('mismatch_type');
            $table->index('resolved_at');
        });
    }
}
