<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Warehouse Workflow Switch
    |--------------------------------------------------------------------------
    |
    | Deployment-tier master switch for the Warehouse Workflow. When OFF, the
    | system runs in Orders-only mode: orders keep their normal lifecycle,
    | but NO new fulfillment may be created or released (automatic triggers,
    | the recovery sweeper, and manual admin releases all refuse). Existing
    | fulfillments are never rewritten — they continue through their original
    | lifecycle (picking, packing, shipping, delivery) until completion.
    |
    | Precedence with the admin runtime toggle (Settings.options.warehouse.
    | workflow_enabled): this value is the higher authority — `false` here
    | disables the workflow everywhere; `true` defers to the admin toggle
    | whose stored default (absent key/row) is enabled.
    |
    | Runtime reads must go through config('warehouse.workflow_enabled') —
    | never env() directly, so `config:cache` stays authoritative.
    |
    */

    'workflow_enabled' => env('WAREHOUSE_WORKFLOW_ENABLED', true),
];
