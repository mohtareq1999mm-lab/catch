<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class FrontendWebhookException extends Exception
{
    public function __construct(
        string $message = 'Frontend webhook request failed.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
