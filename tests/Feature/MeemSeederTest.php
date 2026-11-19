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
        $this->assertCount(11, $sections);

        $expectedIds = [25, 5, 21, 9, 37, 36, 34, 7, 35, 12, 13];
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
        $this->assertCount(11, $data);

        // 1. Hero (25)
        $this->assertEquals(25, $data[0]['id']);
        $this->assertEquals('hero', $data[0]['type']);
        $this->assertNull($data[0]['title']);
        $this->assertEquals('general/hero?', $data[0]['endpoint']);
        $this->assertEquals(1, $data[0]['order']);
        $this->assertEquals('video', $data[0]['setting']['front']['media_type']);

        // 2. Categories (5)
        $this->assertEquals(5, $data[1]['id']);
        $this->assertEquals('categories', $data[1]['type']);
        $this->assertEquals('Shop by Category', $data[1]['title']);
        $this->assertEquals(2, $data[1]['order']);

        // 3. Banners (21)
        $this->assertEquals(21, $data[2]['id']);
        $this->assertEquals('banners', $data[2]['type']);
        $this->assertEquals('Black Friday Deals', $data[2]['title']);
        $this->assertEquals('general/banners?slug=back-to-school', $data[2]['endpoint']);
        $this->assertEquals(3, $data[2]['order']);

        // 4. Products Flash Sale (9)
        $this->assertEquals(9, $data[3]['id']);
        $this->assertEquals('products', $data[3]['type']);
        $this->assertEquals('Black Friday Flash Sale', $data[3]['title']);
        $this->assertEquals(4, $data[3]['order']);

        // 5. Banners Winter Clearance (37)
        $this->assertEquals(37, $data[4]['id']);
        $this->assertEquals('banners', $data[4]['type']);
        $this->assertNull($data[4]['title']);
        $this->assertEquals('general/banners?slug=winter-clearance', $data[4]['endpoint']);
        $this->assertEquals(5, $data[4]['order']);

        // 6. Flash Sales (36)
        $this->assertEquals(36, $data[5]['id']);
        $this->assertEquals('flash-sales', $data[5]['type']);
        $this->assertNull($data[5]['title']);
        $this->assertEquals(6, $data[5]['order']);

        // 7. Banners Summer Sale (34)
        $this->assertEquals(34, $data[6]['id']);
        $this->assertEquals('banners', $data[6]['type']);
        $this->assertNull($data[6]['title']);
        $this->assertEquals('general/banners?slug=summer-sale', $data[6]['endpoint']);
        $this->assertEquals(7, $data[6]['order']);

        // 8. Products Best Sellers (7)
        $this->assertEquals(7, $data[7]['id']);
        $this->assertEquals('products', $data[7]['type']);
        $this->assertEquals('Best Sellers', $data[7]['title']);
        $this->assertEquals('general/products?limit=10&type=best_product_sales', $data[7]['endpoint']);
        $this->assertEquals(8, $data[7]['order']);

        // 9. Banners New Collection (35)
        $this->assertEquals(35, $data[8]['id']);
        $this->assertEquals('banners', $data[8]['type']);
        $this->assertNull($data[8]['title']);
        $this->assertEquals('general/banners?slug=new-collection', $data[8]['endpoint']);
        $this->assertEquals(9, $data[8]['order']);

        // 10. Products Fragrance (12)
        $this->assertEquals(12, $data[9]['id']);
        $this->assertEquals('products', $data[9]['type']);
        $this->assertEquals('Fragrance', $data[9]['title']);
        $this->assertEquals(10, $data[9]['order']);

        // 11. Brands Featured Brands (13)
        $this->assertEquals(13, $data[10]['id']);
        $this->assertEquals('brands', $data[10]['type']);
        $this->assertEquals('Featured Brands', $data[10]['title']);
        $this->assertEquals(11, $data[10]['order']);
    }
}
