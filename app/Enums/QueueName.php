<?php

namespace App\Enums;

/**
 * Canonical queue names. Single source of truth for queue classification.
 * Existing Supervisor workers consume exactly these names — never rename.
 */
enum QueueName: string
{
    case HIGH = 'catch-high';
    case MEDIUM = 'catch-medium';
}
