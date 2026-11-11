<?php

return [
    'session_ready' => 'Guest session is ready.',
    'session_revoked' => 'Guest session has been revoked.',
    'session_required' => 'A guest session is required for this action.',

    'cart_not_found' => 'No active guest cart was found.',
    'product_unavailable' => 'This product is not available for guest checkout.',
    'digital_not_supported' => 'Digital products are not available for guest checkout.',
    'fast_shipping_not_eligible' => 'This product is not eligible for fast shipping.',
    'fast_shipping_not_supported' => 'Fast shipping is not available for guest checkout.',
    'variant_required' => 'Please select a product option before adding it to the cart.',
    'insufficient_stock' => 'The requested quantity is not currently available.',

    'csrf_failed' => 'The security token is missing or invalid. Please refresh the page and try again.',
    'origin_not_allowed' => 'This origin is not allowed to perform this action.',
    'rate_limited' => 'Too many requests. Please wait a moment and try again.',

    'conflict_retry' => 'This request could not be completed. Please try again.',
    'idempotency_mismatch' => 'This idempotency key was already used with different data.',
    'checkout_in_progress' => 'This checkout is already being processed. Please wait.',
    'invalid_idempotency_key' => 'The idempotency key is invalid.',

    'payment_method_not_supported' => 'This payment method is not available for guest checkout.',
    'payment_method_mismatch' => 'The payment method does not match this order.',
    'payment_in_progress' => 'A payment for this order is still being confirmed. Please wait.',
    'order_not_payable' => 'This order can no longer be paid.',
    'order_already_paid' => 'This order is already paid.',
    'order_not_found' => 'Order not found.',

    'tracking_link_invalid' => 'This tracking link is invalid or has expired.',
    'tracking_retrieved' => 'Order status retrieved successfully.',
    'tracking_revoked' => 'Guest tracking access has been revoked.',

    'cancel_link_invalid' => 'This cancellation link is invalid.',
    'cancel_link_used' => 'This cancellation link has already been used.',
    'cancel_link_expired' => 'This cancellation link has expired.',
    'cancel_failed' => 'The order could not be cancelled.',
];
