<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Picking claim lease (minutes)
    |--------------------------------------------------------------------------
    | How long a picking claim stays with a worker before the sweeper
    | returns it to the pool. Admin-configurable via env; callers may
    | still override per-call. Default 15 preserves existing behavior.
    */
    'claim_lease_minutes' => (int) env('FULFILLMENT_CLAIM_LEASE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Claim sweep batch limit
    |--------------------------------------------------------------------------
    */
    'sweep_limit' => (int) env('FULFILLMENT_SWEEP_LIMIT', 100),
];
