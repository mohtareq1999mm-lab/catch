<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->constrained('governorates')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['governorate_id', 'name']);
            $table->index('governorate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::table('cities', function (Blueprint $table) {
            $table->dropIndex(['governorate_id']);
        });
    }
};