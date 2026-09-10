<?php

namespace Tests\Feature;

use App\DTOs\FrontendCachePayload;
use App\Enums\FrontendResource;
use App\Events\FrontendCacheInvalidation;
use App\Jobs\SendFrontendWebhookJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrontendCacheWebhookIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('frontend.enabled', true);
        Config::set('frontend.url', 'https://nextjs.test/api/cache-webhook');
        Config::set('frontend.secret', 'integration-test-secret');
        Config::set('frontend.timeout', 5);
        Config::set('frontend.retry_times', 1);
    }

    /** @test */
    public function event_triggers_job_via_listener()
    {
        Bus::fake();

        Event::dispatch(new FrontendCacheInvalidation(
            resource: FrontendResource::PRODUCTS,
        ));

        Bus::assertDispatched(SendFrontendWebhookJob::class, function ($job) {
            return $job->payload->resource === FrontendResource::PRODUCTS;
        });
    }

    /** @test */
    public function full_job_to_http_chain_succeeds()
    {
        Http::fake([
            'https://nextjs.test/*' => Http::response(['revalidated' => true], 200),
        ]);

        $payload = new FrontendCachePayload(
            resource: FrontendResource::CATEGORIES,
        );

        $job = new SendFrontendWebhookJob($payload);
        $job->handle(app(\App\Contracts\FrontendWebhookDispatcher::class));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://nextjs.test/api/cache-webhook'
                && $request->data()['resource'] === 'categories';
        });
    }

    /** @test */
    public function disabled_webhook_does_not_send_http_request()
    {
        Config::set('frontend.enabled', false);

        Http::fake();

        $payload = new FrontendCachePayload(
            resource: FrontendResource::BRANDS,
        );

        $job = new SendFrontendWebhookJob($payload);
        $job->handle(app(\App\Contracts\FrontendWebhookDispatcher::class));

        Http::assertNothingSent();
    }

    /** @test */
    public function it_generates_valid_signature()
    {
        Http::fake([
            'https://nextjs.test/*' => Http::response(['revalidated' => true], 200),
        ]);

        $payload = new FrontendCachePayload(
            resource: FrontendResource::FLASH_SALES,
        );

        $job = new SendFrontendWebhookJob($payload);
        $job->handle(app(\App\Contracts\FrontendWebhookDispatcher::class));

        Http::assertSent(function ($request) {
            $signature = $request->header('X-Webhook-Signature')[0] ?? '';
            $body = json_encode($request->data());
            $expected = hash_hmac('sha256', $body, 'integration-test-secret');

            return hash_equals($expected, $signature);
        });
    }

    /** @test */
    public function sync_dispatch_executes_webhook_immediately()
    {
        Http::fake([
            'https://nextjs.test/*' => Http::response(['revalidated' => true], 200),
        ]);

        $payload = new FrontendCachePayload(
            resource: FrontendResource::PROMOTIONS,
        );

        $job = new SendFrontendWebhookJob($payload);
        $job->handle(app(\App\Contracts\FrontendWebhookDispatcher::class));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://nextjs.test/api/cache-webhook'
                && $request->data()['resource'] === 'promotions';
        });
    }
}
