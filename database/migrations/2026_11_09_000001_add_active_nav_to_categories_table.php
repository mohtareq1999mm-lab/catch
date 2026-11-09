<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categories') && !Schema::hasColumn('categories', 'active_nav')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->boolean('active_nav')->default(false)->after('is_featured')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'active_nav')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropIndex(['active_nav']);
                $table->dropColumn('active_nav');
            });
        }
    }
};

