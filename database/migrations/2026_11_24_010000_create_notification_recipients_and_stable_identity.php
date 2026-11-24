<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_recipients')) {
            Schema::create('notification_recipients', function (Blueprint $table) {
                $table->id();
                $table->string('recipient_type', 32);
                $table->string('recipient_key', 191)->nullable();
                $table->text('contact_encrypted')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['recipient_type', 'recipient_key'], 'notification_recipients_type_key_unique');
                $table->index(['recipient_type', 'created_at'], 'notification_recipients_type_created_idx');
            });
        }

        if (!Schema::hasTable('notification_recipient_fingerprints')) {
            Schema::create('notification_recipient_fingerprints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('notification_recipient_id');
                $table->foreign('notification_recipient_id', 'notif_recipient_fp_recipient_fk')
                    ->references('id')->on('notification_recipients')->cascadeOnDelete();
                $table->string('version', 32);
                $table->string('fingerprint', 191);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['version', 'fingerprint'], 'notification_recipient_fingerprint_unique');
                $table->unique(['notification_recipient_id', 'version'], 'notification_recipient_version_unique');
                $table->index(['notification_recipient_id', 'created_at'], 'notification_recipient_fp_recipient_created_idx');
            });
        }

        Schema::table('shipment_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('shipment_notifications', 'notification_recipient_id')) {
                $table->unsignedBigInteger('notification_recipient_id')->nullable()->after('recipient_id');
                $table->foreign('notification_recipient_id', 'shipment_notifications_recipient_fk')
                    ->references('id')->on('notification_recipients')->restrictOnDelete();
            }
        });

        // Backfill legacy rows so stable dedupe covers historical notifications. The legacy four-column
        // unique prevents two rows with the same (timeline_event_id, channel, recipient_type, recipient_id),
        // so mapping each distinct legacy identity to one stable recipient cannot create new stable-key duplicates.
        if (Schema::hasColumn('shipment_notifications', 'notification_recipient_id')) {
            $recipientIdsByLegacyKey = [];
            DB::table('shipment_notifications')
                ->whereNull('notification_recipient_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$recipientIdsByLegacyKey) {
                    foreach ($rows as $row) {
                        $type = $this->legacyString($row->recipient_type, 'unknown', 32);
                        $key = $this->legacyString($row->recipient_id, 'unknown', 191);
                        $legacyKey = $type . "\0" . $key;

                        if (!isset($recipientIdsByLegacyKey[$legacyKey])) {
                            $recipientId = DB::table('notification_recipients')
                                ->where('recipient_type', $type)
                                ->where('recipient_key', $key)
                                ->value('id');

                            if (!$recipientId) {
                                $now = now();
                                DB::table('notification_recipients')->insertOrIgnore([
                                    'recipient_type' => $type,
                                    'recipient_key' => $key,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                                $recipientId = DB::table('notification_recipients')
                                    ->where('recipient_type', $type)
                                    ->where('recipient_key', $key)
                                    ->value('id');
                            }

                            $recipientIdsByLegacyKey[$legacyKey] = $recipientId;
                        }

                        DB::table('shipment_notifications')
                            ->where('id', $row->id)
                            ->update(['notification_recipient_id' => $recipientIdsByLegacyKey[$legacyKey]]);
                    }
                });
        }

        // Add stable-recipient uniqueness. Keep legacy unique in place for compatibility during rollout.
        // The column stays nullable for legacy/guest paths; supported recorder paths must resolve identity first.
        Schema::table('shipment_notifications', function (Blueprint $table) {
            $table->unique(
                ['timeline_event_id', 'channel', 'notification_recipient_id'],
                'shipment_notifications_timeline_channel_recipient_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shipment_notifications', function (Blueprint $table) {
            $table->dropUnique('shipment_notifications_timeline_channel_recipient_unique');
            if (Schema::hasColumn('shipment_notifications', 'notification_recipient_id')) {
                $table->dropForeign('shipment_notifications_recipient_fk');
                $table->dropColumn('notification_recipient_id');
            }
        });

        Schema::dropIfExists('notification_recipient_fingerprints');
        Schema::dropIfExists('notification_recipients');
    }

    private function legacyString(mixed $value, string $fallback, int $maxLength): string
    {
        $normalized = is_string($value) ? trim($value) : '';
        if ($normalized === '') {
            $normalized = $fallback;
        }

        return function_exists('mb_substr')
            ? mb_substr($normalized, 0, $maxLength)
            : substr($normalized, 0, $maxLength);
    }
};
