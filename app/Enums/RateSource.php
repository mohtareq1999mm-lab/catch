<?php

namespace App\Enums;

enum RateSource: string
{
    case LEGACY = 'legacy';
    case MANUAL = 'manual';
    case PROVIDER = 'provider';
}
