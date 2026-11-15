<?php

namespace Tests\Feature;

use Database\Seeders\MeemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Tests\TestCase;

class MeemSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();
        app()->setLocale('en');
    }

    public function test_meem_seeder_seeds_sections_correctly(): void
    {
        $this->seed(MeemSeeder::class);

        $page = ContentPage::where('slug', 'home')->first();
        $this->assertNotNull($page);
        $this->assertTrue($page->is_active);

        $sections = Section::where('content_page_id', $page->id)->orderBy('order')->get();
        $this->assertCount(8, $sections);

        $expectedIds = [25, 21, 5, 9, 7, 12, 13, 18];
        $this->assertEquals($expectedIds, $sections->pluck('id')->all());

        // Verify API response structure
        $response = $this->getJson('/api/v1/general/content-pages/home');
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'message' => 'Data fetched successfully',
            'success' => true,
            'data' => [
                'id' => $page->id,
                'slug' => 'home',
                'is_active' => true,
            ],
        ]);

        $data = $response->json('data.sections');
        $this->assertCount(8, $data);

        // Hero section assertions
        $this->assertEquals(25, $data[0]['id']);
        $this->assertEquals('hero', $data[0]['type']);
        $this->assertNull($data[0]['title']);
        $this->assertEquals('general/hero?', $data[0]['endpoint']);
        $this->assertEquals(1, $data[0]['order']);
        $this->assertEquals('video', $data[0]['setting']['front']['media_type']);

        // Banners section assertions
        $this->assertEquals(21, $data[1]['id']);
        $this->assertEquals('banners', $data[1]['type']);
        $this->assertEquals('Black Friday Deals', $data[1]['title']);
        $this->assertEquals(2, $data[1]['order']);

        // Categories section assertions
        $this->assertEquals(5, $data[2]['id']);
        $this->assertEquals('categories', $data[2]['type']);
        $this->assertEquals('Shop by Category', $data[2]['title']);
        $this->assertEquals(3, $data[2]['order']);

        // Flash sale products assertions
        $this->assertEquals(9, $data[3]['id']);
        $this->assertEquals('products', $data[3]['type']);
        $this->assertEquals('Black Friday Flash Sale', $data[3]['title']);
        $this->assertEquals(4, $data[3]['order']);

        // Best sellers products assertions
        $this->assertEquals(7, $data[4]['id']);
        $this->assertEquals('products', $data[4]['type']);
        $this->assertEquals('Best Sellers', $data[4]['title']);
        $this->assertEquals(5, $data[4]['order']);

        // Fragrance products assertions
        $this->assertEquals(12, $data[5]['id']);
        $this->assertEquals('products', $data[5]['type']);
        $this->assertEquals('Fragrance', $data[5]['title']);
        $this->assertEquals(6, $data[5]['order']);

        // Brands assertions
        $this->assertEquals(13, $data[6]['id']);
        $this->assertEquals('brands', $data[6]['type']);
        $this->assertEquals('Featured Brands', $data[6]['title']);
        $this->assertEquals(7, $data[6]['order']);

        // Coupons assertions
        $this->assertEquals(18, $data[7]['id']);
        $this->assertEquals('coupons', $data[7]['type']);
        $this->assertEquals('Coupons & Vouchers', $data[7]['title']);
        $this->assertEquals(8, $data[7]['order']);
    }
}

