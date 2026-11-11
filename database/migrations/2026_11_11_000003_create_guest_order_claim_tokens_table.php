<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest order claim credentials (OTP-gated).
 *
 * The raw token is delivered only once by the future verified issuance flow;
 * only a keyed hash is persisted. This table is intentionally SEPARATE from
 * guest tracking/cancellation tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guest_order_claim_tokens')) {
            return;
        }

        Schema::create('guest_order_claim_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('guest_id')
                ->constrained('guest_identities')
                ->cascadeOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Issuer/claimer. Nullable only for legacy/cleanup safety.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->char('token_hash', 64)->unique('uq_guest_claim_token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['guest_id', 'order_id'], 'idx_guest_claim_guest_order');
            $table->index('expires_at', 'idx_guest_claim_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_order_claim_tokens');
    }
};
