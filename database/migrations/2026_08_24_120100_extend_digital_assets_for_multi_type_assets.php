<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W3 — widen digital_assets into the multi-type asset registry table
     * (Workstream 1 architecture-gaps G2/G3).
     *
     * Existing PDF FILE rows remain fully valid: every new column is
     * nullable or carries a safe default (status backfills to 'active'),
     * and nothing about path/mime/size/original_name semantics changes
     * for file-backed assets.
     *
     * `path` becomes nullable because URL/LICENSE/ACCESS assets have no
     * physical file (locked decision A2 / target schema). File-backed
     * assets keep writing it exactly as before.
     */
    public function up(): void
    {
        Schema::table('digital_assets', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('original_name');
            $table->string('extension', 16)->nullable()->after('mime');
            $table->string('checksum', 64)->nullable();
            $table->string('status', 20)->default('active')->after('type');
            $table->json('metadata')->nullable();
            $table->text('external_url')->nullable()->after('path');
            $table->text('secret')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Status-filtered product listings (admin grids, future access checks).
            $table->index(['product_id', 'status'], 'digital_assets_product_status_idx');

            $table->text('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->downSqlite();

            return;
        }

        Schema::table('digital_assets', function (Blueprint $table) {
            $table->dropIndex('digital_assets_product_status_idx');
            $table->dropColumn([
                'display_name',
                'extension',
                'checksum',
                'status',
                'metadata',
                'external_url',
                'secret',
                'expires_at',
            ]);
        });

        // Self-guarding: engines reject NOT NULL restoration while any
        // non-file row (NULL path) exists — documented migration
        // limitation, never silently destroys data.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE digital_assets MODIFY path VARCHAR(255) NOT NULL');

            return;
        }

        Schema::table('digital_assets', function (Blueprint $table) {
            $table->string('path')->change();
        });
    }

    /**
     * SQLite cannot reliably reverse ->change() modifications (the native
     * table rebuild loses type/nullability fidelity), so down() rebuilds
     * the table with the EXACT original 2026_08_23_120200 definition and
     * copies the original columns across. Non-file W3+ rows would lose
     * their extra fields — they cannot exist on the rolled-back schema.
     */
    private function downSqlite(): void
    {
        Schema::disableForeignKeyConstraints();

        // SQLite index names are GLOBAL per database. Building the fresh
        // table under a temporary NAME (with its own named indexes), then
        // dropping the original (which releases the canonical names) and
        // renaming avoids every collision.
        Schema::create('digital_assets_w3_new', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 20)->default('FILE');
            $table->string('disk', 30)->default('private');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Deliberately NO named unique/index declarations here: SQLite
            // index names are database-global and the original table still
            // owns them until step 3 below.
        });

        $rows = DB::table('digital_assets')
            ->select(['id', 'uuid', 'product_id', 'type', 'disk', 'path', 'original_name', 'mime', 'size', 'sort_order', 'created_at', 'updated_at'])
            ->get();

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('digital_assets_w3_new')->insert($chunk->map(fn ($r) => (array) $r)->all());
        }

        Schema::drop('digital_assets');

        Schema::rename('digital_assets_w3_new', 'digital_assets');

        Schema::table('digital_assets', function (Blueprint $table) {
            $table->unique('uuid');
            $table->index(['product_id', 'sort_order'], 'digital_assets_product_sort_idx');
        });

        Schema::enableForeignKeyConstraints();
    }
};
