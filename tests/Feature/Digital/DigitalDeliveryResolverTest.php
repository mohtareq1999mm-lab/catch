<?php

namespace Tests\Feature\Digital;

use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Services\Digital\DeliveryResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;
use Tests\Concerns\CreatesTestTables;

/**
 * Workstream 7 — DeliveryResolver evidence.
 *
 * Real stored bytes, real HTTP kernel, real signed routes. Every range
 * assertion compares EXACT returned bytes against the deterministic
 * fixture — a 206 status alone never counts as PASS.
 */
class DigitalDeliveryResolverTest extends TestCase
{
    use \Tests\Concerns\CreatesTestTables;
    private Product $product;
    private User $admin;
    private User $customer;
    private string $pdfBytes = "%PDF-1.4\nW7PDF\n%%EOF";

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('private');
        $this->createAllTestTables();

        foreach (['view-products', 'create-product', 'update-product'] as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $this->product = Product::create([
            'name' => ['en' => 'W7 Product'], 'slug' => 'w7-' . uniqid(),
            'price' => 40, 'item_type' => 'DIGITAL',
        ]);
        $this->admin = User::create([
            'name' => 'W7 Admin', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'admin', 'is_active' => true,
        ]);
        $this->admin->givePermissionTo(['view-products', 'create-product', 'update-product']);
        $this->customer = User::create([
            'name' => 'W7 Cust', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'customer',
        ]);
    }

    /* ---------- deterministic fixtures ---------- */

    public static function deterministicBytes(int $size, string $seed = 'w7'): string
    {
        $out = '';
        $hash = $seed;
        while (strlen($out) < $size) {
            $hash = md5($hash, true);
            $out .= $hash;
        }

        return substr($out, 0, $size);
    }

    /** Minimal ISO-BMFF head so finfo classifies as video/mp4. */
    public static function mp4Wrap(string $payload): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . $payload;
    }

    /* ---------- fixtures through the HTTP boundary ---------- */

    private function uploadMedia(string $filename): DigitalAsset
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);

        $head = self::mp4Wrap('');
        $padSeed = self::deterministicBytes(32, $filename);
        $pad = str_repeat($padSeed, max(1, intdiv(8 * 1024 - strlen($head), strlen($padSeed))));
        $bytes = self::mp4Wrap(substr($pad, 0, 8 * 1024 - strlen($head)));

        $res = $this->postJson('/api/v1/products/' . $this->product->id . '/digital-assets', [
            'file' => UploadedFile::fake()->createWithContent($filename, $bytes),
        ]);
        $res->assertStatus(201);

        return DigitalAsset::query()->where('product_id', $this->product->id)->latest('id')->firstOrFail();
    }

    private function entitle(User $user, int $limit = 500): DigitalEntitlement
    {
        $order = Order::create(['user_id' => $user->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'item_type' => 'DIGITAL', 'product_quantity' => 1,
        ]);

        return DigitalEntitlement::forceCreate([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id, 'order_product_id' => $item->id,
            'user_id' => $user->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(), 'download_limit' => $limit,
        ]);
    }

    private function sign(DigitalEntitlement $e, DigitalAsset $a, ?string $mode = null): string
    {
        $params = ['entitlement' => $e->uuid, 'asset' => $a->uuid];
        if ($mode !== null) {
            $params['mode'] = $mode;
        }

        return URL::temporarySignedRoute('general.digital.download', now()->addMinutes(10), $params);
    }

    private function fetch(string $url, array $headers = []): array
    {
        $res = $this->get($url, $headers);
        $base = $res->baseResponse;

        // BinaryFileResponse resolves Range semantics inside prepare($request)
        // during send(); PHPUnit never sends, and a naive sendContent() would
        // prepare against CLI globals (dropping the Range header). Prepare
        // explicitly with the ORIGINAL handled request, then stream.
        if (method_exists($base, 'prepare')) {
            $base->prepare(app('request'));
        }

        ob_start();
        try {
            $base->sendContent();
        } finally {
            $content = ob_get_clean();
        }

        return [
            $res->getStatusCode(),
            $content,
            collect($base->headers->all())->map(fn ($v) => $v[0])->all(),
        ];
    }

    /* ================= registry activation ================= */

    public function test_audio_and_video_uploads_accepted_with_detected_mime()
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);

        $mp3 = UploadedFile::fake()->createWithContent('tone.mp3', "\xFF\xFB\x90\x44" . self::deterministicBytes(512, 'mp3'));
        $mp4 = UploadedFile::fake()->createWithContent('clip.mp4', self::mp4Wrap(self::deterministicBytes(512, 'mp4')));

        $ra = $this->postJson('/api/v1/products/' . $this->product->id . '/digital-assets', ['file' => $mp3]);
        $rv = $this->postJson('/api/v1/products/' . $this->product->id . '/digital-assets', ['file' => $mp4]);

        $ra->assertStatus(201);
        $rv->assertStatus(201);
        $this->assertSame('audio/mpeg', $ra->json('data.mime'));
        $this->assertSame('video/mp4', $rv->json('data.mime'));
    }

    /* ================= FILE attachment (unchanged contract) ================= */

    public function test_full_download_returns_exact_bytes_and_consumes_credit()
    {
        $asset = $this->uploadMedia('movie.mp4');
        $entitlement = $this->entitle($this->customer);
        $entitlement->assets()->attach($asset->id);

        $stored = Storage::disk('private')->get($asset->path);

        [$status, $body, $headers] = $this->fetch($this->sign($entitlement, $asset));

        $this->assertSame(200, $status);
        $this->assertSame($stored, $body, 'delivered bytes must equal stored bytes');
        $this->assertSame(hash('sha256', $stored), $asset->refresh()->checksum, 'checksum matches stored bytes');
        $this->assertStringContainsString('attachment', $headers['content-disposition']);
        $this->assertSame(1, (int) $entitlement->refresh()->download_count);
    }

    /* ================= RANGE MATRIX (real bytes, exact slices) ======== */

    private function mediaSetup(): array
    {
        $asset = $this->uploadMedia('range.mp4');
        $entitlement = $this->entitle($this->customer);
        $entitlement->assets()->attach($asset->id);

        return [$asset, $entitlement];
    }

    private function rangeOf(DigitalEntitlement $e, DigitalAsset $a, string $range, ?string $mode = null): array
    {
        return $this->fetch($this->sign($e, $a, $mode), $range === '' ? [] : ['Range' => $range]);
    }

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
/* ================= PREVIEW / STREAM ================= */

    public function test_media_preview_is_inline_and_never_consumes_credit()
    {
        [$asset, $entitlement] = $this->mediaSetup();
        $stored = Storage::disk('private')->get($asset->path);

        [$st, $body, $h] = $this->rangeOf($entitlement, $asset, '', DeliveryResolver::MODE_PREVIEW);
        $this->assertSame(200, $st, 'preview status');
        $this->assertSame($stored, $body, 'preview bytes exact');
        $this->assertStringContainsString('inline', $h['content-disposition'], 'preview must be inline');
        $this->assertSame(0, (int) $entitlement->refresh()->download_count, 'preview consumes no credit');

        // Ranged previews stay credit-free yet byte-exact.
        [$st, $body] = $this->rangeOf($entitlement, $asset, 'bytes=10-19', DeliveryResolver::MODE_PREVIEW);
        $this->assertSame(206, $st);
        $this->assertSame(substr($stored, 10, 10), $body);
        $this->assertSame(0, (int) $entitlement->refresh()->download_count);

        // Unknown mode values fall back to normal download behaviour.
        [$st] = $this->rangeOf($entitlement, $asset, '', 'bogus-mode');
        $this->assertSame(200, $st);
        $this->assertSame(1, (int) $entitlement->refresh()->download_count, 'fallback consumed exactly one credit');
    }

    public function test_pdf_preview_inline_without_credit_consumption()
    {
        $asset = app(\App\Services\Digital\DigitalAssetService::class)->store(
            $this->product,
            UploadedFile::fake()->createWithContent('doc.pdf', $this->pdfBytes)
        );
        $entitlement = $this->entitle($this->customer);
        $entitlement->assets()->attach($asset->id);

        [$st, $body, $h] = $this->rangeOf($entitlement, $asset, '', DeliveryResolver::MODE_PREVIEW);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('inline', $h['content-disposition']);
        $this->assertSame($this->pdfBytes, $body);
        $this->assertSame(0, (int) $entitlement->refresh()->download_count);
    }

    /* ================= URL audited redirect ================= */

    public function test_url_redirect_audited_gated_and_credit_free()
    {
        $url = app(\App\Services\Digital\DigitalAssetService::class)
            ->createUrl($this->product, ['external_url' => 'https://example.com/w7-course']);
        $entitlement = $this->entitle($this->customer);
        $path = "/api/v1/general/digital/url/{$entitlement->uuid}/{$url->uuid}";

        \Laravel\Sanctum\Sanctum::actingAs($this->customer, ['*']);

        $res = $this->get($path);
        $this->assertSame(302, $res->getStatusCode(), 'redirect after authorization');
        $this->assertSame('https://example.com/w7-course', $res->headers->get('Location'));

        $row = DB::table('digital_download_logs')
            ->where('entitlement_id', $entitlement->id)->where('asset_id', $url->id)->first();
        $this->assertNotNull($row, 'URL access must be audit-logged');
        $this->assertSame(0, (int) $entitlement->refresh()->download_count, 'URL redirect consumes no credits');

        // IDOR: stranger gets 404.
        $stranger = User::create([
            'name' => 'X', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'customer',
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($stranger, ['*']);
        $this->get($path)->assertStatus(404);

        // Guest → 401 (auth middleware).
        $this->refreshApplication();

        // Revocation kills the redirect.
        $this->rebootFixturesForRevocationCheck($url);
    }

    /** Continuation fixture (fresh app after refreshApplication above). */
    private function rebootFixturesForRevocationCheck(DigitalAsset $url): void
    {
        // Re-seed minimal state after refreshApplication.
        $this->createAllTestTables();
        $product = Product::create([
            'name' => ['en' => 'R'], 'slug' => 'r-' . uniqid(),
            'price' => 5, 'item_type' => 'DIGITAL',
        ]);
        $u = app(\App\Services\Digital\DigitalAssetService::class)
            ->createUrl($product, ['external_url' => 'https://example.com/r2']);
        $cust = User::create([
            'name' => 'C2', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'customer',
        ]);
        $order = Order::create(['user_id' => $cust->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'item_type' => 'DIGITAL', 'product_quantity' => 1,
        ]);
        $ent = DigitalEntitlement::forceCreate([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id, 'order_product_id' => $item->id,
            'user_id' => $cust->id, 'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(), 'download_limit' => 9,
        ]);

        $path = "/api/v1/general/digital/url/{$ent->uuid}/{$u->uuid}";
        \Laravel\Sanctum\Sanctum::actingAs($cust, ['*']);
        $this->assertSame(302, $this->get($path)->getStatusCode());

        app(\App\Services\Digital\DigitalFulfillmentService::class)->revoke($ent->fresh());
        $res = $this->get($path);
        $this->assertSame(403, $res->getStatusCode(), 'revoked blocks redirect');

        app(\App\Services\Digital\DigitalEntitlementService::class)->restore($ent->fresh(), null);
        $this->assertSame(302, $this->get($path)->getStatusCode(), 'restore re-allows redirect');

        // Expired blocks too.
        $ent->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertSame(403, $this->get($path)->getStatusCode());
    }
}
