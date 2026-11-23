<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_representatives', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_representatives');
    }
};
