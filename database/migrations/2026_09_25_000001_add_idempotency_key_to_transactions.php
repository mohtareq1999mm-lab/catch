<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 2: Payment + Transaction + Idempotency (GAP-C001)
     *
     * Adds idempotency_key to prevent duplicate payment processing during
     * concurrent callback/webhook scenarios.
     *
     * Business Rule:
     * - Once a transaction is processed (idempotency_key set), no further
     *   processing should occur for that transaction regardless of status.
     * - The key is set immediately after acquiring pessimistic lock and
     *   before any business logic executes.
     * - Unique constraint ensures database-level enforcement.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('idempotency_key', 64)
                ->nullable()
                ->unique()
                ->after('gateway_transaction_id')
                ->comment('UUID token for idempotent payment processing. Set once on first callback/webhook.');

            $table->index('idempotency_key', 'txn_idempotency_key_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('txn_idempotency_key_idx');
            $table->dropColumn('idempotency_key');
        });
    }
};
