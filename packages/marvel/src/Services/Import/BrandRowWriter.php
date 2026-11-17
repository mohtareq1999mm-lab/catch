<?php

namespace Marvel\Services\Import;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Marvel\Database\Models\Brand;
use RuntimeException;
use Throwable;

/**
 * Writes the brands sheet: prepares and validates rows, owns the name/slug
 * identity maps, upserts brands (create/update, slug-safety rule) and runs
 * the best-effort image pipeline (download, attach, temp-file cleanup).
 * Failures are recorded as brand-row errors; a persisted brand records one
 * success on the run outcome.
 */
final class BrandRowWriter
{
    protected array $failedRows = [];

    protected array $createdIds = [];

    protected array $dbByName = [];

    protected array $dbBySlug = [];

    protected array $createdSlugs = [];

    private readonly ImportImageDownloader $images;

    public function __construct(
        private readonly RunOutcome $outcome,
        private readonly \Closure $onRowProcessed,
    ) {
        $this->images = new ImportImageDownloader('message.IMPORT.BRAND', 'brand_img');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function failedRows(): array
    {
        return $this->failedRows;
    }

    public function failedRowCount(): int
    {
        return count($this->failedRows);
    }

    /**
     * @return array<int, int>
     */
    public function createdIds(): array
    {
        return $this->createdIds;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function prepareRows(Collection $rows): array
    {
        $this->loadExistingBrands();

        $pending = [];
        $seenNames = [];

        foreach ($rows->values() as $index => $row) {
            $pending[$index] = $this->prepareRow($row, $index + 2, $seenNames);
        }

        return $pending;
    }

    protected function prepareRow($row, int $excelRow, array &$seenNames): array
    {
        $data = [
            'excel_row' => $excelRow,
            'name_en' => $this->normalizeText($row['name_en'] ?? null),
            'name_ar' => trim((string) ($row['name_ar'] ?? '')),
            'details_en' => (string) ($row['details_en'] ?? ''),
            'details_ar' => (string) ($row['details_ar'] ?? ''),
            'status' => 1,
            'image_desktop_url' => trim((string) ($row['image_desktop_url'] ?? '')),
            'image_mobile_url' => trim((string) ($row['image_mobile_url'] ?? '')),
            'temp_desktop' => null,
            'temp_mobile' => null,
            'errors' => [],
            'target' => null,
            'is_new' => false,
        ];

        if ($data['name_en'] === '') {
            $data['errors'][] = __('message.IMPORT.BRAND.NAME_EN_REQUIRED');
        }

        if ($data['name_ar'] === '') {
            $data['errors'][] = __('message.IMPORT.BRAND.NAME_AR_REQUIRED');
        }

        if (isset($seenNames[$data['name_en']])) {
            $data['errors'][] = __('message.IMPORT.BRAND.DUPLICATE_ROW');
        } elseif ($data['name_en'] !== '') {
            $seenNames[$data['name_en']] = true;
        }

        $status = $this->parseBooleanField($row['status'] ?? null);
        if ($status === 'invalid') {
            $data['errors'][] = __('message.IMPORT.BRAND.INVALID_STATUS');
        } else {
            $data['status'] = $status ?? 1;
        }

        if ($data['image_desktop_url'] !== '' && !$this->images->isValidUrlFormat($data['image_desktop_url'])) {
            $data['errors'][] = __('message.IMPORT.BRAND.INVALID_IMAGE_URL');
        }

        if ($data['image_mobile_url'] !== '' && !$this->images->isValidUrlFormat($data['image_mobile_url'])) {
            $data['errors'][] = __('message.IMPORT.BRAND.INVALID_IMAGE_URL');
        }

        if (!empty($data['errors'])) {
            $this->addFailedRow($data, $data['errors'][0]);

            return $data;
        }

        try {
            $data['temp_desktop'] = $data['image_desktop_url'] !== '' ? $this->images->downloadImage($data['image_desktop_url']) : null;
        } catch (Throwable $e) {
            // Image download failed - log it but don't fail the brand
            report(new RuntimeException("Brand '{$data['name_en']}' desktop image download failed: {$e->getMessage()}"));
            $data['temp_desktop'] = null;
        }

        try {
            $data['temp_mobile'] = $data['image_mobile_url'] !== '' ? $this->images->downloadImage($data['image_mobile_url']) : null;
        } catch (Throwable $e) {
            // Image download failed - log it but don't fail the brand
            report(new RuntimeException("Brand '{$data['name_en']}' mobile image download failed: {$e->getMessage()}"));
            $data['temp_mobile'] = null;
        }

        return $data;
    }

    /**
     * Identity rule mirrors Category import: normalized `name_en` is the
     * business identity. Existing name → update-in-place (slug preserved when
     * unchanged); new name → create with deterministic slug Str::slug(name_en).
     */
    public function upsertBrands(array &$pending): void
    {
        foreach ($pending as $index => $row) {
            if (!empty($row['errors'])) {
                continue;
            }

            try {
                $nameEn = $row['name_en'];
                $matches = $this->dbByName[$nameEn] ?? [];

                if (count($matches) > 1) {
                    $message = __('message.IMPORT.BRAND.AMBIGUOUS_NAME');
                    $this->failPendingRow($pending, $index, $row, $message);

                    continue;
                }

                if (count($matches) === 1) {
                    $brand = $matches[0];

                    if (!$this->updateSlugIsSafe($brand, $nameEn)) {
                        $message = __('message.IMPORT.BRAND.SLUG_CONFLICT');
                        $this->failPendingRow($pending, $index, $row, $message);

                        continue;
                    }

                    $brand->update([
                        'name' => [
                            'en' => $row['name_en'],
                            'ar' => $row['name_ar'],
                        ],
                        'details' => [
                            'en' => $row['details_en'],
                            'ar' => $row['details_ar'],
                        ],
                        'status' => $row['status'],
                    ]);

                    $row['target'] = $brand;
                    $row['is_new'] = false;
                } else {
                    $slug = Str::slug($nameEn);

                    if ($slug === '') {
                        $message = __('message.IMPORT.BRAND.INVALID_SLUG');
                        $this->failPendingRow($pending, $index, $row, $message);

                        continue;
                    }

                    if (isset($this->dbBySlug[$slug]) || isset($this->createdSlugs[$slug])) {
                        $message = __('message.IMPORT.BRAND.SLUG_CONFLICT');
                        $this->failPendingRow($pending, $index, $row, $message);

                        continue;
                    }

                    $brand = Brand::create([
                        'name' => [
                            'en' => $row['name_en'],
                            'ar' => $row['name_ar'],
                        ],
                        'details' => [
                            'en' => $row['details_en'],
                            'ar' => $row['details_ar'],
                        ],
                        'slug' => $slug,
                        'status' => $row['status'],
                    ]);

                    $this->dbByName[$nameEn] = [$brand];
                    $this->dbBySlug[$slug][] = $brand;
                    $this->createdSlugs[$slug] = $nameEn;
                    $this->createdIds[] = $brand->id;

                    $row['target'] = $brand;
                    $row['is_new'] = true;
                }

                $pending[$index] = $row;
            } catch (Throwable $e) {
                report($e);
                $this->failPendingRow($pending, $index, $row, $this->sanitizeErrorMessage($e->getMessage()));
            }
        }
    }

    protected function sanitizeErrorMessage(string $message): string
    {
        $message = preg_replace('#/[^ ]*storage[^ ]*#i', '[storage path]', $message) ?? $message;
        $message = preg_replace('#SQLSTATE\[[^\]]+\].*#i', 'Database error', $message) ?? $message;
        $message = trim($message);

        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '...';
        }

        return $message !== '' ? $message : 'Import failed';
    }

    public function attachImages(array &$pending): void
    {
        foreach ($pending as $index => $row) {
            if (!empty($row['errors']) || $row['target'] === null) {
                continue;
            }

            $attached = true;

            if ($row['temp_desktop'] !== null) {
                $attached = $this->attachImage($row['target'], $row['temp_desktop'], 'brands-desktop') && $attached;
            }

            if ($row['temp_mobile'] !== null) {
                $attached = $this->attachImage($row['target'], $row['temp_mobile'], 'brands-mobile') && $attached;
            }

            // Brand was already created successfully in upsertBrands()
            // Image attachment is optional - don't fail the entire brand if images fail
            $this->outcome->recordSuccess();

            if (!$attached) {
                // Log image failure but keep brand as successful
                report(new RuntimeException("Brand '{$row['name_en']}' created successfully but image attachment failed"));
            }

            ($this->onRowProcessed)();
        }
    }

    protected function attachImage(Brand $brand, string $tempPath, string $collection): bool
    {
        if (!file_exists($tempPath)) {
            return false;
        }

        try {
            if ($brand->hasMedia($collection)) {
                $brand->clearMediaCollection($collection);
            }

            $extension = pathinfo($tempPath, PATHINFO_EXTENSION) ?: 'jpg';

            $brand
                ->addMedia($tempPath)
                ->usingFileName(Str::uuid() . '.' . $extension)
                ->toMediaCollection($collection, 'brands');

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function updateSlugIsSafe(Brand $brand, string $nameEn): bool
    {
        $newSlug = Str::slug($nameEn);

        if ((string) $brand->slug === $newSlug) {
            return true;
        }

        $holders = $this->dbBySlug[$newSlug] ?? [];

        foreach ($holders as $holder) {
            if ((int) $holder->id !== (int) $brand->id) {
                return false;
            }
        }

        return true;
    }

    protected function loadExistingBrands(): void
    {
        $brands = Brand::query()
            ->select(['id', 'name', 'slug'])
            ->get();

        foreach ($brands as $brand) {
            $name = $this->brandEnglishName($brand);

            if ($name !== '') {
                $this->dbByName[$name][] = $brand;
            }

            if (is_string($brand->slug) && $brand->slug !== '') {
                $this->dbBySlug[$brand->slug][] = $brand;
            }
        }
    }

    protected function brandEnglishName(Brand $brand): string
    {
        try {
            $name = $brand->getTranslation('name', 'en', false);
        } catch (Throwable $e) {
            $name = $brand->getRawOriginal('name');
        }

        if (!is_string($name)) {
            return '';
        }

        return $this->normalizeText($name);
    }

    protected function normalizeText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    }

    protected function parseBooleanField($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return '1';
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return '0';
        }

        return 'invalid';
    }
    /**
     * The image pipeline lives on ImportImageDownloader (one owner for
     * brand and category); the writer only routes rows to it.
     */
    public function cleanupTempFiles(array $pending): void
    {
        $this->images->cleanupTempFiles($pending);
    }

    protected function failPendingRow(array &$pending, int $index, array $row, string $message): void
    {
        $row['errors'][] = $message;
        $pending[$index] = $row;
        $this->addFailedRow($row, $message);
    }

    protected function addFailedRow(array $row, string $errorMessage): void
    {
        $this->failedRows[] = [
            'sheet' => 'brands',
            'row' => $row['excel_row'] ?? 0,
            'name_en' => $row['name_en'] ?? '',
            'name_ar' => $row['name_ar'] ?? '',
            'error_message' => $errorMessage,
        ];
    }
}
