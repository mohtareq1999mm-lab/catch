<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #147: explicit external booking reconciliation boundary.
 *
 * Shipments may later participate in post-commit carrier booking flows.
 * When a provider result is unknown/ambiguous, retries must reconcile before
 * re-dispatching external side effects. No external carrier call is made by
 * this migration or by current shipment creation; fields are additive and
 * nullable so existing rows remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('external_booking_state', 32)->nullable()->after('awb_created_at');
            $table->string('external_booking_reference', 128)->nullable()->after('external_booking_state');
            $table->timestamp('external_booking_reconciled_at')->nullable()->after('external_booking_reference');
            $table->text('external_booking_reconciliation_note')->nullable()->after('external_booking_reconciled_at');

            $table->index(['external_booking_state'], 'shipments_external_booking_state_idx');
            $table->unique(['external_booking_reference'], 'shipments_external_booking_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique('shipments_external_booking_reference_unique');
            $table->dropIndex('shipments_external_booking_state_idx');
            $table->dropColumn([
                'external_booking_state',
                'external_booking_reference',
                'external_booking_reconciled_at',
                'external_booking_reconciliation_note',
            ]);
        });
    }
};
