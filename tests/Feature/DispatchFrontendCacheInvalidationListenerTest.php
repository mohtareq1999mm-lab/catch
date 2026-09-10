<?php

namespace Tests\Feature;

use App\Enums\FrontendResource;
use App\Events\FrontendCacheInvalidation;
use App\Jobs\SendFrontendWebhookJob;
use App\Listeners\DispatchFrontendCacheInvalidation;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DispatchFrontendCacheInvalidationListenerTest extends TestCase
{
    /** @test */
    public function it_dispatches_job_when_event_is_fired()
    {
        Bus::fake();

        $event = new FrontendCacheInvalidation(
            resource: FrontendResource::PRODUCTS,
        );

        $listener = new DispatchFrontendCacheInvalidation();
        $listener->handle($event);

        Bus::assertDispatched(SendFrontendWebhookJob::class, function ($job) {
            return $job->payload->resource === FrontendResource::PRODUCTS;
        });
    }

    /** @test */
    public function it_passes_correct_resource_to_payload()
    {
        Bus::fake();

        $event = new FrontendCacheInvalidation(
            resource: FrontendResource::SETTINGS,
        );

        $listener = new DispatchFrontendCacheInvalidation();
        $listener->handle($event);

        Bus::assertDispatched(SendFrontendWebhookJob::class, function ($job) {
            return $job->payload->resource === FrontendResource::SETTINGS
                && $job->payload->toArray()['resource'] === 'settings';
        });
    }

    /** @test */
    public function it_handles_all_resource_types()
    {
        foreach (FrontendResource::cases() as $resource) {
            Bus::fake();

            $event = new FrontendCacheInvalidation(resource: $resource);
            $listener = new DispatchFrontendCacheInvalidation();
            $listener->handle($event);

            Bus::assertDispatched(SendFrontendWebhookJob::class, function ($job) use ($resource) {
                return $job->payload->resource === $resource;
            });
        }
    }
}
