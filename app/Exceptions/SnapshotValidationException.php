<?php

namespace App\Exceptions;

use Exception;

class SnapshotValidationException extends Exception
{
    public function __construct(string $message = 'Snapshot validation failed')
    {
        parent::__construct($message);
    }
}
