<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for inventory stock operations (receipts, transfers).
 *
 * Why a table (and not the activity log): replay protection needs a UNIQUE
 * idempotency key with a stored result snapshot, readable inside the same
 * transaction that performs the work. The activity log is write-only audit
 * with no uniqueness or result contract, so it cannot anchor idempotency.
 * This mirrors the existing Fulfillment/Shipments UNIQUE(idempotency_key)
 * backstop pattern.
 *
 * No foreign keys by design: ledger rows are immutable history that must
 * survive product/warehouse/location deletion. Lookups are by
 * idempotency_key (unique) with a secondary product_id index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_operations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->string('operation', 20); // receipt|transfer|initial_placement
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('source_location_id')->nullable();
            $table->unsignedBigInteger('destination_location_id')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->string('reference', 255)->nullable();
            $table->string('status', 20)->default('processing'); // processing|completed|failed
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_operations');
    }
};
