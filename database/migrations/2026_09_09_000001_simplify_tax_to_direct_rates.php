<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // -----------------------------------------------------------------
        // 1. PRODUCTS: tax_enabled / tax_rate
        // -----------------------------------------------------------------
        if (!Schema::hasColumn('products', 'tax_enabled')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('tax_enabled')->default(false)->after('tax_class_id');
                $table->decimal('tax_rate', 6, 3)->nullable()->after('tax_enabled');
            });
        }

        // Backfill from legacy tax_classes when deterministic
        if (Schema::hasColumn('products', 'tax_class_id') && Schema::hasTable('tax_classes')) {
            try {
                DB::statement("
                    UPDATE products
                    SET tax_enabled = CASE
                        WHEN tax_class_id IS NOT NULL AND EXISTS (SELECT 1 FROM tax_classes WHERE tax_classes.id = products.tax_class_id)
                        THEN 1 ELSE 0 END,
                        tax_rate = (SELECT rate FROM tax_classes WHERE tax_classes.id = products.tax_class_id)
                    WHERE tax_enabled = 0 AND tax_rate IS NULL
                ");
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // -----------------------------------------------------------------
        // 2. SETTINGS: order_tax_enabled / order_tax_rate
        // -----------------------------------------------------------------
        if (!Schema::hasColumn('settings', 'order_tax_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('order_tax_enabled')->default(false)->after('default_tax_class_id');
                $table->decimal('order_tax_rate', 6, 3)->nullable()->after('order_tax_enabled');
            });
        }

        if (Schema::hasColumn('settings', 'default_tax_class_id') && Schema::hasTable('tax_classes')) {
            try {
                DB::statement("
                    UPDATE settings
                    SET order_tax_enabled = CASE
                        WHEN default_tax_class_id IS NOT NULL AND EXISTS (SELECT 1 FROM tax_classes WHERE tax_classes.id = settings.default_tax_class_id)
                        THEN 1 ELSE 0 END,
                        order_tax_rate = (SELECT rate FROM tax_classes WHERE tax_classes.id = settings.default_tax_class_id)
                    WHERE order_tax_enabled = 0 AND order_tax_rate IS NULL
                ");
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // -----------------------------------------------------------------
        // 3. ORDERS: product_taxable_amount + order_tax_* (rename from generic)
        // -----------------------------------------------------------------
        if (!Schema::hasColumn('orders', 'product_taxable_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('product_taxable_amount', 10, 3)->nullable()->after('product_tax_amount');
            });
        }
        if (!Schema::hasColumn('orders', 'order_tax_rate')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('order_tax_rate', 8, 3)->nullable()->after('product_taxable_amount');
            });
        }
        if (!Schema::hasColumn('orders', 'order_taxable_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('order_taxable_amount', 10, 3)->nullable()->after('order_tax_rate');
            });
        }
        if (!Schema::hasColumn('orders', 'order_tax_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('order_tax_amount', 10, 3)->default(0)->after('order_taxable_amount');
            });
        }

        // Backfill order snapshots from legacy generic columns (preserve history)
        if (Schema::hasColumn('orders', 'tax_rate') && Schema::hasColumn('orders', 'order_tax_rate')) {
            try {
                DB::statement("UPDATE orders SET order_tax_rate = tax_rate WHERE order_tax_rate IS NULL AND tax_rate IS NOT NULL");
                DB::statement("UPDATE orders SET order_tax_amount = tax_amount WHERE order_tax_amount = 0 AND tax_amount != 0");
                DB::statement("UPDATE orders SET order_taxable_amount = taxable_amount WHERE order_taxable_amount IS NULL AND taxable_amount IS NOT NULL");
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // -----------------------------------------------------------------
        // 4. ORDER_PRODUCTS: product_taxable_amount
        // -----------------------------------------------------------------
        if (!Schema::hasColumn('order_products', 'product_taxable_amount')) {
            Schema::table('order_products', function (Blueprint $table) {
                $table->decimal('product_taxable_amount', 10, 3)->nullable()->after('product_tax_rate');
            });
        }

        // -----------------------------------------------------------------
        // 5. Drop legacy FKs / columns / table (after backfill)
        // -----------------------------------------------------------------
        // Orders legacy
        $ordersLegacy = ['tax_override_type','tax_override_tax_class_id','tax_class_id','tax_mode','tax_name','tax_rate','taxable_amount','tax_amount'];
        foreach (['tax_override_tax_class_id','tax_class_id'] as $fk) {
            if (Schema::hasColumn('orders', $fk)) {
                try { Schema::table('orders', fn(Blueprint $t) => $t->dropForeign([$fk])); } catch (\Throwable $e) {}
            }
        }
        foreach ($ordersLegacy as $col) {
            if (Schema::hasColumn('orders', $col)) {
                try { Schema::table('orders', fn(Blueprint $t) => $t->dropColumn($col)); } catch (\Throwable $e) {}
            }
        }

        // Products legacy
        if (Schema::hasColumn('products', 'tax_class_id')) {
            try { Schema::table('products', fn(Blueprint $t) => $t->dropForeign(['tax_class_id'])); } catch (\Throwable $e) {}
            try { Schema::table('products', fn(Blueprint $t) => $t->dropColumn('tax_class_id')); } catch (\Throwable $e) {}
        }

        // Settings legacy
        if (Schema::hasColumn('settings', 'default_tax_class_id')) {
            try { Schema::table('settings', fn(Blueprint $t) => $t->dropForeign(['default_tax_class_id'])); } catch (\Throwable $e) {}
            try { Schema::table('settings', fn(Blueprint $t) => $t->dropColumn('default_tax_class_id')); } catch (\Throwable $e) {}
        }

        // Tax classes table
        if (Schema::hasTable('tax_classes')) {
            Schema::dropIfExists('tax_classes');
        }
    }

    public function down(): void
    {
        // Recreate legacy structures (minimal)
        if (!Schema::hasTable('tax_classes')) {
            Schema::create('tax_classes', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable()->unique();
                $table->decimal('rate', 6, 3);
                $table->boolean('is_active')->default(true);
                $table->boolean('applies_to_shipping')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('products', 'tax_class_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('settings', 'default_tax_class_id')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->foreignId('default_tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('orders', 'tax_rate')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->enum('tax_override_type', ['inherit','tax_class','none'])->default('inherit');
                $table->foreignId('tax_override_tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
                $table->foreignId('tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
                $table->string('tax_mode', 20)->default('none');
                $table->string('tax_name', 100)->nullable();
                $table->decimal('tax_rate', 8, 3)->nullable();
                $table->decimal('taxable_amount', 10, 3)->nullable();
                $table->decimal('tax_amount', 10, 3)->default(0);
            });
        }

        // Drop new simple columns
        foreach (['tax_enabled','tax_rate'] as $c) {
            if (Schema::hasColumn('products', $c)) Schema::table('products', fn(Blueprint $t) => $t->dropColumn($c));
        }
        foreach (['order_tax_enabled','order_tax_rate'] as $c) {
            if (Schema::hasColumn('settings', $c)) Schema::table('settings', fn(Blueprint $t) => $t->dropColumn($c));
        }
        foreach (['product_taxable_amount','order_tax_rate','order_taxable_amount','order_tax_amount'] as $c) {
            if (Schema::hasColumn('orders', $c)) Schema::table('orders', fn(Blueprint $t) => $t->dropColumn($c));
        }
        if (Schema::hasColumn('order_products', 'product_taxable_amount')) {
            Schema::table('order_products', fn(Blueprint $t) => $t->dropColumn('product_taxable_amount'));
        }
    }
};
