<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #191: auditable, expiring break-glass finance access. Spatie's permission
 * pivots have no expiry column, so the grant row is the expiry authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission', 191);
            $table->text('reason');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'permission', 'expires_at'], 'idx_finance_grant_lookup');
            $table->index(['revoked_at', 'expires_at'], 'idx_finance_grant_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_access_grants');
    }
};
