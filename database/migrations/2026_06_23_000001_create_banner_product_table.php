<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('banner_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            $table->foreign('banner_id')->references('id')->on('banners')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['banner_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_product');
    }
};
