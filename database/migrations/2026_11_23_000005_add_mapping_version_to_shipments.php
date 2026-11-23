<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #172: snapshot the status-map version in effect at assignment time.
 * Additive and nullable: historical rows keep NULL, never a guessed version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('mapping_version', 32)->nullable()->after('provider_mode');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('mapping_version');
        });
    }
};
