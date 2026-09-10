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
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('title');
            $table->integer('order');
            $table->string('endpoint');
            $table->foreignId('content_page_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('title_visible')->default(true);
            $table->json('setting')->nullable();
            $table->index('content_page_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex(['content_page_id']);
        });
    }
};