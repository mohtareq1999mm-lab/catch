<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #172: a carrier return may point at an existing shipment and retain minimal
 * provider metadata. No lifecycle column, default or historical backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('fulfillment_id')->constrained('shipments')->nullOnDelete();
            $table->json('provider_return_metadata')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_id');
            $table->dropColumn('provider_return_metadata');
        });
    }
};
