<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('generated_by');
            }
            if (!Schema::hasColumn('invoices', 'downloaded_at')) {
                $table->timestamp('downloaded_at')->nullable()->after('verified_at');
            }
            if (!Schema::hasColumn('invoices', 'printed_at')) {
                $table->timestamp('printed_at')->nullable()->after('downloaded_at');
            }
            if (!Schema::hasColumn('invoices', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('printed_at');
            }
            if (!Schema::hasColumn('invoices', 'last_verified_at')) {
                $table->timestamp('last_verified_at')->nullable()->after('archived_at');
            }
            if (!Schema::hasColumn('invoices', 'verify_count')) {
                $table->unsignedInteger('verify_count')->default(0)->after('last_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'verified_at', 'downloaded_at', 'printed_at',
                'archived_at', 'last_verified_at', 'verify_count',
            ]);
        });
    }
};
