<?php

namespace App\Exceptions;

class PickingValidationException extends \RuntimeException
{
    /** @var array<string, mixed> */
    public array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $reason, array $context = [])
    {
        // The reason is a stable machine code (wrong_product, …); only the
        // surrounding sentence is translated.
        parent::__construct(__('wms.picking.validation_failed', ['reason' => $reason]));
        $this->context = array_merge(['reason' => $reason], $context);
    }
}
