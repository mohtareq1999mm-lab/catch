<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #180: a recipient identity is one canonical (channel, normalized contact).
 *
 * Channel is part of the fingerprint identity, so the same contact string can
 * never collapse two channel identities onto one recipient, and the database
 * enforces one owner per (channel, key version, fingerprint hash).
 *
 * Existing rows (if any) were written before channels were modelled and are
 * kept under the legacy empty channel. That value is a migration sentinel, not
 * a channel: new code always supplies an explicit channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_recipient_fingerprints')) {
            return;
        }

        if (! Schema::hasColumn('notification_recipient_fingerprints', 'channel')) {
            Schema::table('notification_recipient_fingerprints', function (Blueprint $table) {
                $table->string('channel', 16)->default('')->after('recipient_id');
            });
        }

        // Add the wider uniqueness guarantee before dropping the legacy one:
        // existing rows all carry the empty-channel sentinel, so the new index
        // can coexist with the old constraint during the transition.
        if (! $this->indexExists('notification_recipient_fingerprints', 'nrf_channel_key_version_hash_unique')) {
            Schema::table('notification_recipient_fingerprints', function (Blueprint $table) {
                $table->unique(
                    ['channel', 'key_version', 'fingerprint_hash'],
                    'nrf_channel_key_version_hash_unique'
                );
            });
        }

        $this->dropIndexIfExists('notification_recipient_fingerprints', 'nrf_key_version_hash_unique');
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_recipient_fingerprints')) {
            return;
        }

        $this->dropIndexIfExists('notification_recipient_fingerprints', 'nrf_channel_key_version_hash_unique');

        // A pre-#180 unique constraint cannot represent two channels owning
        // the same contact. Fail explicitly rather than corrupting or silently
        // merging channel identities during rollback.
        $crossChannelDuplicates = DB::table('notification_recipient_fingerprints')
            ->select('key_version', 'fingerprint_hash')
            ->selectRaw('COUNT(DISTINCT channel) AS channel_count')
            ->groupBy('key_version', 'fingerprint_hash')
            ->havingRaw('COUNT(DISTINCT channel) > 1')
            ->exists();
        if ($crossChannelDuplicates) {
            throw new \RuntimeException(
                'Cannot roll back #180 while the same fingerprint exists in multiple channels.'
            );
        }

        if (! $this->indexExists('notification_recipient_fingerprints', 'nrf_key_version_hash_unique')) {
            Schema::table('notification_recipient_fingerprints', function (Blueprint $table) {
                $table->unique(['key_version', 'fingerprint_hash'], 'nrf_key_version_hash_unique');
            });
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }
};
