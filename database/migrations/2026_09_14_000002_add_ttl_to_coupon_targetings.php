<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_targetings', function (Blueprint $table) {
            $table->unsignedInteger('claim_ttl_hours')
                ->nullable()
                ->after('max_claims')
                ->comment('Hours until active claim expires. NULL = no expiration');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_targetings', function (Blueprint $table) {
            $table->dropColumn('claim_ttl_hours');
        });
    }
};
