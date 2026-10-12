<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-flow per shipping type.
 *
 * Removes ONLY the UNIQUE constraint on order_flows.shipping_type so several
 * independently identifiable flows (stable identity: code, UNIQUE) can share
 * one controlled shipping category (local|international).
 *
 * Preserved: code UNIQUE, order_flows_shipping_active_idx, all foreign keys,
 * all rows and their ids, flow-step memberships, order assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_flows')) {
            return;
        }

        // Resolve the real unique index name from the schema so the migration
        // works even if a previous rename changed the default name.
        $indexes = $this->doctrineIndexesOrNull();

        if ($indexes !== null) {
            foreach ($indexes as $name => $index) {
                try {
                    $isTarget = $index->isUnique() && !$index->isPrimary()
                        && array_map('strtolower', $index->getColumns()) === ['shipping_type'];
                } catch (\Throwable) {
                    continue;
                }

                if ($isTarget) {
                    Schema::table('order_flows', function (Blueprint $table) use ($name) {
                        $table->dropUnique($name);
                    });

                    return;
                }
            }

            // Inspected the schema: no UNIQUE on shipping_type — already migrated.
            return;
        }

        // No introspection available: best-effort drop of the conventional
        // default name. Error 1091 (nothing to drop) means an earlier run
        // already migrated; any other error is rethrown.
        try {
            Schema::table('order_flows', function (Blueprint $table) {
                $table->dropUnique(['shipping_type']);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (!str_contains($e->getMessage(), '1091')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_flows')) {
            return;
        }

        if ($this->uniqueIndexOnShippingType() !== null) {
            return;
        }

        $duplicates = DB::table('order_flows')
            ->select('shipping_type', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('shipping_type')
            ->having('aggregate', '>', 1)
            ->pluck('shipping_type')
            ->all();

        if (!empty($duplicates)) {
            throw new \RuntimeException(
                'Cannot restore order_flows.shipping_type UNIQUE: duplicate flows share type(s) ['
                .implode(', ', $duplicates)
                .']. Merge or reassign the duplicate flows before rolling back.'
            );
        }

        Schema::table('order_flows', function (Blueprint $table) {
            $table->unique('shipping_type');
        });
    }

    /**
     * Actual UNIQUE index name covering exactly (shipping_type), or null when
     * absent or when introspection is unavailable.
     */
    private function uniqueIndexOnShippingType(): ?string
    {
        foreach ($this->doctrineIndexesOrNull() ?? [] as $name => $index) {
            try {
                if ($index->isUnique() && !$index->isPrimary()
                    && array_map('strtolower', $index->getColumns()) === ['shipping_type']) {
                    return $name;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Introspected index map for order_flows, or null when unavailable.
     */
    private function doctrineIndexesOrNull(): ?array
    {
        try {
            return Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes('order_flows');
        } catch (\Throwable) {
            return null;
        }
    }
};
