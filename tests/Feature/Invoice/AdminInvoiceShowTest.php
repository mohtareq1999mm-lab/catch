<?php

namespace Tests\Feature\Invoice;

use Illuminate\Support\Str;
use Tests\Concerns\WithAdminInvoiceContext;
use Tests\TestCase;

/**
 * CATEGORY D — HAPPY PATH (show)
 * CATEGORY N — ERROR HANDLING (nonexistent id)
 * CATEGORY O — RESPONSE CONTRACT for AdminInvoiceResource
 */
class AdminInvoiceShowTest extends TestCase
{
    use WithAdminInvoiceContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminInvoiceContext();

        $this->actingAsStaff($this->createStaffUser(['view-invoice']));
    }

    public function test_show_returns_invoice_with_contract_fields(): void
    {
        $invoice = $this->seedInvoice();

        $response = $this->getJson(self::ADMIN_PREFIX . '/' . $invoice->id);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'order_id',
                    'invoice_number',
                    'status',
                    'subtotal',
                    'total',
                    'currency',
                    'snapshot_hash',
                    'verification_hash',
                    'generation_attempts',
                    'is_correction',
                    'verify_count',
                    'verification_url',
                    'qr_content',
                    'view_url',
                    'snapshot',
                ],
            ]);

        $response->assertJsonPath('data.id', $invoice->id);
        $response->assertJsonPath('data.uuid', $invoice->uuid);
        $response->assertJsonPath('data.invoice_number', $invoice->invoice_number);
        // Admin viewer link points at the admin detail route.
        $this->assertStringContainsString(
            '/api/v1/invoices/' . $invoice->id,
            (string) $response->json('data.view_url')
        );
        // Money fields are rounded to 2 decimals by the resource.
        $this->assertEqualsWithDelta(150.0, $response->json('data.total'), 0.001);
    }

    public function test_show_includes_conditional_download_url_only_when_pdf_exists(): void
    {
        $invoice = $this->seedInvoice(); // stays 'generated' — no pdf_path yet

        $withoutPdf = $this->getJson(self::ADMIN_PREFIX . '/' . $invoice->id);
        $this->assertNull($withoutPdf->json('data.download_url'));

        $filename = str_replace('/', '-', $invoice->invoice_number) . '.pdf';
        $invoice->update([
            'pdf_path' => $filename,
            'pdf_generated_at' => now(),
            'status' => 'ready',
        ]);

        $withPdf = $this->getJson(self::ADMIN_PREFIX . '/' . $invoice->id);
        $withPdf->assertOk();
        // Registered authenticated download route: /api/v1/invoices/{uuid}/download
        $this->assertStringContainsString(
            '/api/v1/invoices/' . $invoice->uuid . '/download',
            (string) $withPdf->json('data.download_url')
        );
    }

    public function test_nonexistent_invoice_id_returns_404(): void
    {
        $this->getJson(self::ADMIN_PREFIX . '/999999')->assertStatus(404);
    }

    /**
     * INV-001 REGRESSION: non-numeric id must never reach the controller as a
     * TypeError. The route now carries ->whereNumber('id'), so malformed ids
     * fail routing with the framework's standard 404.
     */
    public function test_regression_inv001_non_numeric_id_returns_404_not_500(): void
    {
        $response = $this->getJson(self::ADMIN_PREFIX . '/not-an-id');
        $this->assertSame(404, $response->status());
        $this->assertStringNotContainsString('App\\Models', (string) $response->getContent());
    }

    public function test_regression_inv001_uuid_in_numeric_id_route_returns_404_not_500(): void
    {
        $invoice = $this->seedInvoice();

        $response = $this->getJson(self::ADMIN_PREFIX . '/' . $invoice->uuid);
        $this->assertSame(404, $response->status());
    }
}
