<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Check for existing NULL values (should not exist in production)
        $nullCount = DB::table('coupon_assignment_usages')
            ->whereNull('order_id')
            ->count();

        if ($nullCount > 0) {
            \Illuminate\Support\Facades\Log::warning("Found {$nullCount} coupon_assignment_usages rows with NULL order_id during migration. These will be deleted.");

            DB::table('coupon_assignment_usages')
                ->whereNull('order_id')
                ->delete();
        }

        // Step 2: Make order_id NOT NULL — drop FK first to avoid "cannot be NOT NULL: needed in a foreign key constraint SET NULL"
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            try {
                Schema::table('coupon_assignment_usages', function (Blueprint $table) {
                    $table->unsignedBigInteger('order_id')->nullable(false)->change();
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("SQLite order_id NOT NULL migration skipped: " . $e->getMessage());
            }
        } else {
            try {
                Schema::table('coupon_assignment_usages', function (Blueprint $table) {
                    $table->dropForeign(['order_id']);
                });
            } catch (\Throwable $e) {
                // FK may not exist under different name
            }

            Schema::table('coupon_assignment_usages', function (Blueprint $table) {
                $table->unsignedBigInteger('order_id')->nullable(false)->change();
            });

            // Re-add FK with cascade (order deletion should remove usage; null no longer allowed)
            Schema::table('coupon_assignment_usages', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'sqlite') {
            try {
                Schema::table('coupon_assignment_usages', function (Blueprint $table) {
                    $table->dropForeign(['order_id']);
                });
            } catch (\Throwable $e) {}
        }

        Schema::table('coupon_assignment_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });

        if ($driver !== 'sqlite') {
            Schema::table('coupon_assignment_usages', function (Blueprint $table) {
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            });
        }
    }
};
