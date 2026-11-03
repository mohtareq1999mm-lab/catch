<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RateLimitingBypassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['throttle:3,1'])->get('/test-rate-limit-numeric', function () {
            return response()->json(['type' => 'numeric']);
        });

        Route::middleware(['throttle:auth'])->get('/test-rate-limit-named', function () {
            return response()->json(['type' => 'named']);
        });

        Route::middleware(['api'])->get('/test-rate-limit-api-group', function () {
            return response()->json(['type' => 'api_group']);
        });
    }

    /**
     * Test that rate limiting is completely bypassed across all types when disabled.
     */
    public function test_rate_limiting_is_bypassed_globally_when_disabled(): void
    {
        Config::set('app.disable_rate_limiting', true);

        // Numeric limiter: 10 requests on limit of 3
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/test-rate-limit-numeric')
                ->assertOk()
                ->assertJson(['type' => 'numeric']);
        }

        // Named limiter: 15 requests
        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/test-rate-limit-named')
                ->assertOk()
                ->assertJson(['type' => 'named']);
        }

        // API group limiter: 20 requests
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/test-rate-limit-api-group')
                ->assertOk()
                ->assertJson(['type' => 'api_group']);
        }
    }

    /**
     * Test that rate limiting works again when disable_rate_limiting is false.
     */
    public function test_rate_limiting_is_enforced_when_not_disabled(): void
    {
        Config::set('app.disable_rate_limiting', false);

        // First 3 requests should pass
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/test-rate-limit-numeric')->assertOk();
        }

        // 4th request must be throttled with 429
        $this->getJson('/test-rate-limit-numeric')->assertStatus(429);
    }
}
