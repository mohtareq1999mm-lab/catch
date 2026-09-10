<?php

namespace Tests\Feature;

use App\DTOs\FrontendCachePayload;
use App\Enums\FrontendResource;
use App\Exceptions\FrontendWebhookException;
use App\Services\FrontendWebhookService;
use App\ValueObjects\WebhookSignature;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FrontendWebhookServiceTest extends TestCase
{
    private FrontendWebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('frontend.enabled', true);
        Config::set('frontend.url', 'https://nextjs.example.com/api/cache-webhook');
        Config::set('frontend.secret', 'test-secret-key');
        Config::set('frontend.timeout', 10);
        Config::set('frontend.retry_times', 1);
        Config::set('frontend.retry_sleep', 100);
        Config::set('frontend.version', 1);

        $signature = new WebhookSignature(secret: 'test-secret-key');
        $this->service = new FrontendWebhookService($signature);
    }

    /** @test */
    public function it_dispatches_webhook_successfully()
    {
        Http::fake([
            'https://nextjs.example.com/*' => Http::response(['revalidated' => true], 200),
        ]);

        Log::spy();

        $payload = new FrontendCachePayload(
            resource: FrontendResource::PRODUCTS,
        );

        $response = $this->service->dispatch($payload);

        $this->assertTrue($response->success);
        $this->assertEquals(200, $response->statusCode);

        Http::assertSent(function ($request) use ($payload) {
            $body = $request->data();

            return $request->url() === 'https://nextjs.example.com/api/cache-webhook'
                && $request->hasHeader('X-Webhook-Signature')
                && $request->hasHeader('X-Webhook-Version')
                && $request->hasHeader('X-Webhook-Request-Id')
                && $body['resource'] === 'products';
        });
    }

    /** @test */
    public function it_includes_all_required_headers()
    {
        Http::fake([
            'https://nextjs.example.com/*' => Http::response(['revalidated' => true], 200),
        ]);

        $payload = new FrontendCachePayload(
            resource: FrontendResource::CATEGORIES,
        );

        $this->service->dispatch($payload);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Webhook-Secret')
                && $request->hasHeader('X-Webhook-Signature')
                && $request->hasHeader('X-Webhook-Version')
                && $request->hasHeader('X-Webhook-Request-Id')
                && $request->hasHeader('User-Agent')
                && $request->hasHeader('Accept')
                && $request->hasHeader('Content-Type');
        });
    }

    /** @test */
    public function it_returns_disabled_response_when_webhook_is_disabled()
    {
        Config::set('frontend.enabled', false);

        $payload = new FrontendCachePayload(
            resource: FrontendResource::BRANDS,
        );

        $response = $this->service->dispatch($payload);

        $this->assertFalse($response->success);
        $this->assertEquals(0, $response->statusCode);
        $this->assertEquals('Frontend webhook is disabled.', $response->error);

        Http::assertNothingSent();
    }

    /** @test */
    public function it_throws_exception_when_url_is_not_configured()
    {
        Config::set('frontend.url', '');

        $this->expectException(FrontendWebhookException::class);
        $this->expectExceptionMessage('Frontend webhook URL is not configured.');

        $payload = new FrontendCachePayload(
            resource: FrontendResource::PROMOTIONS,
        );

        $this->service->dispatch($payload);
    }

    /** @test */
    public function it_throws_exception_on_http_failure()
    {
        Http::fake([
            'https://nextjs.example.com/*' => Http::response(null, 500),
        ]);

        $this->expectException(FrontendWebhookException::class);

        $payload = new FrontendCachePayload(
            resource: FrontendResource::FLASH_SALES,
        );

        $this->service->dispatch($payload);
    }

    /** @test */
    public function it_throws_exception_on_connection_timeout()
    {
        Http::fake([
            'https://nextjs.example.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $this->expectException(FrontendWebhookException::class);
        $this->expectExceptionMessage('Failed to dispatch frontend webhook');

        $payload = new FrontendCachePayload(
            resource: FrontendResource::SETTINGS,
        );

        $this->service->dispatch($payload);
    }

    /** @test */
    public function it_sends_correct_payload_structure()
    {
        $payload = new FrontendCachePayload(
            resource: FrontendResource::SLIDERS,
        );

        Http::fake();

        $this->service->dispatch($payload);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['version'])
                && isset($body['request_id'])
                && isset($body['resource'])
                && isset($body['occurred_at'])
                && $body['version'] === 1
                && $body['resource'] === 'sliders';
        });
    }

    /** @test */
    public function it_logs_successful_dispatch()
    {
        Http::fake([
            'https://nextjs.example.com/*' => Http::response(['revalidated' => true], 200),
        ]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Frontend webhook dispatched successfully.'
                    && isset($context['request_id'])
                    && isset($context['resource'])
                    && $context['resource'] === 'coupons';
            });

        $payload = new FrontendCachePayload(
            resource: FrontendResource::COUPONS,
        );

        $this->service->dispatch($payload);
    }

    /** @test */
    public function it_logs_failed_dispatch()
    {
        Http::fake([
            'https://nextjs.example.com/*' => Http::response(null, 500),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Frontend webhook dispatch failed.'
                    && isset($context['request_id'])
                    && isset($context['error']);
            });

        $this->expectException(FrontendWebhookException::class);

        $payload = new FrontendCachePayload(
            resource: FrontendResource::FAQS,
        );

        $this->service->dispatch($payload);
    }

    /** @test */
    public function it_sends_only_cache_invalidation_fields()
    {
        Http::fake();

        $payload = new FrontendCachePayload(
            resource: FrontendResource::CONTENT_PAGES,
        );

        $this->service->dispatch($payload);

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertArrayNotHasKey('event', $body);
            $this->assertArrayNotHasKey('action', $body);
            $this->assertArrayNotHasKey('data', $body);

            return true;
        });
    }
}
