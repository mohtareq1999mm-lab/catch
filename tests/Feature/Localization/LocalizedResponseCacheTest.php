<?php

namespace Tests\Feature\Localization;

use App\Traits\HasCache;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Locale-dependent response caching contract.
 *
 * REST responses are localized via CheckLangMiddleware. The requirement:
 * when the `lang` header changes the endpoint must rebuild ("clear cache
 * and restore new response") and never replay an entry cached in another
 * language.
 *
 * Design (deliberate): HasCache::remember() suffixes every key with the
 * active locale, so ar/en never share an entry. Data invalidation (tag
 * flush) is locale-transparent and clears all variants.
 *
 * DB-free, array cache driver only.
 */
class LocalizedResponseCacheTest extends TestCase
{
    use HasCache;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::setDefaultDriver('array');
        Cache::flush();
    }

    public function test_lang_switch_rebuilds_response_in_new_language(): void
    {
        $tag = 'test_localized_cache';
        $key = md5('http://test/api/v1/general/products');

        App::setLocale('ar');
        $arFirst = $this->remember($tag, $key, fn () => 'data_for_' . App::getLocale());
        $this->assertSame('data_for_ar', $arFirst);

        // Simulate the frontend switching lang: ar -> en. The SAME tag+key must
        // not replay the Arabic entry; it must rebuild in English.
        App::setLocale('en');
        $enFirst = $this->remember($tag, $key, fn () => 'data_for_' . App::getLocale());
        $this->assertSame('data_for_en', $enFirst);

        // Switching back mirrors the real app: both users keep valid entries.
        App::setLocale('ar');
        $arSecond = $this->remember($tag, $key, fn () => 'data_for_' . App::getLocale());
        $this->assertSame('data_for_ar', $arSecond);
    }

    public function test_lang_switch_rebuilds_plain_value_payloads_too(): void
    {
        $tag = 'test_localized_value_cache';
        $key = md5('http://test/api/v1/general/settings');

        // Non-closure payload path (fn() => $data wrapper): same isolation must hold.
        App::setLocale('ar');
        $this->assertSame('value_ar', $this->remember($tag, $key, 'value_ar'));

        App::setLocale('en');
        $this->assertSame('value_en', $this->remember($tag, $key, 'value_en'));

        // Reuse: back to Arabic serves the Arabic entry, never the English one.
        App::setLocale('ar');
        $this->assertSame('value_ar', $this->remember($tag, $key, 'value_rebuilt'));
    }

    public function test_tag_flush_clears_every_locale_variant(): void
    {
        $tag = 'test_localized_flush';
        $key = md5('http://test/api/v1/general/categories');

        App::setLocale('ar');
        $this->remember($tag, $key, fn () => 'old_' . App::getLocale());
        App::setLocale('en');
        $this->remember($tag, $key, fn () => 'old_' . App::getLocale());

        // Data changed (observers / imports) -> whole tag invalidated.
        $this->flushTag($tag);

        App::setLocale('ar');
        $this->assertSame('rebuilt_ar', $this->remember($tag, $key, fn () => 'rebuilt_' . App::getLocale()));
        App::setLocale('en');
        $this->assertSame('rebuilt_en', $this->remember($tag, $key, fn () => 'rebuilt_' . App::getLocale()));
    }
}
