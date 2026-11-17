<?php

namespace Marvel\Services\Import;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use RuntimeException;
use Throwable;

/**
 * Writes the categories sheet: prepares and validates rows, owns the
 * name/slug identity maps, upserts categories (create/update, slug-safety
 * rule) and runs the best-effort image pipeline (download, attach, temp-file
 * cleanup). Failures are recorded as category-row errors; a persisted
 * category records one success on the run outcome.
 *
 * Parent assignment lives in CategoryHierarchyLinker, which resolves the
 * hierarchy against the identity maps owned here.
 */
final class CategoryRowWriter
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
        $this->images = new ImportImageDownloader('message.IMPORT.CATEGORY', 'category_img');
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
     * Identity-map lookup for parent resolution (case-insensitive key).
     *
     * @return array<int, Category>
     */
    public function matchByName(string $nameKey): array
    {
        return $this->dbByName[$nameKey] ?? [];
    }

    /**
     * Drop a soft-deleted orphan from the in-memory identity maps so later
     * parent lookups and rollback no longer see it.
     */
    public function forgetCreatedCategory(Category $category, string $nameEn): void
    {
        $nameKey = $this->normalizeKey($nameEn);
        if ($nameKey !== '' && isset($this->dbByName[$nameKey])) {
            $this->dbByName[$nameKey] = array_values(array_filter(
                $this->dbByName[$nameKey],
                fn ($c) => (int) $c->id !== (int) $category->id
            ));
            if (empty($this->dbByName[$nameKey])) {
                unset($this->dbByName[$nameKey]);
            }
        }

        $slug = $category->slug ?? '';
        if (is_string($slug) && $slug !== '') {
            if (isset($this->dbBySlug[$slug])) {
                $this->dbBySlug[$slug] = array_values(array_filter(
                    $this->dbBySlug[$slug],
                    fn ($c) => (int) $c->id !== (int) $category->id
                ));
                if (empty($this->dbBySlug[$slug])) {
                    unset($this->dbBySlug[$slug]);
                }
            }
            unset($this->createdSlugs[$slug]);
        }

        $this->createdIds = array_values(array_filter(
            $this->createdIds,
            fn ($id) => (int) $id !== (int) $category->id
        ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function prepareRows(Collection $rows): array
    {
        $this->loadExistingCategories();

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
            'parent_name' => $this->normalizeText($row['parent_name_en'] ?? null),
            'status' => 1,
            'is_featured' => 0,
            'image_desktop_url' => $this->normalizeImageUrl(trim((string) ($row['image_desktop_url'] ?? ''))),
            'image_mobile_url' => $this->normalizeImageUrl(trim((string) ($row['image_mobile_url'] ?? ''))),
            'temp_desktop' => null,
            'temp_mobile' => null,
            'errors' => [],
            'target' => null,
            'is_new' => false,
        ];

        if ($data['name_en'] === '') {
            $data['errors'][] = __('message.IMPORT.CATEGORY.NAME_EN_REQUIRED');
        }

        if ($data['name_ar'] === '') {
            $data['errors'][] = __('message.IMPORT.CATEGORY.NAME_AR_REQUIRED');
        }

        $nameKey = $this->normalizeKey($data['name_en']);
        if ($nameKey !== '' && isset($seenNames[$nameKey])) {
            $data['errors'][] = __('message.IMPORT.CATEGORY.DUPLICATE_ROW');
        } elseif ($nameKey !== '') {
            $seenNames[$nameKey] = true;
        }

        $status = $this->parseBooleanField($row['status'] ?? null);
        if ($status === 'invalid') {
            $data['errors'][] = __('message.IMPORT.CATEGORY.INVALID_STATUS');
        } else {
            $data['status'] = $status ?? 1;
        }

        $featured = $this->parseBooleanField($row['is_featured'] ?? null);
        if ($featured === 'invalid') {
            $data['errors'][] = __('message.IMPORT.CATEGORY.INVALID_IS_FEATURED');
        } else {
            $data['is_featured'] = $featured ?? 0;
        }

        if ($data['image_desktop_url'] !== '' && !$this->images->isValidUrlFormat($data['image_desktop_url'])) {
            $data['errors'][] = __('message.IMPORT.CATEGORY.INVALID_IMAGE_URL');
        }

        if ($data['image_mobile_url'] !== '' && !$this->images->isValidUrlFormat($data['image_mobile_url'])) {
            $data['errors'][] = __('message.IMPORT.CATEGORY.INVALID_IMAGE_URL');
        }

        if (!empty($data['errors'])) {
            $this->addFailedRow($data, $data['errors'][0]);

            return $data;
        }

        // Best-effort image download: per-image failure does not fail the entire row.
        // This aligns Category with Brand parity (BrandImportService) where images are
        // optional; validation failures (INVALID_IMAGE_URL) still fail the row above.
        try {
            $data['temp_desktop'] = $data['image_desktop_url'] !== '' ? $this->images->downloadImage($data['image_desktop_url']) : null;
        } catch (Throwable $e) {
            report(new RuntimeException("Category '{$data['name_en']}' desktop image download failed: {$e->getMessage()}"));
            $data['temp_desktop'] = null;
        }

        try {
            $data['temp_mobile'] = $data['image_mobile_url'] !== '' ? $this->images->downloadImage($data['image_mobile_url']) : null;
        } catch (Throwable $e) {
            report(new RuntimeException("Category '{$data['name_en']}' mobile image download failed: {$e->getMessage()}"));
            $data['temp_mobile'] = null;
        }

        return $data;
    }

    public function upsertCategories(array &$pending): void
    {
        foreach ($pending as $index => $row) {
            if (!empty($row['errors'])) {
                continue;
            }

            try {
                $nameEn = $row['name_en'];
                $nameKey = $this->normalizeKey($nameEn);
                $matches = $this->dbByName[$nameKey] ?? [];

                if (count($matches) > 1) {
                    $message = __('message.IMPORT.CATEGORY.AMBIGUOUS_NAME');
                    $this->failPendingRow($pending, $index, $row, $message);

                    continue;
                }

                if (count($matches) === 1) {
                    $category = $matches[0];

                    if (!$this->updateSlugIsSafe($category, $nameEn)) {
                        $message = __('message.IMPORT.CATEGORY.SLUG_CONFLICT');
                        $this->failPendingRow($pending, $index, $row, $message);

                        continue;
                    }

                    $category->update([
                        'name' => [
                            'en' => $row['name_en'],
                            'ar' => $row['name_ar'],
                        ],
                        'details' => [
                            'en' => $row['details_en'],
                            'ar' => $row['details_ar'],
                        ],
                        'status' => $row['status'],
                        'is_featured' => $row['is_featured'],
                    ]);

                    $row['target'] = $category;
                    $row['is_new'] = false;
                } else {
                    $slug = Str::slug($nameEn);

                    if ($slug === '') {
                        $message = __('message.IMPORT.CATEGORY.INVALID_SLUG');
                        $this->failPendingRow($pending, $index, $row, $message);

                        continue;
                    }

                    if (isset($this->dbBySlug[$slug]) || isset($this->createdSlugs[$slug])) {
                        $message = __('message.IMPORT.CATEGORY.SLUG_CONFLICT');
                        $this->failPendingRow($pending, $index, $row, $message);

                        continue;
                    }

                    $category = Category::create([
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
                        'is_featured' => $row['is_featured'],
                        'parent_id' => null,
                    ]);

                    $this->dbByName[$nameKey] = [$category];
                    $this->dbBySlug[$slug][] = $category;
                    $this->createdSlugs[$slug] = $nameEn;
                    $this->createdIds[] = $category->id;

                    $row['target'] = $category;
                    $row['is_new'] = true;
                }

                $pending[$index] = $row;
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $this->failPendingRow($pending, $index, $row, $message);
            }
        }
    }

    public function attachImages(array &$pending): void
    {
        foreach ($pending as $index => $row) {
            if (!empty($row['errors']) || $row['target'] === null) {
                continue;
            }

            $attached = true;

            if ($row['temp_desktop'] !== null) {
                $attached = $this->attachImage($row['target'], $row['temp_desktop'], 'categories-desktop') && $attached;
            }

            if ($row['temp_mobile'] !== null) {
                $attached = $this->attachImage($row['target'], $row['temp_mobile'], 'categories-mobile') && $attached;
            }

            // Single authoritative success count. Images are best-effort (Brand parity):
            // parent success + category persisted = row success, image attach failure is logged
            // but does not revert success or delete existing valid media.
            $this->outcome->recordSuccess();
            $pending[$index] = $row;

            if (!$attached) {
                report(new RuntimeException("Category '{$row['name_en']}' image attachment failed (best-effort)"));
            }

            ($this->onRowProcessed)();
        }
    }

    protected function attachImage(Category $category, string $tempPath, string $collection): bool
    {
        if (!file_exists($tempPath)) {
            return false;
        }

        try {
            if ($category->hasMedia($collection)) {
                $category->clearMediaCollection($collection);
            }

            $extension = pathinfo($tempPath, PATHINFO_EXTENSION) ?: 'jpg';

            $category
                ->addMedia($tempPath)
                ->usingFileName(Str::uuid() . '.' . $extension)
                ->toMediaCollection($collection, 'categories');

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function updateSlugIsSafe(Category $category, string $nameEn): bool
    {
        $newSlug = Str::slug($nameEn);

        if ($category->slug === $newSlug) {
            return true;
        }

        $holders = $this->dbBySlug[$newSlug] ?? [];

        foreach ($holders as $holder) {
            if ((int) $holder->id !== (int) $category->id) {
                return false;
            }
        }

        return true;
    }

    protected function loadExistingCategories(): void
    {
        $categories = Category::query()
            ->select(['id', 'name', 'slug'])
            ->get();

        foreach ($categories as $category) {
            $name = $this->categoryEnglishName($category);

            if ($name !== '') {
                $key = $this->normalizeKey($name);
                if ($key !== '') {
                    $this->dbByName[$key][] = $category;
                }
            }

            if (is_string($category->slug) && $category->slug !== '') {
                $this->dbBySlug[$category->slug][] = $category;
            }
        }
    }

    protected function categoryEnglishName(Category $category): string
    {
        try {
            $name = $category->getTranslation('name', 'en', false);
        } catch (Throwable $e) {
            $name = $category->getRawOriginal('name');
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

    public function normalizeKey(?string $value): string
    {
        $normalized = $this->normalizeText($value);

        return $normalized === '' ? '' : mb_strtolower($normalized, 'UTF-8');
    }

    protected function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Single-URL contract: pipe-separated gallery expectation must not be silently encoded.
        // Leave '|' intact so isValidUrlFormat can reject it as malformed.
        if (str_contains($url, '|')) {
            return $url;
        }
        // Preserve already-encoded URLs without double-encoding
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? '';
        $credentials = $user !== '' ? $user . ($pass !== '' ? ':' . $pass : '') . '@' : '';
        $path = $parts['path'] ?? '';
        if ($path !== '') {
            $segments = explode('/', $path);
            $encoded = array_map(fn($seg) => rawurlencode(rawurldecode($seg)), $segments);
            $path = implode('/', $encoded);
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $scheme . '://' . $credentials . $host . $port . $path . $query . $fragment;
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
     * Public seam: DeepVerificationTest exercises SSRF screening through
     * the service, which delegates here. The pipeline itself lives on
     * ImportImageDownloader (one owner for brand and category).
     */
    public function assertSafeUrl(string $url): void
    {
        $this->images->assertSafeUrl($url);
    }

    public function cleanupTempFiles(array $pending): void
    {
        $this->images->cleanupTempFiles($pending);
    }

    /**
     * Remove one row's staged temp files. Public seam: the hierarchy linker
     * cleans up after orphan-removal decisions.
     */
    public function cleanupTempImages(array $row): void
    {
        foreach (['temp_desktop', 'temp_mobile'] as $key) {
            if (!empty($row[$key]) && file_exists($row[$key])) {
                @unlink($row[$key]);
            }
        }
    }

    /**
     * Record one row failure. Public by design: CategoryHierarchyLinker is
     * a sibling collaborator and reports parent-resolution failures through
     * this single writer seam.
     */
    public function failPendingRow(array &$pending, int $index, array $row, string $message): void
    {
        $row['errors'][] = $message;
        $pending[$index] = $row;
        $this->addFailedRow($row, $message);
    }

    protected function addFailedRow(array $row, string $errorMessage): void
    {
        $this->failedRows[] = [
            'sheet' => 'categories',
            'row' => $row['excel_row'] ?? 0,
            'name_en' => $row['name_en'] ?? '',
            'name_ar' => $row['name_ar'] ?? '',
            'parent_name_en' => $row['parent_name'] ?? '',
            'error_message' => $errorMessage,
        ];
    }
}
