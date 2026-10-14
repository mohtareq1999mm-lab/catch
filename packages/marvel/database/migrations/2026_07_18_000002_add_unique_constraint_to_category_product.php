<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('
                DELETE cp1 FROM category_product cp1
                INNER JOIN category_product cp2
                WHERE cp1.id > cp2.id
                AND cp1.category_id = cp2.category_id
                AND cp1.product_id = cp2.product_id
            ');
        }
    }

    public function down(): void
    {
        //
    }
};
