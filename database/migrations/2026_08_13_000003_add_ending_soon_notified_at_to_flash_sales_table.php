<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flash_sales', function (Blueprint $table) {
            $table->timestamp('ending_soon_notified_at')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('flash_sales', function (Blueprint $table) {
            $table->dropColumn('ending_soon_notified_at');
        });
    }
};
