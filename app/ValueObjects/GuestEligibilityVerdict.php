<?php

namespace App\ValueObjects;

use App\Enums\GuestCartRejectionReason;

/**
 * Result of one guest Eligibility evaluation.
 *
 * `availableQuantity` is the known ceiling for the line (variant stock when
 * known, otherwise product stock), or null when stock is unlimited/unknown.
 * Consumers: the add path throws on rejection; Login Merge caps and reports.
 */
final class GuestEligibilityVerdict
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?GuestCartRejectionReason $reason = null,
        public readonly ?int $availableQuantity = null,
    ) {
    }

    public static function allowed(?int $availableQuantity = null): self
    {
        return new self(true, null, $availableQuantity);
    }

    public static function rejected(
        GuestCartRejectionReason $reason,
        ?int $availableQuantity = null
    ): self {
        return new self(false, $reason, $availableQuantity);
    }
}
