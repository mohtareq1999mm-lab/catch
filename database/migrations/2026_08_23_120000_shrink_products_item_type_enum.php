<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Enums\ItemType;

return new class extends Migration
{
    /**
     * Shrink products.item_type to the approved PHYSICAL|DIGITAL domain.
     * Existing SERVICE rows are converted to PHYSICAL before the shrink so
     * no data is lost and the column never violates its own enum.
     */
    public function up(): void
    {
        $serviceCount = DB::table('products')->where('item_type', 'SERVICE')->count();

        if ($serviceCount > 0) {
            Log::warning('item_type migration: converting SERVICE products to PHYSICAL.', [
                'converted_rows' => $serviceCount,
            ]);

            DB::table('products')
                ->where('item_type', 'SERVICE')
                ->update(['item_type' => ItemType::PHYSICAL]);
        }

        $this->shrinkEnum(ItemType::getValues());
    }

    /**
     * Restore the previous (wider) enum domain. Rows already converted to
     * PHYSICAL keep their value; only the allowed domain is widened back.
     */
    public function down(): void
    {
        $this->shrinkEnum(['PHYSICAL', 'DIGITAL', 'SERVICE']);
    }

    /**
     * Native ENUM narrowing is a MySQL-only operation. SQLite (used by the
     * test suite) treats enums as VARCHAR + CHECK; its constraint is created
     * by the base table migration and does not need re-narrowing here.
     */
    private function shrinkEnum(array $values): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $columns = implode(', ', array_map(fn ($v) => "'$v'", $values));

        DB::statement(
            "ALTER TABLE products MODIFY item_type ENUM($columns) NOT NULL DEFAULT 'PHYSICAL'"
        );
    }
};
