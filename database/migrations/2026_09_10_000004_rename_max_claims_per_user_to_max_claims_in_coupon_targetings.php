<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CRITICAL ARCHITECTURE CORRECTION:
     *
     * Rename max_claims_per_user → max_claims
     *
     * Semantics:
     * - max_claims = TOTAL number of claims allowed for this coupon across ALL users
     * - UNIQUE(coupon_id, user_id) = ONE claim per user per coupon
     *
     * These two rules coexist:
     * - max_claims limits total coupon capacity (e.g., "first 100 users")
     * - UNIQUE constraint prevents duplicate claims from same user
     *
     * Example: max_claims = 100
     * - 100 different users can claim
     * - Each user can claim at most once
     * - 101st user is rejected
     */
    public function up(): void
    {
        Schema::table('coupon_targetings', function (Blueprint $table) {
            $table->renameColumn('max_claims_per_user', 'max_claims');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coupon_targetings', function (Blueprint $table) {
            $table->renameColumn('max_claims', 'max_claims_per_user');
        });
    }
};
