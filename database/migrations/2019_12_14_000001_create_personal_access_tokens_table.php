<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This file was missing from version control and is the root cause of
     * `SQLSTATE[42S02]: 1146 Table 'meemmarket_catch.personal_access_tokens' doesn't exist`
     * when the `throttle:api` limiter calls `$request->user()` → Sanctum Guard → findToken().
     * The canonical migration lives in vendor/laravel/sanctum; publishing it here makes the
     * table part of the repo's migration history and fixes RefreshDatabase (:memory:) as well
     * as production `php artisan migrate` without relying on Sanctum's conditional loadMigrationsFrom.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
