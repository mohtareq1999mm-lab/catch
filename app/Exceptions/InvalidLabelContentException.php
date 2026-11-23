<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InvalidLabelContentException extends RuntimeException
{
    public static function invalidFormat(): self
    {
        return new self('Label format is not allowed.');
    }

    public static function invalidMagicBytes(): self
    {
        return new self('Label content does not match the declared format.');
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self("Label content exceeds maximum size of {$maxBytes} bytes.");
    }

    public static function empty(): self
    {
        return new self('Label content is empty.');
    }
}
