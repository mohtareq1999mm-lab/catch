<?php

namespace Marvel\Services\Import;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One owner for the import image download pipeline (brand and category
 * families): URL format validation, SSRF host/IP screening, bounded
 * redirect following, size and MIME limits, and temp-file staging.
 *
 * The two families previously carried byte-identical copies that had
 * drifted apart on the security side: the category copy rejected
 * pipe-separated URL cells and blocked private/reserved IPv6 ranges, the
 * brand copy rejected pipe-separated cells but blocked ALL IPv6 hosts
 * outright (including public ones). The unified guard keeps the pipe
 * rejection for both and replaces brand's blanket IPv6 ban with the
 * category-shaped screening — private/reserved IPv6 is blocked for both,
 * public IPv6 hosts (previously unusable for brand images) now download.
 * Brand rows keep their non-fatal image posture (a failed download never
 * fails the row).
 */
final class ImportImageDownloader
{
    public const MAX_IMAGE_SIZE = 5 * 1024 * 1024;

    public const MAX_REDIRECTS = 5;

    public const IMAGE_TIMEOUT = 30;

    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
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
     * violation; the caller decides whether that fails the row.
     */
    public function downloadImage(string $url): string
    {
        if (!$this->isValidUrlFormat($url)) {
            throw new RuntimeException(__($this->translationPrefix . '.INVALID_IMAGE_URL'));
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
        // its own user-facing message.
        try {
            $this->ssrfGuard->assertResolvablePublicHost($host);
        } catch (UnsafeImageUrlException) {
            throw new RuntimeException(__($this->translationPrefix . '.UNSAFE_IMAGE_URL'));
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


    private function resolveRedirectUrl(string $baseUrl, string $location): string
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
            default => 'jpg',
        };
    }

    private function isActualImage(string $tempPath): bool
    {
        $info = @getimagesize($tempPath);

        return is_array($info) && ($info[0] > 0) && ($info[1] > 0);
    }
}
