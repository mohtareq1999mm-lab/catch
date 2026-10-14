<?php

namespace Marvel\Services\Import\ImageHandlers;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

class UrlImageHandler
{
    protected array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];

    protected int $maxFileSize = 5 * 1024 * 1024;

    protected int $timeout = 30;

    protected int $maxRedirects = 5;

    public function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        // Preserve already-encoded %20, encode literal spaces safely
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
        // Use rawurlencode on each path segment
        if (str_contains($path, ' ')) {
            $segments = explode('/', $path);
            $segments = array_map(function ($seg) {
                // Decode %20 to space then re-encode to normalize, avoid double %2520
                $decoded = str_replace('%20', ' ', $seg);
                // rawurlencode then revert %2F etc not needed for segment
                return str_replace('%2F', '/', rawurlencode($decoded));
            }, $segments);
            $path = implode('/', $segments);
            // fix double-encoding of already encoded chars like %20 -> %2520 edge
            $path = str_replace('%2520', '%20', $path);
        }
        if ($query !== null && str_contains($query, ' ')) {
            // Encode spaces in query as %20, preserve other encodings
            $query = str_replace(' ', '%20', str_replace('%20', ' ', $query));
            $query = str_replace(' ', '%20', $query);
            // Use http_build_query safe? keep simple
            $query = str_replace('%2520', '%20', $query);
        }

        $normalized = $scheme . '://' . $host . $port . $path;
        if ($query !== null) {
            $normalized .= '?' . $query;
        }
        if ($fragment !== null) {
            $normalized .= '#' . $fragment;
        }
        // Preserve original if no spaces, avoid altering
        if ($normalized !== $url && str_contains($url, ' ')) {
            return $normalized;
        }
        // Fallback: simple space→%20 for literal spaces (covers most cases like "Definition Loose Powder - Sheer-500x500.png")
        if (str_contains($url, ' ')) {
            return str_replace(' ', '%20', $url);
        }
        return $url;
    }

    public function download(string $url): ?string
    {
        $url = $this->normalizeGoogleDriveUrl($url);
        $url = $this->normalizeImageUrl($url);

        try {
            $this->assertSafeUrl($url);
        } catch (Exception $e) {
            Log::warning("Blocked image URL: {$url} — " . $e->getMessage());
            return null;
        }

        try {
            $this->ensureTempDirectoryExists();

            $currentUrl = $url;
            $redirects = 0;
            $response = null;

            while ($redirects <= $this->maxRedirects) {
                $response = Http::timeout($this->timeout)
                    ->withOptions(['verify' => false, 'allow_redirects' => false])
                    ->get($currentUrl);

                if ($response->status() >= 300 && $response->status() < 400) {
                    $location = $response->header('Location');
                    if (empty($location)) {
                        Log::warning("Redirect without Location from {$currentUrl}");
                        return null;
                    }
                    $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
                    try {
                        $this->assertSafeUrl($nextUrl);
                    } catch (Exception $e) {
                        Log::warning("Blocked redirect URL: {$nextUrl} — " . $e->getMessage());
                        return null;
                    }
                    $currentUrl = $nextUrl;
                    $redirects++;
                    continue;
                }
                break;
            }

            if ($redirects > $this->maxRedirects) {
                Log::warning("Too many redirects for {$url}");
                return null;
            }

            if (!$response || !$response->successful()) {
                Log::warning("Failed to download image from {$currentUrl}: HTTP " . ($response ? $response->status() : 'no response'));
                return null;
            }

            $body = $response->body();
            $bodySize = strlen($body);

            if ($bodySize > $this->maxFileSize) {
                Log::warning("Image too large from {$currentUrl}: {$bodySize} bytes");
                return null;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $body);
            finfo_close($finfo);

            if (!in_array($mimeType, $this->allowedMimes)) {
                Log::warning("Invalid MIME type for {$currentUrl}: {$mimeType}");
                return null;
            }

            $extension = $this->mimeToExtension($mimeType);
            $tempPath = storage_path('app/temp/import_url_' . Str::random(16) . '.' . $extension);

            file_put_contents($tempPath, $body);

            if (! $this->isActualImage($tempPath)) {
                @unlink($tempPath);
                Log::warning("File is not a valid image: {$currentUrl}");
                return null;
            }

            Log::info("Downloaded image from {$currentUrl} ({$mimeType}, {$bodySize} bytes)");

            return $tempPath;
        } catch (Exception $e) {
            Log::error("Failed to download image from {$url}: " . $e->getMessage());
            return null;
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

    protected function assertSafeUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid URL format');
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? null;
        if (!$host || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new Exception('URL must be http/https with host');
        }
        $ips = $this->resolveHost($host);
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new Exception('URL resolves to private/reserved IP');
            }
        }
    }

    protected function resolveHost(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (!empty($rec['ip'])) $ips[] = $rec['ip'];
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
            }
        }
        if (empty($ips)) {
            $ip = gethostbyname($host);
            if ($ip !== $host) $ips[] = $ip;
        }
        return array_unique(array_filter($ips));
    }

    protected function isBlockedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
        // Block private, reserved, loopback, link-local, multicast, unspecified
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        // Additional explicit blocks
        $blockedPrefixes = ['0.', '169.254.', '192.0.2.', '198.51.100.', '203.0.113.', '::1', 'fe80:', 'fc00:', 'ff00:'];
        foreach ($blockedPrefixes as $prefix) {
            if (str_starts_with($ip, $prefix)) return true;
        }
        return false;
    }

    protected function resolveRedirectUrl(string $baseUrl, string $location): string
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

    protected function isActualImage(string $tempPath): bool
    {
        $info = @getimagesize($tempPath);
        return $info !== false;
    }

    protected function normalizeGoogleDriveUrl(string $url): string
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

    protected function ensureTempDirectoryExists(): void
    {
        $path = storage_path('app/temp');
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }

    protected function mimeToExtension(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
        ];

        return $map[$mime] ?? 'jpg';
    }
}
