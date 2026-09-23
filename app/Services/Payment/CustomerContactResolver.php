<?php

namespace App\Services\Payment;

use Marvel\Database\Models\Order;

class CustomerContactResolver
{
    /**
     * Generate a deterministic, gateway-safe email for orders without user email.
     * This email is NEVER persisted to users.email or orders.user_email.
     * It exists only at the gateway boundary.
     *
     * NOTE (synthetic): the @no-email.meem.local domain is intentionally
     * unroutable — providers that require a syntactically valid address get
     * one, but no real mailbox exists and no receipt/notification can ever
     * escape to it. Deterministic per order id so retries submit the same value.
     */
    public function emailForGateway(Order $order): string
    {
        $email = $order->user_email ?? $order->user?->email;

        if ($email) {
            return $email;
        }

        // Deterministic fallback for gateway submission only
        return 'order-' . $order->id . '@no-email.meem.local';
    }
}
