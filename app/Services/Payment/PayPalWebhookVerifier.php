<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Srmklive\PayPal\Services\PayPal as PayPalClient;

/**
 * PayPal webhook-signature verification via the SDK
 * verify-webhook-signature call (webhook_id from config).
 *
 * NOTE: `callable` cannot be a property type in PHP, so the client factory
 * is mixed and validated with is_callable() before use. Tests inject a fake
 * client factory here; production builds the real SDK client. Sandbox is
 * BLOCKED in this environment (no live credentials): this verifier must
 * only ever be exercised via the injected factory seam in tests.
 */
class PayPalWebhookVerifier
{
    public function __construct(
        private mixed $clientFactory = null,
    ) {}

    public function webhookId(): string
    {
        return trim((string) config('payment.gateways.paypal.webhook_id', ''));
    }

    /**
     * @param array{auth_algo: string, cert_url: string, transmission_id: string, transmission_sig: string, transmission_time: string} $transmission
     */
    public function verify(array $transmission, array $event): bool
    {
        $webhookId = $this->webhookId();

        if ($webhookId === '') {
            throw new \RuntimeException('PayPal webhook is not configured.');
        }

        $result = $this->client()->verifyWebHook([
            'auth_algo' => $transmission['auth_algo'],
            'cert_url' => $transmission['cert_url'],
            'transmission_id' => $transmission['transmission_id'],
            'transmission_sig' => $transmission['transmission_sig'],
            'transmission_time' => $transmission['transmission_time'],
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);

        return is_array($result)
            && strtoupper(trim((string) ($result['verification_status'] ?? ''))) === 'SUCCESS';
    }

    /**
     * Build a configured SDK client. Reads ONLY the config/payment.php
     * `paypal` block keys (mode + credentials), mirroring PayPalGateway.
     */
    private function client(): object
    {
        if (is_callable($this->clientFactory)) {
            return ($this->clientFactory)();
        }

        $mode = strtolower(trim((string) config('payment.gateways.paypal.mode', 'sandbox')));
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            $mode = 'sandbox';
        }

        $provider = new PayPalClient();
        $provider->setApiCredentials([
            'mode' => $mode,
            'sandbox' => [
                'client_id' => (string) config('payment.gateways.paypal.client_id', ''),
                'client_secret' => (string) config('payment.gateways.paypal.client_secret', ''),
                'app_id' => '',
            ],
            'live' => [
                'client_id' => (string) config('payment.gateways.paypal.client_id', ''),
                'client_secret' => (string) config('payment.gateways.paypal.client_secret', ''),
                'app_id' => '',
            ],
            'payment_action' => 'Sale',
            'currency' => 'USD',
            'notify_url' => '',
            'locale' => 'en_US',
            'validate_ssl' => true,
        ]);
        $provider->getAccessToken();

        return $provider;
    }
}
