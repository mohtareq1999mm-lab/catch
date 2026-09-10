<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;

class CreatePromotionsTable extends Migration
{
    public function up()
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('code')->unique();
            $table->enum('type', PromotionType::getValues());
            $table->enum('type_amount', PromotionMountType::getValues());
            $table->decimal('value', 10, 2);
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->integer('required_quantity_type')->nullable();
            $table->integer('limiter')->nullable();
            $table->integer('usage')->default(0);
            $table->date('start_at')->nullable();
            $table->date('end_at')->nullable();
            $table->boolean('status')->default(true);
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('minimum_order_amount', 10, 2)->default(0);
            $table->string('apply_to')->default('specific_products');
            $table->timestamps();

            $table->index(['status', 'start_at', 'end_at'], 'promotions_validity_index');
            $table->index(['usage', 'limiter'], 'promotions_usage_limiter_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('promotions');

    }
}
