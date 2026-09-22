<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-07 backfill: normalize all persisted coupon codes to canonical UPPER(TRIM).
 *
 * queryByCode() uses indexed exact equality (`code = ?`). Any pre-canonical
 * lowercase rows would become unfindable, so normalize them once here.
 * Safe: codes are ASCII by construction; UPPER(TRIM) is idempotent.
 * Order snapshots are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('UPDATE `coupons` SET `code` = UPPER(TRIM(`code`)) WHERE `code` != BINARY UPPER(TRIM(`code`))');
        } else {
            $rows = DB::table('coupons')->select('id', 'code')->get();
            foreach ($rows as $row) {
                $canonical = mb_strtoupper(trim((string) $row->code), 'UTF-8');
                if ($row->code !== $canonical) {
                    DB::table('coupons')->where('id', $row->id)->update(['code' => $canonical]);
                }
            }
        }
    }

    public function down(): void
    {
        // No-op: normalization is idempotent and irreversible by design.
    }
};
