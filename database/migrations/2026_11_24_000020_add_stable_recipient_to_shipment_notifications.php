<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #167: shipment notification dedupe keys on the stable recipient identity
 * owned by #176, not on a rotating fingerprint version.
 *
 * Legacy `user` rows are backfilled onto the canonical `user:{id}` recipient
 * because that key is non-PII and derivable from the stored legacy value.
 * Legacy guest rows keep a null stable link: their stored recipient value may
 * be a contact (PII) and inventing an identity for them would silently merge
 * unrelated contacts. Those rows stay covered by the legacy unique index.
 */
return new class extends Migration
{
    private const UNIQUE = 'shipment_notifications_stable_recipient_unique';
    private const FK = 'shipment_notifications_recipient_fk';

    public function up(): void
    {
        if (!Schema::hasTable('shipment_notifications') || !Schema::hasTable('notification_recipients')) {
            return;
        }

        if (!Schema::hasColumn('shipment_notifications', 'notification_recipient_id')) {
            Schema::table('shipment_notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('notification_recipient_id')->nullable()->after('recipient_id');

                if ($this->supportsAlterForeignKey()) {
                    $table->foreign('notification_recipient_id', self::FK)
                        ->references('id')->on('notification_recipients')
                        ->restrictOnDelete();
                }
            });
        }

        $this->backfillUserRecipients();
        $this->detachDuplicateLinks();
        $this->ensureUniqueIndex();
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipment_notifications') || !Schema::hasColumn('shipment_notifications', 'notification_recipient_id')) {
            return;
        }

        Schema::table('shipment_notifications', function (Blueprint $table) {
            if ($this->indexExists(self::UNIQUE)) {
                $table->dropUnique(self::UNIQUE);
            }

            if ($this->supportsAlterForeignKey() && $this->foreignKeyExists()) {
                $table->dropForeign(self::FK);
            }

            $table->dropColumn('notification_recipient_id');
        });
    }

    private function backfillUserRecipients(): void
    {
        $legacyIds = DB::table('shipment_notifications')
            ->whereNull('notification_recipient_id')
            ->where('recipient_type', 'user')
            ->distinct()
            ->pluck('recipient_id');

        foreach ($legacyIds as $legacyId) {
            $legacy = trim((string) $legacyId);
            if ($legacy === '' || ctype_digit($legacy) !== true) {
                continue;
            }

            $userId = (int) $legacy;
            if ($userId <= 0) {
                continue;
            }

            $recipientId = $this->canonicalUserRecipientId($userId);
            if ($recipientId === null) {
                continue;
            }

            DB::table('shipment_notifications')
                ->where('recipient_type', 'user')
                ->where('recipient_id', $legacy)
                ->whereNull('notification_recipient_id')
                ->update(['notification_recipient_id' => $recipientId]);
        }
    }

    private function canonicalUserRecipientId(int $userId): ?int
    {
        $key = 'user:' . $userId;

        $existing = DB::table('notification_recipients')
            ->where('recipient_type', 'user')
            ->where('recipient_key', $key)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        // A hard-deleted user leaves the canonical recipient without a user
        // link; the stable key still identifies the historical recipient.
        $liveUserId = DB::table('users')->where('id', $userId)->value('id');

        DB::table('notification_recipients')->insertOrIgnore([
            'recipient_type' => 'user',
            'recipient_key' => $key,
            'user_id' => $liveUserId !== null ? (int) $liveUserId : null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = DB::table('notification_recipients')
            ->where('recipient_type', 'user')
            ->where('recipient_key', $key)
            ->value('id');

        return $created !== null ? (int) $created : null;
    }

    /**
     * Defensive: a legacy writer could have stored the same logical recipient
     * under two string forms. Only the first row keeps the stable link so the
     * unique index can be created without deleting history.
     */
    private function detachDuplicateLinks(): void
    {
        $groups = DB::table('shipment_notifications')
            ->select('timeline_event_id', 'channel', 'notification_recipient_id')
            ->whereNotNull('notification_recipient_id')
            ->groupBy('timeline_event_id', 'channel', 'notification_recipient_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $keepId = DB::table('shipment_notifications')
                ->where('timeline_event_id', $group->timeline_event_id)
                ->where('channel', $group->channel)
                ->where('notification_recipient_id', $group->notification_recipient_id)
                ->orderBy('id')
                ->value('id');

            if ($keepId === null) {
                continue;
            }

            DB::table('shipment_notifications')
                ->where('timeline_event_id', $group->timeline_event_id)
                ->where('channel', $group->channel)
                ->where('notification_recipient_id', $group->notification_recipient_id)
                ->where('id', '<>', (int) $keepId)
                ->update(['notification_recipient_id' => null]);
        }
    }

    private function ensureUniqueIndex(): void
    {
        if ($this->indexExists(self::UNIQUE)) {
            return;
        }

        Schema::table('shipment_notifications', function (Blueprint $table) {
            $table->unique(
                ['timeline_event_id', 'channel', 'notification_recipient_id'],
                self::UNIQUE
            );
        });
    }

    private function indexExists(string $name): bool
    {
        if (!$this->usesInformationSchema()) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'shipment_notifications')
            ->where('index_name', $name)
            ->exists();
    }

    private function foreignKeyExists(): bool
    {
        if (!$this->usesInformationSchema()) {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'shipment_notifications')
            ->where('constraint_name', self::FK)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }

    private function usesInformationSchema(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }

    private function supportsAlterForeignKey(): bool
    {
        return Schema::getConnection()->getDriverName() !== 'sqlite';
    }
};
