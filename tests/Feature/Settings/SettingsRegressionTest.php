<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Settings;
use Tests\TestCase;

class SettingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function createSettings(array $extra = []): Settings
    {
        return Settings::create(array_merge([
            'site_name' => json_encode(['en' => 'Test Site']),
            'options' => ['currency' => 'USD'],
        ], $extra));
    }

    /** @test */
    public function getData_returns_settings_when_exists(): void
    {
        $this->createSettings();

        $settings = Settings::getData();

        $this->assertInstanceOf(Settings::class, $settings);
        $this->assertEquals('USD', $settings->options['currency']);
    }

    /** @test */
    public function getData_returns_null_when_no_settings(): void
    {
        $settings = Settings::getData();

        $this->assertNull($settings);
    }

    /** @test */
    public function getData_with_language_returns_same_result(): void
    {
        $this->createSettings(['options' => ['currency' => 'EUR']]);

        $settings = Settings::getData('en');
        $this->assertInstanceOf(Settings::class, $settings);
        $this->assertEquals('EUR', $settings->options['currency']);
    }

    /** @test */
    public function getData_caches_the_result(): void
    {
        $this->createSettings();

        $key = 'cached_settings_en';

        Cache::forget($key);
        $this->assertFalse(Cache::has($key));

        Settings::getData();

        $this->assertTrue(Cache::has($key));
    }

    /** @test */
    public function getData_uses_different_cache_key_per_language(): void
    {
        $this->createSettings();

        $en = Settings::getData('en');
        $ar = Settings::getData('ar');

        $this->assertNotNull($en);
        $this->assertNotNull($ar);
        $this->assertNotEquals(
            Cache::get('cached_settings_en'),
            Cache::get('cached_settings_ar'),
            'Different language keys should store separate cache entries'
        );
    }

    /** @test */
    public function getData_cache_is_stored_with_key_per_language(): void
    {
        $this->createSettings();

        Settings::getData('en');

        $this->assertNotNull(Cache::get('cached_settings_en'));
    }

    /** @test */
    public function getData_returns_cached_result_without_database_query(): void
    {
        $this->createSettings();

        $first = Settings::getData('en');
        $this->assertInstanceOf(Settings::class, $first);

        Settings::truncate();

        $cached = Settings::getData('en');
        $this->assertInstanceOf(Settings::class, $cached);
        $this->assertEquals('USD', $cached->options['currency']);
    }

    /** @test */
    public function getData_does_not_cache_null_result(): void
    {
        $this->assertNull(Settings::getData('en'));

        $this->createSettings(['options' => ['currency' => 'GBP']]);

        $settings = Settings::getData('en');
        $this->assertInstanceOf(Settings::class, $settings);
        $this->assertEquals('GBP', $settings->options['currency']);
    }

    /** @test */
    public function settings_can_be_read_after_update(): void
    {
        $setting = $this->createSettings();

        $setting->setTranslation('site_name', 'en', 'After');
        $setting->save();

        $this->assertEquals('After', $setting->fresh()->getTranslation('site_name', 'en'));
    }

    /** @test */
    public function options_are_cast_to_array(): void
    {
        $this->createSettings(['options' => ['currency' => 'USD', 'taxClass' => '1']]);

        $settings = Settings::first();

        $this->assertIsArray($settings->options);
        $this->assertEquals('USD', $settings->options['currency']);
    }
}
