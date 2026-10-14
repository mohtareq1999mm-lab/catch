<?php

namespace Marvel\Enums;

use BenSampo\Enum\Enum;

final class CartOperation extends Enum
{
    public const INCREMENT = 'increment';
    public const DECREMENT = 'decrement';
}
