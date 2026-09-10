<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 191)->nullable();
            $table->text('comment');
            $table->string('status', 20)->default('pending')->index('idx_site_reviews_status');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'idx_site_reviews_user_id');
            $table->index('moderated_by', 'idx_site_reviews_moderated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_reviews');
    }
};
