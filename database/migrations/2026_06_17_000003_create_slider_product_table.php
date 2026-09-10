<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slider_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('slider_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            $table->foreign('slider_id')->references('id')->on('sliders')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['slider_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slider_product');
    }
};
