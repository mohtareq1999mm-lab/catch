<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Category;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class CategoryMediaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'public';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** @test */
    public function category_implements_has_media(): void
    {
        $category = new Category();
        $this->assertInstanceOf(HasMedia::class, $category);
    }

    /** @test */
    public function upload_category_image(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Image Test'],
            'slug' => 'image-test',
        ]);

        $file = UploadedFile::fake()->image('category.jpg', 800, 600);
        $media = $category
            ->addMedia($file)
            ->toMediaCollection('categories-desktop', self::DISK);

        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'model_type' => Category::class,
            'model_id' => $category->id,
            'collection_name' => 'categories-desktop',
            'file_name' => 'category.jpg',
            'disk' => self::DISK,
        ]);

        Storage::disk(self::DISK)->assertExists($media->getPathRelativeToRoot());
    }

    /** @test */
    public function update_category_image_removes_old_file(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Update Image'],
            'slug' => 'update-image',
        ]);

        $oldFile = UploadedFile::fake()->image('old.jpg', 800, 600);
        $oldMedia = $category
            ->addMedia($oldFile)
            ->toMediaCollection('categories-desktop', self::DISK);

        $oldMediaPath = $oldMedia->getPathRelativeToRoot();

        $category->clearMediaCollection('categories-desktop');

        $newFile = UploadedFile::fake()->image('new.jpg', 800, 600);
        $newMedia = $category
            ->addMedia($newFile)
            ->toMediaCollection('categories-desktop', self::DISK);

        Storage::disk(self::DISK)->assertMissing($oldMediaPath);

        $this->assertDatabaseMissing('media', ['id' => $oldMedia->id]);
        $this->assertDatabaseHas('media', ['id' => $newMedia->id]);
    }

    /** @test */
    public function soft_delete_preserves_media(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Soft Delete Media'],
            'slug' => 'soft-delete-media',
        ]);

        $file = UploadedFile::fake()->image('preserved.jpg', 800, 600);
        $media = $category
            ->addMedia($file)
            ->toMediaCollection('categories-desktop', self::DISK);

        $mediaPath = $media->getPathRelativeToRoot();
        $mediaId = $media->id;

        $category->delete();

        $this->assertSoftDeleted($category);
        $this->assertDatabaseHas('media', ['id' => $mediaId]);

        Storage::disk(self::DISK)->assertExists($mediaPath);
    }

    /** @test */
    public function force_delete_category_removes_all_media(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Force Delete Media'],
            'slug' => 'force-delete-media',
        ]);

        $file = UploadedFile::fake()->image('gone.jpg', 800, 600);
        $media = $category
            ->addMedia($file)
            ->toMediaCollection('categories-desktop', self::DISK);

        $mediaPath = $media->getPathRelativeToRoot();
        $mediaId = $media->id;

        $category->forceDelete();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('media', ['id' => $mediaId]);

        Storage::disk(self::DISK)->assertMissing($mediaPath);
    }

    /** @test */
    public function multiple_media_files_preserved_on_soft_delete(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Multiple Media'],
            'slug' => 'multiple-media',
        ]);

        $paths = [];
        for ($i = 1; $i <= 3; $i++) {
            $file = UploadedFile::fake()->image("img{$i}.jpg", 100, 100);
            $media = $category
                ->addMedia($file)
                ->toMediaCollection('categories-desktop', self::DISK);
            $paths[] = $media->getPathRelativeToRoot();
        }

        $this->assertCount(3, $category->getMedia('categories-desktop'));

        $category->delete();

        $this->assertCount(3, Media::query()->where('model_id', $category->id)->get());

        foreach ($paths as $path) {
            Storage::disk(self::DISK)->assertExists($path);
        }
    }

    /** @test */
    public function multiple_media_files_all_removed_on_force_delete(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Force Multiple'],
            'slug' => 'force-multiple',
        ]);

        $paths = [];
        for ($i = 1; $i <= 3; $i++) {
            $file = UploadedFile::fake()->image("img{$i}.jpg", 100, 100);
            $media = $category
                ->addMedia($file)
                ->toMediaCollection('categories-desktop', self::DISK);
            $paths[] = $media->getPathRelativeToRoot();
        }

        $category->forceDelete();

        $this->assertDatabaseMissing('media', ['model_id' => $category->id]);

        foreach ($paths as $path) {
            Storage::disk(self::DISK)->assertMissing($path);
        }
    }

    /** @test */
    public function media_collections_work_independently(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'Two Collections'],
            'slug' => 'two-collections',
        ]);

        $desktop = $category
            ->addMedia(UploadedFile::fake()->image('desktop.jpg'))
            ->toMediaCollection('categories-desktop', self::DISK);

        $mobile = $category
            ->addMedia(UploadedFile::fake()->image('mobile.jpg'))
            ->toMediaCollection('categories-mobile', self::DISK);

        $this->assertCount(1, $category->getMedia('categories-desktop'));
        $this->assertCount(1, $category->getMedia('categories-mobile'));
        $this->assertCount(2, $category->getMedia('*'));
    }

    /** @test */
    public function category_crud_unaffected_by_media(): void
    {
        Storage::fake(self::DISK);

        $category = Category::create([
            'name' => ['en' => 'CRUD + Media'],
            'slug' => 'crud-media',
        ]);

        $category->addMedia(UploadedFile::fake()->image('test.jpg'))
            ->toMediaCollection('categories-desktop', self::DISK);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);

        $category->delete();
        $this->assertSoftDeleted($category);

        $category->restore();
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertNotNull($category->getFirstMedia('categories-desktop'));
    }
}
