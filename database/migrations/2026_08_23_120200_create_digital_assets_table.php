<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Digital assets attached to DIGITAL products. Files live on a PRIVATE
     * disk; `path` is never exposed through APIs.
     */
    public function up(): void
    {
        Schema::create('digital_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // FILE is the only implemented type; LICENSE / ACTIVATION_CODE are reserved.
            $table->string('type', 20)->default('FILE');
            $table->string('disk', 30)->default('private');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order'], 'digital_assets_product_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_assets');
    }
};
