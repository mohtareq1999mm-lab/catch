<?php

namespace App\Enums;

/**
 * Provider execution mode recorded as settlement evidence identity.
 */
enum SettlementMode: string
{
    case SANDBOX = 'sandbox';
    case PRODUCTION = 'production';
}
