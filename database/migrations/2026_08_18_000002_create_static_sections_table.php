<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('static_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('static_page_id')->constrained('static_pages')->cascadeOnDelete();
            $table->json('title');
            $table->json('content')->nullable();
            $table->integer('order')->default(1);
            $table->timestamps();
            $table->index('static_page_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('static_sections');
    }
};