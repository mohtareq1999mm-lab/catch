<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbox attempt counters must survive sustained broker outages
     * (unsignedTinyInteger caps at 255 — roughly 4h of per-minute
     * sweeps — after which the publisher would wedge on overflow).
     */
    public function up(): void
    {
        Schema::table('coupon_outbox', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_outbox', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->change();
        });
    }
};
