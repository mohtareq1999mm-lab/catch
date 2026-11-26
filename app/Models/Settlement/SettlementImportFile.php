<?php

declare(strict_types=1);

namespace App\Models\Settlement;

use Illuminate\Database\Eloquent\Model;

/**
 * #185: private import evidence header.
 *
 * Deliberately has no lifecycle hooks, observers, or state transitions: a row
 * records bytes, identity, parser metadata, and malformed rows only.
 */
class SettlementImportFile extends Model
{
    protected $table = 'settlement_import_files';

    protected $fillable = [
        'statement_key',
        'provider_code',
        'mode',
        'account_code',
        'original_name',
        'disk',
        'path',
        'file_hash',
        'mime_type',
        'size_bytes',
        'parser_class',
        'parser_version',
        'parsed_line_count',
        'malformed_rows',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'parsed_line_count' => 'integer',
        'malformed_rows' => 'array',
    ];
}
