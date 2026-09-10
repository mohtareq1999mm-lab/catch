<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'uuid')) {
                $table->uuid('uuid')->unique()->after('id');
            }
            if (!Schema::hasColumn('invoices', 'verification_hash')) {
                $table->string('verification_hash', 64)->nullable()->after('snapshot_hash');
                $table->index('verification_hash', 'idx_invoices_verification_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_verification_hash');
            $table->dropColumn(['uuid', 'verification_hash']);
        });
    }
};
