<?php

namespace Marvel\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Marvel\Database\Models\Brand;

class BrandsExport implements FromCollection, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'brands';
    }

    /**
     * All brands with EN/AR translations and current media URLs, mirroring
     * the CategoriesExport contract (minus hierarchy fields).
     */
    protected ?\Illuminate\Support\Collection $cachedCollection = null;

    public function collection()
    {
        if ($this->cachedCollection !== null) {
            return $this->cachedCollection;
        }

        $brands = Brand::query()
            ->with('media')
            ->select(['id', 'name', 'details', 'slug', 'status'])
            ->orderBy('id')
            ->get();

        return $this->cachedCollection = $brands->map(function (Brand $brand) {
            return [
                'name_en' => $this->translation($brand, 'name', 'en'),
                'name_ar' => $this->translation($brand, 'name', 'ar'),
                'details_en' => $this->translation($brand, 'details', 'en'),
                'details_ar' => $this->translation($brand, 'details', 'ar'),
                'status' => (string) (int) $brand->status,
                'image_desktop_url' => (string) ($brand->getFirstMediaUrl('brands-desktop') ?: ''),
                'image_mobile_url' => (string) ($brand->getFirstMediaUrl('brands-mobile') ?: ''),
            ];
        });
    }

    public function headings(): array
    {
        return ['name_en', 'name_ar', 'details_en', 'details_ar', 'status', 'image_desktop_url', 'image_mobile_url'];
    }

    public function store(string $filename, string $disk)
    {
        return \Maatwebsite\Excel\Facades\Excel::store($this, $filename, $disk);
    }

    public function download(string $filename)
    {
        return \Maatwebsite\Excel\Facades\Excel::download($this, $filename);
    }

    protected function translation(Brand $brand, string $field, string $locale): string
    {
        try {
            $value = $brand->getTranslation($field, $locale, false);
        } catch (\Throwable $e) {
            try {
                $raw = $brand->getRawOriginal($field);
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

                return is_array($decoded) ? (string) ($decoded[$locale] ?? '') : '';
            } catch (\Throwable $e2) {
                return '';
            }
        }

        return is_string($value) ? $value : '';
    }
}
