<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('static_sections', function (Blueprint $table) {
            $table->string('type')->default('text')->after('static_page_id');
            $table->json('config')->nullable()->after('content');
            $table->boolean('is_active')->default(true)->after('config');
        });

        // Backfill existing rows (TiDB/MySQL compatible — no enum type).
        // Legacy sections are text/content blocks.
        DB::table('static_sections')->whereNull('type')->orWhere('type', '')->update(['type' => 'text']);
    }

    public function down(): void
    {
        Schema::table('static_sections', function (Blueprint $table) {
            $table->dropColumn(['type', 'config', 'is_active']);
        });
    }
};
