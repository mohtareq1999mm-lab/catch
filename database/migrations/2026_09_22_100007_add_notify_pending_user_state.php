<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the notify_pending state: set atomically when an evaluation
     * emits notification.requested, so a duplicate evaluation before the
     * notifier commits NOTIFIED converges to duplicate_skipped instead of
     * emitting a second request.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE coupon_distribution_user_states MODIFY state ENUM('eligible','not_eligible','notify_pending','notified') NOT NULL DEFAULT 'not_eligible'"
            );

            return;
        }

        Schema::table('coupon_distribution_user_states', function (Blueprint $table) {
            $table->string('state')->default('not_eligible')->change();
        });
    }

    public function down(): void
    {
        DB::table('coupon_distribution_user_states')
            ->where('state', 'notify_pending')
            ->update(['state' => 'eligible']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE coupon_distribution_user_states MODIFY state ENUM('eligible','not_eligible','notified') NOT NULL DEFAULT 'not_eligible'"
            );

            return;
        }

        Schema::table('coupon_distribution_user_states', function (Blueprint $table) {
            $table->string('state')->default('not_eligible')->change();
        });
    }
};
