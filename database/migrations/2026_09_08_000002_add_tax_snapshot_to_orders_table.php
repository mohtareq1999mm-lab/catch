<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Override input state — persists through pending-order reuse/retry.
            $table->enum('tax_override_type', ['inherit', 'tax_class', 'none'])->default('inherit');
            $table->foreignId('tax_override_tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();

            // Immutable snapshot written at order creation/update (pending only).
            $table->foreignId('tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
            $table->string('tax_mode', 20)->default('none');
            $table->string('tax_name', 100)->nullable();
            $table->decimal('tax_rate', 8, 3)->nullable();
            $table->decimal('taxable_amount', 10, 3)->nullable();
            $table->decimal('tax_amount', 10, 3)->default(0);
            $table->decimal('product_tax_amount', 10, 3)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['tax_override_tax_class_id']);
            $table->dropForeign(['tax_class_id']);
            $table->dropColumn([
                'tax_override_type',
                'tax_override_tax_class_id',
                'tax_class_id',
                'tax_mode',
                'tax_name',
                'tax_rate',
                'taxable_amount',
                'tax_amount',
                'product_tax_amount',
            ]);
        });
    }
};
