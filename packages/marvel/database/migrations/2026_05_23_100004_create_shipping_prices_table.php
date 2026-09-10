<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_prices');
    }
};