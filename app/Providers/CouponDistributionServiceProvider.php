<?php

namespace App\Providers;

use App\Services\Coupon\Distribution\Messaging\CouponEventTransport;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Messaging\RabbitMqCouponEventTransport;
use Illuminate\Support\ServiceProvider;

class CouponDistributionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('rabbitmq.php'), 'rabbitmq');
        $this->mergeConfigFrom(config_path('coupon-distribution.php'), 'coupon-distribution');

        // Production backbone is RabbitMQ. The fake is bound explicitly by
        // tests (and only tests) — application code type-hints the contract.
        $this->app->singleton(CouponEventTransport::class, static function ($app) {
            if ($app->runningUnitTests() && $app->bound(FakeCouponEventTransport::class)) {
                return $app->make(FakeCouponEventTransport::class);
            }

            return new RabbitMqCouponEventTransport();
        });

        $this->app->singleton(FakeCouponEventTransport::class, static fn () => new FakeCouponEventTransport());
    }

    public function boot(): void
    {
        if (app()->isProduction()
            && ((string) config('rabbitmq.password', '') === '')
        ) {
            \Illuminate\Support\Facades\Log::warning(
                'coupon.rabbitmq using default/empty credentials in production — set RABBITMQ_USERNAME/RABBITMQ_PASSWORD.'
            );
        }
    }
}
