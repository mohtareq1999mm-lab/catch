<?php

return [
    'audit' => [
        'override_reason_required' => 'A provider override requires a reason of at most 500 characters.',
        'provider_overridden' => 'Shipping provider overridden by administrator',
    ],
    'webhook_accepted' => 'Shipping webhook accepted.',
    'webhook_duplicate' => 'Shipping webhook already recorded.',
    'webhook_invalid_signature' => 'Shipping webhook signature is invalid.',
    'webhook_invalid_payload' => 'Shipping webhook payload is invalid.',
    'webhook_rate_limited' => 'Too many shipping webhook deliveries. Please retry later.',
    'webhook_unavailable' => 'Shipping webhook verification is temporarily unavailable.',
];
