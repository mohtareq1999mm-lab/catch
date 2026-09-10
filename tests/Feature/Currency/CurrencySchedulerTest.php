<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

class CurrencySchedulerTest extends CurrencyTestCase
{
    public function test_currency_sync_is_scheduled_every_six_hours_when_enabled(): void
    {
        config(['currency.enabled' => true]);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('currency:sync-rates')
            ->assertExitCode(0);
    }

    public function test_currency_sync_command_is_disabled_by_default(): void
    {
        config(['currency.enabled' => false]);

        $this->artisan('currency:sync-rates')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    }
}
