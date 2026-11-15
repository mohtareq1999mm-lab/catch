<?php

declare(strict_types=1);

namespace Database\Seeders\Meem\Support;

use RuntimeException;

/**
 * Raised when a Meem dataset cannot be seeded safely.
 *
 * Every message is written for an operator: it must say which dataset failed
 * and what must change. It must never contain a credential or a token.
 */
final class MeemSeedException extends RuntimeException
{
}
