<?php

namespace App\Console\Commands;

use App\Services\Currency\ExchangeRateSyncService;
use Illuminate\Console\Command;

class SyncCurrencyRates extends Command
{
    protected $signature = 'currency:sync-rates {--dry-run}';

    protected $description = 'Synchronize active currency rates from the configured provider.';

    public function handle(ExchangeRateSyncService $syncService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!config('currency.enabled', false) && !$dryRun) {
            $this->warn('Currency rate synchronization is disabled.');

            return self::SUCCESS;
        }

        $result = $syncService->sync($dryRun);

        if ($result['status'] === 'skipped') {
            $this->warn('Currency rate synchronization was skipped because another run is active.');

            return self::SUCCESS;
        }

        if ($result['status'] === 'failed') {
            $this->error('Currency rate synchronization failed.');

            return self::FAILURE;
        }

        if ($dryRun && !empty($result['comparisons'])) {
            $this->table(
                ['Currency', 'Current', 'Provider', 'Difference %', 'Mode', 'Anomaly'],
                array_map(fn (array $comparison) => [
                    $comparison['currency'],
                    $comparison['current_rate'] ?? 'N/A',
                    $comparison['provider_rate'],
                    $comparison['difference_percent'],
                    $comparison['mode'] ?: 'manual',
                    $comparison['anomaly'] ? 'yes' : 'no',
                ], $result['comparisons']),
            );
        }

        $this->info($dryRun ? 'Currency rate dry-run completed.' : 'Currency rates synchronized.');

        if ($dryRun && ($result['anomalies'] ?? 0) > 0) {
            $this->warn('Provider rate anomalies require review.');

            return 2;
        }

        return self::SUCCESS;
    }
}
