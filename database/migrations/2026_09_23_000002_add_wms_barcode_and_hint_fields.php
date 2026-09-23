<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7: warehouse/location hardening (additive, reversible).
     * - locations.barcode: unique nullable scan identity (WHERE).
     * - product_locations.reserved_quantity → allocated_hint: kills the
     *   second-authority confusion; hints never decide sellability.
     */
    public function up(): void
    {
        // Idempotent: a previous partial run may have added `barcode`
        // (DDL is not transactional on MySQL) while the rename below failed
        // on the CHECK constraints referencing `reserved_quantity`.
        if (! Schema::hasColumn('locations', 'barcode')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('barcode', 100)->nullable()->unique()->after('code');
            });
        }

        if (
            Schema::hasColumn('product_locations', 'reserved_quantity')
            && ! Schema::hasColumn('product_locations', 'allocated_hint')
        ) {
            $this->dropProductLocationReservedChecks();

            Schema::table('product_locations', function (Blueprint $table) {
                $table->renameColumn('reserved_quantity', 'allocated_hint');
            });

            $this->addProductLocationAllocatedChecks();
        }
    }

    public function down(): void
    {
        if (
            Schema::hasColumn('product_locations', 'allocated_hint')
            && ! Schema::hasColumn('product_locations', 'reserved_quantity')
        ) {
            $this->dropProductLocationAllocatedChecks();

            Schema::table('product_locations', function (Blueprint $table) {
                $table->renameColumn('allocated_hint', 'reserved_quantity');
            });

            $this->addProductLocationReservedChecks();
        }

        if (Schema::hasColumn('locations', 'barcode')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropUnique(['barcode']);
                $table->dropColumn('barcode');
            });
        }
    }

    private function isMySql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }

    private function dropCheck(string $table, string $constraint): void
    {
        try {
            DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$constraint}`");
        } catch (\Throwable $e) {
            // Constraint may not exist (fresh DB, SQLite, partial state).
            // Safe to ignore: the subsequent ADD CHECK / rename is authoritative.
        }
    }

    private function addCheck(string $table, string $constraint, string $expression): void
    {
        try {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$expression})");
        } catch (\Throwable $e) {
            // If the constraint already exists (retry after partial run),
            // MySQL throws ER_CHECK_CONSTRAINT_DUP_NAME — safe to ignore
            // because the desired state is already in place.
            if (! str_contains($e->getMessage(), 'Duplicate check constraint name')) {
                throw $e;
            }
        }
    }

    private function dropProductLocationReservedChecks(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        $this->dropCheck('product_locations', 'check_reserved_non_negative');
        $this->dropCheck('product_locations', 'check_reserved_lte_quantity');
    }

    private function addProductLocationReservedChecks(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        $this->addCheck('product_locations', 'check_reserved_non_negative', 'reserved_quantity >= 0');
        $this->addCheck('product_locations', 'check_reserved_lte_quantity', 'reserved_quantity <= quantity');
    }

    private function dropProductLocationAllocatedChecks(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        $this->dropCheck('product_locations', 'check_allocated_non_negative');
        $this->dropCheck('product_locations', 'check_allocated_lte_quantity');
    }

    private function addProductLocationAllocatedChecks(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        $this->addCheck('product_locations', 'check_allocated_non_negative', 'allocated_hint >= 0');
        $this->addCheck('product_locations', 'check_allocated_lte_quantity', 'allocated_hint <= quantity');
    }
};
