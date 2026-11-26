<?php

return [
    'fetched_many' => 'COD settlement statements fetched successfully.',
    'fetched_one' => 'COD settlement statement fetched successfully.',
    'precision_unavailable' => 'Settlement precision cannot be established for the order currency.',
    'invalid_payload' => 'Invalid COD settlement field: :field.',
    'currency_conflict' => 'Mixed-currency statements and currency conflicts are not allowed.',
    'duplicate_statement' => 'A COD settlement statement already exists for this provider, mode, account, period, and currency.',
    'duplicate_statement_key' => 'A COD settlement statement with this statement key already exists.',
    'overlapping_statement' => 'A COD settlement statement overlaps an existing statement for this provider, mode, account, and currency.',
    'lock_timeout' => 'Could not acquire settlement scope lock; please retry.',
    'line_matched' => 'COD settlement line matched successfully.',
    'closed' => 'COD settlement statement closed successfully.',
    'invalid_actor' => 'A valid finance actor is required.',
    'already_closed' => 'Settlement statement is already closed.',
    'closure_variance_blocked' => 'Settlement variance :variance exceeds the approved :tolerance tolerance for :currency.',
    'closure_approval_reference_required' => 'An approved variance threshold requires an approval reference.',
    'ambiguous_order_match' => 'Order number :order_number matches multiple orders (:candidates); manual matching is required.',
];
