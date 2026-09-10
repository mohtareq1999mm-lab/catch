<?php

namespace Marvel\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Marvel\Database\Models\Category;
use Throwable;

class CategoriesExport implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $categories;

    public function __construct()
    {
        $this->loadCategories();
    }

    protected function loadCategories(): void
    {
        $categories = Category::query()
            ->with(['parent:id,name'])
            ->select(['id', 'name', 'details', 'slug', 'parent_id', 'level', 'status', 'is_featured'])
            ->orderBy('level')
            ->orderBy('id')
            ->get();

        $parentNames = [];

        foreach ($categories as $category) {
            $parentNames[$category->id] = $this->translationOf($category, 'name', 'en');
        }

        foreach ($categories as $category) {
            $category->parent_name_en = ($category->parent_id && isset($parentNames[$category->parent_id]))
                ? $parentNames[$category->parent_id]
                : '';
            $category->image_desktop_url = $this->firstImageUrl($category, 'categories-desktop');
            $category->image_mobile_url = $this->firstImageUrl($category, 'categories-mobile');
        }

        $this->categories = $categories;
    }

    protected function translationOf(Category $category, string $field, string $locale): string
    {
        try {
            $value = $category->getTranslation($field, $locale, false);

            return is_string($value) ? trim($value) : '';
        } catch (Throwable $e) {
            try {
                $value = $category->getRawOriginal($field);
            } catch (Throwable $e2) {
                $value = null;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    $value = $decoded[$locale] ?? '';
                }
            }

            return is_string($value) ? trim($value) : '';
        }
    }

    protected function firstImageUrl(Category $category, string $collection): string
    {
        try {
            $media = $category->getMedia($collection);

            if ($media->count()) {
                return (string) $media->first()->getUrl();
            }
        } catch (Throwable $e) {
        }

        return '';
    }

    public function collection(): Collection
    {
        return $this->categories;
    }

    public function headings(): array
    {
        return [
            'name_en',
            'name_ar',
            'details_en',
            'details_ar',
            'parent_name_en',
            'status',
            'is_featured',
            'image_desktop_url',
            'image_mobile_url',
        ];
    }

    public function map($category): array
    {
        return [
            $this->translationOf($category, 'name', 'en'),
            $this->translationOf($category, 'name', 'ar'),
            $this->translationOf($category, 'details', 'en'),
            $this->translationOf($category, 'details', 'ar'),
            $category->parent_name_en,
            (int) $category->status === 1 ? '1' : '0',
            (int) $category->is_featured === 1 ? '1' : '0',
            $category->image_desktop_url,
            $category->image_mobile_url,
        ];
    }

    public function store(string $filename, string $disk)
    {
        return \Maatwebsite\Excel\Facades\Excel::store($this, $filename, $disk);
    }

    public function download(string $filename)
    {
        return \Maatwebsite\Excel\Facades\Excel::download($this, $filename);
    }
}