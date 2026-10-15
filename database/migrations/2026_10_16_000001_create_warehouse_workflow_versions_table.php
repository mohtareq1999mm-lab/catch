<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warehouse Workflow — immutable published versions (Q-02, Q-04).
     *
     * Append-only: each publish inserts a new row. Current = latest id.
     * Concurrent publishers both succeed as separate versions; no lost
     * updates (no single-row overwrite). Published rows are never updated
     * (enforced in model + service). No nullable UNIQUE (MySQL pitfall).
     */
    public function up(): void
    {
        Schema::create('warehouse_workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->boolean('picking_enabled')->default(true);
            $table->boolean('packing_enabled')->default(true);
            $table->boolean('shipping_enabled')->default(true);
            // Q-07: Order Received mandatory in v1, stored for audit.
            $table->boolean('order_received_enabled')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_workflow_versions');
    }
};
