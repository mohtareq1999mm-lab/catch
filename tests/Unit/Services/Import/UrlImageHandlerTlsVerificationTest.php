<?php

namespace Tests\Unit\Services\Import;

use Illuminate\Support\Facades\Http;
use Marvel\Services\Import\ImageHandlers\UrlImageHandler;
use Marvel\Services\Import\SsrfGuard;
use Tests\TestCase;

/**
 * Regression for the product import image path: TLS certificate verification
 * must stay enabled (candidate 01, architecture review).
 *
 * The URL comes from an uploaded workbook cell, i.e. from whoever supplied
 * the file. With `verify => false` a network attacker could substitute the
 * image response; the SSRF guard cannot compensate because it screens the
 * destination, not the transport.
 */
class UrlImageHandlerTlsVerificationTest extends TestCase
{
    /** 1x1 transparent PNG. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_http_options_enforce_tls_verification(): void
    {
        $this->assertTrue(
            UrlImageHandler::HTTP_OPTIONS['verify'],
            'Imported-image downloads must verify TLS certificates.'
        );

        $this->assertFalse(
            UrlImageHandler::HTTP_OPTIONS['allow_redirects'],
            'Redirects stay manual so every hop passes the SSRF guard.'
        );
    }

    public function test_download_still_stages_a_valid_image_with_verification_enabled(): void
    {
        Http::fake([
            'https://8.8.8.8/logo.png' => Http::response(
                base64_decode(self::PNG_1X1),
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $handler = new UrlImageHandler(new SsrfGuard());

        $path = $handler->download('https://8.8.8.8/logo.png');

        try {
            $this->assertNotNull($path, 'A valid public image must still download with verification enabled.');
            $this->assertFileExists($path);
            $this->assertNotFalse(getimagesize($path), 'The staged file must be a real image.');

            Http::assertSent(fn ($request) => $request->url() === 'https://8.8.8.8/logo.png');
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
        }
    }
}
