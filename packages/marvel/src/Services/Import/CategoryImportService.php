<?php

namespace Marvel\Services\Import;

use App\Events\CategoryImportProgress;
use App\Services\General\CategoryHierarchyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Exceptions\ImportCancelledException;
use RuntimeException;
use Throwable;

class CategoryImportService
{
    protected const FLUSH_THRESHOLD = 20;

    protected const MAX_IMAGE_SIZE = 5 * 1024 * 1024;

    protected const MAX_REDIRECTS = 5;

    protected const IMAGE_TIMEOUT = 30;

    protected const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
    ];

    protected ?int $importId = null;

    protected ?int $broadcastUserId = null;

    protected int $successCount = 0;

    protected array $failedRows = [];

    protected array $createdIds = [];

    protected array $dbByName = [];

    protected array $dbBySlug = [];

    protected array $createdSlugs = [];

    protected int $processedCount = 0;

    protected float $currentProgress = 0.0;

    protected float $startedAt;

    protected int $lastTickProcessedCount = 0;

    protected float $lastTickTime;

    public function __construct(?int $importId = null)
    {
        $this->importId = $importId;
        $this->startedAt = microtime(true);
        $this->lastTickTime = $this->startedAt;
    }

    public function processRows(Collection $rows): void
    {
        try {
            $pending = $this->prepareRows($rows);

            $this->writeExplicitProgress(10.0);

            if ($this->isCancelled()) {
                throw new ImportCancelledException();
            }

            $this->upsertCategories($pending);

            $this->writeExplicitProgress(60.0);

            if ($this->isCancelled()) {
                throw new ImportCancelledException();
            }

            $this->assignParents($pending);

            $this->writeExplicitProgress(80.0);

            if ($this->isCancelled()) {
                throw new ImportCancelledException();
            }

            $this->attachImages($pending);

            $this->writeExplicitProgress(99.0);
        } finally {
            $this->cleanupTempFiles($pending ?? []);
        }
    }

    protected function prepareRows(Collection $rows): array
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
            'original_parent_id' => null,
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

        if ($data['image_desktop_url'] !== '' && !$this->isValidUrlFormat($data['image_desktop_url'])) {
            $data['errors'][] = __('message.IMPORT.CATEGORY.INVALID_IMAGE_URL');
        }

        if ($data['image_mobile_url'] !== '' && !$this->isValidUrlFormat($data['image_mobile_url'])) {
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
            $data['temp_desktop'] = $data['image_desktop_url'] !== '' ? $this->downloadImage($data['image_desktop_url']) : null;
        } catch (Throwable $e) {
            report(new RuntimeException("Category '{$data['name_en']}' desktop image download failed: {$e->getMessage()}"));
            $data['temp_desktop'] = null;
        }

        try {
            $data['temp_mobile'] = $data['image_mobile_url'] !== '' ? $this->downloadImage($data['image_mobile_url']) : null;
        } catch (Throwable $e) {
            report(new RuntimeException("Category '{$data['name_en']}' mobile image download failed: {$e->getMessage()}"));
            $data['temp_mobile'] = null;
        }

        return $data;
    }

    protected function upsertCategories(array &$pending): void
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
                    $row['original_parent_id'] = $category->parent_id;
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
                    $row['original_parent_id'] = null;
                }

                $pending[$index] = $row;
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $this->failPendingRow($pending, $index, $row, $message);
            }
        }
    }

    protected function assignParents(array &$pending): void
    {
        $hierarchyService = app(CategoryHierarchyService::class);

        foreach ($pending as $index => $row) {
            if (!empty($row['errors']) || $row['target'] === null) {
                continue;
            }

            $parentName = $row['parent_name'];
            $parentId = null;
            $parentError = null;

            if ($parentName !== '') {
                $parentKey = $this->normalizeKey($parentName);
                $matches = $this->dbByName[$parentKey] ?? [];

                if (count($matches) === 0) {
                    $parentError = __('message.IMPORT.CATEGORY.MISSING_PARENT');
                } elseif (count($matches) > 1) {
                    $parentError = __('message.IMPORT.CATEGORY.AMBIGUOUS_PARENT');
                } else {
                    $parentId = (int) $matches[0]->id;
                }
            }

            if ($parentError !== null) {
                // Non-fatal domain contract: missing/ambiguous parent does not fail the category.
                // Persist as root (parent_id = null) and keep row as success candidate.
                // Log warning for forensic audit, do not increment failedRows.
                report(new \RuntimeException("Category '{$row['name_en']}' parent warning ({$parentError}): parent_name_en='{$row['parent_name']}'"));
                try {
                    $row['target']->parent_id = null;
                    $row['target']->save();
                } catch (\Throwable $e) {
                    $this->failPendingRow($pending, $index, $row, $e->getMessage());
                    if (!empty($row['is_new']) && $row['target'] !== null) {
                        $this->removeOrphanCategory($pending, $index, $row);
                    } else {
                        $this->cleanupTempImages($row);
                    }
                    continue;
                }
                $pending[$index] = $row;
                $this->flushProgressTick();
                continue;
            }

            try {
                if ($parentId !== null) {
                    $hierarchyService->ensureHierarchyIsValid($row['target'], $parentId);
                }

                $row['target']->parent_id = $parentId;
                $row['target']->save();

                // Success is counted authoritatively in attachImages after media handling.
                // Parent assignment success only persists the hierarchy; not counted yet.
                $pending[$index] = $row;
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten()->implode(' ');
                $message = $messages ?: __('message.IMPORT.CATEGORY.INVALID_PARENT');
                $this->failPendingRow($pending, $index, $row, $message);
                // Hierarchy validation failure (self/cycle) - keep newly created category as root
                // per matrix: self parent stays root level 1, do not delete orphan.
                $this->cleanupTempImages($row);
            } catch (Throwable $e) {
                $this->failPendingRow($pending, $index, $row, $e->getMessage());
                if (!empty($row['is_new']) && $row['target'] !== null) {
                    $this->removeOrphanCategory($pending, $index, $row);
                } else {
                    $this->cleanupTempImages($row);
                }
            }

            $this->flushProgressTick();
        }
    }

    protected function removeOrphanCategory(array &$pending, int $index, array $row): void
    {
        $category = $row['target'] ?? null;

        if (!$category) {
            return;
        }

        try {
            // Soft-delete the orphaned newly created category to preserve audit trail
            $category->delete();
        } catch (Throwable $e) {
            report($e);
        }

        // Remove from in-memory indexes to prevent later parent lookups finding the orphan
        $nameKey = $this->normalizeKey($row['name_en'] ?? '');
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

        // Ensure temp images for this orphan row are cleaned
        $this->cleanupTempImages($row);
        // Mark pending row as failed state without target to prevent later attach
        $pending[$index]['target'] = null;
    }

    protected function attachImages(array &$pending): void
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
            $this->successCount++;
            $pending[$index] = $row;

            if (!$attached) {
                report(new RuntimeException("Category '{$row['name_en']}' image attachment failed (best-effort)"));
            }

            $this->flushProgressTick();
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

    protected function normalizeKey(?string $value): string
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

    protected function isValidUrlFormat(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Single-URL contract: reject pipe-separated gallery expectations.
        // A cell containing '|' is one malformed URL, not 3 images.
        if (str_contains($url, '|')) {
            return false;
        }

        return true;
    }

    protected function downloadImage(string $url): string
    {
        if (!$this->isValidUrlFormat($url)) {
            throw new RuntimeException(__('message.IMPORT.CATEGORY.INVALID_IMAGE_URL'));
        }

        $this->assertSafeUrl($url);

        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertSafeUrl($currentUrl);

            $response = Http::timeout(self::IMAGE_TIMEOUT)
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->get($currentUrl);

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');

                if (!$location) {
                    throw new RuntimeException(__('message.IMPORT.CATEGORY.UNSAFE_IMAGE_URL'));
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(__('message.IMPORT.CATEGORY.IMAGE_DOWNLOAD_FAILED') . " (HTTP {$status})");
            }

            $contentLength = $response->header('Content-Length');

            if ($contentLength !== null && (int) $contentLength > self::MAX_IMAGE_SIZE) {
                throw new RuntimeException(__('message.IMPORT.CATEGORY.IMAGE_TOO_LARGE'));
            }

            $body = $response->body();

            if (strlen($body) > self::MAX_IMAGE_SIZE) {
                throw new RuntimeException(__('message.IMPORT.CATEGORY.IMAGE_TOO_LARGE'));
            }

            $mime = $this->detectMime($body);

            if (!in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
                throw new RuntimeException(__('message.IMPORT.CATEGORY.UNSUPPORTED_IMAGE_TYPE'));
            }

            if (stripos(substr($body, 0, 2048), '<svg') !== false) {
                throw new RuntimeException(__('message.IMPORT.CATEGORY.UNSUPPORTED_IMAGE_TYPE'));
            }

            $extension = $this->mimeToExtension($mime);
            $tempDir = storage_path('app/temp');

            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempPath = $tempDir . '/category_img_' . Str::random(16) . '.' . $extension;

            file_put_contents($tempPath, $body);

            if ($this->isActualImage($tempPath) === false) {
                @unlink($tempPath);

                throw new RuntimeException(__('message.IMPORT.CATEGORY.INVALID_IMAGE_FILE'));
            }

            return $tempPath;
        }

        throw new RuntimeException(__('message.IMPORT.CATEGORY.TOO_MANY_REDIRECTS'));
    }

    protected function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(__('message.IMPORT.CATEGORY.UNSAFE_IMAGE_URL'));
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            throw new RuntimeException(__('message.IMPORT.CATEGORY.INVALID_IMAGE_URL'));
        }

        $host = trim($host, '[]');

        $ips = $this->resolveHost($host);

        if (empty($ips)) {
            throw new RuntimeException(__('message.IMPORT.CATEGORY.UNSAFE_IMAGE_URL'));
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new RuntimeException(__('message.IMPORT.CATEGORY.UNSAFE_IMAGE_URL'));
            }
        }
    }

    protected function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host);

        return is_array($ips) ? $ips : [];
    }

    protected function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            if ($long === false) {
                return true;
            }

            $unsigned = sprintf('%u', $long);

            $blocks = [
                ['0.0.0.0', '0.255.255.255'],
                ['10.0.0.0', '10.255.255.255'],
                ['100.64.0.0', '100.127.255.255'],
                ['127.0.0.0', '127.255.255.255'],
                ['169.254.0.0', '169.254.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.0.0.0', '192.0.0.255'],
                ['192.0.2.0', '192.0.2.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['198.18.0.0', '198.19.255.255'],
                ['198.51.100.0', '198.51.100.255'],
                ['203.0.113.0', '203.0.113.255'],
                ['224.0.0.0', '239.255.255.255'],
                ['240.0.0.0', '255.255.255.255'],
            ];

            foreach ($blocks as [$start, $end]) {
                if ($unsigned >= sprintf('%u', ip2long($start)) && $unsigned <= sprintf('%u', ip2long($end))) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);

            if ($bin === false) {
                return true;
            }

            if ($bin === str_repeat("\x00", 16)) {
                return true;
            }

            if ($bin === str_repeat("\x00", 15) . "\x01") {
                return true;
            }

            if (substr($bin, 0, 10) === str_repeat("\x00", 10) && substr($bin, 10, 2) === "\xff\xff") {
                return $this->isBlockedIp(inet_ntop(substr($bin, 12, 4)));
            }

            if ((ord($bin[0]) & 0xfe) === 0xfc) {
                return true;
            }

            if ((ord($bin[0]) & 0xc0) === 0x80) {
                return true;
            }

            if (substr($bin, 0, 4) === "\x20\x01\x0d\xb8") {
                return true;
            }

            return false;
        }

        return true;
    }

    protected function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);

        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

            return $scheme . ':' . $location;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $scheme . '://' . $host . $dir . $location;
    }

    protected function detectMime(string $body): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $body);
        finfo_close($finfo);

        return is_string($mime) ? strtolower($mime) : '';
    }

    protected function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    protected function isActualImage(string $tempPath): bool
    {
        $info = @getimagesize($tempPath);

        return is_array($info) && ($info[0] > 0) && ($info[1] > 0);
    }

    protected function translateImageError(string $message): string
    {
        if (str_contains($message, 'image URL') || str_contains($message, 'address')) {
            return __('message.IMPORT.CATEGORY.UNSAFE_IMAGE_URL');
        }

        if (str_contains($message, 'MIME') || str_contains($message, 'unsupported') || str_contains($message, 'image type')) {
            return __('message.IMPORT.CATEGORY.UNSUPPORTED_IMAGE_TYPE');
        }

        if (str_contains($message, 'large')) {
            return __('message.IMPORT.CATEGORY.IMAGE_TOO_LARGE');
        }

        return $message;
    }

    protected function cleanupTempImages(array $row): void
    {
        foreach (['temp_desktop', 'temp_mobile'] as $key) {
            if (!empty($row[$key]) && file_exists($row[$key])) {
                @unlink($row[$key]);
            }
        }
    }

    protected function cleanupTempFiles(array $pending): void
    {
        foreach ($pending as $row) {
            $this->cleanupTempImages($row);
        }
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
            'sheet' => 'categories',
            'row' => $row['excel_row'] ?? 0,
            'name_en' => $row['name_en'] ?? '',
            'name_ar' => $row['name_ar'] ?? '',
            'parent_name_en' => $row['parent_name'] ?? '',
            'error_message' => $errorMessage,
        ];
    }

    public function rollbackCreatedData(): void
    {
        $ids = array_reverse($this->createdIds);

        foreach ($ids as $id) {
            $category = Category::withTrashed()->find($id);

            if ($category && !$category->trashed()) {
                $category->delete();
            }
        }
    }

    public function getFailedRows(): array
    {
        return $this->failedRows;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    protected function signalPath(string $type): ?string
    {
        if ($this->importId === null) {
            return null;
        }

        return storage_path("app/imports/{$type}_{$this->importId}.json");
    }

    protected function writeSignal(string $type, array $data): void
    {
        $path = $this->signalPath($type);

        if ($path === null) {
            return;
        }

        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            file_put_contents($path, json_encode($data), LOCK_EX);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function isCancelled(): bool
    {
        $path = $this->signalPath('cancel');

        if ($path === null) {
            return false;
        }

        clearstatcache(true, $path);

        return file_exists($path);
    }

    public function writeExplicitProgress(float $progress): void
    {
        $this->currentProgress = $progress;
        $this->lastTickProcessedCount = $this->successCount + count($this->failedRows);
        $this->lastTickTime = microtime(true);

        $this->publishProgress($progress);
    }

    protected function flushProgressTick(): void
    {
        $this->processedCount = $this->successCount + count($this->failedRows);

        if ($this->processedCount % self::FLUSH_THRESHOLD !== 0) {
            return;
        }

        $this->publishProgress($this->currentProgress);
    }

    public function finalizeProgress(): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->processedCount = $this->successCount + count($this->failedRows);

        if ($this->isCancelled()) {
            throw new ImportCancelledException();
        }

        Import::where('id', $this->importId)->update([
            'processed_rows' => $this->processedCount,
            'success_rows' => $this->successCount,
            'failed_rows' => count($this->failedRows),
        ]);

        $this->publishProgress(100.0);
    }

    /**
     * Persist the progress snapshot to the signal file and broadcast it to the
     * importing user's private channel in real time.
     */
    protected function publishProgress(float $progress): void
    {
        $data = [
            'processed_rows' => $this->successCount + count($this->failedRows),
            'success_rows' => $this->successCount,
            'failed_rows' => count($this->failedRows),
            'progress' => $progress,
        ];

        $this->writeSignal('progress', $data);

        if (!$this->shouldBroadcastProgress()) {
            return;
        }

        $this->broadcastProgress($data);
    }

    protected function shouldBroadcastProgress(): bool
    {
        return config('app.env') !== 'testing';
    }

    protected function resolveBroadcastUserId(): ?int
    {
        if ($this->broadcastUserId !== null) {
            return $this->broadcastUserId;
        }

        if ($this->importId === null) {
            return null;
        }

        $this->broadcastUserId = (int) Import::where('id', $this->importId)->value('created_by') ?: null;

        return $this->broadcastUserId;
    }

    protected function broadcastProgress(array $data): void
    {
        $userId = $this->resolveBroadcastUserId();

        if ($userId === null) {
            Log::warning('category.import.progress.skipped', [
                'import_id' => $this->importId,
                'reason' => 'no_creator_user',
            ]);

            return;
        }

        Log::info('category.import.progress.dispatch', [
            'import_id' => $this->importId,
            'user_id' => $userId,
            'channel' => 'private-users.' . $userId,
            'event' => 'category.import.progress',
            'payload' => $data,
        ]);

        try {
            CategoryImportProgress::dispatch($userId, $this->importId, $data);

            Log::info('category.import.progress.dispatched', [
                'import_id' => $this->importId,
                'user_id' => $userId,
                'channel' => 'private-users.' . $userId,
                'event' => 'category.import.progress',
                'payload' => $data,
            ]);
        } catch (Throwable $e) {
            Log::error('category.import.progress.broadcast_failed', [
                'import_id' => $this->importId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }
}