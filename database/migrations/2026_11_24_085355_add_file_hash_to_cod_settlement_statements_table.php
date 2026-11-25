<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #162: imported statements are identified by provider + mode + file hash.
 *
 * NULL hashes stay legal: MySQL's unique index permits multiple NULL rows, so
 * manually created statements without a source file are not deduplicated by
 * the file-hash index. The service lock/pre-check remains the manual-scope
 * duplicate guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table): void {
            if (! Schema::hasColumn('cod_settlement_statements', 'file_hash')) {
                $table->char('file_hash', 64)->nullable()->after('statement_key');
            }

            // #156 established scope uniqueness. Imported statements are one
            // statement per source file within that scope, so file_hash joins
            // the scope key. Manual rows keep file_hash NULL; MySQL permits
            // multiple NULL rows and the service lock/pre-check still guards
            // their duplicate scope.
            if ($this->indexExists('uq_cod_settlement_statement_identity')) {
                $table->dropUnique('uq_cod_settlement_statement_identity');
            }

            $table->unique(
                [
                    'provider_code', 'mode', 'account_code', 'period_start',
                    'period_end', 'currency_code', 'file_hash',
                ],
                'uq_cod_settlement_statement_identity',
            );
            $table->unique(['provider_code', 'mode', 'file_hash'], 'uq_cod_settlement_import_file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table): void {
            $table->dropUnique('uq_cod_settlement_import_file_hash');

            if ($this->indexExists('uq_cod_settlement_statement_identity')) {
                $table->dropUnique('uq_cod_settlement_statement_identity');
            }

            $table->unique(
                ['provider_code', 'mode', 'account_code', 'period_start', 'period_end', 'currency_code'],
                'uq_cod_settlement_statement_identity',
            );

            if (Schema::hasColumn('cod_settlement_statements', 'file_hash')) {
                $table->dropColumn('file_hash');
            }
        });
    }

    private function indexExists(string $name): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['cod_settlement_statements', $name],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
