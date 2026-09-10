<?php

namespace App\Exceptions;

use Exception;

class FinancialInvariantException extends Exception
{
    public function __construct(string $message = 'Financial invariant violation')
    {
        parent::__construct($message);
    }
}
