<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->decimal('product_tax_rate', 8, 3)->nullable();
            $table->decimal('product_tax_amount', 10, 3)->default(0);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('default_tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropForeign(['default_tax_class_id']);
            $table->dropColumn('default_tax_class_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tax_class_id']);
            $table->dropColumn('tax_class_id');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn('product_tax_rate');
            $table->dropColumn('product_tax_amount');
        });
    }
};
