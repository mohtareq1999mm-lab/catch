<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('imports')) {
            return;
        }

        if (! Schema::hasColumn('imports', 'operation_type')) {
            Schema::table('imports', function (Blueprint $table) {
                $table->string('operation_type')->nullable()->after('type');
                $table->index(['operation_type', 'status'], 'imports_operation_type_status_index');
            });
        }

        // Backfill operation_type from legacy type values
        $map = [
            'product' => 'product-import',
            'category' => 'category-import',
            'brand' => 'brand-import',
        ];

        foreach ($map as $legacy => $canonical) {
            DB::table('imports')
                ->where('type', $legacy)
                ->whereNull('operation_type')
                ->update(['operation_type' => $canonical]);
        }

        // For already canonical values, copy directly
        DB::table('imports')
            ->whereNull('operation_type')
            ->whereNotNull('type')
            ->update(['operation_type' => DB::raw('`type`')]);

        // Make operation_type the primary discriminator going forward
        // Keep `type` column for backward compatibility (do not drop yet)
    }

    public function down(): void
    {
        if (Schema::hasTable('imports') && Schema::hasColumn('imports', 'operation_type')) {
            Schema::table('imports', function (Blueprint $table) {
                $table->dropIndex('imports_operation_type_status_index');
                $table->dropColumn('operation_type');
            });
        }
    }
};
