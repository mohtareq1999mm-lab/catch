<?php

namespace App\Providers;

use App\Contracts\ExchangeRateProviderInterface;
use App\Contracts\FrontendWebhookDispatcher;
use App\Mail\Transport\ResendTransport;
use App\Services\FrontendWebhookService;
use App\ValueObjects\WebhookSignature;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Builder;
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

        // OTP-gated guest order claiming is fail-closed until a real verifier
        // is bound (see GuestOrderClaimService activation steps).
        $this->app->bind(
            \App\Contracts\GuestPhoneVerificationContract::class,
            \App\Services\Guest\NullGuestPhoneVerification::class
        );

        // ADR 0003: one app-level OTP gateway binding. The configured provider
        // wins; when it cannot be constructed (a dev machine with
        // ACTIVE_OTP_GATEWAY=twilio and no credentials) the committed
        // LocalGateway is the dev path. Production stays fail-closed: the
        // LocalGateway only accepts codes while the Local OTP switch is on,
        // and the static bypass is hard-gated to non-production.
        $this->app->bind(\Marvel\Otp\OtpInterface::class, function () {
            $name = (string) config('auth.active_otp_gateway', 'twilio');
            $class = 'Marvel\\Otp\\Gateways\\' . ucfirst($name) . 'Gateway';

            if (class_exists($class)) {
                try {
                    return new $class();
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return new \Marvel\Otp\Gateways\LocalGateway();
        });

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

        // StripeGateway lazy-builds its SDK client from config on first use.
        // The container must NOT auto-resolve the nullable StripeClient
        // constructor arg: it would inject an empty-key instance and every
        // provider call would fail with "No API key provided" despite a
        // configured key (proven by real Stripe TEST E2E, 2026-09-27).
        $this->app->bind(
            \App\Services\Gateway\StripeGateway::class,
            fn($app) => new \App\Services\Gateway\StripeGateway(
                $app->make(\App\Services\Payment\PaymentCurrencyResolver::class)
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

        // Telescope is NOT installed in this project (config/telescope.php is
        // dead config). If it is ever added, these redactions must activate so
        // guest Bearer tokens, cookies and CSRF tokens never reach the
        // telescope_entries table (approved plan S8).
        if (class_exists(\Laravel\Telescope\Telescope::class)) {
            \Laravel\Telescope\Telescope::hideRequestHeaders(['authorization', 'cookie', 'x-csrf-token']);
            \Laravel\Telescope\Telescope::hideResponseHeaders(['set-cookie']);
        }

        // NOTE: use Builder directly (not Schema facade). Schema::defaultStringLength()
        // resolves 'db.schema' which requires the default DB connection, so any
        // environment with an unconfigured/missing DB (e.g. composer
        // package:discover with DB_CONNECTION=sqlite) would fatal here.
        Builder::defaultStringLength(191);
        if (!\App::environment('local')) {
            $this->app['request']->server->set('HTTPS', true);
        }

        Mail::extend('resend', function (array $config) {
            $apiKey = $config['key'] ?? config('services.resend.key');

            return new ResendTransport(\Resend::client($apiKey));
        });

        // RateLimiter::for('login', function (Request $request) {
        //     return Limit::perMinute(5)
        //         ->by($request->ip())
        //         ->response(function (Request $request, array $headers) {
        //             return response()->json([
        //                 'success' => false,
        //                 'message' => __('message.too_many_requests'),
        //                 'data' => null,
        //             ], 429, $headers);
        //         });
        // });


        // Public API limiter: BypassThrottle:public-api is attached to the
        // guest route group, so an UNDEFINED limiter would 500 the whole guest
        // surface whenever rate limiting is enabled (DISABLE_RATE_LIMITING=false).
        // Values are config-driven; the limit itself is bypassed by default.
        RateLimiter::for('public-api', function (Request $request) {
            $max = max(1, (int) config('app.public_api_throttle_per_minute', 120));

            return Limit::perMinute($max)
                ->by((string) $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => __('message.too_many_requests'),
                        'data' => null,
                    ], 429, $headers);
                });
        });

        // RateLimiter::for('authenticated', function (Request $request) {
        //     return Limit::perMinute(300)
        //         ->by($request->user()?->id ?? $request->ip())
        //         ->response(function (Request $request, array $headers) {
        //             return response()->json([
        //                 'success' => false,
        //                 'message' => __('message.too_many_requests'),
        //                 'data' => null,
        //             ], 429, $headers);
        //         });
        // });

        // RateLimiter::for('admin', function (Request $request) {
        //     return Limit::perMinute(400)
        //         ->by($request->user()?->id ?? $request->ip())
        //         ->response(function (Request $request, array $headers) {
        //             return response()->json([
        //                 'success' => false,
        //                 'message' => __('message.too_many_requests'),
        //                 'data' => null,
        //             ], 429, $headers);
        //         });
        // });

        // RateLimiter::for('payment-callback', function (Request $request) {
        //     return Limit::perMinute(20)
        //         ->by($request->ip())
        //         ->response(function (Request $request, array $headers) {
        //             return response()->json([
        //                 'success' => false,
        //                 'message' => __('message.too_many_requests'),
        //                 'data' => null,
        //             ], 429, $headers);
        //         });
        // });

        // RateLimiter::for('payment-webhook', function (Request $request) {
        //     return Limit::perMinute(20)
        //         ->by($request->ip())
        //         ->response(function (Request $request, array $headers) {
        //             return response()->json([
        //                 'success' => false,
        //                 'message' => __('message.too_many_requests'),
        //                 'data' => null,
        //             ], 429, $headers);
        //         });
        // });

        // Identity-less public surfaces, FAIL-CLOSED (spec #73 F3, ticket #77).
        // Both routes resolve these limiters through the `throttle.failclosed`
        // alias (the GuestRateLimit policy): enforced even while the global
        // disable switch is on, and a limiter-backend failure answers 429
        // instead of bypassing. Keys stay IP-based. The 429 body is built by
        // that middleware envelope, so no per-limiter response callback.
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(3)->by((string) $request->ip());
        });

        RateLimiter::for('public-tracking', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // RateLimiter::for('sensitive', function (Request $request) {
        //     return Limit::perMinute(5)->by($request->ip())->response(function (Request $request, array $headers) {
        //         return response()->json(['success'=>false,'message'=>__('message.too_many_requests'),'data'=>null],429,$headers);
        //     });
        // });
        // RateLimiter::for('cart', function (Request $request) {
        //     return Limit::perMinute(20)->by($request->user()?->id ?? $request->ip())->response(function (Request $request, array $headers) {
        //         return response()->json(['success'=>false,'message'=>__('message.too_many_requests'),'data'=>null],429,$headers);
        //     });
        // });
        // RateLimiter::for('analytics', function (Request $request) {
        //     return Limit::perMinute(60)->by($request->user()?->id ?? $request->ip())->response(function (Request $request, array $headers) {
        //         return response()->json(['success'=>false,'message'=>__('message.too_many_requests'),'data'=>null],429,$headers);
        //     });
        // });
        // RateLimiter::for('refunds', function (Request $request) {
        //     return Limit::perMinute(5)->by($request->user()?->id ?? $request->ip())->response(function (Request $request, array $headers) {
        //         return response()->json(['success'=>false,'message'=>__('message.too_many_requests'),'data'=>null],429,$headers);
        //     });
        // });
    }
}
