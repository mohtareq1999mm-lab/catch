<?php

namespace Tests\Feature\StaticPages;

use Database\Seeders\StaticPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\StaticPage;
use Marvel\Database\Models\StaticSection;
use Tests\TestCase;

class StaticPageSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        Cache::flush();
    }

    public function test_seeder_creates_three_pages(): void
    {
        (new StaticPageSeeder())->run();

        $this->assertSame(3, StaticPage::count());
    }

    public function test_seeder_pages_have_expected_slugs(): void
    {
        (new StaticPageSeeder())->run();

        $this->assertSame(
            ['about-us', 'terms-and-conditions', 'privacy-policy'],
            StaticPage::orderBy('id')->pluck('slug')->all()
        );
    }

    public function test_seeder_pages_have_english_titles(): void
    {
        (new StaticPageSeeder())->run();

        $this->assertSame('About Us', StaticPage::where('slug', 'about-us')->first()->getTranslation('title', 'en'));
        $this->assertSame('Terms and Conditions', StaticPage::where('slug', 'terms-and-conditions')->first()->getTranslation('title', 'en'));
        $this->assertSame('Privacy Policy', StaticPage::where('slug', 'privacy-policy')->first()->getTranslation('title', 'en'));
    }

    public function test_seeder_pages_have_arabic_titles(): void
    {
        (new StaticPageSeeder())->run();

        $this->assertSame('من نحن', StaticPage::where('slug', 'about-us')->first()->getTranslation('title', 'ar'));
        $this->assertSame('الشروط والأحكام', StaticPage::where('slug', 'terms-and-conditions')->first()->getTranslation('title', 'ar'));
        $this->assertSame('سياسة الخصوصية', StaticPage::where('slug', 'privacy-policy')->first()->getTranslation('title', 'ar'));
    }

    public function test_seeder_pages_are_active(): void
    {
        (new StaticPageSeeder())->run();

        $this->assertSame(3, StaticPage::where('is_active', true)->count());
        $this->assertSame(0, StaticPage::where('is_active', false)->count());
    }

    public function test_seeder_creates_no_sections(): void
    {
        (new StaticPageSeeder())->run();

        $this->assertSame(0, StaticSection::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        (new StaticPageSeeder())->run();
        (new StaticPageSeeder())->run();
        (new StaticPageSeeder())->run();

        $this->assertSame(3, StaticPage::count());
    }

    public function test_seeder_preserves_edited_titles(): void
    {
        $page = StaticPage::create([
            'slug' => 'about-us',
            'title' => ['en' => 'Custom', 'ar' => 'مخصص'],
            'is_active' => true,
        ]);
        StaticPage::create(['slug' => 'terms-and-conditions', 'title' => ['en' => 'T', 'ar' => 'ت'], 'is_active' => true]);
        StaticPage::create(['slug' => 'privacy-policy', 'title' => ['en' => 'P', 'ar' => 'ص'], 'is_active' => true]);

        (new StaticPageSeeder())->run();

        $this->assertSame('Custom', $page->refresh()->getTranslation('title', 'en'));
    }

    public function test_seeder_preserves_deactivated_pages(): void
    {
        $page = StaticPage::create([
            'slug' => 'about-us',
            'title' => ['en' => 'About Us', 'ar' => 'من نحن'],
            'is_active' => false,
        ]);
        StaticPage::create(['slug' => 'terms-and-conditions', 'title' => ['en' => 'T', 'ar' => 'ت'], 'is_active' => true]);
        StaticPage::create(['slug' => 'privacy-policy', 'title' => ['en' => 'P', 'ar' => 'ص'], 'is_active' => true]);

        (new StaticPageSeeder())->run();

        $this->assertFalse($page->refresh()->is_active);
    }

    public function test_seeded_pages_are_publicly_accessible(): void
    {
        (new StaticPageSeeder())->run();

        $this->getJson('/api/v1/general/static-pages')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        foreach (['about-us', 'terms-and-conditions', 'privacy-policy'] as $slug) {
            $this->getJson('/api/v1/general/static-pages/' . $slug)->assertOk();
        }
    }
}