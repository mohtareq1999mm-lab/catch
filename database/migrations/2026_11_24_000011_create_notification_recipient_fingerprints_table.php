<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_recipient_fingerprints')) {
            Schema::create('notification_recipient_fingerprints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
                $table->string('key_version', 32);
                $table->string('fingerprint_hash', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['key_version', 'fingerprint_hash'], 'nrf_key_version_hash_unique');
                $table->unique(['recipient_id', 'key_version'], 'nrf_recipient_key_version_unique');
                $table->index('recipient_id', 'nrf_recipient_id_idx');
            });

            return;
        }

        if (! Schema::hasColumn('notification_recipient_fingerprints', 'metadata')) {
            Schema::table('notification_recipient_fingerprints', fn (Blueprint $table) => $table->json('metadata')->nullable());
        }

        foreach ([
            [['key_version', 'fingerprint_hash'], 'nrf_key_version_hash_unique'],
            [['recipient_id', 'key_version'], 'nrf_recipient_key_version_unique'],
        ] as [$columns, $name]) {
            $this->ensureUniqueIndex('notification_recipient_fingerprints', $columns, $name);
        }

        if (! $this->indexExists('notification_recipient_fingerprints', ['recipient_id'], 'nrf_recipient_id_idx')) {
            Schema::table('notification_recipient_fingerprints', fn (Blueprint $table) => $table->index('recipient_id', 'nrf_recipient_id_idx'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipient_fingerprints');
    }

    private function ensureUniqueIndex(string $table, array $columns, string $name): void
    {
        foreach (Schema::getIndexes($table) as $indexName => $index) {
            if (($index['columns'] ?? []) !== $columns || $indexName === $name) {
                continue;
            }
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($indexName));
        }

        if (! $this->indexExists($table, $columns, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        }
    }

    private function indexExists(string $table, array $columns, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $indexName => $index) {
            if ($indexName === $name || ($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
};
