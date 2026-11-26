<?php

declare(strict_types=1);

/*
 * #191: finance authority is explicitly isolated from broad RBAC seeding.
 *
 * Variance tolerances are deployment-approved, currency-specific thresholds
 * in minor units. Zero is the default and is the only safe value when no
 * finance approval has been recorded for a currency.
 */
return [
    'break_glass' => [
        'max_hours' => max(1, (int) env('FINANCE_BREAK_GLASS_MAX_HOURS', 8)),
    ],

    'settlement' => [
        'variance_tolerance_minor' => [
            'EGP' => max(0, (int) env('FINANCE_EGP_VARIANCE_TOLERANCE_MINOR', 0)),
            'USD' => max(0, (int) env('FINANCE_USD_VARIANCE_TOLERANCE_MINOR', 0)),
        ],
    ],
];
