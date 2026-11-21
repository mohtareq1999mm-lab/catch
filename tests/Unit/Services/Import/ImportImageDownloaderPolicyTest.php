<?php

namespace Tests\Unit\Services\Import;

use Illuminate\Support\Facades\Http;
use Marvel\Services\Import\ImportImageDownloader;
use Marvel\Services\Import\SsrfGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * Policy tests for the unified import image downloader (#107). No import run
 * is needed — Http::fake exercises the policy surface directly: format gate,
 * SSRF resolve, bounded redirects, size cap, MIME allow-list, temp staging.
 */
class ImportImageDownloaderPolicyTest extends TestCase
{
    /** 1x1 transparent PNG. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function downloader(): ImportImageDownloader
    {
        return new ImportImageDownloader('message.IMPORT.PRODUCT', 'product_img', new SsrfGuard());
    }

    public function test_bad_scheme_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('message.IMPORT.PRODUCT.UNSAFE_IMAGE_URL'));

        $this->downloader()->downloadImage('ftp://example.com/logo.png');
    }

    public function test_private_ip_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('message.IMPORT.PRODUCT.UNSAFE_IMAGE_URL'));

        $this->downloader()->downloadImage('http://127.0.0.1/logo.png');
    }

    public function test_redirect_to_private_ip_is_rejected(): void
    {
        Http::fake([
            'https://8.8.8.8/logo.png' => Http::response(
                '',
                302,
                ['Location' => 'http://192.168.1.10/logo.png']
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('message.IMPORT.PRODUCT.UNSAFE_IMAGE_URL'));

        $this->downloader()->downloadImage('https://8.8.8.8/logo.png');
    }

    public function test_oversize_body_is_rejected(): void
    {
        Http::fake([
            'https://8.8.8.8/logo.png' => Http::response(
                '',
                200,
                ['Content-Length' => (string) (ImportImageDownloader::MAX_IMAGE_SIZE + 1)]
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('message.IMPORT.PRODUCT.IMAGE_TOO_LARGE'));

        $this->downloader()->downloadImage('https://8.8.8.8/logo.png');
    }

    public function test_disallowed_mime_is_rejected(): void
    {
        Http::fake([
            'https://8.8.8.8/logo.png' => Http::response(
                'this is not an image',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('message.IMPORT.PRODUCT.UNSUPPORTED_IMAGE_TYPE'));

        $this->downloader()->downloadImage('https://8.8.8.8/logo.png');
    }

    public function test_svg_is_rejected_everywhere(): void
    {
        // #107 decision 1: SVG is rejected everywhere — stored SVG is the
        // latent stored-XSS vector if media is ever served inline.
        Http::fake([
            'https://8.8.8.8/logo.svg' => Http::response(
                '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
                200,
                ['Content-Type' => 'image/svg+xml']
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('message.IMPORT.PRODUCT.UNSUPPORTED_IMAGE_TYPE'));

        $this->downloader()->downloadImage('https://8.8.8.8/logo.svg');
    }

    public function test_valid_image_is_staged(): void
    {
        Http::fake([
            'https://8.8.8.8/logo.png' => Http::response(
                base64_decode(self::PNG_1X1),
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $path = $this->downloader()->downloadImage('https://8.8.8.8/logo.png');

        try {
            $this->assertNotNull($path, 'A valid public image must still download.');
            $this->assertFileExists($path);
            $this->assertNotFalse(getimagesize($path), 'The staged file must be a real image.');
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
        }
    }
}
