<?php

namespace App\Exceptions;

use App\Enums\GuestErrorCode;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Guest credential/session rejection (A4 / A10).
 *
 * Carries the machine-readable code explicitly instead of smuggling it
 * through the exception message, so the Handler never has to parse strings.
 * `messageKey` stays a raw translation key; rendering happens at the seam.
 * Only the approved codes are set; a plain 401 carries none.
 */
class GuestSessionRejection extends HttpException
{
    public function __construct(
        private ?GuestErrorCode $guestCode,
        int $statusCode,
        string $messageKey
    ) {
        parent::__construct($statusCode, $messageKey);
    }

    public function guestCode(): ?GuestErrorCode
    {
        return $this->guestCode;
    }

    public function messageKey(): string
    {
        return $this->getMessage();
    }
}
