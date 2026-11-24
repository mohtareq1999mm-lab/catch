<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #162: imported statements are identified by provider + mode + file hash.
 *
 * NULL hashes stay legal: MySQL's unique index permits multiple NULL rows, so
 * manually created statements without a source file are not deduplicated.
 * Non-NULL hashes are unique within the provider/mode pair — the database,
 * not just the service pre-check, is the final concurrency authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table): void {
            if (! Schema::hasColumn('cod_settlement_statements', 'file_hash')) {
                $table->char('file_hash', 64)->nullable()->after('statement_key');
            }

            $table->unique(['provider_code', 'mode', 'file_hash'], 'uq_cod_settlement_import_file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table): void {
            $table->dropIndex('uq_cod_settlement_import_file_hash');

            if (Schema::hasColumn('cod_settlement_statements', 'file_hash')) {
                $table->dropColumn('file_hash');
            }
        });
    }
};
