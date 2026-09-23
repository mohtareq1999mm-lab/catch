<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\UnsupportedGatewayException;
use App\Services\Payment\Contracts\PaymentGatewayContract;

/**
 * Resolves gateway adapters from the merged config + settings definitions
 * and guards the initiate/verify paths.
 *
 * INITIATE vs VERIFY split:
 * - canInitiate() is the full gate for NEW money movement (enabled +
 *   configured + method + currency). Use before createInvoice/refund.
 * - canVerify()/resolve() deliberately ignore the enabled flag so
 *   verifyPayment() keeps working for in-flight payments after a disable.
 *   Never add an isEnabled check to the verify path without auditing every
 *   callback/webhook caller — mid-payment disables must still complete.
 */
class PaymentGatewayRegistry
{
    public function __construct(
        private GatewaySettingsService $settings,
    ) {}

    public function resolve(string $code): PaymentGatewayContract
    {
        $definition = $this->settings->definition($code);
        $class = is_array($definition) ? ($definition['class'] ?? null) : null;

        if (!is_string($class) || $class === '' || !class_exists($class)) {
            throw new UnsupportedGatewayException($code);
        }

        $instance = app($class);

        if (!$instance instanceof PaymentGatewayContract) {
            throw new UnsupportedGatewayException($code);
        }

        return $instance;
    }

    /**
     * @return array{ok: bool, reason: string}
     */
    public function canInitiate(string $code, string $method, string $currency): array
    {
        $definition = $this->settings->definition($code);

        if (!is_array($definition)) {
            return ['ok' => false, 'reason' => 'unknown'];
        }

        if (!$this->settings->isEnabled($code)) {
            return ['ok' => false, 'reason' => 'disabled'];
        }

        $class = $definition['class'] ?? null;

        if (!is_string($class) || $class === '' || !class_exists($class)) {
            return ['ok' => false, 'reason' => 'unknown'];
        }

        try {
            $adapter = app($class);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'misconfigured'];
        }

        if (!$adapter instanceof PaymentGatewayContract) {
            return ['ok' => false, 'reason' => 'misconfigured'];
        }

        $configured = true;
        if (method_exists($adapter, 'isConfigured')) {
            try {
                $configured = (bool) $adapter->isConfigured();
            } catch (\Throwable $e) {
                $configured = false;
            }
        }

        if (!$configured) {
            return ['ok' => false, 'reason' => 'misconfigured'];
        }

        $methods = $definition['methods'] ?? [];
        if (!is_array($methods) || !in_array($method, $methods, true)) {
            return ['ok' => false, 'reason' => 'method_unsupported'];
        }

        if (method_exists($adapter, 'supportsCurrency')) {
            try {
                if (!$adapter->supportsCurrency($currency)) {
                    return ['ok' => false, 'reason' => 'currency_unsupported'];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'reason' => 'currency_unsupported'];
            }
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    public function canVerify(string $code): bool
    {
        $definition = $this->settings->definition($code);

        if (!is_array($definition)) {
            return false;
        }

        $class = $definition['class'] ?? null;

        return is_string($class) && $class !== '' && class_exists($class);
    }
}
