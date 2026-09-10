<?php

namespace App\Enums;

/**
 * Canonical queue names. Single source of truth for queue classification.
 * Existing Supervisor workers consume exactly these names — never rename.
 */
enum QueueName: string
{
    case HIGH = 'meem-high';
    case MEDIUM = 'meem-medium';
}
