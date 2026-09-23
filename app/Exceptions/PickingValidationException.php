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
        parent::__construct("Picking validation failed: {$reason}");
        $this->context = array_merge(['reason' => $reason], $context);
    }
}
