<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #149: the carrier environment is snapshotted at assignment time. Existing
 * rows stay nullable; no client input or later config flip may rewrite it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('provider_mode', 20)->nullable()->after('provider_code');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('provider_mode');
        });
    }
};
