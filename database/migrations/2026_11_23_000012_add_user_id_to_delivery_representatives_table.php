<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('delivery_representatives') || Schema::hasColumn('delivery_representatives', 'user_id')) {
            return;
        }

        Schema::table('delivery_representatives', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->unique('user_id', 'delivery_representatives_user_id_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('delivery_representatives') || !Schema::hasColumn('delivery_representatives', 'user_id')) {
            return;
        }

        Schema::table('delivery_representatives', function (Blueprint $table) {
            $table->dropUnique('delivery_representatives_user_id_unique');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
