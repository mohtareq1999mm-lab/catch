<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_targetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();

            // Targeting mode: 'assignment' (whitelist via coupon_assignments) or 'dynamic' (rule-based)
            $table->enum('mode', ['assignment', 'dynamic'])->default('assignment');

            // Whether claim action is required before use
            $table->boolean('require_claim')->default(false);

            // Maximum lifetime claims per user (null = unlimited)
            // NOTE: This field is renamed to max_claims in migration 2026_09_10_000004
            $table->unsignedInteger('max_claims_per_user')->nullable();

            // Eligibility rule tree (JSON)
            // Structure: {"operator": "AND", "rules": [{"type": "min_completed_orders", "value": 5}, ...]}
            $table->json('rule_tree')->nullable();

            $table->timestamps();

            // One targeting configuration per coupon
            $table->unique('coupon_id');

            // Index for querying claim-required coupons
            $table->index('require_claim');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_targetings');
    }
};
