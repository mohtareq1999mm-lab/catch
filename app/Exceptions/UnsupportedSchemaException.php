<?php

namespace App\Exceptions;

use Exception;

class UnsupportedSchemaException extends Exception
{
    public function __construct(int $schemaVersion)
    {
        parent::__construct("Unsupported snapshot schema version: {$schemaVersion}");
    }
}
