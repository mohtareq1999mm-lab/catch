<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WMS batch idempotency: network retries must not create duplicate
     * active batches. Nullable + unique so legacy rows stay valid and only
     * keyed requests participate in replay.
     */
    public function up(): void
    {
        Schema::table('fulfillment_batches', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('batch_number');
            $table->unique('idempotency_key', 'fulfillment_batches_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_batches', function (Blueprint $table) {
            $table->dropUnique('fulfillment_batches_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
