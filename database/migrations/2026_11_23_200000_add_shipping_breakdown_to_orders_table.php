<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'cod_fee')) {
                $table->decimal('cod_fee', 12, 3)->nullable()->after('fast_shipping_fee');
            }
            if (!Schema::hasColumn('orders', 'shipping_breakdown')) {
                $table->json('shipping_breakdown')->nullable()->after('cod_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('orders', 'shipping_breakdown') ? 'shipping_breakdown' : null,
                Schema::hasColumn('orders', 'cod_fee') ? 'cod_fee' : null,
            ]));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
