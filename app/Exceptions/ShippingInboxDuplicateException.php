<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * #182: duplicate legacy inbox rows were audited and must be resolved by an
 * operator before the scoped unique constraint can be installed.
 */
final class ShippingInboxDuplicateException extends RuntimeException
{
    public static function constraintBlocked(int $groups): self
    {
        return new self(sprintf(
            'Shipping inbox uniqueness migration blocked: %d duplicate group(s) audited; resolve them before adding the constraint.',
            $groups,
        ));
    }
}
