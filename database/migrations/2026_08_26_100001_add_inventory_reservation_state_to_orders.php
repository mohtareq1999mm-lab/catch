<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order-owned inventory reservation (Option C hybrid).
 *
 * The Order becomes the owner of the inventory reservation lifecycle:
 *   none -> active      (checkout, atomic with Order creation)
 *   active -> committed (payment success)
 *   active -> released  (24h expiry / unpaid cancellation)
 *
 * order_products rows are the exact per-line reservation source; the
 * products/product_variants.reserved_quantity counters remain the
 * availability index. No dedicated reservation table is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'inventory_state')) {
                $table->enum('inventory_state', ['none', 'active', 'released', 'committed'])
                    ->default('none')
                    ->after('status');
            }
            if (!Schema::hasColumn('orders', 'inventory_reserved_at')) {
                $table->timestamp('inventory_reserved_at')->nullable()->after('inventory_state');
            }
            if (!Schema::hasColumn('orders', 'reservation_expires_at')) {
                $table->timestamp('reservation_expires_at')->nullable()->after('inventory_reserved_at');
            }
        });

        $this->createReaperIndex();
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $this->dropReaperIndex();

        foreach (['reservation_expires_at', 'inventory_reserved_at'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        if (Schema::hasColumn('orders', 'inventory_state')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('inventory_state');
            });
        }
    }

    /**
     * Reaper lookup index: (status, reservation_expires_at).
     * SQLite's schema builder cannot introspect indexes on Laravel 10, so the
     * existence guard is driver-aware and duplicate creation is tolerated.
     */
    private function createReaperIndex(): void
    {
        if ($this->indexExists()) {
            return;
        }

        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['status', 'reservation_expires_at'], 'orders_status_reservation_expires_idx');
            });
        } catch (\Throwable $e) {
            if (!$this->indexExists()) {
                throw $e;
            }
        }
    }

    private function dropReaperIndex(): void
    {
        if (!$this->indexExists()) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_reservation_expires_idx');
        });
    }

    private function indexExists(): bool
    {
        try {
            return collect(Schema::getIndexes('orders'))
                ->pluck('name')
                ->contains('orders_status_reservation_expires_idx');
        } catch (\Throwable $e) {
            // SQLite on Laravel 10 cannot introspect indexes; probe pragmatically.
            try {
                $sql = DB::selectOne(
                    "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
                    ['orders_status_reservation_expires_idx']
                );

                return $sql !== null;
            } catch (\Throwable $inner) {
                return false;
            }
        }
    }
};
