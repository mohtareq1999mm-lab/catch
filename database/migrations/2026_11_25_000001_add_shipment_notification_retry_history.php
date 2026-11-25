<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #183: bounded shipment-notification retries and an append-only history.
 *
 * The notification table keeps its existing columns and unique indexes; the
 * new columns are additive defaults. History is a separate identity-keyed
 * table so identical replays cannot create duplicate rows and ordering is
 * stable even when several transitions share a timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipment_notifications')) {
            Schema::table('shipment_notifications', function (Blueprint $table) {
                if (!Schema::hasColumn('shipment_notifications', 'source')) {
                    $table->string('source', 32)->default('direct')->after('status');
                }
                if (!Schema::hasColumn('shipment_notifications', 'attempts')) {
                    $table->unsignedSmallInteger('attempts')->default(0)->after('source');
                }
                if (!Schema::hasColumn('shipment_notifications', 'max_attempts')) {
                    $table->unsignedSmallInteger('max_attempts')->default(3)->after('attempts');
                }
                if (!Schema::hasColumn('shipment_notifications', 'next_retry_at')) {
                    $table->timestamp('next_retry_at')->nullable()->after('max_attempts');
                }
                if (!Schema::hasColumn('shipment_notifications', 'last_error')) {
                    $table->text('last_error')->nullable()->after('failure_reason');
                }

                $table->index(['status', 'next_retry_at'], 'shipment_notifications_retry_due_idx');
            });
        }

        if (Schema::hasTable('shipment_notification_history')) {
            return;
        }

        Schema::create('shipment_notification_history', function (Blueprint $table) {
            // Soft references: deleting a notification must never erase history.
            $table->id();
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->unsignedBigInteger('timeline_event_id');
            $table->string('channel', 32);
            $table->string('recipient_type', 32);
            $table->string('recipient_id', 191);
            $table->unsignedBigInteger('notification_recipient_id')->nullable();
            $table->string('source', 32)->default('direct');
            $table->string('event', 32);
            $table->string('status', 32)->nullable();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->text('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            // Replay identity: timeline + channel + recipient identity + source
            // + lifecycle event + attempt number. insertOrIgnore makes replays
            // idempotent without ever updating an existing row.
            $table->unique(
                ['timeline_event_id', 'channel', 'recipient_type', 'recipient_id', 'source', 'event', 'attempt'],
                'shipment_notification_history_identity_unique'
            );
            $table->index(['timeline_event_id', 'id'], 'shipment_notification_history_timeline_order_idx');
            $table->index(['notification_id', 'id'], 'shipment_notification_history_notification_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_notification_history');

        if (!Schema::hasTable('shipment_notifications')) {
            return;
        }

        Schema::table('shipment_notifications', function (Blueprint $table) {
            $table->dropIndex('shipment_notifications_retry_due_idx');

            foreach (['source', 'attempts', 'max_attempts', 'next_retry_at', 'last_error'] as $column) {
                if (Schema::hasColumn('shipment_notifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
