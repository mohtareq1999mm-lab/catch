<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Category;
use Spatie\MediaLibrary\HasMedia;
use Tests\TestCase;

class CategoryMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_implements_has_media(): void
    {
        $category = new Category();
        $this->assertInstanceOf(HasMedia::class, $category);
    }

    public function test_category_has_media_collections_defined(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Media Test'],
            'slug' => 'media-test',
        ]);

        $this->assertCount(0, $category->getMedia('categories-desktop'));
        $this->assertCount(0, $category->getMedia('categories-mobile'));
    }
}
