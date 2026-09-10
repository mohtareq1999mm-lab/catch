<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot the product's item_type onto each order item at purchase
     * time so historical orders never change meaning when a product's
     * classification changes later.
     */
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->string('item_type', 16)
                ->default(\Marvel\Enums\ItemType::PHYSICAL)
                ->after('is_gift');

            $table->index(['order_id', 'item_type'], 'order_products_order_item_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropIndex('order_products_order_item_type_idx');
            $table->dropColumn('item_type');
        });
    }
};
