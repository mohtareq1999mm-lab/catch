<?php

namespace Tests\Feature\Localization;

use App\Http\Middleware\CheckLangMiddleware;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Locale selection contract (CheckLangMiddleware).
 *
 * DB-free. Documents the precedence: explicit ar|en header wins, missing
 * header keeps the historical 'en' default, anything else falls back to the
 * application default — always explicit so Octane workers cannot leak the
 * previous request's locale (or Carbon locale).
 */
class LocaleMiddlewareTest extends TestCase
{
    private function runMiddleware(?string $header): string
    {
        $request = Request::create('/api/v1/general/settings', 'GET');
        if ($header !== null) {
            $request->headers->set('lang', $header);
        } else {
            $request->headers->remove('lang');
        }

        $middleware = app(CheckLangMiddleware::class);
        $middleware->handle($request, fn ($req) => response()->json(['locale' => app()->getLocale()]));

        return app()->getLocale();
    }

    public function test_arabic_header_selects_arabic(): void
    {
        $this->assertSame('ar', $this->runMiddleware('ar'));
        $this->assertSame('تم تقديم طلبك. ستدفع عند الاستلام.', __('checkout.cod_success'));
    }

    public function test_english_header_selects_english(): void
    {
        $this->assertSame('en', $this->runMiddleware('en'));
        $this->assertSame('Your order has been placed. You will pay upon delivery.', __('checkout.cod_success'));
    }

    public function test_missing_header_keeps_historical_english_default(): void
    {
        $this->assertSame('en', $this->runMiddleware(null));
    }

    public function test_unsupported_locale_falls_back_to_app_default_explicitly(): void
    {
        // Fail-closed + explicit: never leaves a stale locale behind.
        app()->setLocale('en');
        $this->assertSame(config('app.locale'), $this->runMiddleware('fr'));
        $this->assertSame(config('app.locale'), $this->runMiddleware('xx'));
        $this->assertSame(config('app.locale'), $this->runMiddleware(''));
    }

    public function test_locale_does_not_leak_between_requests(): void
    {
        $this->assertSame('en', $this->runMiddleware('en'));
        // An unsupported value must reset to the app default, NOT keep 'en'.
        $this->assertSame(config('app.locale'), $this->runMiddleware('fr'));
        $this->assertSame('ar', $this->runMiddleware('ar'));
        $this->assertSame(config('app.locale'), $this->runMiddleware('de'));
    }

    public function test_carbon_locale_follows_app_locale(): void
    {
        $this->runMiddleware('ar');
        $this->assertSame('ar', \Carbon\Carbon::getLocale());
        $this->runMiddleware('en');
        $this->assertSame('en', \Carbon\Carbon::getLocale());
    }

    public function test_header_matching_is_case_insensitive(): void
    {
        $this->assertSame('ar', $this->runMiddleware('AR'));
        $this->assertSame('en', $this->runMiddleware('En'));
    }

    public function test_placeholders_stay_stable_across_locales(): void
    {
        // Dynamic values interpolate identically in both locales; only the
        // surrounding sentence is translated.
        $this->runMiddleware('ar');
        $ar = __('message.MESSAGE.COUPON_CONFIG_HIGH_LIMIT', ['limiter' => 5000]);
        $this->runMiddleware('en');
        $en = __('message.MESSAGE.COUPON_CONFIG_HIGH_LIMIT', ['limiter' => 5000]);
        $this->assertStringContainsString('5000', $ar);
        $this->assertStringContainsString('5000', $en);
        $this->assertNotSame($ar, $en);
    }
}
