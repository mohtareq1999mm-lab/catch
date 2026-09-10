<?php

namespace App\Services\Invoice\Validators;

use App\Contracts\Services\Invoice\SnapshotValidatorInterface;
use App\Exceptions\SnapshotValidationException;

class MetadataValidator implements SnapshotValidatorInterface
{
    public function validate(array $snapshot): void
    {
        $metadata = $snapshot['metadata'] ?? [];

        if (!isset($metadata['system_version'])) {
            throw new SnapshotValidationException('metadata.system_version is required');
        }

        if (!isset($metadata['locale'])) {
            throw new SnapshotValidationException('metadata.locale is required');
        }

        if (!is_string($metadata['locale'])) {
            throw new SnapshotValidationException('metadata.locale must be a string');
        }

        if (!isset($metadata['generated_at'])) {
            throw new SnapshotValidationException('metadata.generated_at is required');
        }
    }
}
