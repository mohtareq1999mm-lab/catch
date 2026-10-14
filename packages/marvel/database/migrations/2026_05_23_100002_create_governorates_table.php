<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->boolean('is_fast_shipping_enabled')->default(false);
            $table->unique(['country_id', 'name']);
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governorates');
        Schema::table('governorates', function (Blueprint $table) {
            $table->dropIndex('country_id');
        });
    }
};