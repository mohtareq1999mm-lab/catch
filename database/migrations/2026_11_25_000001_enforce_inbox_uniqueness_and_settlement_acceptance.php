<?php

use App\Enums\Settlement\SettlementAcceptanceStatus;
use App\Services\Shipment\ShippingInboxDuplicateAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #182: two additive enforcement steps.
 *
 * 1. Read-only duplicate audit of both shipping inbox tables, written to the
 *    canonical activity log BEFORE any constraint is installed. If duplicates
 *    exist the migration fails closed; no row is deleted or rewritten.
 * 2. Settlement acceptance fields. Acceptance is explicit finance evidence;
 *    this migration never creates an adjustment or touches payment/order state.
 */
return new class extends Migration
{
    public function up(): void
    {
        $audit = app(ShippingInboxDuplicateAudit::class);
        $audit->assertClean($audit->audit('migration'));

        Schema::table('shipping_provider_events', function (Blueprint $table): void {
            // The inbox already enforces this tuple on shipping_webhook_events.
            // Processed evidence must be scoped identically so an event id from
            // a different deployment account can never suppress a delivery.
            $table->unique(
                ['provider_code', 'mode', 'account_code', 'provider_event_key'],
                'uq_shipping_provider_event_identity',
            );
        });

        Schema::table('cod_settlement_statements', function (Blueprint $table): void {
            $table->string('acceptance_status', 16)
                ->default(SettlementAcceptanceStatus::PENDING->value)
                ->after('metadata');
            $table->timestamp('accepted_at')->nullable()->after('acceptance_status');
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_statement_id')->nullable()->after('accepted_by')
                ->constrained('cod_settlement_statements')->nullOnDelete();

            $table->index(
                ['provider_code', 'mode', 'account_code', 'acceptance_status'],
                'idx_cod_settlement_acceptance',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table): void {
            $table->dropIndex('idx_cod_settlement_acceptance');
            $table->dropForeign(['accepted_by']);
            $table->dropForeign(['supersedes_statement_id']);
            $table->dropColumn([
                'acceptance_status',
                'accepted_at',
                'accepted_by',
                'supersedes_statement_id',
            ]);
        });

        Schema::table('shipping_provider_events', function (Blueprint $table): void {
            $table->dropUnique('uq_shipping_provider_event_identity');
        });
    }
};
