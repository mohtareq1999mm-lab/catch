<?php
$f = realpath('tests/Feature/Digital/DigitalDeliveryResolverTest.php');
$t = file_get_contents($f);
$start = strpos($t, 'public function test_range_full_matrix_with_byte_integrity');
$end = strpos($t, '/* ================= PREVIEW', $start);
if ($start === false || $end === false) { echo "anchors missing\n"; exit(1); }

$new = <<<'METHOD'
public function test_range_full_matrix_with_byte_integrity()
    {
        [$asset, $entitlement] = $this->mediaSetup();

        $stored = Storage::disk('private')->get($asset->path);
        $total = strlen($stored);

        // 1. Full request - no Range header.
        [$st, $body, $h] = $this->rangeOf($entitlement, $asset, '');
        $this->assertSame(200, $st, 'case1 status');
        $this->assertSame($stored, $body, 'case1 full bytes exact');

        // 2. Valid single-byte range.
        [$st, $body, $h] = $this->rangeOf($entitlement, $asset, 'bytes=0-0');
        $this->assertSame(206, $st, 'case2 status');
        $this->assertSame(1, strlen($body), 'case2 length');
        $this->assertSame($stored[0], $body[0], 'case2 byte exact');
        $this->assertSame('bytes 0-0/' . $total, $h['content-range'], 'case2 Content-Range');
        $this->assertSame('bytes', $h['accept-ranges'], 'case2 Accept-Ranges advertised');

        // 3. Mid-slice range.
        [$st, $body, $h] = $this->rangeOf($entitlement, $asset, 'bytes=100-199');
        $this->assertSame(206, $st, 'case3 status');
        $this->assertSame(substr($stored, 100, 100), $body, 'case3 exact slice');
        $this->assertSame('bytes 100-199/' . $total, $h['content-range'], 'case3 Content-Range');

        // 4. Start range clamped at EOF.
        [$st, $body, $h] = $this->rangeOf($entitlement, $asset, 'bytes=' . ($total - 50) . '-99999');
        $this->assertSame(206, $st, 'case4 status');
        $this->assertSame(substr($stored, $total - 50), $body, 'case4 tail exact');
        $this->assertSame('bytes ' . ($total - 50) . '-' . ($total - 1) . '/' . $total, $h['content-range'], 'case4 Content-Range');

        // 5. Open-ended suffix (last N bytes).
        [$st, $body, $h] = $this->rangeOf($entitlement, $asset, 'bytes=-128');
        $this->assertSame(206, $st, 'case5 status');
        $this->assertSame(substr($stored, -128), $body, 'case5 tail exact');
        $this->assertSame('bytes ' . ($total - 128) . '-' . ($total - 1) . '/' . $total, $h['content-range'], 'case5 Content-Range');
        $this->assertSame('128', $h['content-length'], 'case5 Content-Length');

        // 6. Unsatisfiable range -> 416 with bytes */total.
        [$st, , $h] = $this->rangeOf($entitlement, $asset, 'bytes=99999999-');
        $this->assertSame(416, $st, 'case6 status');
        $this->assertStringContainsString('*/' . $total, $h['content-range'] ?? '', 'case6 Content-Range');

        // 7. Invalid syntax -> lenient full-body 200 (RFC-allowed fallback).
        [$st, $body] = $this->rangeOf($entitlement, $asset, 'bytes=abc-def');
        $this->assertSame(200, $st, 'case7 invalid syntax ignored');
        $this->assertSame($total, strlen($body));

        // Multi-range -> unsupported -> lenient full body.
        [$st, $body] = $this->rangeOf($entitlement, $asset, 'bytes=0-1,5-6');
        $this->assertSame(200, $st, 'multi-range falls back to full');
        $this->assertSame($total, strlen($body));
    }

METHOD;

$t = substr_replace($t, $new, $start, $end - $start);
file_put_contents($f, $t);
echo "matrix method rewritten\n";
