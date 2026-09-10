<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Category;
use Marvel\Exports\CategoriesExport;
use Marvel\Http\Resources\CategoryResource;
use Marvel\Services\Import\CategoryImportService;
use Tests\TestCase;

class CategoryImportForensicTest extends TestCase
{
    use RefreshDatabase;

    private function row(array $overrides = []): array
    {
        return array_merge([
            'name_en' => 'Cat ' . uniqid(),
            'name_ar' => 'فئة',
            'details_en' => 'Details',
            'details_ar' => 'تفاصيل',
            'parent_name_en' => '',
            'status' => 1,
            'is_featured' => 0,
            'image_desktop_url' => '',
            'image_mobile_url' => '',
        ], $overrides);
    }

    private function service(): CategoryImportService
    {
        return new CategoryImportService();
    }

    private function tinyPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/wcAAgAB/azn7wAAAABJRU5ErkJggg==');
    }

    private function htmlBody(): string
    {
        return '<html><body>fake image</body></html>';
    }

    // ========== PARENT TESTS ==========

    public function test_root_category_parent_null_success(): void
    {
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Electronics', 'name_ar' => 'إلكترونيات', 'parent_name_en' => '']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $this->assertEmpty($service->getFailedRows());
        $cat = Category::where('slug', 'electronics')->first();
        $this->assertNotNull($cat);
        $this->assertNull($cat->parent_id);
        $this->assertEquals(1, $cat->level);
    }

    public function test_existing_parent_success(): void
    {
        Category::create(['name' => ['en' => 'Electronics', 'ar' => 'إ'], 'slug' => 'electronics']);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Phones', 'parent_name_en' => 'Electronics']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $phones = Category::where('slug', 'phones')->first();
        $this->assertNotNull($phones);
        $this->assertEquals(Category::where('slug', 'electronics')->first()->id, $phones->parent_id);
        $this->assertEquals(2, $phones->level);
    }

    public function test_parent_before_child_success(): void
    {
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Electronics']),
            $this->row(['name_en' => 'Phones', 'parent_name_en' => 'Electronics']),
        ]));
        $this->assertEquals(2, $service->getSuccessCount());
        $phones = Category::where('slug', 'phones')->first();
        $electronics = Category::where('slug', 'electronics')->first();
        $this->assertEquals($electronics->id, $phones->parent_id);
    }

    public function test_parent_after_child_success(): void
    {
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Phones', 'parent_name_en' => 'Electronics']),
            $this->row(['name_en' => 'Electronics']),
        ]));
        $this->assertEquals(2, $service->getSuccessCount());
        $phones = Category::where('slug', 'phones')->first();
        $electronics = Category::where('slug', 'electronics')->first();
        $this->assertEquals($electronics->id, $phones->parent_id);
        $this->assertEquals(2, $phones->level);
        $this->assertEquals(1, $electronics->level);
    }

    public function test_missing_specified_parent_failed_no_orphan(): void
    {
        // New domain contract (Phase 4): missing parent is NON-FATAL — category persists as root, not failed.
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Orphan', 'parent_name_en' => 'DoesNotExist']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $this->assertCount(0, $service->getFailedRows());
        $cat = Category::where('slug', 'orphan')->first();
        $this->assertNotNull($cat);
        $this->assertNull($cat->parent_id);
        $this->assertEquals(1, $cat->level);
        // success+failed == total and no double count
        $this->assertEquals(1, $service->getSuccessCount() + count($service->getFailedRows()));
    }

    public function test_ambiguous_parent_failed_no_orphan(): void
    {
        // Create two categories with same lowercased name to cause ambiguous
        // We bypass service to force duplicate names via direct DB insert with different slugs
        // Use raw insert to allow duplicate English names
        Category::create(['name' => ['en' => 'Common', 'ar' => 'a'], 'slug' => 'common-1']);
        // second with same name but slug different - need to bypass slug unique via direct
        \DB::table('categories')->insert([
            'name' => json_encode(['en' => 'Common', 'ar' => 'b']),
            'slug' => 'common-2',
            'parent_id' => null,
            'level' => 1,
            'status' => 1,
            'is_featured' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Child', 'parent_name_en' => 'Common']),
        ]));
        // Ambiguous parent is also non-fatal per new contract: persists as root
        $this->assertEquals(1, $service->getSuccessCount());
        $this->assertCount(0, $service->getFailedRows());
        $child = Category::where('slug', 'child')->first();
        $this->assertNotNull($child);
        $this->assertNull($child->parent_id);
    }

    public function test_case_insensitive_parent_lookup(): void
    {
        Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'electronics']);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Phones', 'parent_name_en' => 'electronics']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $phones = Category::where('slug', 'phones')->first();
        $this->assertEquals(Category::where('slug', 'electronics')->first()->id, $phones->parent_id);

        // also test uppercase
        $service2 = $this->service();
        $service2->processRows(new Collection([
            $this->row(['name_en' => 'Tablets', 'parent_name_en' => ' ELECTRONICS ']),
        ]));
        $this->assertEquals(1, $service2->getSuccessCount());
        $tablets = Category::where('slug', 'tablets')->first();
        $this->assertEquals(Category::where('slug', 'electronics')->first()->id, $tablets->parent_id);
    }

    public function test_self_parent_failed_keeps_root(): void
    {
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Gadgets', 'parent_name_en' => 'Gadgets']),
        ]));
        $this->assertEquals(0, $service->getSuccessCount());
        $cat = Category::where('slug', 'gadgets')->first();
        $this->assertNotNull($cat);
        $this->assertNull($cat->parent_id);
        $this->assertEquals(1, $cat->level);
    }

    public function test_cycle_failed(): void
    {
        $electronics = Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'electronics']);
        $phones = Category::create(['name' => ['en' => 'Phones'], 'slug' => 'phones', 'parent_id' => $electronics->id]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Electronics', 'parent_name_en' => 'Phones']),
        ]));
        $this->assertEquals(0, $service->getSuccessCount());
        $electronics->refresh();
        $this->assertNull($electronics->parent_id);
    }

    public function test_parent_change_correct(): void
    {
        $electronics = Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'electronics']);
        $phones = Category::create(['name' => ['en' => 'Phones'], 'slug' => 'phones']);
        $child = Category::create(['name' => ['en' => 'Child'], 'slug' => 'child', 'parent_id' => $electronics->id]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Child', 'parent_name_en' => 'Phones']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $child->refresh();
        $this->assertEquals($phones->id, $child->parent_id);
    }

    public function test_parent_removal_to_null_success(): void
    {
        $electronics = Category::create(['name' => ['en' => 'Electronics'], 'slug' => 'electronics']);
        $child = Category::create(['name' => ['en' => 'Child'], 'slug' => 'child', 'parent_id' => $electronics->id]);
        $this->assertEquals(2, $child->level);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Child', 'parent_name_en' => '']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $child->refresh();
        $this->assertNull($child->parent_id);
        $this->assertEquals(1, $child->level);
    }

    public function test_counter_atomicity_no_double_count(): void
    {
        // Bad row uses truly invalid data (invalid status) so it fails; missing parent is now non-fatal and would succeed.
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Good']),
            $this->row(['name_en' => 'Bad', 'status' => 'maybe']),
            $this->row(['name_en' => 'AlsoGood']),
        ]));
        $this->assertEquals(2, $service->getSuccessCount());
        $this->assertCount(1, $service->getFailedRows());
        $this->assertEquals(3, $service->getSuccessCount() + count($service->getFailedRows()));
    }

    // ========== IMAGE TESTS ==========

    public function test_no_images_media_zero(): void
    {
        Storage::fake('categories');
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'NoImages']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'noimages')->first();
        $this->assertCount(0, $cat->getMedia('categories-desktop'));
        $this->assertCount(0, $cat->getMedia('categories-mobile'));
        $this->assertEquals(0, \DB::table('media')->where('model_id', $cat->id)->count());
    }

    public function test_desktop_only_media_one(): void
    {
        Storage::fake('categories');
        $png = $this->tinyPng();
        Http::fake([
            'https://example.com/a.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'DeskOnly', 'image_desktop_url' => 'https://example.com/a.jpg']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'deskonly')->first();
        $this->assertCount(1, $cat->getMedia('categories-desktop'));
        $this->assertCount(0, $cat->getMedia('categories-mobile'));
        $this->assertDatabaseHas('media', [
            'model_id' => $cat->id,
            'collection_name' => 'categories-desktop',
            'disk' => 'categories',
        ]);
    }

    public function test_mobile_only_media_one(): void
    {
        Storage::fake('categories');
        $png = $this->tinyPng();
        Http::fake([
            'https://example.com/b.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'MobOnly', 'image_mobile_url' => 'https://example.com/b.jpg']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'mobonly')->first();
        $this->assertCount(0, $cat->getMedia('categories-desktop'));
        $this->assertCount(1, $cat->getMedia('categories-mobile'));
    }

    public function test_desktop_and_mobile_media_two(): void
    {
        Storage::fake('categories');
        $png = $this->tinyPng();
        Http::fake([
            'https://example.com/a.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://example.com/b.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Both', 'image_desktop_url' => 'https://example.com/a.jpg', 'image_mobile_url' => 'https://example.com/b.jpg']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'both')->first();
        $this->assertCount(1, $cat->getMedia('categories-desktop'));
        $this->assertCount(1, $cat->getMedia('categories-mobile'));
        $this->assertEquals(2, \DB::table('media')->where('model_id', $cat->id)->count());
        // API verification
        $resource = (new CategoryResource($cat))->toArray(request());
        $this->assertNotEmpty($resource['image']['desktop']);
        $this->assertNotEmpty($resource['image']['mobile']);
        // Export verification
        $export = new CategoriesExport();
        $collection = $export->collection();
        $exported = $collection->firstWhere('slug', 'both');
        $this->assertNotEmpty($exported->image_desktop_url);
        $this->assertNotEmpty($exported->image_mobile_url);
    }

    public function test_pipe_separated_url_fails_validation(): void
    {
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Pipe', 'image_desktop_url' => 'https://example.com/a.jpg|https://example.com/b.jpg|https://example.com/c.jpg']),
        ]));
        $this->assertEquals(0, $service->getSuccessCount());
        $this->assertCount(1, $service->getFailedRows());
        $this->assertEquals(__('message.IMPORT.CATEGORY.INVALID_IMAGE_URL'), $service->getFailedRows()[0]['error_message']);
        // No category should be created because validation fails before upsert
        $this->assertDatabaseMissing('categories', ['slug' => 'pipe']);
        $this->assertEquals(0, \DB::table('media')->count());
    }

    public function test_invalid_image_url_fails_row(): void
    {
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'BadUrl', 'image_desktop_url' => 'not-a-url']),
        ]));
        $this->assertEquals(0, $service->getSuccessCount());
        $this->assertCount(1, $service->getFailedRows());
        $this->assertDatabaseMissing('categories', ['slug' => 'badurl']);
    }

    public function test_unsafe_url_best_effort_category_still_succeeds(): void
    {
        // Private IP should be blocked in downloadImage but now best-effort, so category still succeeds with no media
        Storage::fake('categories');
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Unsafe', 'image_desktop_url' => 'http://127.0.0.1/image.jpg']),
        ]));
        // With best-effort, unsafe download does not fail row; category succeeds but no media
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'unsafe')->first();
        $this->assertNotNull($cat);
        $this->assertCount(0, $cat->getMedia('categories-desktop'));
    }

    public function test_html_pretending_to_be_image_is_rejected_best_effort(): void
    {
        Storage::fake('categories');
        Http::fake([
            'https://example.com/fake.jpg' => Http::response($this->htmlBody(), 200, ['Content-Type' => 'text/html']),
        ]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'HtmlFake', 'image_desktop_url' => 'https://example.com/fake.jpg']),
        ]));
        // Best-effort: category succeeds, but media is not attached
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'htmlfake')->first();
        $this->assertCount(0, $cat->getMedia('categories-desktop'));
    }

    public function test_wrong_mime_svg_rejected(): void
    {
        Storage::fake('categories');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
        Http::fake([
            'https://example.com/image.svg' => Http::response($svg, 200, ['Content-Type' => 'image/svg+xml']),
        ]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'SvgTest', 'image_desktop_url' => 'https://example.com/image.svg']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'svgtest')->first();
        $this->assertCount(0, $cat->getMedia('categories-desktop'));
    }

    public function test_reimport_replaces_images_no_duplicates(): void
    {
        Storage::fake('categories');
        $png = $this->tinyPng();
        Http::fake([
            'https://example.com/a1.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://example.com/b1.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://example.com/a2.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://example.com/b2.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Reimport', 'image_desktop_url' => 'https://example.com/a1.jpg', 'image_mobile_url' => 'https://example.com/b1.jpg']),
        ]));
        $cat = Category::where('slug', 'reimport')->first();
        $this->assertEquals(2, \DB::table('media')->where('model_id', $cat->id)->count());

        // Re-import same category with new images
        $service2 = $this->service();
        $service2->processRows(new Collection([
            $this->row(['name_en' => 'Reimport', 'image_desktop_url' => 'https://example.com/a2.jpg', 'image_mobile_url' => 'https://example.com/b2.jpg']),
        ]));
        $cat->refresh();
        $this->assertEquals(1, $cat->getMedia('categories-desktop')->count());
        $this->assertEquals(1, $cat->getMedia('categories-mobile')->count());
        $this->assertEquals(2, \DB::table('media')->where('model_id', $cat->id)->count());

        // Re-import N times, ensure no accumulation
        for ($i = 0; $i < 3; $i++) {
            $s = $this->service();
            $s->processRows(new Collection([
                $this->row(['name_en' => 'Reimport', 'image_desktop_url' => 'https://example.com/a2.jpg', 'image_mobile_url' => 'https://example.com/b2.jpg']),
            ]));
        }
        $cat->refresh();
        $this->assertEquals(1, $cat->getMedia('categories-desktop')->count());
        $this->assertEquals(1, $cat->getMedia('categories-mobile')->count());
        $this->assertEquals(2, \DB::table('media')->where('model_id', $cat->id)->count());
    }

    public function test_reimport_preserves_existing_image_when_empty(): void
    {
        Storage::fake('categories');
        $png = $this->tinyPng();
        Http::fake([
            'https://example.com/a.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://example.com/b.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'Preserve', 'image_desktop_url' => 'https://example.com/a.jpg', 'image_mobile_url' => 'https://example.com/b.jpg']),
        ]));
        $cat = Category::where('slug', 'preserve')->first();
        $this->assertEquals(2, \DB::table('media')->where('model_id', $cat->id)->count());

        // Re-import with empty desktop, new mobile? With current best-effort, empty means preserve
        $service2 = $this->service();
        $service2->processRows(new Collection([
            $this->row(['name_en' => 'Preserve', 'image_desktop_url' => '', 'image_mobile_url' => 'https://example.com/b.jpg']),
        ]));
        $cat->refresh();
        $this->assertEquals(1, $cat->getMedia('categories-desktop')->count(), 'desktop should be preserved when empty');
        $this->assertEquals(1, $cat->getMedia('categories-mobile')->count());
    }

    public function test_oversized_image_best_effort(): void
    {
        Storage::fake('categories');
        $large = str_repeat('a', 6 * 1024 * 1024); // 6MB > 5MB limit
        Http::fake([
            'https://example.com/large.jpg' => Http::response($large, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($large)]),
        ]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'LargeImg', 'image_desktop_url' => 'https://example.com/large.jpg']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'largeimg')->first();
        $this->assertCount(0, $cat->getMedia('categories-desktop'));
    }

    public function test_image_url_with_spaces_is_encoded_and_succeeds(): void
    {
        Storage::fake('categories');
        $png = $this->tinyPng();
        // Provide URL with literal spaces; service should encode path to %20 before download
        Http::fake([
            'https://example.com/Some%20Image%20Name.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $service = $this->service();
        $service->processRows(new Collection([
            $this->row(['name_en' => 'SpaceImg', 'image_desktop_url' => 'https://example.com/Some Image Name.png']),
        ]));
        $this->assertEquals(1, $service->getSuccessCount());
        $cat = Category::where('slug', 'spaceimg')->first();
        $this->assertCount(1, $cat->getMedia('categories-desktop'));
        $this->assertCount(0, $cat->getMedia('categories-mobile'));
    }
}
