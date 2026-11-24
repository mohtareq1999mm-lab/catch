<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryRepresentativeMigrationSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::connection('sqlite')->create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        DB::connection('sqlite')->table('users')->insert([
            'id' => 1,
            'name' => 'User One',
            'email' => 'user@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sqlite_aborts_and_preserves_legacy_rows_without_user_id(): void
    {
        Schema::connection('sqlite')->create('delivery_representatives', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::connection('sqlite')->table('delivery_representatives')->insert([
            'id' => 7,
            'name' => 'Legacy Rep',
            'phone' => '100',
            'email' => 'legacy@example.test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_24_000001_add_user_id_to_delivery_representatives_table.php');

        try {
            $migration->up();
            $this->fail('Expected migration to refuse unmapped legacy rows.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot safely migrate', $exception->getMessage());
            $this->assertStringContainsString('user_id', $exception->getMessage());
        }

        $this->assertSame(1, DB::connection('sqlite')->table('delivery_representatives')->count());
        $this->assertSame('Legacy Rep', DB::connection('sqlite')->table('delivery_representatives')->value('name'));
        $this->assertFalse(Schema::connection('sqlite')->hasColumn('delivery_representatives', 'user_id'));
        $this->assertFalse(Schema::connection('sqlite')->hasTable('delivery_representatives__tmp_173'));
    }

    public function test_sqlite_rebuild_preserves_rows_and_enforces_user_link(): void
    {
        Schema::connection('sqlite')->create('delivery_representatives', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::connection('sqlite')->table('delivery_representatives')->insert([
            'id' => 7,
            'user_id' => 1,
            'name' => 'Mapped Rep',
            'phone' => '100',
            'email' => 'mapped@example.test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_24_000001_add_user_id_to_delivery_representatives_table.php');
        $migration->up();

        $row = DB::connection('sqlite')->table('delivery_representatives')->first();
        $this->assertSame(7, (int) $row->id);
        $this->assertSame(1, (int) $row->user_id);
        $this->assertSame('Mapped Rep', $row->name);

        $columns = DB::connection('sqlite')->select("PRAGMA table_info('delivery_representatives')");
        $userColumn = collect($columns)->first(fn ($column) => $column->name === 'user_id');
        $this->assertNotNull($userColumn);
        $this->assertSame(1, (int) $userColumn->notnull);

        $indexes = DB::connection('sqlite')->select("PRAGMA index_list('delivery_representatives')");
        $this->assertNotNull(collect($indexes)->first(fn ($index) => $index->name === 'delivery_representatives_user_id_unique'));

        $foreignKeys = DB::connection('sqlite')->select("PRAGMA foreign_key_list('delivery_representatives')");
        $this->assertNotNull(collect($foreignKeys)->first(fn ($key) => $key->table === 'users' && $key->from === 'user_id'));
    }

    public function test_sqlite_aborts_and_preserves_rows_when_user_id_is_duplicated(): void
    {
        Schema::connection('sqlite')->create('delivery_representatives', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        foreach (['Rep One', 'Rep Two'] as $name) {
            DB::connection('sqlite')->table('delivery_representatives')->insert([
                'user_id' => 1,
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_24_000001_add_user_id_to_delivery_representatives_table.php');

        try {
            $migration->up();
            $this->fail('Expected migration to refuse duplicate user links.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot enforce unique user_id', $exception->getMessage());
        }

        $this->assertSame(2, DB::connection('sqlite')->table('delivery_representatives')->count());
        $this->assertFalse(Schema::connection('sqlite')->hasTable('delivery_representatives__tmp_173'));
    }

    public function test_sqlite_empty_table_gets_required_user_link_schema(): void
    {
        Schema::connection('sqlite')->create('delivery_representatives', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_11_24_000001_add_user_id_to_delivery_representatives_table.php');
        $migration->up();

        $this->assertTrue(Schema::connection('sqlite')->hasColumn('delivery_representatives', 'user_id'));
        $this->assertSame(0, DB::connection('sqlite')->table('delivery_representatives')->count());

        $columns = DB::connection('sqlite')->select("PRAGMA table_info('delivery_representatives')");
        $userColumn = collect($columns)->first(fn ($column) => $column->name === 'user_id');
        $this->assertNotNull($userColumn);
        $this->assertSame(1, (int) $userColumn->notnull);
    }
}
