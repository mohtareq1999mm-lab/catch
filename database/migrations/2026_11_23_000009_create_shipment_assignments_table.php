<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('delivery_representative_id')->constrained('delivery_representatives')->cascadeOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('unassigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'status']);
            $table->index(['delivery_representative_id', 'status'], 'shipment_assignments_rep_status_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `shipment_assignments` ADD COLUMN `active_shipment_id` BIGINT UNSIGNED " .
                "GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN `shipment_id` ELSE NULL END) VIRTUAL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX `shipment_assignments_one_active_per_shipment` " .
                "ON `shipment_assignments` (`active_shipment_id`)"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $indexExists = (bool) DB::selectOne(
                "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() " .
                "AND table_name = 'shipment_assignments' AND index_name = 'shipment_assignments_one_active_per_shipment'"
            );
            if ($indexExists) {
                DB::statement("DROP INDEX `shipment_assignments_one_active_per_shipment` ON `shipment_assignments`");
            }
            if (Schema::hasColumn('shipment_assignments', 'active_shipment_id')) {
                DB::statement("ALTER TABLE `shipment_assignments` DROP COLUMN `active_shipment_id`");
            }
        }

        Schema::dropIfExists('shipment_assignments');
    }
};
