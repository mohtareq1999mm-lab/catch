<?php

declare(strict_types=1);

/*
 * #185: deployment-backed settlement import limits and parser adapters.
 *
 * Imports are evidence only. No setting here grants payment, reconciliation,
 * acceptance, adjustment, or closure authority; provider adapters are selected
 * by stable provider code and never execute provider traffic in tests.
 */
return [
    'imports' => [
        'disk' => 'private',
        'directory' => 'settlement-statements',
        'max_bytes' => max(1, (int) env('SETTLEMENT_IMPORT_MAX_BYTES', 2097152)),
        'allowed_extensions' => ['csv'],
        'allowed_mime_types' => ['text/csv', 'text/plain', 'application/csv'],
        'parsers' => [
            'default' => App\Services\Settlement\Parsers\CsvSettlementStatementParser::class,
        ],
    ],
];
