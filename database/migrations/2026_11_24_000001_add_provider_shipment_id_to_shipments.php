<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'provider_shipment_id')) {
                $table->string('provider_shipment_id', 191)->nullable()->after('tracking_number');
            }
            if (!$this->hasIndex('uq_ship_provider_shipment_identity')) {
                // A carrier id is only unique inside its provider + mode;
                // NULLs are allowed by MySQL unique indexes. No historical
                // values are inferred or backfilled by #156.
                $table->unique(
                    ['provider_code', 'provider_mode', 'provider_shipment_id'],
                    'uq_ship_provider_shipment_identity',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if ($this->hasIndex('uq_ship_provider_shipment_identity')) {
                $table->dropUnique('uq_ship_provider_shipment_identity');
            }
            if (Schema::hasColumn('shipments', 'provider_shipment_id')) {
                $table->dropColumn('provider_shipment_id');
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['shipments', $name],
        ) !== [];
    }
};
