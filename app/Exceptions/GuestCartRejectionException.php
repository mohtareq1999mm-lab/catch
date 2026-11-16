<?php

namespace App\Exceptions;

use App\Contracts\ApiRejection;
use App\Enums\GuestCartRejectionReason;
use InvalidArgumentException;

/**
 * Business rejection from the guest cart lifecycle (S12).
 *
 * Carries the closed reason enum alongside the human message so the rejection
 * renderer can add `data.reason` without changing status codes or localized
 * text.
 */
class GuestCartRejectionException extends InvalidArgumentException implements ApiRejection
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

    public function statusCode(): int
    {
        return 422;
    }

    public function message(): string
    {
        return $this->getMessage();
    }

    public function errors(): array
    {
        return [];
    }

    public function data(): array
    {
        return ['reason' => $this->reason->value];
    }
}
