<?php

namespace Marvel\Services\Import;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Import;
use Marvel\Exceptions\ImportCancelledException;
use RuntimeException;
use Throwable;

class BrandImportService
{
    use BroadcastsFileOperationProgress;

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

            $this->upsertBrands($pending);

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

        if ($data['image_desktop_url'] !== '' && !$this->isValidUrlFormat($data['image_desktop_url'])) {
            $data['errors'][] = __('message.IMPORT.BRAND.INVALID_IMAGE_URL');
        }

        if ($data['image_mobile_url'] !== '' && !$this->isValidUrlFormat($data['image_mobile_url'])) {
            $data['errors'][] = __('message.IMPORT.BRAND.INVALID_IMAGE_URL');
        }

        if (!empty($data['errors'])) {
            $this->addFailedRow($data, $data['errors'][0]);

            return $data;
        }

        try {
            $data['temp_desktop'] = $data['image_desktop_url'] !== '' ? $this->downloadImage($data['image_desktop_url']) : null;
        } catch (Throwable $e) {
            // Image download failed - log it but don't fail the brand
            report(new RuntimeException("Brand '{$data['name_en']}' desktop image download failed: {$e->getMessage()}"));
            $data['temp_desktop'] = null;
        }

        try {
            $data['temp_mobile'] = $data['image_mobile_url'] !== '' ? $this->downloadImage($data['image_mobile_url']) : null;
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
    protected function upsertBrands(array &$pending): void
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

    protected function attachImages(array &$pending): void
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
            $this->successCount++;
            
            if (!$attached) {
                // Log image failure but keep brand as successful
                report(new RuntimeException("Brand '{$row['name_en']}' created successfully but image attachment failed"));
            }

            $this->flushProgressTick();
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

    protected function isValidUrlFormat(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    protected function downloadImage(string $url): string
    {
        if (!$this->isValidUrlFormat($url)) {
            throw new RuntimeException(__('message.IMPORT.BRAND.INVALID_IMAGE_URL'));
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
                    throw new RuntimeException(__('message.IMPORT.BRAND.UNSAFE_IMAGE_URL'));
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(__('message.IMPORT.BRAND.IMAGE_DOWNLOAD_FAILED') . " (HTTP {$status})");
            }

            $contentLength = $response->header('Content-Length');

            if ($contentLength !== null && (int) $contentLength > self::MAX_IMAGE_SIZE) {
                throw new RuntimeException(__('message.IMPORT.BRAND.IMAGE_TOO_LARGE'));
            }

            $body = $response->body();

            if (strlen($body) > self::MAX_IMAGE_SIZE) {
                throw new RuntimeException(__('message.IMPORT.BRAND.IMAGE_TOO_LARGE'));
            }

            $mime = $this->detectMime($body);

            if (!in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
                throw new RuntimeException(__('message.IMPORT.BRAND.UNSUPPORTED_IMAGE_TYPE'));
            }

            if (stripos(substr($body, 0, 2048), '<svg') !== false) {
                throw new RuntimeException(__('message.IMPORT.BRAND.UNSUPPORTED_IMAGE_TYPE'));
            }

            $extension = $this->mimeToExtension($mime);
            $tempDir = storage_path('app/temp');

            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempPath = $tempDir . '/brand_img_' . Str::random(16) . '.' . $extension;

            file_put_contents($tempPath, $body);

            if ($this->isActualImage($tempPath) === false) {
                @unlink($tempPath);

                throw new RuntimeException(__('message.IMPORT.BRAND.INVALID_IMAGE_FILE'));
            }

            return $tempPath;
        }

        throw new RuntimeException(__('message.IMPORT.BRAND.TOO_MANY_REDIRECTS'));
    }

    protected function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(__('message.IMPORT.BRAND.UNSAFE_IMAGE_URL'));
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            throw new RuntimeException(__('message.IMPORT.BRAND.INVALID_IMAGE_URL'));
        }

        $host = trim($host, '[]');

        $ips = $this->resolveHost($host);

        if (empty($ips)) {
            throw new RuntimeException(__('message.IMPORT.BRAND.UNSAFE_IMAGE_URL'));
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new RuntimeException(__('message.IMPORT.BRAND.UNSAFE_IMAGE_URL'));
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
            return __('message.IMPORT.BRAND.UNSAFE_IMAGE_URL');
        }

        if (str_contains($message, 'MIME') || str_contains($message, 'unsupported') || str_contains($message, 'image type')) {
            return __('message.IMPORT.BRAND.UNSUPPORTED_IMAGE_TYPE');
        }

        if (str_contains($message, 'large')) {
            return __('message.IMPORT.BRAND.IMAGE_TOO_LARGE');
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
            'sheet' => 'brands',
            'row' => $row['excel_row'] ?? 0,
            'name_en' => $row['name_en'] ?? '',
            'name_ar' => $row['name_ar'] ?? '',
            'error_message' => $errorMessage,
        ];
    }

    public function rollbackCreatedData(): void
    {
        $ids = array_reverse($this->createdIds);

        foreach ($ids as $id) {
            $brand = Brand::withTrashed()->find($id);

            if ($brand && !$brand->trashed()) {
                $brand->delete();
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

    protected function signalPath(string $signalType): ?string
    {
        if ($this->importId === null) {
            return null;
        }

        return storage_path("app/imports/{$signalType}_{$this->importId}.json");
    }

    protected function writeSignal(string $signalType, array $data): void
    {
        $path = $this->signalPath($signalType);

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

    protected function publishProgress(float $progress): void
    {
        $data = [
            'processed_rows' => $this->successCount + count($this->failedRows),
            'success_rows' => $this->successCount,
            'failed_rows' => count($this->failedRows),
            'progress' => $progress,
        ];

        $this->writeSignal('progress', $data);

        if ($this->importId === null) {
            return;
        }

        $this->broadcastFileOperationProgress(
            FileOperationEvent::BRAND_IMPORT_PROGRESS,
            'brand-import',
            (int) $this->importId,
            $progress,
            $this->successCount + count($this->failedRows),
            $this->successCount,
            count($this->failedRows)
        );
    }
}
