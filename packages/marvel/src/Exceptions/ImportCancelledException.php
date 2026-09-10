<?php

namespace Marvel\Exceptions;

use RuntimeException;

class ImportCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Import was cancelled by user');
    }
}
