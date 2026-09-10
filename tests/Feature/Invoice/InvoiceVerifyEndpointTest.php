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
 * GET /api/v1/general/invoices/verify/{uuid}
 *
 * Regression for the disabled-InvoiceResource 500 (known issue #7):
 * the authentic path must return 200 with invoice data, not TypeError.
 */
class InvoiceVerifyEndpointTest extends TestCase
{
    use CreatesTestTables, WithInvoiceTables, DatabaseTransactions;

    private const URI = '/api/v1/general/invoices/verify/%s';

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
            'name' => 'Verify ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
    }

    private function seedInvoice(): \App\Models\Invoice
    {
        $user = $this->createUser(uniqid() . '@verify.test');
        $order = \Marvel\Database\Models\Order::create([
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

        return $this->invoiceService->generateFromOrder($order);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $invoice = $this->seedInvoice();

        $this->getJson(sprintf(self::URI, $invoice->uuid))->assertStatus(401);
    }

    public function test_authentic_invoice_returns_200_with_invoice_data(): void
    {
        $invoice = $this->seedInvoice();
        Sanctum::actingAs($this->createUser('scanner@verify.test'), ['*']);

        $response = $this->getJson(sprintf(self::URI, $invoice->uuid));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.authentic', true)
            ->assertJsonPath('data.invoice.uuid', $invoice->uuid)
            ->assertJsonPath('data.invoice.invoice_number', $invoice->invoice_number)
            ->assertJsonPath('data.order.id', $invoice->order_id)
            ->assertJsonPath('data.qr_content', url('/api/v1/general/invoices/verify/' . $invoice->uuid));

        // Ready-made viewer link inside the invoice payload (registered route: invoices/uuid/{uuid}).
        $this->assertStringContainsString(
            '/api/v1/invoices/uuid/' . $invoice->uuid,
            (string) $response->json('data.invoice.view_url')
        );

        // Side effects persisted.
        $invoice->refresh();
        $this->assertSame(1, $invoice->verify_count);
        $this->assertNotNull($invoice->verified_at);
        $this->assertNotNull($invoice->last_verified_at);
        $this->assertDatabaseHas('invoice_timeline', [
            'invoice_id' => $invoice->id,
            'event' => 'verified',
        ]);
    }

    public function test_tampered_invoice_returns_409(): void
    {
        $invoice = $this->seedInvoice();
        $invoice->update(['verification_hash' => str_repeat('0', 64)]);
        Sanctum::actingAs($this->createUser('scan2@verify.test'), ['*']);

        $response = $this->getJson(sprintf(self::URI, $invoice->uuid));

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.authentic', false)
            ->assertJsonPath('data.tampered', true);
    }

    public function test_unknown_uuid_returns_404_envelope(): void
    {
        Sanctum::actingAs($this->createUser('scan3@verify.test'), ['*']);

        $this->getJson(sprintf(self::URI, '00000000-0000-0000-0000-000000000000'))
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_repeated_verification_increments_verify_count(): void
    {
        $invoice = $this->seedInvoice();
        Sanctum::actingAs($this->createUser('scan4@verify.test'), ['*']);

        $this->getJson(sprintf(self::URI, $invoice->uuid))->assertOk();
        $this->getJson(sprintf(self::URI, $invoice->uuid))->assertOk();
        $this->getJson(sprintf(self::URI, $invoice->uuid))->assertOk();

        $this->assertSame(3, $invoice->refresh()->verify_count);
    }
}
