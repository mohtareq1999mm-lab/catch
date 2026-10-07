<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 unification: the refunds table becomes the canonical refund
     * ledger. New audit columns (currency, decider, decision note) plus a
     * lookup index. The transitional 'processing' value is retired — the
     * canonical approval is an atomic PENDING -> APPROVED transition under
     * lock, so no intermediate state is ever written.
     */
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('amount')
                ->comment('ISO currency of amount; falls back to the order currency when null (legacy rows)');
            $table->unsignedBigInteger('decided_by')->nullable()->after('status')
                ->comment('Admin user id that approved/rejected; null while pending');
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->text('decision_note')->nullable()->after('decided_at');
        });

        // Retire the transitional claim value. Safe: verified zero rows carry
        // it (live catch = 0 refund rows) and no writer emits it anymore.
        DB::statement("ALTER TABLE `refunds` MODIFY `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");

        Schema::table('refunds', function (Blueprint $table) {
            $table->index(['order_id', 'status'], 'refunds_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex('refunds_order_status_idx');
        });

        DB::statement("ALTER TABLE `refunds` MODIFY `status` ENUM('approved','pending','rejected','processing') NOT NULL DEFAULT 'pending'");

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['currency', 'decided_by', 'decided_at', 'decision_note']);
        });
    }
};
