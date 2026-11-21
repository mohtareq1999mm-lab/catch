<?php

namespace Marvel\Services\Import;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

/**
 * One owner for the import image download pipeline (product, brand and
 * category families): URL format validation, SSRF host/IP screening,
 * bounded redirect following, size and MIME limits, and temp-file staging.
 *
 * The two families previously carried byte-identical copies that had
 * drifted: product (UrlImageHandler) accepted SVG and used a null-returning
 * failure contract, brand/category rejected SVG and threw. #107 user
 * decisions (recorded on the issue): SVG is rejected everywhere (stored SVG
 * is the latent stored-XSS vector); size is 5 MB for all families; the
 * failure contract is one throwing interface whose caller decides whether
 * the failure fails the row; staging is the same directory for all
 * families. The unified MIME allow-list is the product set minus SVG — the
 * brand pipeline now accepts WebP too, aligning with brand request
 * validation (BrandCreateRequest). `SsrfGuard` remains the policy
 * implementation; `UrlImageHandler` is deleted.
 */
final class ImportImageDownloader
{
    public const MAX_IMAGE_SIZE = 5 * 1024 * 1024;

    public const MAX_REDIRECTS = 5;

    public const IMAGE_TIMEOUT = 30;

    /**
     * One HTTP contract for every hop of the download: TLS certificate
     * verification is never disabled and redirects stay manual so each hop
     * passes the SSRF guard (see assertSafeUrl()).
     */
    public const HTTP_OPTIONS = ['allow_redirects' => false, 'verify' => true];

    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly string $translationPrefix,
        private readonly string $tempFilePrefix,
        private readonly SsrfGuard $ssrfGuard = new SsrfGuard(),
    ) {
    }

    /**
     * Single-URL contract: reject pipe-separated gallery expectations.
     * A cell containing '|' is one malformed URL, not 3 images.
     */
    public function isValidUrlFormat(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        if (str_contains($url, '|')) {
            return false;
        }

        return true;
    }

    /**
     * Download one image through the bounded pipeline and stage it as a
     * temp file. Throws with the family's translated message on any
     * violation; the caller decides whether that fails the row (#107
     * decision 3: one throwing interface, caller decides posture).
     */
    public function downloadImage(string $url): string
    {
        $url = $this->normalizeGoogleDriveUrl($url);
        $url = $this->normalizeImageUrl($url);

        if (!$this->isValidUrlFormat($url)) {
            throw new RuntimeException(__($this->translationPrefix . '.INVALID_IMAGE_URL'));
        }

        $this->assertSafeUrl($url);

        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertSafeUrl($currentUrl);

            $response = Http::timeout(self::IMAGE_TIMEOUT)
                ->withOptions(self::HTTP_OPTIONS)
                ->get($currentUrl);

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');

                if (!$location) {
                    throw new RuntimeException(__($this->translationPrefix . '.UNSAFE_IMAGE_URL'));
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(__($this->translationPrefix . '.IMAGE_DOWNLOAD_FAILED') . " (HTTP {$status})");
            }

            $contentLength = $response->header('Content-Length');

            if ($contentLength !== null && (int) $contentLength > self::MAX_IMAGE_SIZE) {
                throw new RuntimeException(__($this->translationPrefix . '.IMAGE_TOO_LARGE'));
            }

            $body = $response->body();

            if (strlen($body) > self::MAX_IMAGE_SIZE) {
                throw new RuntimeException(__($this->translationPrefix . '.IMAGE_TOO_LARGE'));
            }

            $mime = $this->detectMime($body);

            if (!in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
                throw new RuntimeException(__($this->translationPrefix . '.UNSUPPORTED_IMAGE_TYPE'));
            }

            if (stripos(substr($body, 0, 2048), '<svg') !== false) {
                throw new RuntimeException(__($this->translationPrefix . '.UNSUPPORTED_IMAGE_TYPE'));
            }

            $extension = $this->mimeToExtension($mime);
            $tempDir = storage_path('app/temp');

            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempPath = $tempDir . '/' . $this->tempFilePrefix . '_' . Str::random(16) . '.' . $extension;

            file_put_contents($tempPath, $body);

            if ($this->isActualImage($tempPath) === false) {
                @unlink($tempPath);

                throw new RuntimeException(__($this->translationPrefix . '.INVALID_IMAGE_FILE'));
            }

            return $tempPath;
        }

        throw new RuntimeException(__($this->translationPrefix . '.TOO_MANY_REDIRECTS'));
    }

    /**
     * SSRF screening: http(s) only, resolvable host, every resolved IP
     * outside the private/reserved ranges (IPv4 and IPv6).
     */
    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(__($this->translationPrefix . '.UNSAFE_IMAGE_URL'));
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if ($host === '') {
            throw new RuntimeException(__($this->translationPrefix . '.INVALID_IMAGE_URL'));
        }

        // The ONE SSRF policy (SsrfGuard): resolution + the strictest block
        // rules live there; this path translates the internal failure into
        // the family's user-facing message.
        try {
            $this->ssrfGuard->assertResolvablePublicHost($host);
        } catch (UnsafeImageUrlException) {
            throw new RuntimeException(__($this->translationPrefix . '.UNSAFE_IMAGE_URL'));
        }
    }

    /**
     * Product posture preserved (#107 decision 3): the product importer
     * decides the failure is non-fatal at its call site.
     */
    public function isValidUrl(string $url): bool
    {
        $url = $this->normalizeImageUrl($url);
        try {
            $this->assertSafeUrl($url);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Remove the staged temp files of a pending row set.
     *
     * @param  array<int, array<string, mixed>>  $pending
     */
    public function cleanupTempFiles(array $pending): void
    {
        foreach ($pending as $row) {
            foreach (['temp_desktop', 'temp_mobile'] as $key) {
                if (!empty($row[$key]) && file_exists($row[$key])) {
                    @unlink($row[$key]);
                }
            }
        }
    }

    public function attachToModel(HasMedia $model, string $filePath, string $collection = 'products'): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $model->addMedia($filePath)
            ->toMediaCollection($collection);
    }

    public function cleanup(string $filePath): void
    {
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }
        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }
        $path = $base['path'] ?? '/';
        $dir = rtrim(dirname($path), '/');
        return $scheme . '://' . $host . $port . $dir . '/' . $location;
    }

    private function detectMime(string $body): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $body);
        finfo_close($finfo);

        return is_string($mime) ? strtolower($mime) : '';
    }

    private function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function isActualImage(string $tempPath): bool
    {
        $info = @getimagesize($tempPath);

        return is_array($info) && ($info[0] > 0) && ($info[1] > 0);
    }

    /**
     * Google Drive "uc?export=download" links carry a real file id, so the
     * raw URL has no extension that could be used for the audit surface.
     */
    private function normalizeGoogleDriveUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !str_contains($host, 'drive.google.com')) {
            return $url;
        }

        $fileId = null;

        if (preg_match('/\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $fileId = $matches[1];
        }

        if (!$fileId) {
            $query = parse_url($url, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $params);
                if (!empty($params['id'])) {
                    $fileId = $params['id'];
                }
            }
        }

        if ($fileId) {
            return 'https://drive.google.com/uc?export=download&confirm=t&id=' . $fileId;
        }

        return $url;
    }

    /**
     * Preserve already-encoded %20, encode literal spaces safely — mirrors
     * the product path's workbook-cell normalization.
     */
    private function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        // Split URL into components to avoid double-encoding
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? null;
        $fragment = $parts['fragment'] ?? null;

        // Encode literal spaces in path/query without double-encoding %20
        if (str_contains($path, ' ')) {
            $segments = explode('/', $path);
            $segments = array_map(function ($seg) {
                $decoded = str_replace('%20', ' ', $seg);

                return str_replace('%2F', '/', rawurlencode($decoded));
            }, $segments);
            $path = implode('/', $segments);
        }
        if ($query !== null && str_contains($query, ' ')) {
            $query = str_replace(' ', '%20', str_replace('%20', ' ', $query));
            $query = str_replace(' ', '%20', $query);
        }

        $normalized = $scheme . '://' . $host . $port . $path;
        if ($query !== null) {
            $normalized .= '?' . $query;
        }
        if ($fragment !== null) {
            $normalized .= '#' . $fragment;
        }

        return $normalized;
    }
}
