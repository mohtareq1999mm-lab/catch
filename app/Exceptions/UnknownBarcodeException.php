<?php

namespace App\Exceptions;

class UnknownBarcodeException extends \RuntimeException
{
    public function __construct(string $code)
    {
        parent::__construct("Unknown barcode: {$code}");
    }
}
