<?php

namespace App\Services\Invoice;

use App\Contracts\Services\Invoice\SnapshotValidatorInterface;

class InvoiceSnapshotValidator
{
    private array $validators;

    public function __construct(SnapshotValidatorInterface ...$validators)
    {
        $this->validators = $validators;
    }

    public function validate(array $snapshot): void
    {
        foreach ($this->validators as $validator) {
            $validator->validate($snapshot);
        }
    }
}
