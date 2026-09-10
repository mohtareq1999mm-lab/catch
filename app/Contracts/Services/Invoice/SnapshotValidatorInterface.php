<?php

namespace App\Contracts\Services\Invoice;

use App\Exceptions\SnapshotValidationException;

interface SnapshotValidatorInterface
{
    /**
     * Validate a snapshot array.
     *
     * @param array $snapshot The complete snapshot data.
     * @return void
     * @throws SnapshotValidationException
     */
    public function validate(array $snapshot): void;
}
