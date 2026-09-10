<?php

namespace Tests\Feature;

use App\Contracts\FrontendWebhookDispatcher;
use App\DTOs\FrontendCachePayload;
use App\Enums\FrontendResource;
use App\Jobs\SendFrontendWebhookJob;
use App\Support\WebhookResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendFrontendWebhookJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('frontend.queue', 'meem-high');
    }

    /** @test */
    public function it_dispatches_on_configured_queue()
    {
        Queue::fake();

        $payload = new FrontendCachePayload(
            resource: FrontendResource::PRODUCTS,
        );

        SendFrontendWebhookJob::dispatch($payload);

        Queue::assertPushedOn('meem-high', SendFrontendWebhookJob::class);
    }

    /** @test */
    public function it_calls_dispatcher_on_handle()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::CATEGORIES,
        );

        $dispatched = false;

        $mockDispatcher = \Mockery::mock(FrontendWebhookDispatcher::class);
        $mockDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(\Mockery::on(function ($p) use ($payload, &$dispatched) {
                $dispatched = $p->resource === $payload->resource;
                return $dispatched;
            }))
            ->andReturn(new WebhookResponse(
                success: true,
                statusCode: 200,
            ));

        $this->app->instance(FrontendWebhookDispatcher::class, $mockDispatcher);

        $job = new SendFrontendWebhookJob($payload);
        $job->handle($mockDispatcher);

        $this->assertTrue($dispatched, 'Dispatcher was not called with the expected payload');
    }

    /** @test */
    public function it_logs_error_on_failure()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::BRANDS,
        );

        $logged = false;

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) use (&$logged) {
                $logged = $message === 'SendFrontendWebhookJob failed after all retries.'
                    && isset($context['request_id'])
                    && isset($context['resource'])
                    && isset($context['error']);
                return $logged;
            });

        $job = new SendFrontendWebhookJob($payload);

        $job->failed(new \Exception('Simulated failure'));

        $this->assertTrue($logged, 'Expected error was not logged');
    }

    /** @test */
    public function it_has_correct_retry_configuration()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::PROMOTIONS,
        );

        $job = new SendFrontendWebhookJob($payload);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([10, 30, 60], $job->backoff);
        $this->assertEquals(30, $job->timeout);
    }

    /** @test */
    public function it_has_retry_until_deadline()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::SETTINGS,
        );

        $job = new SendFrontendWebhookJob($payload);

        $retryUntil = $job->retryUntil();

        $this->assertInstanceOf(\DateTime::class, $retryUntil);
        $this->assertGreaterThan(now(), $retryUntil);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
