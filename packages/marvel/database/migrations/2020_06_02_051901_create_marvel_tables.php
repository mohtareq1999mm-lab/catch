<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingType;

class CreateMarvelTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Schema::create('shipping_classes', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('name');
        //     $table->double('amount');
        //     $table->string('is_global')->default(true);
        //     $table->enum('type', ShippingType::getValues())->default(ShippingType::FIXED);
        //     $table->timestamps();
        // });
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('slug');
            $table->enum('discount_type', DiscountType::getValues())->nullable();
            $table->decimal('discount', 8, 3)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('limiter')->nullable();
            $table->integer('used')->default(0);
            $table->boolean('status')->default(true);
            $table->string('border_color')->nullable();
            $table->boolean('borderless')->default(false);
            $table->timestamps();
        });


        // Schema::create('types', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('name');
        //     $table->string('slug');
        //     $table->string('icon')->nullable();
        //     $table->json('promotional_sliders')->nullable();
        //     $table->json('images')->nullable();
        //     $table->timestamps();
        // });

        // Schema::create('authors', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('name');
        //     $table->boolean('is_approved')->default(false);
        //     $table->json('image')->nullable();
        //     $table->json('cover_image')->nullable();
        //     $table->string('slug');
        //     $table->text('bio')->nullable();
        //     $table->text('quote')->nullable();
        //     $table->string('born')->nullable();
        //     $table->string('death')->nullable();
        //     $table->string('languages')->nullable();
        //     $table->json('socials')->nullable();
        //     $table->timestamps();
        // });

        // Schema::create('manufacturers', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('name');
        //     $table->boolean('is_approved')->default(false);
        //     $table->json('image')->nullable();
        //     $table->json('cover_image')->nullable();
        //     $table->string('slug');
        //     $table->unsignedBigInteger('type_id');
        //     $table->foreign('type_id')->references('id')->on('types')->onDelete('cascade');
        //     $table->text('description')->nullable();
        //     $table->string('website')->nullable();
        //     $table->json('socials')->nullable();
        //     $table->timestamps();
        // });
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->integer('order');
            $table->text('description')->nullable();
            $table->boolean('status')->default(false);
            $table->softDeletes();
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

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('in_stock')->default(true);
            $table->boolean('status')->default(false);
            $table->enum('product_type', ProductType::getValues())->default(ProductType::SIMPLE);
            $table->string('height')->nullable();
            $table->string('width')->nullable();
            $table->string('length')->nullable();
            $table->string('weight')->nullable();
            $table->integer('pieces')->default(1);
            $table->boolean('has_flash_sale')->default(false);
            $table->boolean('has_discount')->default(false);
            $table->enum('discount_type', DiscountType::getValues())->default(DiscountType::PERCENTAGE);
            $table->double('discount_amount', 10, 2)->default(0);
            $table->boolean('discount_status')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('price_after_discount', 10, 2)->nullable();
            $table->decimal('price_after_flash_sale', 10, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->boolean('is_fast_shipping_available')->default(false);
            $table->integer('in_flash_sale')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index('price');
            $table->index('sold_quantity');
            $table->index('name');
            $table->index('slug');
            $table->index('sku');
            $table->index('is_fast_shipping_available');
            $table->index(['status', 'deleted_at', 'price'], 'idx_products_status_deleted_price');
            $table->index('height', 'idx_products_height');
            $table->index('width', 'idx_products_width');
            $table->index('length', 'idx_products_length');
            $table->index('weight', 'idx_products_weight');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->nullable()->unique();
            $table->double('price', 10, 2);
            $table->double('sale_price', 10, 2)->nullable();
            $table->boolean('in_stock')->default(true);
            $table->integer('quantity')->default(0);
            $table->string('height')->nullable();
            $table->string('width')->nullable();
            $table->string('weight')->nullable();
            $table->string('length')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->timestamps();

            $table->index('product_id');
            $table->index('sku');
            $table->index('price', 'idx_variants_price');
            $table->index('height', 'idx_variants_height');
            $table->index('width', 'idx_variants_width');
            $table->index('length', 'idx_variants_length');
            $table->index('weight', 'idx_variants_weight');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('user_phone');
            $table->string('user_email');
            $table->json('address');
            $table->string('notes')->nullable();
            $table->decimal('shipping_price', 8, 3)->nullable();
            $table->decimal('total_price', 8, 3);
            $table->decimal('price', 8, 3);
            $table->string('coupon')->nullable();
            $table->decimal('coupon_discount', 10, 3)->nullable();
            $table->string('coupon_discount_type')->nullable();
            $table->decimal('coupon_discount_max_amount', 10, 3)->nullable();
            $table->enum('status', ['pending', 'completed', 'delivered', 'cancelled'])->default('pending');
            $table->enum('shipping_method', ['SCHEDULED', 'FAST'])->default('SCHEDULED');
            $table->dateTime('expected_delivery_at')->nullable();
            $table->decimal('fast_shipping_fee', 12, 2)->default(0);
            $table->string('fulfillment_type', 20)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_gateway', 50)->nullable();
            $table->unsignedBigInteger('pickup_location_id')->nullable();
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->string('pickup_location_name')->nullable();
            $table->text('pickup_location_address')->nullable();
            $table->string('pickup_location_phone')->nullable();
            $table->string('pickup_location_coordinates')->nullable();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('promotion_code')->nullable();
            $table->string('promotion_type')->nullable();
            $table->decimal('promotion_discount', 10, 3)->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'orders_user_id_created_at_index');
            $table->index('status', 'orders_status_index');
            $table->index('shipping_method');
        });

        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('product_sku')->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->json('attributes')->nullable();
            $table->integer('product_quantity');
            $table->decimal('product_price', 8, 3);
            $table->decimal('product_total_price', 8, 3);
            $table->decimal('product_discount_price', 10, 3)->nullable();
            $table->decimal('product_flash_sale_price', 10, 3)->nullable();
            $table->decimal('promotion_discount_amount', 10, 2)->default(0);
            $table->boolean('is_gift')->default(false);
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->timestamps();

            $table->index('order_id', 'order_products_order_id_index');
            $table->index(['order_id', 'is_gift'], 'order_products_order_gift_index');
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->integer('invoice_id');
            $table->bigInteger('user_id');
            $table->string('payment_method');
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->string('gateway_transaction_id', 255)->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('qr_code_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index('status', 'txn_status_idx');
            $table->index('uuid', 'txn_uuid_idx');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug');
            $table->text('details')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete();
            $table->boolean('status')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('level')->default(1)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->unique(['category_id', 'product_id'], 'cat_prod_unique');
            $table->index(['product_id', 'category_id'], 'idx_cat_prod_product_category');
            $table->index(['category_id', 'product_id'], 'idx_cat_prod_category_product');
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('coupon')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->enum('status', ['active', 'expired', 'checked_out'])->default('active');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unique('user_id');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });


        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->unsignedBigInteger('attribute_id');
            $table->foreign('attribute_id')->references('id')->on('attributes')->onDelete('cascade');
            $table->string('value');
            $table->timestamps();

            $table->unique(['attribute_id', 'slug'], 'attribute_values_attribute_id_slug_unique');
        });


        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->integer('quantity');
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->json('attributes')->nullable();
            $table->integer('reserved_quantity')->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('shipping_method', 20)->default('scheduled');
            $table->boolean('is_gift')->default(false);
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->timestamps();

            $table->index(['cart_id', 'product_id', 'product_variant_id']);
            $table->index(['cart_id', 'is_gift'], 'cart_items_cart_gift_index');
        });

        Schema::create('attribute_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attribute_value_id');
            $table->foreign('attribute_value_id')->references('id')->on('attribute_values')->onDelete('cascade');
            $table->unsignedBigInteger('product_variant_id');
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['attribute_value_id', 'product_variant_id'], 'attribute_product_value_variant_unique');
            $table->index(['product_variant_id', 'attribute_value_id'], 'idx_attr_prod_variant_value');
            $table->index(['attribute_value_id', 'product_variant_id'], 'idx_attr_prod_value_variant');
        });


        // Schema::create('tax_classes', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('country')->nullable();
        //     $table->string('state')->nullable();
        //     $table->string('zip')->nullable();
        //     $table->string('city')->nullable();
        //     $table->double('rate');
        //     $table->string('name')->nullable();
        //     $table->integer('is_global')->nullable();
        //     $table->integer('priority')->nullable();
        //     $table->boolean('on_shipping')->default(1);
        //     $table->timestamps();
        // });

        Schema::create('address', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->json('address');
            $table->json('location')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->foreign('customer_id')->references('id')->on('users');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name');
            $table->text('site_desc')->nullable();
            $table->text('meta_desc')->nullable();
            $table->string('site_copy_right')->nullable();
            $table->string('logo')->nullable();
            $table->string('footer_logo')->nullable();
            $table->string('favicon')->nullable();
            $table->string('site_email')->nullable();
            $table->string('email_support')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('promotion_video_url')->nullable();
            $table->string('youtube')->nullable();
            $table->string('tiktok')->nullable();
            $table->string('snapchat')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('minimum_order_amount', 10, 2)->default(50);
            $table->boolean('fast_shipping_page_publish')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->json('avatar')->nullable();
            $table->text('bio')->nullable();
            $table->json('socials')->nullable();
            $table->string('contact')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->foreign('customer_id')->references('id')->on('users');
            $table->timestamps();
        });



        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('url')->default('');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Schema::dropIfExists('shipping_classes');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('types');
        Schema::dropIfExists('products');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attribute_product');
        // Schema::dropIfExists('tax_classes');
        Schema::dropIfExists('address');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('attachments');
        // Schema::dropIfExists('authors');
        // Schema::dropIfExists('manufacturers');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('sliders');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('cart_items');
    }
}
