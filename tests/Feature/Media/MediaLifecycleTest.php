<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'public';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public static function mediaModelProvider(): array
    {
        return [
            'Category' => [
                Category::class,
                'categories-desktop',
                fn () => Category::create([
                    'name' => ['en' => 'Test Category'],
                    'slug' => 'test-category',
                ]),
            ],
            'Brand' => [
                Brand::class,
                'brand',
                fn () => Brand::create([
                    'name' => 'Test Brand',
                    'slug' => 'test-brand',
                ]),
            ],
            'Banner' => [
                Banner::class,
                'banner',
                fn () => Banner::create([
                    'title' => ['en' => 'Test Banner'],
                    'slug' => 'test-banner',
                ]),
            ],
        ];
    }

    private const REGISTERED_MEDIA_MODELS = [
        'Banner'   => Banner::class,
        'Brand'    => Brand::class,
        'Category' => Category::class,
    ];

    /** @test */
    public function all_affected_models_use_media_and_soft_deletes(): void
    {
        $affectedModels = [
            Banner::class,
            Brand::class,
            Category::class,
            'Marvel\Database\Models\FlashSale',
            'Marvel\Database\Models\Product',
            'Marvel\Database\Models\Review',
            'Marvel\Database\Models\Shop',
            'Marvel\Database\Models\Slider',
            'Marvel\Database\Models\User',
        ];

        foreach ($affectedModels as $modelClass) {
            $traits = class_uses_recursive($modelClass);
            $this->assertTrue(
                in_array('Spatie\MediaLibrary\InteractsWithMedia', $traits),
                "$modelClass is missing InteractsWithMedia"
            );
            $this->assertTrue(
                in_array('Illuminate\Database\Eloquent\SoftDeletes', $traits),
                "$modelClass is missing SoftDeletes"
            );
        }
    }

    /** @test */
    public function all_affected_models_are_registered_in_event_service_provider(): void
    {
        $providerPath = app_path('Providers/EventServiceProvider.php');
        $content = file_get_contents($providerPath);

        $affectedModels = [
            Banner::class,
            Brand::class,
            Category::class,
            'Marvel\Database\Models\FlashSale',
            'Marvel\Database\Models\Product',
            'Marvel\Database\Models\Review',
            'Marvel\Database\Models\Shop',
            'Marvel\Database\Models\Slider',
            'Marvel\Database\Models\User',
        ];

        foreach ($affectedModels as $modelClass) {
            $shortName = class_basename($modelClass);
            $this->assertStringContainsString(
                "$shortName::class",
                $content,
                "$modelClass is not registered in EventServiceProvider"
            );
        }
    }

    /** @test */
    public function media_cleanup_observer_is_registered_for_all_affected_models(): void
    {
        $providerPath = app_path('Providers/EventServiceProvider.php');
        $content = file_get_contents($providerPath);

        $this->assertStringContainsString(
            'MediaCleanupObserver::class',
            $content,
            'MediaCleanupObserver is not registered in EventServiceProvider'
        );

        $affectedModels = [
            Banner::class,
            Brand::class,
            Category::class,
            'Marvel\Database\Models\FlashSale',
            'Marvel\Database\Models\Product',
            'Marvel\Database\Models\Review',
            'Marvel\Database\Models\Shop',
            'Marvel\Database\Models\Slider',
            'Marvel\Database\Models\User',
        ];

        $observerCount = 0;
        foreach ($affectedModels as $modelClass) {
            $shortName = class_basename($modelClass);
            if (preg_match("/$shortName::class\s*=>\s*\[.*MediaCleanupObserver::class/s", $content)) {
                $observerCount++;
            }
        }

        $this->assertEquals(
            count($affectedModels),
            $observerCount,
            'Not all affected models have MediaCleanupObserver registered'
        );
    }

    /** @test */
    public function media_cleanup_observer_preserves_media_on_soft_delete(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Observer Test'],
            'slug' => 'observer-test',
        ]);

        $media = $category
            ->addMedia(UploadedFile::fake()->image('test.jpg'))
            ->toMediaCollection('categories-desktop', self::DISK);

        $mediaId = $media->id;

        $category->delete();

        $this->assertDatabaseHas('media', ['id' => $mediaId]);
    }

    /** @test */
    public function observer_does_not_interfere_with_non_soft_delete_deletion(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'No Soft Delete'],
            'slug' => 'no-soft-delete',
        ]);

        $media = $category
            ->addMedia(UploadedFile::fake()->image('direct.jpg'))
            ->toMediaCollection('categories-desktop', self::DISK);

        $mediaId = $media->id;

        $category->forceDelete();

        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
    }

    /** @test */
    public function observer_preserves_media_across_consecutive_soft_deletes(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Consecutive 1'],
            'slug' => 'consecutive-1',
        ]);
        $category->addMedia(UploadedFile::fake()->image('img1.jpg'))
            ->toMediaCollection('categories-desktop', self::DISK);
        $category->delete();

        $category2 = Category::create([
            'name' => ['en' => 'Consecutive 2'],
            'slug' => 'consecutive-2',
        ]);
        $category2->addMedia(UploadedFile::fake()->image('img2.jpg'))
            ->toMediaCollection('categories-desktop', self::DISK);
        $category2->delete();

        $this->assertDatabaseCount('media', 2);
    }

    /** @test */
    public function observer_keeps_original_media_when_recreating_model_after_soft_delete(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Recreate Original'],
            'slug' => 'recreate-original',
        ]);
        $category->addMedia(UploadedFile::fake()->image('first.jpg'))
            ->toMediaCollection('categories-desktop', self::DISK);

        $category->delete();

        $category2 = Category::create([
            'name' => ['en' => 'Recreate New'],
            'slug' => 'recreate-new',
        ]);
        $media2 = $category2->addMedia(UploadedFile::fake()->image('second.jpg'))
            ->toMediaCollection('categories-desktop', self::DISK);

        $this->assertDatabaseHas('media', ['id' => $media2->id]);
        $this->assertDatabaseCount('media', 2);
    }

    /** @dataProvider mediaModelProvider */
    public function test_model_implements_has_media(string $modelClass, string $collection, callable $factory): void
    {
        $model = $factory();
        $this->assertInstanceOf(HasMedia::class, $model);
    }

    /** @dataProvider mediaModelProvider */
    public function test_model_can_upload_media(string $modelClass, string $collection, callable $factory): void
    {
        Storage::fake(self::DISK);

        $model = $factory();

        $file = UploadedFile::fake()->image('upload.jpg', 800, 600);
        $media = $model
            ->addMedia($file)
            ->toMediaCollection($collection, self::DISK);

        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'model_type' => $modelClass,
            'model_id' => $model->id,
            'collection_name' => $collection,
            'file_name' => 'upload.jpg',
            'disk' => self::DISK,
        ]);

        Storage::disk(self::DISK)->assertExists($media->getPathRelativeToRoot());
    }

    /** @dataProvider mediaModelProvider */
    public function test_soft_delete_removes_orphan_media(string $modelClass, string $collection, callable $factory): void
    {
        Storage::fake(self::DISK);

        $model = $factory();

        $file = UploadedFile::fake()->image('orphan.jpg', 800, 600);
        $media = $model
            ->addMedia($file)
            ->toMediaCollection($collection, self::DISK);

        $mediaPath = $media->getPathRelativeToRoot();
        $mediaId = $media->id;

        $model->delete();

        $this->assertSoftDeleted($model);

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model));
        if ($usesSoftDeletes) {
            $this->assertDatabaseHas('media', ['id' => $mediaId]);
            Storage::disk(self::DISK)->assertExists($mediaPath);
        } else {
            $this->assertDatabaseMissing('media', ['id' => $mediaId]);
            Storage::disk(self::DISK)->assertMissing($mediaPath);
        }
    }

    /** @dataProvider mediaModelProvider */
    public function test_force_delete_removes_media(string $modelClass, string $collection, callable $factory): void
    {
        Storage::fake(self::DISK);

        $model = $factory();

        $file = UploadedFile::fake()->image('force.jpg', 800, 600);
        $media = $model
            ->addMedia($file)
            ->toMediaCollection($collection, self::DISK);

        $mediaPath = $media->getPathRelativeToRoot();
        $mediaId = $media->id;

        $model->forceDelete();

        $this->assertDatabaseMissing($model->getTable(), ['id' => $model->id]);
        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
        Storage::disk(self::DISK)->assertMissing($mediaPath);
    }

    /** @dataProvider mediaModelProvider */
    public function test_multiple_media_files_all_removed_on_soft_delete(string $modelClass, string $collection, callable $factory): void
    {
        Storage::fake(self::DISK);

        $model = $factory();

        $paths = [];
        for ($i = 1; $i <= 3; $i++) {
            $file = UploadedFile::fake()->image("img{$i}.jpg", 100, 100);
            $media = $model
                ->addMedia($file)
                ->toMediaCollection($collection, self::DISK);
            $paths[] = $media->getPathRelativeToRoot();
        }

        $this->assertCount(3, $model->getMedia($collection));

        $model->delete();

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model));
        if ($usesSoftDeletes) {
            $this->assertCount(3, Media::query()->where('model_id', $model->id)->get());
            foreach ($paths as $path) {
                Storage::disk(self::DISK)->assertExists($path);
            }
        } else {
            $this->assertCount(0, Media::query()->where('model_id', $model->id)->get());
            foreach ($paths as $path) {
                Storage::disk(self::DISK)->assertMissing($path);
            }
        }
    }

    /** @dataProvider mediaModelProvider */
    public function test_update_media_clears_old_and_adds_new(string $modelClass, string $collection, callable $factory): void
    {
        Storage::fake(self::DISK);

        $model = $factory();

        $oldFile = UploadedFile::fake()->image('old.jpg', 800, 600);
        $oldMedia = $model
            ->addMedia($oldFile)
            ->toMediaCollection($collection, self::DISK);

        $oldMediaPath = $oldMedia->getPathRelativeToRoot();

        $model->clearMediaCollection($collection);

        $newFile = UploadedFile::fake()->image('new.jpg', 800, 600);
        $newMedia = $model
            ->addMedia($newFile)
            ->toMediaCollection($collection, self::DISK);

        Storage::disk(self::DISK)->assertMissing($oldMediaPath);

        $this->assertDatabaseMissing('media', ['id' => $oldMedia->id]);
        $this->assertDatabaseHas('media', ['id' => $newMedia->id]);
    }
}
