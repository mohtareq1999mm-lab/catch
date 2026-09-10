<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $nullUuids = DB::table('transactions')->whereNull('uuid')->get();
        foreach ($nullUuids as $row) {
            DB::table('transactions')
                ->where('id', $row->id)
                ->update(['uuid' => (string) Str::uuid()]);
        }

        DB::statement("UPDATE transactions SET status = 'paid' WHERE status IS NULL");

        DB::statement("UPDATE transactions
            SET amount = (SELECT total_price FROM orders WHERE orders.id = transactions.order_id)
            WHERE amount IS NULL");
    }

    public function down(): void
    {
        //
    }
};
