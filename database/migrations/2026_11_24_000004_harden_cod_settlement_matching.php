<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #156 review hardening: scoped lookups, durable audit evidence and safe
 * rollback semantics. No historical provider ids are inferred or backfilled.
 */
return new class extends Migration
{
    private const ORDER_NUMBER_INDEX = 'idx_orders_order_number';
    private const MATCH_INVARIANT = 'chk_cod_line_match_order';

    public function up(): void
    {
        $this->addOrderNumberIndex();
        $this->hardenAudits();
        $this->addMatchInvariant();
    }

    public function down(): void
    {
        $this->dropMatchInvariant();
        $this->restoreAuditForeignKeys();
        $this->dropOrderNumberIndex();
    }

    private function addOrderNumberIndex(): void
    {
        if (!Schema::hasColumn('orders', 'order_number')) {
            return;
        }

        if ($this->hasIndex('orders', self::ORDER_NUMBER_INDEX)) {
            return;
        }

        // Non-unique: order_number is not a database-unique business key.
        // Ambiguity is detected in the service; this index only bounds scans.
        Schema::table('orders', function (Blueprint $table) {
            $table->index('order_number', self::ORDER_NUMBER_INDEX);
        });
    }

    private function dropOrderNumberIndex(): void
    {
        if ($this->hasIndex('orders', self::ORDER_NUMBER_INDEX)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex(self::ORDER_NUMBER_INDEX);
            });
        }
    }

    private function hardenAudits(): void
    {
        if (!Schema::hasTable('cod_settlement_match_audits')) {
            return;
        }

        Schema::table('cod_settlement_match_audits', function (Blueprint $table) {
            if (!Schema::hasColumn('cod_settlement_match_audits', 'actor_name')) {
                $table->string('actor_name', 191)->nullable()->after('actor_id');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'statement_key')) {
                $table->string('statement_key', 191)->nullable()->after('actor_name');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'line_key')) {
                $table->string('line_key', 191)->nullable()->after('statement_key');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'awb')) {
                $table->string('awb', 191)->nullable()->after('order_id');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'provider_shipment_id')) {
                $table->string('provider_shipment_id', 191)->nullable()->after('awb');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'order_number')) {
                $table->string('order_number', 191)->nullable()->after('provider_shipment_id');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'previous_order_id')) {
                $table->unsignedBigInteger('previous_order_id')->nullable()->after('previous_match_status');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'previous_match_method')) {
                $table->string('previous_match_method', 32)->nullable()->after('previous_order_id');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'provider_code')) {
                $table->string('provider_code', 32)->nullable()->after('previous_match_method');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'mode')) {
                $table->string('mode', 16)->nullable()->after('provider_code');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'account_code')) {
                $table->string('account_code', 64)->nullable()->after('mode');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'period_start')) {
                $table->date('period_start')->nullable()->after('account_code');
            }
            if (!Schema::hasColumn('cod_settlement_match_audits', 'period_end')) {
                $table->date('period_end')->nullable()->after('period_start');
            }
        });

        Schema::table('cod_settlement_match_audits', function (Blueprint $table) {
            $table->dropForeign(['statement_id']);
            $table->dropForeign(['line_id']);
            $table->dropForeign(['actor_id']);
        });

        // Actor deletion must keep the evidence row and rely on the snapshot.
        Schema::table('cod_settlement_match_audits', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
        });

        Schema::table('cod_settlement_match_audits', function (Blueprint $table) {
            $table->foreign('statement_id', 'fk_cod_audit_statement_restrict')
                ->references('id')->on('cod_settlement_statements')->restrictOnDelete();
            $table->foreign('line_id', 'fk_cod_audit_line_restrict')
                ->references('id')->on('cod_settlement_lines')->restrictOnDelete();
            $table->foreign('actor_id', 'fk_cod_audit_actor_nullable')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function restoreAuditForeignKeys(): void
    {
        if (!Schema::hasTable('cod_settlement_match_audits')) {
            return;
        }

        // actor_id stays nullable on rollback: deleted actors must not make
        // down() fail on evidence that already relies on the name snapshot.
        Schema::table('cod_settlement_match_audits', function (Blueprint $table) {
            $table->dropForeign('fk_cod_audit_statement_restrict');
            $table->dropForeign('fk_cod_audit_line_restrict');
            $table->dropForeign('fk_cod_audit_actor_nullable');
            $table->foreign('statement_id', 'cod_settlement_match_audits_statement_id_foreign')
                ->references('id')->on('cod_settlement_statements')->cascadeOnDelete();
            $table->foreign('line_id', 'cod_settlement_match_audits_line_id_foreign')
                ->references('id')->on('cod_settlement_lines')->cascadeOnDelete();
            $table->foreign('actor_id', 'cod_settlement_match_audits_actor_id_foreign')
                ->references('id')->on('users')->cascadeOnDelete();

            foreach ([
                'period_end', 'period_start', 'account_code', 'mode', 'provider_code',
                'previous_match_method', 'previous_order_id', 'provider_shipment_id',
                'order_number', 'awb', 'line_key', 'statement_key', 'actor_name',
            ] as $column) {
                if (Schema::hasColumn('cod_settlement_match_audits', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addMatchInvariant(): void
    {
        if (!$this->isMysqlWithLines()) {
            return;
        }

        if (!$this->hasMatchInvariant()) {
            DB::statement(
                'ALTER TABLE cod_settlement_lines ADD CONSTRAINT ' . self::MATCH_INVARIANT
                . " CHECK (order_id IS NOT NULL OR match_status = 'unmatched')"
            );
        }
    }

    private function dropMatchInvariant(): void
    {
        if (!$this->isMysqlWithLines() || !$this->hasMatchInvariant()) {
            return;
        }

        DB::statement('ALTER TABLE cod_settlement_lines DROP CHECK ' . self::MATCH_INVARIANT);
    }

    private function isMysqlWithLines(): bool
    {
        return DB::connection()->getDriverName() === 'mysql' && Schema::hasTable('cod_settlement_lines');
    }

    private function hasMatchInvariant(): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            ['cod_settlement_lines', self::MATCH_INVARIANT, 'CHECK'],
        ) !== [];
    }

    private function hasIndex(string $table, string $index): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index],
        ) !== [];
    }
};
