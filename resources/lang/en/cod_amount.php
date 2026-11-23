<?php

return [
    'missing_order' => 'The order for the COD amount cannot be resolved.',
    'missing_currency' => 'The order currency is missing.',
    'missing_precision' => 'Currency precision metadata is missing for the order currency.',
    'invalid_precision' => 'Currency precision metadata is invalid for the order currency.',
    'missing_paid_evidence' => 'Captured payment evidence is missing for the order.',
    'invalid_amount' => 'A payment amount cannot be read without guessing precision.',
    'mixed_currency' => 'Payment evidence currency does not match the order currency.',
    'inconsistent_evidence' => 'Executed refunds exceed the captured amount for the order.',
];
