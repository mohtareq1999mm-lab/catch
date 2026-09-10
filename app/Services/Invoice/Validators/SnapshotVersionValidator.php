<?php

namespace App\Services\Invoice\Validators;

use App\Contracts\Services\Invoice\SnapshotValidatorInterface;
use App\Exceptions\UnsupportedSchemaException;

class SnapshotVersionValidator implements SnapshotValidatorInterface
{
    private const SUPPORTED_SCHEMA_VERSIONS = [2, 3];

    private const SUPPORTED_SNAPSHOT_VERSIONS = ['2.0.0', '2.1.0'];

    public function validate(array $snapshot): void
    {
        $schema = $snapshot['snapshot_schema'] ?? null;
        $version = $snapshot['snapshot_version'] ?? null;

        if ($schema === null || !is_int($schema)) {
            throw new UnsupportedSchemaException((int) $schema);
        }

        if (!in_array($schema, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw new UnsupportedSchemaException($schema);
        }

        if ($version !== null && !in_array($version, self::SUPPORTED_SNAPSHOT_VERSIONS, true)) {
            throw new UnsupportedSchemaException($schema);
        }
    }
}
