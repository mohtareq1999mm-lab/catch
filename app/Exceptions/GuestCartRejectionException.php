<?php

namespace App\Exceptions;

use App\Enums\GuestCartRejectionReason;
use InvalidArgumentException;

/**
 * Business rejection from the guest cart lifecycle (S12).
 *
 * Carries the closed reason enum alongside the human message so the
 * controller can add `data.reason` without changing status codes or
 * localized text.
 */
class GuestCartRejectionException extends InvalidArgumentException
{
    public function __construct(
        private GuestCartRejectionReason $reason,
        string $message
    ) {
        parent::__construct($message);
    }

    public function reason(): GuestCartRejectionReason
    {
        return $this->reason;
    }
}
