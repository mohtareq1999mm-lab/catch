<?php

namespace App\Providers;

use App\Services\Guest\GuestIdentityService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;

class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * Only guest-checkout public surfaces are registered here. They are
     * consumed by the dedicated `guest.throttle` middleware, which does NOT
     * inherit the project-wide disable_rate_limiting bypass: protected public
     * guest endpoints must fail closed.
     */
    public function boot(): void
    {
        foreach ((array) config('guest_checkout.throttle', []) as $name => $settings) {
            if (!is_array($settings)) {
                continue;
            }

            $max = max(1, (int) ($settings['max'] ?? 60));
            $decay = max(1, (int) ($settings['decay'] ?? 1));

            RateLimiter::for($name, function (Request $request) use ($max, $decay, $name) {
                $limits = [
                    Limit::perMinutes($decay, $max)->by($this->guestKey($request)),
                ];

                // High-value surfaces also get an IP-scoped ceiling so that
                // rotating guest identities cannot multiply the budget.
                // guest_session joins it per D8: session-creation identity
                // farming from one IP is capped at the same 3x ceiling.
                // The key is namespaced differently from guestKey()'s
                // anonymous fallback ('ip:…'); sharing that string made both
                // limits hit ONE counter, silently halving the budget.
                if (in_array($name, ['guest_checkout', 'guest_payment', 'guest_session'], true)) {
                    $limits[] = Limit::perMinutes($decay, $max * 3)->by('ceiling:ip:' . (string) $request->ip());
                }

                return $limits;
            });
        }
    }

    /**
     * Per-guest identity when the verified credential is present, otherwise
     * per client IP. Never trusts a client-supplied identifier.
     */
    private function guestKey(Request $request): string
    {
        try {
            $identity = app(GuestIdentityService::class)->current($request);

            if ($identity) {
                return 'guest:' . $identity->id;
            }
        } catch (\Throwable $e) {
            // Lookup failure must not silently fall back to a weaker key when
            // the backend is broken; the middleware turns the throw into a
            // fail-closed 429. Re-throw here.
            throw $e;
        }

        return 'ip:' . (string) $request->ip();
    }
}
