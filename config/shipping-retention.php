<?php

/*
|--------------------------------------------------------------------------
| Shipping evidence retention periods (policy metadata only)
|--------------------------------------------------------------------------
| #136: these day counts record the intended retention window for shipping
| evidence. They are NON-DESTRUCTIVE POLICY METADATA ONLY: no pruning command,
| scheduled task, job, listener, or model observer reads them. Changing a value
| cannot delete, purge, or modify any stored evidence. Destructive retention
| would require a separate, explicitly approved implementation.
*/

return [
    /*
     | Provider webhook events (days). Non-destructive policy metadata only;
     | no webhook inbox pruning is registered or authorized.
     */
    'webhook_events' => max(1, (int) env('SHIPPING_RETENTION_WEBHOOK_EVENTS_DAYS', 365)),

    /*
     | Redacted webhook/provider payloads (days). Non-destructive policy
     | metadata only; no payload deletion is registered or authorized.
     */
    'redacted_payloads' => max(1, (int) env('SHIPPING_RETENTION_REDACTED_PAYLOADS_DAYS', 90)),

    /*
     | Carrier labels / AWB files (days). Non-destructive policy metadata only;
     | no label removal is registered or authorized.
     */
    'labels' => max(1, (int) env('SHIPPING_RETENTION_LABELS_DAYS', 365)),

    /*
     | Proof-of-delivery evidence (days). Non-destructive policy metadata only;
     | no POD removal is registered or authorized.
     */
    'proof_of_delivery' => max(1, (int) env('SHIPPING_RETENTION_PROOF_OF_DELIVERY_DAYS', 365)),

    /*
     | COD settlement files (days). Non-destructive policy metadata only;
     | no settlement-file deletion is registered or authorized.
     */
    'settlement_files' => max(1, (int) env('SHIPPING_RETENTION_SETTLEMENT_FILES_DAYS', 2555)),
];
