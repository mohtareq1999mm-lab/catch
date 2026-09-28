<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual Status Catalog + Flow names (Spatie Translatable convention,
 * same as countries/governorates: {en, ar} JSON).
 *
 * - MySQL: widen name columns varchar(100) -> TEXT (Arabic + JSON needs
 *   the headroom), then data-convert plain strings to {"en","ar"}.
 * - SQLite: typeless affinity already stores any length; data conversion
 *   only.
 * - Machine-readable `code` columns are untouched: codes stay the stable
 *   business identifiers; only display names become bilingual.
 * - Down migration keeps bilingual data (TEXT holds it); it only narrows
 *   nothing. Irreversible by design — documented, no data loss path.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `order_statuses` MODIFY `name` TEXT NOT NULL');
            DB::statement('ALTER TABLE `order_flows` MODIFY `name` TEXT NOT NULL');
        }

        $this->convertTable('order_statuses', \App\Services\OrderFlow\OrderFlowService::arabicStatusNames());
        $this->convertTable('order_flows', \App\Services\OrderFlow\OrderFlowService::arabicFlowNames(), 'code');
    }

    public function down(): void
    {
        // Bilingual payloads remain valid in TEXT columns; narrowing back
        // to varchar(100) could truncate Arabic JSON, so down() is a no-op
        // by design. Restore requires a fresh migrate from 2026_09_28.
    }

    /**
     * @param  array<string, string>  $arabicByCode
     */
    private function convertTable(string $table, array $arabicByCode, string $codeColumn = 'code'): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach (DB::table($table)->select('id', $codeColumn, 'name')->get() as $row) {
            $code = $row->{$codeColumn};
            $name = $row->name;

            if ($this->isBilingual($name)) {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                'name' => json_encode(
                    ['en' => (string) $name, 'ar' => $arabicByCode[$code] ?? null],
                    JSON_UNESCAPED_UNICODE
                ),
            ]);
        }
    }

    private function isBilingual(mixed $name): bool
    {
        if (!is_string($name)) {
            return false;
        }

        $decoded = json_decode($name, true);

        return is_array($decoded) && array_key_exists('en', $decoded);
    }
};
