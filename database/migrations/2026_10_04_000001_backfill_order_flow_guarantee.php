<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P2-2 (D6/C6): the database itself guarantees "every order has one flow".
     *
     * Additive and non-destructive:
     *  1. Backfill legacy NULLs with the ACTIVE local flow + a stage mirror
     *     (status -> current_status_id). Unmapped statuses or a missing
     *     active flow ABORT the migration with a report — never guessed.
     *  2. flow_id / current_status_id become NOT NULL (MySQL raw DDL with
     *     FK preservation; pgsql SET NOT NULL; sqlite skipped — no ALTER
     *     support there, where the model-level creation backstop still
     *     enforces the guarantee at runtime).
     *
     * Reversible: down() restores NULLability (backfilled data stays).
     */
    public function up(): void
    {
        if (!Schema::hasTable('orders')
            || !Schema::hasTable('order_flows')
            || !Schema::hasTable('order_statuses')
        ) {
            return; // pre-flow migration window
        }

        if (!Schema::hasColumn('orders', 'flow_id')
            || !Schema::hasColumn('orders', 'current_status_id')
        ) {
            return;
        }

        $this->backfillOrAbort();
        $this->enforceNotNull(nullToNotNull: true);
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE orders ALTER COLUMN flow_id DROP NOT NULL');
            DB::statement('ALTER TABLE orders ALTER COLUMN current_status_id DROP NOT NULL');

            return;
        }

        if ($driver !== 'mysql' || !Schema::hasTable('orders')) {
            return;
        }

        $this->enforceNotNull(nullToNotNull: false);
    }

    /**
     * Backfill every flow-less row with the ACTIVE local flow + stage
     * mirror. Throws (aborting the migration, nothing written) when:
     *  - no ACTIVE local flow exists, or
     *  - any flow-less row carries a status with no catalog row.
     */
    private function backfillOrAbort(): void
    {
        $nullFlowCount = (int) DB::table('orders')->whereNull('flow_id')->count();
        $nullStageCount = (int) DB::table('orders')->whereNull('current_status_id')->count();

        if ($nullFlowCount === 0 && $nullStageCount === 0) {
            return; // guarantee already holds
        }

        // Fail-closed parity with runtime resolution: only an ACTIVE local
        // flow may adopt rows. Absence aborts the deploy, never guesses.
        $flowId = DB::table('order_flows')
            ->where('shipping_type', 'local')
            ->where('is_active', true)
            ->value('id');

        if (!$flowId) {
            $this->abort('no ACTIVE local order flow exists; seed the order flows and retry.', [
                'flow_nulls' => $nullFlowCount,
                'stage_nulls' => $nullStageCount,
            ]);
        }

        $statusIds = DB::table('order_statuses')->pluck('id', 'code');

        // Fail-closed on unmapped: every legacy status must resolve to a
        // catalog row BEFORE any row is touched (abort + report, never guess).
        $unmapped = DB::table('orders')
            ->where(function ($query) {
                $query->whereNull('flow_id')->orWhereNull('current_status_id');
            })
            ->distinct()
            ->pluck('status')
            ->reject(fn ($code) => $statusIds->has($code))
            ->values()
            ->all();

        if (!empty($unmapped)) {
            $this->abort(
                'unmapped order statuses [' . implode(', ', $unmapped) . ']; ' .
                'remediate the order_statuses catalog (or the offending order rows) and retry.',
                [
                    'statuses' => $unmapped,
                    'flow_nulls' => $nullFlowCount,
                    'stage_nulls' => $nullStageCount,
                ]
            );
        }

        DB::table('orders')->whereNull('flow_id')->update(['flow_id' => $flowId]);

        foreach ($statusIds as $code => $id) {
            DB::table('orders')
                ->where('status', $code)
                ->whereNull('current_status_id')
                ->update(['current_status_id' => $id]);
        }

        // Belt-and-braces: the unmapped precheck makes this unreachable.
        $remaining = (int) DB::table('orders')
            ->whereNull('flow_id')
            ->orWhereNull('current_status_id')
            ->count();

        if ($remaining > 0) {
            $this->abort('backfill left residual NULL rows after mapping.', ['remaining' => $remaining]);
        }
    }

    /**
     * @param  bool  $nullToNotNull  true = enforce NOT NULL, false = restore NULLability.
     */
    private function enforceNotNull(bool $nullToNotNull): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $this->mysqlSetNullability('flow_id', $nullToNotNull);
            $this->mysqlSetNullability('current_status_id', $nullToNotNull);

            return;
        }

        if ($driver === 'pgsql') {
            $action = $nullToNotNull ? 'SET NOT NULL' : 'DROP NOT NULL';
            DB::statement("ALTER TABLE orders ALTER COLUMN flow_id {$action}");
            DB::statement("ALTER TABLE orders ALTER COLUMN current_status_id {$action}");

            return;
        }

        // SQLite cannot ALTER column nullability without a table rebuild;
        // the model-level creation backstop still enforces the guarantee.
    }

    /**
     * MySQL MODIFY on an FK column: drop the FK, change nullability,
     * re-add the FK with its original referential rules preserved.
     */
    private function mysqlSetNullability(string $column, bool $notNull): void
    {
        $fk = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'orders')
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->first(['CONSTRAINT_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME']);

        $rules = ['ON DELETE NO ACTION', 'ON UPDATE CASCADE'];

        if ($fk) {
            $rule = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('CONSTRAINT_NAME', $fk->CONSTRAINT_NAME)
                ->first(['DELETE_RULE', 'UPDATE_RULE']);

            if ($rule) {
                $rules = [
                    'ON DELETE ' . $this->mysqlReferentialRuleClause($rule->DELETE_RULE),
                    'ON UPDATE ' . $this->mysqlReferentialRuleClause($rule->UPDATE_RULE),
                ];
            }

            DB::statement("ALTER TABLE `orders` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
        }

        $nullability = $notNull ? 'NOT NULL' : 'NULL';
        DB::statement("ALTER TABLE `orders` MODIFY `{$column}` BIGINT UNSIGNED {$nullability}");

        if ($fk) {
            DB::statement(
                "ALTER TABLE `orders` ADD CONSTRAINT `{$fk->CONSTRAINT_NAME}` " .
                "FOREIGN KEY (`{$column}`) REFERENCES `{$fk->REFERENCED_TABLE_NAME}` " .
                "(`{$fk->REFERENCED_COLUMN_NAME}`) {$rules[0]} {$rules[1]}"
            );
        }
    }

    private function mysqlReferentialRuleClause(string $rule): string
    {
        return match (strtoupper($rule)) {
            'CASCADE' => 'CASCADE',
            'SET NULL' => 'SET NULL',
            'SET DEFAULT' => 'SET DEFAULT',
            'RESTRICT' => 'RESTRICT',
            default => 'NO ACTION',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function abort(string $reason, array $context): never
    {
        Log::error('Order flow backfill aborted: ' . $reason, $context);

        throw new \RuntimeException('Order flow backfill aborted: ' . $reason . ' Nothing was written.');
    }
};
