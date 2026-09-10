<?php

namespace App\Exceptions;

use Exception;

class CurrencyMismatchException extends Exception
{
    public function __construct(string $message = 'Currency mismatch detected')
    {
        parent::__construct($message);
    }
}
