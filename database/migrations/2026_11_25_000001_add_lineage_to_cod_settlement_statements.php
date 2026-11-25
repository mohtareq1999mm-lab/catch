<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #179: additive statement lineage and finance acceptance evidence.
 *
 * Existing rows keep original/pending defaults. No historical statement is
 * backfilled, reclassified, or marked as superseded by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table) {
            if (Schema::hasColumn('cod_settlement_statements', 'statement_type')) {
                return;
            }

            $table->string('statement_type', 32)->default('original')->after('statement_key');
            $table->foreignId('supersedes_statement_id')
                ->nullable()
                ->after('statement_type')
                ->constrained('cod_settlement_statements')
                ->nullOnDelete();
            $table->string('acceptance_status', 32)
                ->default('pending')
                ->after('status');
            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('acceptance_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_reason')->nullable()->after('reviewed_at');
            $table->timestamp('superseded_at')->nullable()->after('review_reason');

            $table->index(['statement_type', 'acceptance_status'], 'idx_cod_settlement_lineage');
            $table->index('supersedes_statement_id', 'idx_cod_settlement_supersedes');
        });
    }

    public function down(): void
    {
        Schema::table('cod_settlement_statements', function (Blueprint $table) {
            if (!Schema::hasColumn('cod_settlement_statements', 'statement_type')) {
                return;
            }

            $table->dropConstrainedForeignId('supersedes_statement_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropIndex('idx_cod_settlement_lineage');
            $table->dropIndex('idx_cod_settlement_supersedes');

            $table->dropColumn([
                'statement_type',
                'acceptance_status',
                'reviewed_at',
                'review_reason',
                'superseded_at',
            ]);
        });
    }
};
