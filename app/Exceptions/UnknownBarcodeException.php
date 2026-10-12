<?php

namespace App\Exceptions;

class UnknownBarcodeException extends \RuntimeException
{
    public function __construct(string $code)
    {
        // The scanned code (or the 'empty' marker) stays machine-stable.
        // Capped: user-supplied scan input must not bloat the message.
        parent::__construct(__('wms.barcode.unknown', ['code' => \Illuminate\Support\Str::limit($code, 200)]));
    }
}
