<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #148: the authoritative provider assignment is server-side. `courier`
 * remains a legacy free-text field for compatibility; it is never used to
 * select a gateway or to build a customer-facing response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('provider_code', 50)->nullable()->after('courier');
            $table->string('provider_service_level', 50)->nullable()->after('provider_code');
            $table->boolean('provider_override')->default(false)->after('provider_service_level');
            $table->timestamp('awb_created_at')->nullable()->after('provider_override');
            $table->index('provider_code', 'idx_ship_provider_code');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('idx_ship_provider_code');
            $table->dropColumn([
                'provider_code',
                'provider_service_level',
                'provider_override',
                'awb_created_at',
            ]);
        });
    }
};
