<?php

namespace Tests\Feature\Invoice;

use App\Jobs\GenerateInvoicePdfJob;
use App\Models\Invoice;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithAdminInvoiceContext;
use Tests\TestCase;

/**
 * CATEGORY — END-TO-END FLOW
 *
 * Full business outcome without mocking the application:
 *   admin index → correct (creates correction + dispatches job on meem-medium)
 *   → execute the real PDF job (DomPDF) against a fake storage disk
 *   → correction becomes READY with a stored file + checksum
 *   → cancel the ORIGINAL invoice afterwards
 *   → final persisted states verified through both HTTP and DB.
 */
class AdminInvoiceEndToEndTest extends TestCase
{
    use WithAdminInvoiceContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminInvoiceContext();
    }

    public function test_complete_lifecycle_from_index_to_correction_pdf_to_cancellation(): void
    {
        $staff = $this->createStaffUser(['view-invoices', 'correct-invoice', 'cancel-invoice']);
        $this->actingAsStaff($staff);

        // 1. Seed order + invoice (job captured by trait's Queue::fake).
        $original = $this->seedInvoice();
        $this->assertSame('generated', $original->refresh()->status);

        // 2. Admin sees exactly one invoice.
        Queue::fake();
        $list = $this->getJson(self::ADMIN_PREFIX);
        $list->assertOk();
        $this->assertSame(1, $list->json('data.links.total'));

        // 3. Correct it → new correction invoice created and dispatched.
        $correctionResponse = $this->postJson(self::ADMIN_PREFIX . '/' . $original->id . '/correct', [
            'reason' => 'E2E: total misprint',
            'overrides' => ['total' => 175.25],
        ]);
        $correctionResponse->assertOk();
        $correctionId = $correctionResponse->json('data.id');

        $original->refresh();
        $this->assertSame('corrected', $original->status);
        $correction = Invoice::findOrFail($correctionId);
        $this->assertTrue($correction->is_correction);
        $this->assertEqualsWithDelta(175.25, (float) $correction->total, 0.001);

        // 4. Run the REAL queued job (DomPDF) against fake storage.
        Storage::fake('public');
        $job = null;
        Queue::assertPushed(
            GenerateInvoicePdfJob::class,
            function (GenerateInvoicePdfJob $pushed) use (&$job, $correctionId) {
                if ($pushed->invoice->id === $correctionId) {
                    $job = $pushed;
                    return true;
                }
                return false;
            }
        );

        $this->assertNotNull($job, 'GenerateInvoicePdfJob must be queued for the correction');
        $job->handle();

        $correction->refresh();
        $this->assertSame('ready', $correction->status);
        $this->assertNotNull($correction->pdf_path);
        $this->assertNotNull($correction->pdf_checksum);
        Storage::disk('public')->assertExists('invoices/' . $correction->pdf_path);

        // 5. Cancel the original (legal transition corrected → cancelled).
        Queue::fake();
        $cancel = $this->postJson(self::ADMIN_PREFIX . '/' . $original->id . '/cancel', [
            'reason' => 'E2E: voiding superseded original',
        ]);
        $cancel->assertOk();

        // 6. Final persisted state.
        $original->refresh();
        $this->assertSame('cancelled', $original->status);
        $this->assertNotNull($original->cancelled_at);

        // 7. Timeline tells the whole story in order:
        //    original: generated → corrected → cancelled
        //    correction: generated
        //    ('pdf_regenerated' exists ONLY via the /regenerate endpoint, unused here)
        $events = \Illuminate\Support\Facades\DB::table('invoice_timeline')
            ->whereIn('invoice_id', [$original->id, $correctionId])
            ->orderBy('id')
            ->pluck('event')
            ->all();

        $this->assertSame(
            ['generated', 'corrected', 'generated', 'cancelled'],
            $events
        );

        // 8. Index now reports two invoices with distinct statuses.
        $finalList = $this->getJson(self::ADMIN_PREFIX)->assertOk();
        $rows = collect($finalList->json('data.data'))->keyBy('id');
        $this->assertSame('cancelled', $rows[$original->id]['status']);
        $this->assertSame('ready', $rows[$correctionId]['status']);
        $this->assertTrue($rows[$correctionId]['is_correction']);
    }
}
