<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class CheckLangMiddleware
{
    /**
     * Resolve the request locale from the `lang` header.
     *
     * Contract (documented — frontend sends `lang: ar|en`):
     * - `lang: ar|en`  → that locale.
     * - header missing → `en` (historical default, preserved for existing clients).
     * - unsupported value → application default (`config('app.locale')`, currently `ar`).
     *
     * The fallback branch is explicit (not a no-op) so locale state can never
     * leak between requests on long-running workers (Octane/Swoole), where
     * the application instance — and its locale — persists across requests.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->header('lang', 'en');
        $lang = is_string($lang) ? strtolower(trim($lang)) : '';

        $languages = ['en', 'ar'];

        if ($lang !== '' && in_array($lang, $languages, true)) {
            $locale = $lang;
        } else {
            $locale = (string) config('app.locale');
        }

        App::setLocale($locale);
        // Keep Carbon's human diffs (diffForHumans) in the same language as
        // the API text. Set explicitly every request so Octane workers can't
        // leak the previous request's Carbon locale either.
        try {
            \Carbon\Carbon::setLocale($locale);
        } catch (\Throwable) {
            // Carbon locale data missing — API text stays translated anyway.
        }

        return $next($request);
    }
}