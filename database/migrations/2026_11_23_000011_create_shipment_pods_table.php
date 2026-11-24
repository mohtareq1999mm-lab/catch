<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_pods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('shipment_assignment_id')->nullable()->constrained('shipment_assignments')->nullOnDelete();
            $table->foreignId('shipment_delivery_attempt_id')->nullable()->constrained('shipment_delivery_attempts')->nullOnDelete();
            $table->string('status', 32)->default('successful');
            $table->string('recipient_name')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->string('file_reference', 1024);
            $table->string('signature_reference', 1024)->nullable();
            $table->string('otp_reference', 191)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'status']);
        });

        // MySQL-only invariant. On non-MySQL drivers the generated-column index is
        // unavailable; ProofOfDeliveryService still enforces one successful POD via
        // a shipment row lock + recheck before insert.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `shipment_pods` ADD COLUMN `successful_shipment_id` BIGINT UNSIGNED " .
                "GENERATED ALWAYS AS (CASE WHEN `status` = 'successful' THEN `shipment_id` ELSE NULL END) VIRTUAL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX `shipment_pods_one_successful_per_shipment` " .
                "ON `shipment_pods` (`successful_shipment_id`)"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $indexExists = (bool) DB::selectOne(
                "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() " .
                "AND table_name = 'shipment_pods' AND index_name = 'shipment_pods_one_successful_per_shipment'"
            );
            if ($indexExists) {
                DB::statement("DROP INDEX `shipment_pods_one_successful_per_shipment` ON `shipment_pods`");
            }
            if (Schema::hasColumn('shipment_pods', 'successful_shipment_id')) {
                DB::statement("ALTER TABLE `shipment_pods` DROP COLUMN `successful_shipment_id`");
            }
        }

        Schema::dropIfExists('shipment_pods');
    }
};
