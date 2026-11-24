<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table): void {
            if (! Schema::hasColumn('cod_settlement_statements', 'file_hash')) {
                $table->char('file_hash', 64)->nullable()->after('statement_key');
                $table->index(['provider_code', 'mode', 'file_hash'], 'idx_cod_settlement_import_file_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table): void {
            if (Schema::hasColumn('cod_settlement_statements', 'file_hash')) {
                $table->dropIndex('idx_cod_settlement_import_file_hash');
                $table->dropColumn('file_hash');
            }
        });
    }
};
