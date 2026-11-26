<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Audit\ActivityAuditService;
use App\Exceptions\ShippingInboxDuplicateException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * #182: pre-constraint duplicate audit for the shipping inbox.
 *
 * The audit is read-only with respect to inbox rows: it never deletes,
 * rewrites, or merges deliveries. Only an activity-log record is written, so
 * an operator can see exactly which scoped identities must be resolved before
 * the unique constraint is installed. Event ids are recorded as `event_id`
 * (not `*_key`) so the central redactor does not hide the audit evidence.
 */
final class ShippingInboxDuplicateAudit
{
    private const LOG_NAME = 'shipping-inbox';

    private const LIMIT = 200;

    /**
     * @return array{
     *   webhook_events: list<array<string,mixed>>,
     *   provider_events: list<array<string,mixed>>,
     *   total_duplicate_groups: int,
     *   total_duplicate_rows: int,
     *   truncated: bool
     * }
     */
    public function audit(string $source = 'migration'): array
    {
        $webhook = $this->duplicates(
            'shipping_webhook_events',
            ['provider_code', 'mode', 'account_code', 'event_key'],
        );
        $provider = $this->duplicates(
            'shipping_provider_events',
            ['provider_code', 'mode', 'account_code', 'provider_event_key'],
        );

        $report = $this->buildReport($webhook, $provider);
        $this->recordAudit($report, $source);

        return $report;
    }

    /** Fail closed: the constraint must not be added while duplicates exist. */
    public function assertClean(array $report): void
    {
        if (($report['total_duplicate_groups'] ?? 0) > 0 || ($report['truncated'] ?? false)) {
            throw ShippingInboxDuplicateException::constraintBlocked((int) $report['total_duplicate_groups']);
        }
    }

    /**
     * @param array{groups: list<array<string,mixed>>, duplicate_rows: int, truncated: bool} $webhook
     * @param array{groups: list<array<string,mixed>>, duplicate_rows: int, truncated: bool} $provider
     * @return array{
     *   webhook_events: list<array<string,mixed>>,
     *   provider_events: list<array<string,mixed>>,
     *   total_duplicate_groups: int,
     *   total_duplicate_rows: int,
     *   truncated: bool
     * }
     */
    private function buildReport(array $webhook, array $provider): array
    {
        return [
            'webhook_events' => $webhook['groups'],
            'provider_events' => $provider['groups'],
            'total_duplicate_groups' => count($webhook['groups']) + count($provider['groups']),
            'total_duplicate_rows' => $webhook['duplicate_rows'] + $provider['duplicate_rows'],
            'truncated' => $webhook['truncated'] || $provider['truncated'],
        ];
    }

    /** @param array<string,mixed> $report */
    private function recordAudit(array $report, string $source): void
    {
        ActivityAuditService::recordBatch(
            self::LOG_NAME,
            $report['total_duplicate_groups'] > 0
                ? 'shipping_inbox_duplicates_found'
                : 'shipping_inbox_duplicates_none',
            'Shipping inbox account-key duplicate audit before uniqueness enforcement.',
            ['source' => $source, 'ticket' => '#182'],
            $report,
            Str::uuid()->toString(),
        );
    }

    /**
     * @param list<string> $identityColumns
     * @return array{groups: list<array<string,mixed>>, duplicate_rows: int, truncated: bool}
     */
    private function duplicates(string $table, array $identityColumns): array
    {
        if (! Schema::hasTable($table)) {
            return ['groups' => [], 'duplicate_rows' => 0, 'truncated' => false];
        }

        $select = $identityColumns;
        $select[] = DB::raw('COUNT(*) as row_count');

        $rows = DB::table($table)
            ->select($select)
            ->groupBy($identityColumns)
            ->having('row_count', '>', 1)
            ->orderByDesc('row_count')
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $rows->count() > self::LIMIT;
        $groups = [];
        $duplicateRows = 0;

        foreach ($rows->take(self::LIMIT) as $row) {
            $row = (array) $row;
            $count = (int) $row['row_count'];
            $groups[] = $this->auditGroup($row);
            $duplicateRows += $count - 1;
        }

        return [
            'groups' => $groups,
            'duplicate_rows' => $duplicateRows,
            'truncated' => $truncated,
        ];
    }

    /** @param array<string,mixed> $row */
    private function auditGroup(array $row): array
    {
        unset($row['row_count']);

        // The source column names end in `_key`; rename them so the audit
        // redactor preserves the identity for operators.
        foreach ($row as $name => $value) {
            if (str_ends_with((string) $name, '_key')) {
                $row[str_replace('_key', '_id', (string) $name)] = $value;
                unset($row[$name]);
            }
        }

        return $row;
    }
}
