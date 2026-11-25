<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_recipients')) {
            Schema::create('notification_recipients', function (Blueprint $table) {
                $table->id();
                $table->string('recipient_type', 32); // guest_contact|user
                $table->string('recipient_key', 191)->nullable(); // non-PII: user:{id}; NULL for fingerprint-only guests
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('guest_id')->nullable()->constrained('guest_identities')->nullOnDelete();
                $table->string('status', 32)->default('active');
                $table->text('contact_encrypted')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['recipient_type', 'recipient_key'], 'notification_recipients_type_key_unique');
                $table->index(['recipient_type', 'status'], 'notification_recipients_type_status_idx');
                $table->index(['recipient_type', 'created_at'], 'notification_recipients_type_created_idx');
                $table->index(['user_id', 'recipient_type'], 'notification_recipients_user_type_idx');
                $table->index(['guest_id', 'recipient_type'], 'notification_recipients_guest_type_idx');
                $table->index(['guest_id', 'user_id', 'recipient_type'], 'nr_guest_user_type_idx');
            });

            return;
        }

        Schema::table('notification_recipients', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_recipients', 'recipient_key')) {
                $table->string('recipient_key', 191)->nullable()->after('recipient_type');
            }
            if (! Schema::hasColumn('notification_recipients', 'contact_encrypted')) {
                $table->text('contact_encrypted')->nullable()->after('status');
            }
            if (! Schema::hasColumn('notification_recipients', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });

        $this->ensureUniqueIndex('notification_recipients', ['recipient_type', 'recipient_key'], 'notification_recipients_type_key_unique');
        $this->ensureIndex('notification_recipients', ['recipient_type', 'status'], 'notification_recipients_type_status_idx');
        $this->ensureIndex('notification_recipients', ['recipient_type', 'created_at'], 'notification_recipients_type_created_idx');
        $this->ensureIndex('notification_recipients', ['user_id', 'recipient_type'], 'notification_recipients_user_type_idx');
        $this->ensureIndex('notification_recipients', ['guest_id', 'recipient_type'], 'notification_recipients_guest_type_idx');
        $this->ensureIndex('notification_recipients', ['guest_id', 'user_id', 'recipient_type'], 'nr_guest_user_type_idx');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }

    private function ensureIndex(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $columns, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
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
