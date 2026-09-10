<?php

namespace App\Providers;

use App\Contracts\ExchangeRateProviderInterface;
use App\Contracts\FrontendWebhookDispatcher;
use App\Mail\Transport\ResendTransport;
use App\Services\FrontendWebhookService;
use App\ValueObjects\WebhookSignature;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Notifications\ChannelManager;
use App\Notifications\Channels\FcmChannel;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(\App\Contexts\ChannelContext::class);
        $this->app->singleton(\App\Services\Currency\CurrencyService::class);
        $this->app->bind(ExchangeRateProviderInterface::class, function ($app) {
            return $app->make(\App\Services\Currency\Providers\FrankfurterProvider::class);
        });

        // Register the custom FCM notification channel so via('fcm') resolves.
        $this->app->make(ChannelManager::class)->extend('fcm', function () {
            return new FcmChannel();
        });

        $this->app->bind(FrontendWebhookDispatcher::class, FrontendWebhookService::class);

        $this->app->bind(WebhookSignature::class, function ($app) {
            return new WebhookSignature(
                secret: config('frontend.secret', ''),
            );
        });

        $this->app->bind(
            \App\Services\Invoice\InvoiceSnapshotValidator::class,
            fn($app) => new \App\Services\Invoice\InvoiceSnapshotValidator(
                $app->make(\App\Services\Invoice\Validators\StructureValidator::class),
                $app->make(\App\Services\Invoice\Validators\FinancialInvariantValidator::class),
                $app->make(\App\Services\Invoice\Validators\CurrencyValidator::class),
                $app->make(\App\Services\Invoice\Validators\MoneyValidator::class),
                $app->make(\App\Services\Invoice\Validators\MetadataValidator::class),
                $app->make(\App\Services\Invoice\Validators\SnapshotVersionValidator::class),
            )
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        ini_set('serialize_precision', '-1');

        Schema::defaultStringLength(191);
        if (!\App::environment('local')) {
            $this->app['request']->server->set('HTTPS', true);
        }

        Mail::extend('resend', function (array $config) {
            $apiKey = $config['key'] ?? config('services.resend.key');

            return new ResendTransport(\Resend::client($apiKey));
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => __('message.too_many_requests'),
                        'data' => null,
                    ], 429, $headers);
                });
        });


        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(120)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => __('message.too_many_requests'),
                        'data' => null,
                    ], 429, $headers);
                });
        });

        RateLimiter::for('authenticated', function (Request $request) {
            return Limit::perMinute(300)
                ->by($request->user()->id)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => __('message.too_many_requests'),
                        'data' => null,
                    ], 429, $headers);
                });
        });

        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(400)
                ->by($request->user()->id)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => __('message.too_many_requests'),
                        'data' => null,
                    ], 429, $headers);
                });
        });
    }
}
