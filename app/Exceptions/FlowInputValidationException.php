<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Fail-closed Flow Input validation failure.
 *
 * Carries per-key errors so controllers return a structured 422
 * WITHOUT changing order state. Extends InvalidArgumentException so
 * existing catch sites treating it as a 422 keep working.
 */
class FlowInputValidationException extends InvalidArgumentException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        string $message,
        private array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
