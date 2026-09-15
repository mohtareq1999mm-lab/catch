<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_claims', function (Blueprint $table) {
            $table->enum('status', ['active', 'expired', 'redeemed'])
                ->default('active')
                ->after('user_id');

            $table->timestamp('expires_at')
                ->nullable()
                ->after('claimed_at');

            $table->timestamp('redeemed_at')
                ->nullable()
                ->after('expires_at');
        });

        DB::table('coupon_claims')->update(['status' => 'active']);

        Schema::table('coupon_claims', function (Blueprint $table) {
            $table->dropUnique(['coupon_id', 'user_id']);
        });

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('
                CREATE UNIQUE INDEX idx_active_claim
                ON coupon_claims(coupon_id, user_id)
                WHERE status = ?
            ', ['active']);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX idx_active_claim ON coupon_claims');
        }

        DB::statement('
            DELETE c1 FROM coupon_claims c1
            INNER JOIN coupon_claims c2
            ON c1.coupon_id = c2.coupon_id
            AND c1.user_id = c2.user_id
            AND c1.id > c2.id
        ');

        Schema::table('coupon_claims', function (Blueprint $table) {
            $table->unique(['coupon_id', 'user_id']);
            $table->dropColumn(['status', 'expires_at', 'redeemed_at']);
        });
    }
};
