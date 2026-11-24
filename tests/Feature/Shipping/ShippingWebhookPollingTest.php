<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Contracts\Shipping\ShippingProviderPoller;
use App\DTOs\Shipping\CarrierWebhookEvent;
use App\Models\Shipping\ShippingPollCursor;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Shipment\ShippingProviderPollingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ShippingWebhookPollingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.providers.null.mode' => 'sandbox',
            'shipping.providers.null.modes.sandbox.enabled' => true,
            'shipping.providers.null.modes.sandbox.webhook.secret' => 'whsec_test_169',
            'shipping.providers.null.modes.sandbox.webhook.version' => 'v1',
            'shipping.webhook_rate_limit.max_attempts' => 60,
            'shipping.webhook_rate_limit.decay_seconds' => 60,
        ]);
    }

    public function test_polling_stores_events_through_inbox_and_advances_cursor_after_durable_acceptance(): void
    {
        $poller = new FakePoller([
            [
                'events' => [$this->event('evt_poll_1'), $this->event('evt_poll_2')],
                'next_cursor' => 'cursor-2',
                'has_more' => false,
            ],
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');

        $this->assertSame(2, $result->accepted);
        $this->assertSame(0, $result->duplicates);
        $this->assertSame(2, ShippingWebhookEvent::count());
        $cursor = ShippingPollCursor::query()->firstOrFail();
        $this->assertSame('null', $cursor->provider_code);
        $this->assertSame('sandbox', $cursor->mode);
        $this->assertSame('null-local', $cursor->account_code);
        $this->assertSame('cursor-2', $cursor->cursor);
        $this->assertNotNull($cursor->last_success_at);
    }

    public function test_polling_replay_is_deduped_and_cursor_advances(): void
    {
        $event = $this->event('evt_poll_replay');
        $poller = new FakePoller([
            ['events' => [$event], 'next_cursor' => 'cursor-1', 'has_more' => false],
            ['events' => [$event], 'next_cursor' => 'cursor-2', 'has_more' => false],
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $service = app(ShippingProviderPollingService::class);
        $first = $service->poll('null', 'sandbox', 'null-local');
        $second = $service->poll('null', 'sandbox', 'null-local');

        $this->assertSame(1, $first->accepted);
        $this->assertSame(0, $first->duplicates);
        $this->assertSame(0, $second->accepted);
        $this->assertSame(1, $second->duplicates);
        $this->assertSame(1, ShippingWebhookEvent::count());
        $this->assertSame('cursor-2', ShippingPollCursor::query()->firstOrFail()->cursor);
    }

    public function test_polling_handles_pagination(): void
    {
        $poller = new FakePoller([
            ['events' => [$this->event('evt_page_1')], 'next_cursor' => 'cursor-1', 'has_more' => true],
            ['events' => [$this->event('evt_page_2')], 'next_cursor' => 'cursor-2', 'has_more' => false],
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');

        $this->assertSame(2, $result->accepted);
        $this->assertSame('cursor-2', ShippingPollCursor::query()->firstOrFail()->cursor);
        $this->assertSame(2, ShippingWebhookEvent::count());
    }

    public function test_polling_partial_failure_does_not_advance_cursor_past_durable_acceptance(): void
    {
        $poller = new FakePoller([
            ['events' => [$this->event('evt_ok')], 'next_cursor' => 'cursor-1', 'has_more' => true],
            new \RuntimeException('provider page failed'),
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');

        $this->assertSame(1, $result->accepted);
        $this->assertSame(1, $result->errors);
        $this->assertSame('cursor-1', ShippingPollCursor::query()->firstOrFail()->cursor);
        $this->assertSame(1, ShippingWebhookEvent::count());
    }

    public function test_polling_command_runs_once_per_provider_mode_account_without_overlap(): void
    {
        config([
            'shipping.providers' => [
                'null' => config('shipping.providers.null'),
            ],
            'shipping.providers.null.modes.production.enabled' => true,
            'shipping.providers.null.modes.production.webhook.secret' => 'whsec_test_169',
            'shipping.providers.null.modes.production.webhook.version' => 'v1',
        ]);

        $poller = new FakePoller([]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        Artisan::call('shipping:poll-webhooks');

        $this->assertCount(2, $poller->calls);
        $this->assertSame([
            ['provider' => 'null', 'mode' => 'sandbox', 'account' => 'null-local', 'cursor' => null],
            ['provider' => 'null', 'mode' => 'production', 'account' => 'null-local', 'cursor' => null],
        ], $poller->calls);
    }

    public function test_polling_command_uses_canonical_provider_account_and_ignores_block_account_drift(): void
    {
        config([
            'shipping.providers.null.account_code' => 'canonical-acct',
            'shipping.providers.null.modes.sandbox.account_code' => 'drifted-acct',
            'shipping.providers.null.modes.production.enabled' => true,
            'shipping.providers.null.modes.production.webhook.secret' => 'whsec_test_169',
            'shipping.providers.null.modes.production.webhook.version' => 'v1',
            'shipping.providers.null.modes.production.account_code' => 'other-drift',
        ]);

        $poller = new FakePoller([]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        Artisan::call('shipping:poll-webhooks', ['--provider' => 'null']);

        $this->assertSame(['canonical-acct', 'canonical-acct'], array_column($poller->calls, 'account'));
        $this->assertDatabaseMissing('shipping_poll_cursors', ['account_code' => 'drifted-acct']);
        $this->assertDatabaseMissing('shipping_poll_cursors', ['account_code' => 'other-drift']);
    }

    public function test_polling_command_isolates_target_failures_and_continues_remaining_targets(): void
    {
        config([
            'shipping.providers.null.modes.production.enabled' => true,
            'shipping.providers.null.modes.production.webhook.secret' => 'whsec_test_169',
            'shipping.providers.null.modes.production.webhook.version' => 'v1',
        ]);

        $poller = new FakePoller([
            new \RuntimeException('boom sandbox'),
            ['events' => [$this->event('evt_after_failure', 'null-local', 'production')], 'next_cursor' => 'cursor-prod', 'has_more' => false],
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $exit = Artisan::call('shipping:poll-webhooks', ['--provider' => 'null']);

        $this->assertSame(1, $exit);
        $this->assertGreaterThanOrEqual(2, count($poller->calls));
        $this->assertContains(['provider' => 'null', 'mode' => 'sandbox', 'account' => 'null-local', 'cursor' => null], $poller->calls);
        $this->assertContains(['provider' => 'null', 'mode' => 'production', 'account' => 'null-local', 'cursor' => null], $poller->calls);
        $this->assertSame(1, ShippingWebhookEvent::query()->where('event_key', 'evt_after_failure')->count());
        $this->assertDatabaseHas('shipping_poll_cursors', [
            'provider_code' => 'null',
            'mode' => 'production',
            'account_code' => 'null-local',
            'cursor' => 'cursor-prod',
        ]);
    }

    public function test_polling_storage_failure_does_not_advance_cursor_past_durable_acceptance(): void
    {
        $poller = new FakePoller([
            ['events' => [$this->event('evt_store_fail')], 'next_cursor' => 'cursor-bad', 'has_more' => false],
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        ShippingWebhookEvent::creating(function (): never {
            throw new \RuntimeException('db write failed');
        });

        try {
            $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');
        } finally {
            ShippingWebhookEvent::flushEventListeners();
        }

        $this->assertSame(0, $result->accepted);
        $this->assertSame(1, $result->errors);
        $this->assertNull(ShippingPollCursor::query()->firstOrFail()->cursor);
        $this->assertSame(0, ShippingWebhookEvent::count());
    }

    public function test_failed_cursor_save_does_not_persist_advanced_cursor_during_error_reporting(): void
    {
        $poller = new FakePoller([
            ['events' => [$this->event('evt_cursor_save_fail')], 'next_cursor' => 'cursor-bad', 'has_more' => false],
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        ShippingPollCursor::updating(function (ShippingPollCursor $cursor): void {
            if ($cursor->cursor === 'cursor-bad') {
                throw new \RuntimeException('cursor save failed');
            }
        });

        try {
            $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');
        } finally {
            ShippingPollCursor::flushEventListeners();
        }

        $this->assertSame(0, $result->accepted);
        $this->assertSame(1, $result->errors);
        $this->assertNull($result->cursor);
        $this->assertSame(0, ShippingWebhookEvent::count());
        $cursorRow = ShippingPollCursor::query()->firstOrFail();
        $this->assertNull($cursorRow->cursor);
        $this->assertNotNull($cursorRow->last_error);
    }

    public function test_polling_rejects_event_with_drifted_account_identity(): void
    {
        $poller = new FakePoller([
            ['events' => [$this->event('evt_drifted_account', 'drifted-acct')], 'next_cursor' => 'cursor-drift', 'has_more' => false],
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');

        $this->assertSame(0, $result->accepted);
        $this->assertSame(1, $result->errors);
        $this->assertNull($result->cursor);
        $this->assertSame(0, ShippingWebhookEvent::count());
        $this->assertDatabaseMissing('shipping_webhook_events', ['account_code' => 'drifted-acct']);
        $this->assertNull(ShippingPollCursor::query()->firstOrFail()->cursor);
    }

    public function test_polling_skips_target_when_lock_is_held(): void
    {
        $lock = Cache::lock('shipping-poll:null:sandbox:null-local', 300);
        $this->assertTrue($lock->get());

        $poller = new FakePoller([]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');

        $this->assertSame([], $poller->calls);
        $this->assertSame(0, $result->accepted);
        $this->assertSame(0, $result->duplicates);
        $this->assertSame(0, $result->errors);
        $this->assertNull($result->cursor);
    }

    public function test_polling_max_page_budget_stops_endless_distinct_cursors(): void
    {
        config(['shipping.polling.max_pages' => 3]);
        $poller = new EndlessPoller(fn (int $page) => $this->event('evt_page_budget_'.$page));
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');

        $this->assertSame(3, $poller->calls);
        $this->assertSame(3, $result->accepted);
        $this->assertSame(0, $result->duplicates);
        $this->assertSame(1, $result->errors);
        $this->assertSame('cursor-3', $result->cursor);
        $this->assertSame(3, ShippingWebhookEvent::count());
    }

    public function test_polling_time_budget_stops_endless_distinct_cursors(): void
    {
        config(['shipping.polling.time_budget_seconds' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-11-24T00:00:00Z'));

        $poller = new EndlessPoller(
            fn (int $page) => $this->event('evt_time_budget_'.$page),
            function (): void {
                Carbon::setTestNow(Carbon::now()->addSeconds(2));
            },
        );
        $this->app->instance(ShippingProviderPoller::class, $poller);

        try {
            $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');
        } finally {
            Carbon::setTestNow(null);
        }

        $this->assertSame(1, $poller->calls);
        $this->assertSame(1, $result->accepted);
        $this->assertSame(1, $result->errors);
        $this->assertSame('cursor-1', $result->cursor);
    }

    public function test_polling_command_fails_when_no_valid_canonical_account_exists(): void
    {
        config([
            'shipping.providers' => ['null' => config('shipping.providers.null')],
            'shipping.providers.null.account_code' => '',
        ]);

        $poller = new FakePoller([]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $exit = Artisan::call('shipping:poll-webhooks');

        $this->assertSame(1, $exit);
        $this->assertSame([], $poller->calls);
        $this->assertStringContainsString('No valid shipping polling targets', Artisan::output());
    }

    public function test_polling_non_advancing_pagination_fails_closed_without_spin(): void
    {
        $poller = new FakePoller([
            ['events' => [$this->event('evt_spin_1')], 'next_cursor' => 'same-cursor', 'has_more' => true],
            ['events' => [$this->event('evt_spin_2')], 'next_cursor' => 'same-cursor', 'has_more' => true],
        ]);
        $this->app->instance(ShippingProviderPoller::class, $poller);

        $result = app(ShippingProviderPollingService::class)->poll('null', 'sandbox', 'null-local');

        $this->assertSame(2, $result->accepted);
        $this->assertSame(1, $result->errors);
        $this->assertSame('same-cursor', $result->cursor);
        $this->assertCount(2, $poller->calls);
        $this->assertSame(2, ShippingWebhookEvent::count());
    }

    private function event(string $id, string $accountCode = 'null-local', string $mode = 'sandbox'): CarrierWebhookEvent
    {
        return new CarrierWebhookEvent(
            'null',
            $mode,
            ['event' => $id],
            null,
            'redacted:'.hash('sha256', $id),
            $id,
            'shipment.status_changed',
            'v1',
            $accountCode,
            'carrier-ref',
            CarbonImmutable::parse('2026-11-22T10:00:00Z'),
            ['event_key' => $id, 'status' => 'in_transit', 'event_type' => 'shipment.status_changed', 'webhook_version' => 'v1'],
            null,
            'in_transit',
        );
    }
}

final class FakePoller implements ShippingProviderPoller
{
    /** @var list<array{events:list<CarrierWebhookEvent>,next_cursor:?string,has_more:bool}|\Throwable> */
    private array $pages;

    /** @var list<array{provider:string,mode:string,account:string,cursor:?string}> */
    public array $calls = [];

    /** @param list<array{events:list<CarrierWebhookEvent>,next_cursor:?string,has_more:bool}|\Throwable> $pages */
    public function __construct(array $pages)
    {
        $this->pages = $pages;
    }

    public function poll(string $providerCode, string $mode, string $accountCode, ?string $cursor): object
    {
        $this->calls[] = ['provider' => $providerCode, 'mode' => $mode, 'account' => $accountCode, 'cursor' => $cursor];
        $page = array_shift($this->pages);
        if ($page instanceof \Throwable) {
            throw $page;
        }
        $page ??= ['events' => [], 'next_cursor' => $cursor, 'has_more' => false];

        return new class($page['events'], $page['next_cursor'], $page['has_more']) {
            /** @param list<CarrierWebhookEvent> $events */
            public function __construct(public array $events, public ?string $nextCursor, public bool $hasMore) {}
        };
    }
}

final class EndlessPoller implements ShippingProviderPoller
{
    public int $calls = 0;

    public function __construct(
        private readonly \Closure $eventFactory,
        private readonly ?\Closure $onPoll = null,
    ) {}

    public function poll(string $providerCode, string $mode, string $accountCode, ?string $cursor): object
    {
        $this->calls++;
        if ($this->onPoll !== null) {
            ($this->onPoll)();
        }

        return new class([($this->eventFactory)($this->calls)], 'cursor-'.$this->calls, true) {
            /** @param list<CarrierWebhookEvent> $events */
            public function __construct(public array $events, public ?string $nextCursor, public bool $hasMore) {}
        };
    }
}
