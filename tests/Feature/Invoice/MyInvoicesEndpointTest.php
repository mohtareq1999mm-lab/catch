<?php

namespace Tests\Feature\Invoice;

use App\Services\Invoice\InvoiceNumberService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\InvoiceSnapshotService;
use App\Services\Invoice\InvoiceSnapshotValidator;
use App\Services\Invoice\InvoiceTimelineService;
use App\Services\Invoice\SnapshotIntegrityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

/**
 * GET /api/v1/general/invoices/my-invoices — lightweight list contract.
 *
 * Proves the list returns ONLY invoice-level summary fields (no snapshot),
 * while pagination, ownership scoping and the DETAIL endpoint stay intact.
 */
class MyInvoicesEndpointTest extends TestCase
{
    use CreatesTestTables, WithInvoiceTables, DatabaseTransactions;

    private const URI = '/api/v1/general/invoices/my-invoices';

    private InvoiceService $invoiceService;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        if (config('database.default') === 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->createAllTestTables();
        $this->createInvoiceTables();

        $this->invoiceService = new InvoiceService(
            new InvoiceSnapshotService(),
            new InvoiceSnapshotValidator(),
            new SnapshotIntegrityService(),
            new InvoiceNumberService(),
            new InvoiceTimelineService(),
        );
    }

    private function createUser(string $email): User
    {
        return User::create([
            'name' => 'List ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
    }

    private function createOrderFor(User $user): \Marvel\Database\Models\Order
    {
        return \Marvel\Database\Models\Order::create([
            'user_id' => $user->id,
            'status' => 'processing',
            'price' => 100.0,
            'total_price' => 110.0,
            'shipping_price' => 10.0,
            'payment_method' => 'online',
            'payment_gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'shipping_method' => 'SCHEDULED',
        ]);
    }

    // 1 + 2 + 3: 200, pagination works, summary fields present
    public function test_returns_paginated_lightweight_summaries(): void
    {
        $user = $this->createUser('a@mi.test');
        for ($i = 0; $i < 3; $i++) {
            $this->invoiceService->generateFromOrder($this->createOrderFor($user));
        }
        Sanctum::actingAs($user);

        $response = $this->getJson(self::URI . '?limit=2&page=1');

        $response->assertOk()->assertJsonPath('success', true);

        $items = $response->json('data.data');
        $this->assertCount(2, $items);                       // pagination applied
        $this->assertSame(3, $response->json('data.links.total'));

        foreach ($items as $item) {
            foreach ([ // required summary fields
                'uuid', 'invoice_number', 'status', 'subtotal', 'shipping_price',
                'total_discount', 'total', 'currency', 'payment_method',
                'payment_gateway', 'generated_at', 'pdf_generated_at',
                'verification_url', 'download_url', 'view_url',
            ] as $field) {
                $this->assertArrayHasKey($field, $item);
            }
        }
    }

    // 4 + 5 + 6: snapshot and all subfields absent — even across many invoices
    public function test_snapshot_and_all_subfields_are_never_emitted(): void
    {
        $user = $this->createUser('b@mi.test');
        for ($i = 0; $i < 4; $i++) {                        // multiple invoices
            $this->invoiceService->generateFromOrder($this->createOrderFor($user));
        }
        Sanctum::actingAs($user);

        $response = $this->getJson(self::URI)->assertOk();
        $raw = $response->getContent();

        $this->assertStringNotContainsString('"snapshot"', $raw);
        foreach (['customer', 'billing_address', 'shipping_address', 'fulfillment',
                  'pickup_location', 'items', 'pricing_breakdown', 'payment',
                  'metadata', 'audit', 'snapshot_version', 'snapshot_schema'] as $sub) {
            $this->assertStringNotContainsString('"' . $sub . '"', $raw);
        }
    }

    // 7: ownership filtering unchanged
    public function test_lists_only_the_authenticated_users_invoices(): void
    {
        $mine = $this->createUser('me@mi.test');
        $other = $this->createUser('other@mi.test');

        $myInvoice = $this->invoiceService->generateFromOrder($this->createOrderFor($mine));
        $this->invoiceService->generateFromOrder($this->createOrderFor($other));

        Sanctum::actingAs($mine);
        $response = $this->getJson(self::URI)->assertOk();

        $uuids = collect($response->json('data.data'))->pluck('uuid');
        $this->assertSame([$myInvoice->uuid], $uuids->all());
        $this->assertSame(1, $response->json('data.links.total'));
    }

    // 8: authentication behavior unchanged
    public function test_guest_gets_401(): void
    {
        $this->getJson(self::URI)->assertStatus(401);
    }

    // 9: customer DETAIL endpoint keeps the full snapshot
    public function test_detail_endpoint_still_includes_snapshot(): void
    {
        $user = $this->createUser('c@mi.test');
        $order = $this->createOrderFor($user);
        $invoice = $this->invoiceService->generateFromOrder($order);
        Sanctum::actingAs($user);

        $detail = $this->getJson('/api/v1/general/orders/' . $order->id . '/invoice')->assertOk();

        $this->assertSame($invoice->uuid, $detail->json('data.uuid'));
        $this->assertNotNull($detail->json('data.snapshot'));
        $this->assertArrayHasKey('pricing_breakdown', $detail->json('data.snapshot'));
    }

    // 9b: view/download URLs are temporary SIGNED links usable WITHOUT Sanctum
    public function test_view_and_download_urls_are_signed(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = $this->createUser('dl@mi.test');
        $order = $this->createOrderFor($user);
        $invoice = $this->invoiceService->generateFromOrder($order);

        $filename = str_replace('/', '-', $invoice->invoice_number) . '.pdf';
        \Illuminate\Support\Facades\Storage::disk('public')->put('invoices/' . $filename, '%PDF-1.4 test');
        $invoice->update(['pdf_path' => $filename, 'pdf_generated_at' => now(), 'status' => 'ready']);

        Sanctum::actingAs($user);
        $response = $this->getJson(self::URI)->assertOk();

        $viewUrl = $response->json('data.data.0.view_url');
        $downloadUrl = $response->json('data.data.0.download_url');

        foreach ([
            ['/api/v1/general/invoices/view/', $viewUrl],
            ['/api/v1/general/invoices/download/', $downloadUrl],
        ] as [$segment, $url]) {
            $this->assertStringContainsString($segment, $url);
            $this->assertStringContainsString('expires=', $url);
            $this->assertStringContainsString('signature=', $url);
        }
    }

    // 9b-guest: signed URLs open WITHOUT any authentication (fresh guest context)
    public function test_guest_can_follow_signed_view_and_download_urls(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = $this->createUser('guest@mi.test');
        $order = $this->createOrderFor($user);
        $invoice = $this->invoiceService->generateFromOrder($order);
        $filename = str_replace('/', '-', $invoice->invoice_number) . '.pdf';
        \Illuminate\Support\Facades\Storage::disk('public')->put('invoices/' . $filename, '%PDF-1.4 test');
        $invoice->update(['pdf_path' => $filename, 'pdf_generated_at' => now(), 'status' => 'ready']);

        // Generate the same urls the resource emits — no actingAs anywhere.
        $viewUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'general.invoices.view', now()->addMinutes(10), ['uuid' => $invoice->uuid]
        );
        $downloadUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'general.invoices.download', now()->addMinutes(10), ['uuid' => $invoice->uuid]
        );

        // VIEW streams the PDF inline.
        $viewPath = parse_url($viewUrl, PHP_URL_PATH) . '?' . parse_url($viewUrl, PHP_URL_QUERY);
        $view = $this->get($viewPath)->assertOk();
        $this->assertSame('application/pdf', $view->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $view->headers->get('Content-Disposition'));

        // DOWNLOAD streams the PDF as attachment + records bookkeeping.
        $downloadPath = parse_url($downloadUrl, PHP_URL_PATH) . '?' . parse_url($downloadUrl, PHP_URL_QUERY);
        $download = $this->get($downloadPath)->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertNotNull($invoice->refresh()->downloaded_at);

        // Guest hitting my-invoices itself stays protected.
        $this->getJson(self::URI)->assertStatus(401);
    }

    // 9c: signature tampering is rejected with 403
    public function test_tampered_signed_urls_are_rejected(): void
    {
        $user = $this->createUser('tamper@mi.test');
        $order = $this->createOrderFor($user);
        $invoice = $this->invoiceService->generateFromOrder($order);

        $viewUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'general.invoices.view', now()->addMinutes(10), ['uuid' => $invoice->uuid]
        );
        $path = parse_url($viewUrl, PHP_URL_PATH);
        parse_str(parse_url($viewUrl, PHP_URL_QUERY), $params);

        // modified uuid
        $this->get($path . '?' . http_build_query(array_merge($params, [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ])))->assertStatus(403);

        // modified expiration
        $this->get($path . '?' . preg_replace('/expires=\d+/', 'expires=9999999999', http_build_query($params)))
            ->assertStatus(403);

        // invalid signature
        $this->get($path . '?' . preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('a', 64), http_build_query($params)))
            ->assertStatus(403);

        // missing signature
        $this->get($path . '?' . http_build_query(array_diff_key($params, ['signature' => ''])))
            ->assertStatus(403);

        // expired
        $expired = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'general.invoices.view', now()->subMinutes(5), ['uuid' => $invoice->uuid]
        );
        $this->get(parse_url($expired, PHP_URL_PATH) . '?' . parse_url($expired, PHP_URL_QUERY))
            ->assertStatus(403);
    }

    // 10: admin endpoints unchanged (admin show still exposes hashes/snapshot)
    public function test_admin_show_endpoint_remains_unchanged(): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate('view-invoice', 'api');
        $admin = $this->createUser('admin@mi.test');
        $admin->givePermissionTo('view-invoice');
        $order = $this->createOrderFor($this->createUser('cust@mi.test'));
        $invoice = $this->invoiceService->generateFromOrder($order);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/invoices/' . $invoice->id)->assertOk();

        $response->assertJsonPath('data.invoice_number', $invoice->invoice_number)
            ->assertJsonPath('data.snapshot_hash', $invoice->snapshot_hash)
            ->assertJsonPath('data.verification_hash', $invoice->verification_hash);
        $this->assertNotNull($response->json('data.snapshot'));
    }
}
