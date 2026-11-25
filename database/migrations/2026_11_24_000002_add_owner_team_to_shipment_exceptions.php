<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #191: an unmapped provider status must carry a named operational owner even
 * when no individual user has been assigned yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipment_exceptions') || Schema::hasColumn('shipment_exceptions', 'owner_team')) {
            return;
        }

        Schema::table('shipment_exceptions', function (Blueprint $table) {
            $table->string('owner_team', 64)->nullable()->after('owner_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('shipment_exceptions') && Schema::hasColumn('shipment_exceptions', 'owner_team')) {
            Schema::table('shipment_exceptions', function (Blueprint $table) {
                $table->dropColumn('owner_team');
            });
        }
    }
};
