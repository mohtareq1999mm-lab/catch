<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * D4 — address is optional for DIGITAL-only orders. The base table
     * declares orders.address as NOT NULL, which breaks digital-only
     * checkout on strict MySQL. SQLite test schemas already treat the
     * column as nullable, so this aligns production with the approved
     * behavior without weakening any constraint used by PHYSICAL flows.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE orders MODIFY address JSON NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Defensive: satisfy NOT NULL before restoring it.
        DB::table('orders')->whereNull('address')->update(['address' => '{}']);

        DB::statement('ALTER TABLE orders MODIFY address JSON NOT NULL');
    }
};
