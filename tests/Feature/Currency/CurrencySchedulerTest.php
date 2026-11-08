<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use Illuminate\Console\Scheduling\Schedule;

class CurrencySchedulerTest extends CurrencyTestCase
{
    public function test_currency_sync_is_scheduled_every_six_hours_when_enabled(): void
    {
        config(['currency.exchange_api.enabled' => true]);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'currency:sync-rates'));

        $this->assertNotNull($event);
        $this->assertSame('0 */6 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_currency_sync_command_is_disabled_by_default(): void
    {
        config(['currency.exchange_api.enabled' => false]);

        $this->artisan('currency:sync-rates')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    }
}
