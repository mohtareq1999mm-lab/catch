<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // General API rate limit - prevents scraping and DoS
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        // Authentication endpoints - login, register, social login
        // Protects against brute force and credential stuffing
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // OTP endpoints - stricter limit due to SMS/email costs
        // Protects against OTP bombing and brute force
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Sensitive operations - password reset, contact forms
        // Protects against email bombing and spam
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Order creation - per authenticated user or IP
        // Protects against order spam and inventory locking attacks
        RateLimiter::for('orders', function (Request $request) {
            return Limit::perMinute(10)->by(optional($request->user())->id ?: $request->ip());
        });

        // Content creation - reviews, questions, messages
        // Protects against spam and fake review bombing
        RateLimiter::for('content', function (Request $request) {
            return Limit::perMinute(5)->by(optional($request->user())->id ?: $request->ip());
        });

        // Refund requests - stricter limit for financial operations
        RateLimiter::for('refunds', function (Request $request) {
            return Limit::perMinute(5)->by(optional($request->user())->id ?: $request->ip());
        });

        // File uploads - prevent storage abuse
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)->by(optional($request->user())->id ?: $request->ip());
        });

        // Search endpoints - prevent scraping
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Cart endpoints - add/update/delete cart items, bulk operations
        // Protects against inventory locking abuse and cart manipulation
        RateLimiter::for('cart', function (Request $request) {
            return Limit::perMinute(20)->by(optional($request->user())->id ?: $request->ip());
        });

        // Dashboard/Analytics endpoints - platform-wide metrics
        // Protects against automated scraping of business-sensitive data
        RateLimiter::for('analytics', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}
