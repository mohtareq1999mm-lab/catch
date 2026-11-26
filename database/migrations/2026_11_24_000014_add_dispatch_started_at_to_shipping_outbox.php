<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #178: distinguish "lease claimed" from "external call may have started".
 *
 * processing_started_at records when a worker claimed the row.
 * dispatch_started_at is written immediately before invoking the provider, in
 * its own committed write. On lease recovery:
 *   - NULL dispatch_started_at  => reliable evidence the call never started,
 *                                  so the row may safely return to pending.
 *   - non-NULL dispatch_started_at => a dispatch may have occurred, so the row
 *                                  becomes unknown for reconciliation instead
 *                                  of being blindly retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipping_outbox')) {
            return;
        }
        if (! Schema::hasColumn('shipping_outbox', 'dispatch_started_at')) {
            Schema::table('shipping_outbox', function (Blueprint $table) {
                $table->timestamp('dispatch_started_at')->nullable()->after('processing_started_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shipping_outbox') && Schema::hasColumn('shipping_outbox', 'dispatch_started_at')) {
            Schema::table('shipping_outbox', function (Blueprint $table) {
                $table->dropColumn('dispatch_started_at');
            });
        }
    }
};
