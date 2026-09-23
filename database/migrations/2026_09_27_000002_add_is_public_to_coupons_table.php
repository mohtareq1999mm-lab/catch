<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent public-discoverability capability for coupons.
 *
 * WHY (discovery proof): legacy "public" is inferred as NOT(assignments),
 * which makes PUBLIC+ASSIGNED unrepresentable (Case B vs Case D are
 * identical in all existing rows). A persisted flag is the only way to
 * separate the dimensions without touching targeting/eligibility.
 *
 * SAFETY: purely additive boolean, default FALSE → every existing row keeps
 * its current visibility exactly (no backfill update needed); publicity is
 * opt-in per coupon. Reversible via dropColumn. No index: coupons table is
 * small and catalog queries already filter on relations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_public')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
