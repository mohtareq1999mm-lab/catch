<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('delivery_representatives')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->upSqlite();
            return;
        }

        $this->upRelational($driver);
    }

    public function down(): void
    {
        if (!Schema::hasTable('delivery_representatives')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->downSqlite();
            return;
        }

        Schema::table('delivery_representatives', function (Blueprint $table) {
            if ($this->indexExists('delivery_representatives', 'delivery_representatives_user_id_unique')) {
                $table->dropUnique('delivery_representatives_user_id_unique');
            }
            if (Schema::hasColumn('delivery_representatives', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });
    }

    private function upRelational(string $driver): void
    {
        $hasUserId = Schema::hasColumn('delivery_representatives', 'user_id');

        if (!$hasUserId) {
            $rowCount = (int) DB::table('delivery_representatives')->count();
            if ($rowCount > 0) {
                throw new RuntimeException(
                    'Migration 2026_11_24_000001_add_user_id_to_delivery_representatives_table cannot safely add required user_id to '
                    . $rowCount . ' existing delivery_representatives row(s) on ' . $driver . '. '
                    . 'Backfill a valid users.id for every row (or resolve legacy rows) before rerunning. Schema was not modified.'
                );
            }

            // Fresh install / empty table: safe to add required column.
            Schema::table('delivery_representatives', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });
            $hasUserId = true;
        }

        $this->assertNoLegacyRowsWithoutUser($driver);
        $this->assertNoDuplicateUserLinks($hasUserId);

        // Enforce DB constraints across supported paths. Fresh installs have no rows
        // and pass the legacy check; populated installs must have explicit user_id.
        if ($driver === 'mysql') {
            // MODIFY NOT NULL is blocked while the column is used by a FK.
            // Enforce NOT NULL first, then (re)create the FK.
            $this->ensureMysqlUserIdNotNull();
            $this->ensureMysqlForeignKey();
        } else {
            // Conservative path for other relational drivers: enforce FK/unique;
            // NOT NULL enforcement is driver-specific and may require doctrine/dbal.
            if (!$this->foreignKeyExists('delivery_representatives', 'user_id')) {
                Schema::table('delivery_representatives', function (Blueprint $table) {
                    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                });
            }
        }

        if ($hasUserId && !$this->indexExists('delivery_representatives', 'delivery_representatives_user_id_unique')) {
            Schema::table('delivery_representatives', function (Blueprint $table) {
                $table->unique('user_id', 'delivery_representatives_user_id_unique');
            });
        }
    }

    private function upSqlite(): void
    {
        $hasUserId = Schema::hasColumn('delivery_representatives', 'user_id');
        $legacyCount = $hasUserId
            ? (int) DB::table('delivery_representatives')->whereNull('user_id')->count()
            : (int) DB::table('delivery_representatives')->count();

        if ($legacyCount > 0) {
            throw new RuntimeException(
                'Migration 2026_11_24_000001_add_user_id_to_delivery_representatives_table cannot safely migrate '
                . $legacyCount . ' delivery_representatives row(s) without a mapped user_id. '
                . 'Backfill delivery_representatives.user_id to existing users (or remove/resolve legacy rows) before rerunning this migration. '
                . 'Rows were not modified.'
            );
        }

        $this->assertNoDuplicateUserLinks($hasUserId);

        Schema::create('delivery_representatives__tmp_173', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique('user_id', 'delivery_representatives_user_id_unique');
            $table->index(['is_active', 'id']);
        });

        if ($hasUserId) {
            DB::statement(
                'INSERT INTO delivery_representatives__tmp_173 (id,user_id,name,phone,email,is_active,metadata,created_at,updated_at) '
                . 'SELECT id,user_id,name,phone,email,is_active,metadata,created_at,updated_at FROM delivery_representatives WHERE user_id IS NOT NULL'
            );
        } else {
            DB::statement(
                'INSERT INTO delivery_representatives__tmp_173 (id,user_id,name,phone,email,is_active,metadata,created_at,updated_at) '
                . 'SELECT id,NULL,name,phone,email,is_active,metadata,created_at,updated_at FROM delivery_representatives WHERE 1 = 0'
            );
        }

        Schema::drop('delivery_representatives');
        Schema::rename('delivery_representatives__tmp_173', 'delivery_representatives');
    }

    private function downSqlite(): void
    {
        Schema::create('delivery_representatives__tmp_173_down', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'id']);
        });
        DB::statement(
            'INSERT INTO delivery_representatives__tmp_173_down (id,name,phone,email,is_active,metadata,created_at,updated_at) '
            . 'SELECT id,name,phone,email,is_active,metadata,created_at,updated_at FROM delivery_representatives'
        );
        Schema::drop('delivery_representatives');
        Schema::rename('delivery_representatives__tmp_173_down', 'delivery_representatives');
    }

    private function assertNoLegacyRowsWithoutUser(string $driver): void
    {
        $nullCount = (int) DB::table('delivery_representatives')->whereNull('user_id')->count();
        if ($nullCount === 0) {
            return;
        }

        $sampleIds = DB::table('delivery_representatives')
            ->whereNull('user_id')
            ->orderBy('id')
            ->limit(20)
            ->pluck('id')
            ->implode(', ');

        throw new RuntimeException(
            'Migration 2026_11_24_000001_add_user_id_to_delivery_representatives_table cannot enforce NOT NULL user_id on '
            . $driver . ': ' . $nullCount . ' delivery_representatives row(s) have NULL user_id'
            . ($sampleIds !== '' ? ' (sample ids: ' . $sampleIds . ')' : '') . '. '
            . 'Backfill each row with a valid users.id before rerunning. Rows were not modified.'
        );
    }

    private function assertNoDuplicateUserLinks(bool $hasUserId): void
    {
        if (!$hasUserId) {
            return;
        }

        $duplicates = DB::table('delivery_representatives')
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->having('total', '>', 1)
            ->orderBy('user_id')
            ->limit(20)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $sample = $duplicates
            ->map(fn ($row) => (int) $row->user_id . ' (x' . (int) $row->total . ')')
            ->implode(', ');

        throw new RuntimeException(
            'Migration 2026_11_24_000001_add_user_id_to_delivery_representatives_table cannot enforce unique user_id: '
            . $duplicates->count() . ' user_id value(s) are duplicated (' . $sample . '). '
            . 'Resolve duplicate delivery representative profiles before rerunning. Rows were not modified.'
        );
    }

    private function ensureMysqlForeignKey(): void
    {
        if ($this->foreignKeyExists('delivery_representatives', 'user_id')) {
            return;
        }

        Schema::table('delivery_representatives', function (Blueprint $table) {
            $table->foreign('user_id', 'delivery_representatives_user_id_foreign')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    private function ensureMysqlUserIdNotNull(): void
    {
        $column = DB::selectOne(
            "SHOW COLUMNS FROM `delivery_representatives` WHERE Field = 'user_id'"
        );
        if (!$column) {
            throw new RuntimeException(
                'Migration 2026_11_24_000001_add_user_id_to_delivery_representatives_table cannot find delivery_representatives.user_id on mysql.'
            );
        }

        $fields = [];
        foreach ((array) $column as $key => $value) {
            $fields[strtolower((string) $key)] = $value;
        }
        if (strtoupper((string) ($fields['null'] ?? '')) === 'NO') {
            return;
        }

        // MySQL cannot MODIFY a column used by a FK. Drop any such FK first;
        // ensureMysqlForeignKey() recreates it immediately after.
        $this->dropMysqlUserForeignKey();

        // Safe after assertNoLegacyRowsWithoutUser() confirmed no NULLs.
        DB::statement('ALTER TABLE `delivery_representatives` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');
    }

    private function dropMysqlUserForeignKey(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $database = $connection->getDatabaseName();
        $rows = DB::select(
            'SELECT tc.CONSTRAINT_NAME AS constraint_name '
            . 'FROM information_schema.table_constraints tc '
            . 'INNER JOIN information_schema.key_column_usage kcu '
            . 'ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME '
            . 'AND tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA '
            . 'AND tc.TABLE_NAME = kcu.TABLE_NAME '
            . "WHERE tc.CONSTRAINT_TYPE = 'FOREIGN KEY' AND tc.TABLE_SCHEMA = ? "
            . 'AND tc.TABLE_NAME = ? AND kcu.COLUMN_NAME = ? LIMIT 1',
            [$database, 'delivery_representatives', 'user_id']
        );

        foreach ($rows as $row) {
            $name = (string) ($row->constraint_name ?? '');
            if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                throw new RuntimeException(
                    'Migration 2026_11_24_000001_add_user_id_to_delivery_representatives_table found an unsafe FK constraint name; refusing to alter schema.'
                );
            }

            DB::statement("ALTER TABLE `delivery_representatives` DROP FOREIGN KEY `{$name}`");
            return;
        }
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $database = $connection->getDatabaseName();
        $rows = DB::select(
            'SELECT 1 FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND column_name = ? AND referenced_table_name IS NOT NULL LIMIT 1',
            [$database, $table, $column]
        );

        return !empty($rows);
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            $rows = DB::select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $index]
            );
            return !empty($rows);
        }

        return false;
    }
};
