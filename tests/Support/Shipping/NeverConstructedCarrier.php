<?php

declare(strict_types=1);

namespace Tests\Support\Shipping;

/**
 * Test double proving fail-closed paths never reach a provider adapter.
 */
class NeverConstructedCarrier
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }
}
