<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #127/#175: database-level enforcement of immutable provider evidence.
 *
 * Eloquent observers restore snapshots on normal save(), but query-builder
 * writes and saveQuietly() bypass model events. On MySQL, BEFORE UPDATE
 * triggers reject any attempt to change an identity/payload snapshot while
 * leaving operational fields mutable. Non-MySQL connections skip trigger
 * creation; there the model guard remains advisory only.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const WEBHOOK_SNAPSHOT_FIELDS = [
        'provider_code',
        'mode',
        'account_code',
        'webhook_version',
        'event_key',
        'event_type',
        'provider_shipment_ref',
        'redacted_payload',
        'raw_status',
        'occurred_at',
        'received_at',
    ];

    /** @var list<string> */
    private const PROVIDER_EVENT_SNAPSHOT_FIELDS = [
        'provider_code',
        'mode',
        'account_code',
        'provider_event_key',
        'raw_status',
        'occurred_at',
        'received_at',
        'ordering_key',
        'mapping_version',
        'payload_reference',
        'payload_hash',
    ];

    public function up(): void
    {
        if (!$this->isMySql()) {
            return;
        }

        $this->dropTriggers();

        DB::unprepared($this->triggerSql(
            'shipping_webhook_events',
            'trg_shipping_webhook_events_snapshot_immutable',
            self::WEBHOOK_SNAPSHOT_FIELDS,
        ));
        DB::unprepared($this->triggerSql(
            'shipping_provider_events',
            'trg_shipping_provider_events_snapshot_immutable',
            self::PROVIDER_EVENT_SNAPSHOT_FIELDS,
        ));
    }

    public function down(): void
    {
        if (!$this->isMySql()) {
            return;
        }

        $this->dropTriggers();
    }

    private function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    private function dropTriggers(): void
    {
        foreach ([
            'trg_shipping_webhook_events_snapshot_immutable',
            'trg_shipping_provider_events_snapshot_immutable',
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS `'.$trigger.'`');
        }
    }

    /**
     * @param  list<string>  $snapshotFields
     */
    private function triggerSql(string $table, string $trigger, array $snapshotFields): string
    {
        $conditions = [];
        foreach ($snapshotFields as $field) {
            // Null-safe equality: NULL -> value and value -> NULL are changes.
            $conditions[] = 'NOT (NEW.`'.$field.'` <=> OLD.`'.$field.'`)';
        }

        return 'CREATE TRIGGER `'.$trigger.'` BEFORE UPDATE ON `'.$table.'` FOR EACH ROW '
            .'BEGIN IF '.implode(' OR ', $conditions).' THEN '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} snapshot fields are immutable (#175)'; "
            .'END IF; END';
    }
};
