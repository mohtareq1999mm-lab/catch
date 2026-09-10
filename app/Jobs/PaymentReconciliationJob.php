<?php

namespace App\Jobs;

use App\DTOs\GatewayResult;
use App\Models\PaymentReconciliationResult;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;

class PaymentReconciliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('meem-medium');
    }

    public function handle(PaymentGatewayFactory $gatewayFactory): void
    {
        $startTime = microtime(true);

        Log::info('PaymentReconciliationJob started');

        $candidates = Transaction::query()
            ->whereNotNull('gateway_transaction_id')
            ->where('status', '!=', 'failed')
            ->with('order')
            ->cursor();

        $checked = 0;
        $mismatches = 0;
        $gatewayFailures = 0;
        $skipped = 0;

        foreach ($candidates as $transaction) {
            $order = $transaction->order;

            if (!$order) {
                $skipped++;
                continue;
            }

            $gateway = $this->resolveGateway($gatewayFactory, $transaction->payment_method);
            if (!$gateway) {
                $skipped++;
                continue;
            }

            $gatewayResult = $this->verifyWithGateway($gateway, $transaction->gateway_transaction_id);
            if (!$gatewayResult) {
                $gatewayFailures++;
                continue;
            }

            $checked++;

            if ($this->compareAmount($transaction, $order, $gatewayResult)) {
                $mismatches++;
            }
            if ($this->compareCurrency($transaction, $order, $gatewayResult)) {
                $mismatches++;
            }
            if ($this->comparePaymentStatus($transaction, $order, $gatewayResult)) {
                $mismatches++;
            }
            if ($this->compareOrderStatus($transaction, $order, $gatewayResult)) {
                $mismatches++;
            }
            if ($this->compareRefundStatus($transaction, $order, $gatewayResult)) {
                $mismatches++;
            }
        }

        $duration = round(microtime(true) - $startTime, 2);

        Log::info('PaymentReconciliationJob finished', [
            'duration_seconds' => $duration,
            'transactions_checked' => $checked,
            'mismatches_found' => $mismatches,
            'gateway_failures' => $gatewayFailures,
            'skipped' => $skipped,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PaymentReconciliationJob failed.', [
            'error' => $e->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    private function resolveGateway(PaymentGatewayFactory $factory, ?string $gatewayName): ?PaymentGatewayContract
    {
        if (!$gatewayName) {
            return null;
        }

        try {
            return $factory->make($gatewayName);
        } catch (\Throwable) {
            return null;
        }
    }

    private function verifyWithGateway(PaymentGatewayContract $gateway, string $transactionId): ?GatewayResult
    {
        try {
            $result = $gateway->verifyPayment($transactionId);

            if ($result->status === null && !$result->success) {
                return null;
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning("Gateway verification threw exception for transaction {$transactionId}: {$e->getMessage()}");

            return null;
        }
    }

    private function compareAmount(Transaction $transaction, Order $order, GatewayResult $gatewayResult): bool
    {
        if ($gatewayResult->amount === null) {
            return false;
        }

        $localAmount = (float) $order->total_price;
        $gatewayAmount = (float) $gatewayResult->amount;

        if (abs($localAmount - $gatewayAmount) > 0.01) {
            $this->recordMismatch($transaction, $order, 'amount', (string) $localAmount, (string) $gatewayAmount);

            return true;
        }

        return false;
    }

    private function compareCurrency(Transaction $transaction, Order $order, GatewayResult $gatewayResult): bool
    {
        if ($gatewayResult->currency === null) {
            return false;
        }

$localCurrency = $order->currency_code ?? $order->base_currency_code
            ?? config('payment.default_currency');
        $gatewayCurrency = $gatewayResult->currency;

        if ($localCurrency !== $gatewayCurrency) {
            $this->recordMismatch($transaction, $order, 'currency', $localCurrency, $gatewayCurrency);

            return true;
        }

        return false;
    }

    private function comparePaymentStatus(Transaction $transaction, Order $order, GatewayResult $gatewayResult): bool
    {
        if ($gatewayResult->status === null) {
            return false;
        }

        $localStatus = $transaction->status;

        if ($this->isGatewayStatusPaid($gatewayResult->status) && $localStatus !== 'paid') {
            $this->recordMismatch(
                $transaction, $order, 'payment_status',
                $localStatus,
                "Gateway: {$gatewayResult->status}"
            );

            return true;
        }

        if ($this->isGatewayStatusFailed($gatewayResult->status) && $localStatus === 'paid') {
            $this->recordMismatch(
                $transaction, $order, 'payment_status',
                $localStatus,
                "Gateway: {$gatewayResult->status}"
            );

            return true;
        }

        return false;
    }

    private function compareOrderStatus(Transaction $transaction, Order $order, GatewayResult $gatewayResult): bool
    {
        if ($gatewayResult->status === null) {
            return false;
        }

        if ($this->isGatewayStatusPaid($gatewayResult->status) && $order->status !== 'completed') {
            $this->recordMismatch(
                $transaction, $order, 'order_status',
                $order->status,
                'Gateway reports paid but order is not completed'
            );

            return true;
        }

        if ($this->isGatewayStatusFailed($gatewayResult->status) && $order->status === 'completed') {
            $this->recordMismatch(
                $transaction, $order, 'order_status',
                $order->status,
                'Gateway reports failed but order is completed'
            );

            return true;
        }

        return false;
    }

    private function compareRefundStatus(Transaction $transaction, Order $order, GatewayResult $gatewayResult): bool
    {
        return false;
    }

    private function recordMismatch(Transaction $transaction, Order $order, string $type, string $expected, string $actual): void
    {
        PaymentReconciliationResult::create([
            'transaction_id' => $transaction->id,
            'order_id' => $order->id,
            'gateway' => $transaction->payment_method ?? 'unknown',
            'mismatch_type' => $type,
            'expected_value' => $expected,
            'actual_value' => $actual,
        ]);
    }

    private function isGatewayStatusPaid(string $status): bool
    {
        return in_array(strtolower($status), ['paid', 'success', 'completed'], true);
    }

    private function isGatewayStatusFailed(string $status): bool
    {
        return in_array(strtolower($status), ['failed', 'expired', 'cancelled'], true);
    }
}
