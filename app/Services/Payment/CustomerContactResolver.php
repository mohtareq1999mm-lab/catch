<?php

namespace App\Services\Payment;

use Marvel\Database\Models\Order;

class CustomerContactResolver
{
    /**
     * Generate a deterministic, gateway-safe email for orders without user email.
     * This email is NEVER persisted to users.email or orders.user_email.
     * It exists only at the gateway boundary.
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
