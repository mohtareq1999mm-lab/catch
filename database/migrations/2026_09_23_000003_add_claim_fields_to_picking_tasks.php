<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8: order-picking support on the single task model.
     * - batch_id nullable (NULL = order picking; set = batch picking).
     * - claim protocol: claimed_by/at/expires_at.
     * - traceability denorm: order_id/order_item_id.
     * - idempotent confirm: op_seq + scan_log.
     */
    public function up(): void
    {
        Schema::table('picking_tasks', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->change();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->after('fulfillment_item_id');
            $table->unsignedBigInteger('order_item_id')->nullable()->after('order_id');
            $table->unsignedInteger('op_seq')->default(0);
            $table->json('scan_log')->nullable();
            $table->index(['order_id', 'status']);
            $table->index(['claimed_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('picking_tasks', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'status']);
            $table->dropIndex(['claimed_by', 'status']);
            $table->dropColumn([
                'order_id', 'order_item_id', 'op_seq', 'scan_log',
                'claimed_by', 'claimed_at', 'claim_expires_at',
            ]);
        });
    }
};
