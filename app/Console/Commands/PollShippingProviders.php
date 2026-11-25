<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shipment\CarrierWebhookVerifier;
use App\Services\Shipment\ShippingProviderPollingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollShippingProviders extends Command
{
    protected $signature = 'shipping:poll-webhooks {--provider=} {--mode=} {--account=}';

    protected $description = 'Poll configured shipping providers and ingest events through the webhook inbox';

    public function handle(ShippingProviderPollingService $polling): int
    {
        $targets = $this->targets();
        $failures = 0;

        if ($targets === []) {
            $this->warn('No valid shipping polling targets; see logs for skipped configuration.');

            return self::FAILURE;
        }

        foreach ($targets as $target) {
            try {
                $result = $polling->poll($target['provider'], $target['mode'], $target['account']);
                Log::info('Shipping provider polled', [
                    'provider' => $target['provider'],
                    'mode' => $target['mode'],
                    'account' => $target['account'],
                    'accepted' => $result->accepted,
                    'duplicates' => $result->duplicates,
                    'errors' => $result->errors,
                    'cursor' => $result->cursor,
                ]);
                if ($result->errors > 0) {
                    $failures++;
                }
            } catch (\Throwable $e) {
                $failures++;
                Log::warning('Shipping provider polling target failed', [
                    'provider' => $target['provider'],
                    'mode' => $target['mode'],
                    'account' => $target['account'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Polled '.count($targets).' shipping provider target(s).');

        if ($failures > 0) {
            $this->warn($failures.' target(s) failed; see logs for details.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<array{provider:string,mode:string,account:string}> */
    private function targets(): array
    {
        $providers = config('shipping.providers', []);
        $targets = [];
        $providerFilter = $this->option('provider');
        $modeFilter = $this->option('mode');
        $accountFilter = $this->option('account');

        foreach (is_array($providers) ? $providers : [] as $providerCode => $provider) {
            if (! is_array($provider)) {
                continue;
            }
            if (is_string($providerFilter) && $providerFilter !== '' && $providerFilter !== $providerCode) {
                continue;
            }

            $modes = is_array($provider['modes'] ?? null) ? $provider['modes'] : [];
            foreach ($modes as $mode => $block) {
                if (! is_array($block) || ($block['enabled'] ?? false) !== true) {
                    continue;
                }
                if (is_string($modeFilter) && $modeFilter !== '' && $modeFilter !== $mode) {
                    continue;
                }

                try {
                    $account = CarrierWebhookVerifier::canonicalAccountCode($provider, (string) $providerCode);
                } catch (\Throwable $e) {
                    Log::warning('Shipping polling target skipped: invalid canonical account', [
                        'provider' => (string) $providerCode,
                        'mode' => (string) $mode,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
                if (is_string($accountFilter) && $accountFilter !== '' && $accountFilter !== $account) {
                    continue;
                }

                $targets[] = [
                    'provider' => (string) $providerCode,
                    'mode' => (string) $mode,
                    'account' => $account,
                ];
            }
        }

        return $targets;
    }
}
