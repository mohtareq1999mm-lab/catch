<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #151: keep the provider's raw status as a first-class inbox field as well
 * as inside the redacted payload. The verbatim value is evidence; it is never
 * derived from a status map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_webhook_events', function (Blueprint $table) {
            $table->string('raw_status', 191)->nullable()->after('redacted_payload');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_webhook_events', function (Blueprint $table) {
            $table->dropColumn('raw_status');
        });
    }
};
