<?php

namespace Tests\Feature\Invoice;

use App\Jobs\GenerateInvoicePdfJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithAdminInvoiceContext;
use Tests\TestCase;

/**
 * CATEGORY D — HAPPY PATH (regenerate)
 * CATEGORY E — STATE TRANSITIONS
 * CATEGORY G/I — EVENT + JOB/QUEUE dispatch (queue name verified: meem-medium)
 * CATEGORY N — ERROR HANDLING / invalid transitions
 * CATEGORY F — repeated regenerate attempts counter
 */
class AdminInvoiceRegenerateTest extends TestCase
{
    use WithAdminInvoiceContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminInvoiceContext();

        $this->actingAsStaff($this->createStaffUser(['regenerate-invoice']));
    }

    public function test_regenerate_from_generated_transitions_to_pdf_generating_and_dispatches_job(): void
    {
        $invoice = $this->seedInvoice();
        $this->assertSame('generated', $invoice->refresh()->status);

        Queue::fake(); // reset captures made during seeding

        $response = $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/regenerate');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pdf_generating')
            ->assertJsonPath('data.invoice_id', $invoice->id);

        // Persisted state is the source of truth.
        $invoice->refresh();
        $this->assertSame('pdf_generating', $invoice->status);
        $this->assertSame(1, $invoice->generation_attempts);
        $this->assertNull($invoice->last_generation_error);

        // Timeline side effect.
        $this->assertDatabaseHas('invoice_timeline', [
            'invoice_id' => $invoice->id,
            'event' => 'pdf_regenerated',
        ]);

        // Job dispatched on the REAL configured queue (source: GenerateInvoicePdfJob constructor).
        Queue::assertPushedOn('meem-medium', GenerateInvoicePdfJob::class);
        Queue::assertPushed(GenerateInvoicePdfJob::class, 1);
    }

    public function test_repeated_regenerate_increments_attempts_and_pushes_again(): void
    {
        $invoice = $this->seedInvoice(); // generated → attempts 0

        // Legal path: generated → ready → failed (failed is regenerable).
        $invoice->update(['status' => 'ready']);
        $invoice->update(['status' => 'failed']);

        Queue::fake(); // reset captures made during seeding

        $attemptsBefore = $invoice->refresh()->generation_attempts;
        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/regenerate')->assertOk();

        // Return to a regenerable state through legal transitions.
        // refresh() first: POST#1 mutated the row via HTTP; this local
        // instance still holds 'failed' as its original values.
        // pdf_generating → ready → failed.
        $invoice->refresh();
        $invoice->update(['status' => 'ready']);
        $invoice->update(['status' => 'failed']);

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/regenerate')->assertOk();

        $invoice->refresh();
        $this->assertSame($attemptsBefore + 2, $invoice->generation_attempts);
        Queue::assertPushed(GenerateInvoicePdfJob::class, 2);
    }

    public function test_regenerate_is_rejected_for_terminal_or_non_pdf_statuses(): void
    {
        foreach (['cancelled', 'corrected'] as $status) {
            $invoice = $this->seedInvoice($status === 'cancelled' ? null : 'c2@invoice.test');
            InvoiceStatusHelper::forceStatus($invoice, $status);

            Queue::fake(); // reset seed-time captures

            $response = $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/regenerate');

            $response->assertStatus(422)
                ->assertJsonPath('success', false);

            $invoice->refresh();
            $this->assertSame($status, $invoice->status, "status must not change from {$status}");
            $this->assertSame(0, $invoice->generation_attempts);
            Queue::assertNothingPushed();
        }
    }

    /**
     * INV-002 REGRESSION (OPTION A — documented contract).
     * Decision source: api-desc/invoice/api.md:303 ("Allowed only from failed,
     * ready, generated"), frontend.md:142, README_INVOICE_FRONTEND_FLOW.md:652.
     * READY → regenerate → PDF_GENERATING is intended behavior; the enum now
     * permits READY → PDF_GENERATING so the controller allowlist and the state
     * machine agree.
     */
    public function test_regression_inv002_regenerate_from_ready_follows_valid_transition(): void
    {
        $invoice = $this->seedInvoice();
        $invoice->update(['status' => 'ready']);
        $this->assertSame('ready', $invoice->refresh()->status);

        Queue::fake(); // reset seed-time captures

        $response = $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/regenerate');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pdf_generating');

        // Persisted transition + attempt accounting.
        $invoice->refresh();
        $this->assertSame('pdf_generating', $invoice->status);
        $this->assertSame(1, $invoice->generation_attempts);
        $this->assertNull($invoice->last_generation_error);

        // Timeline + job on the documented queue.
        $this->assertDatabaseHas('invoice_timeline', [
            'invoice_id' => $invoice->id,
            'event' => 'pdf_regenerated',
        ]);
        Queue::assertPushedOn('meem-medium', GenerateInvoicePdfJob::class);
        Queue::assertPushed(GenerateInvoicePdfJob::class, 1);

        // Job success path completes the loop: pdf_generating → ready.
        Storage::fake('public');
        $job = null;
        Queue::assertPushed(GenerateInvoicePdfJob::class, function (GenerateInvoicePdfJob $pushed) use (&$job) {
            $job = $pushed;
            return true;
        });
        $job->handle();

        $invoice->refresh();
        $this->assertSame('ready', $invoice->status);
        $this->assertSame(2, $invoice->generation_attempts);
        $this->assertNotNull($invoice->pdf_path);
    }

    public function test_regenerate_from_failed_remains_supported(): void
    {
        $invoice = $this->seedInvoice();

        // generated → ready → failed (legal walk to FAILED).
        $invoice->update(['status' => 'ready']);
        $invoice->update(['status' => 'failed']);

        Queue::fake();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/regenerate')
            ->assertOk()
            ->assertJsonPath('data.status', 'pdf_generating');

        $invoice->refresh();
        $this->assertSame('pdf_generating', $invoice->status);
        Queue::assertPushedOn('meem-medium', GenerateInvoicePdfJob::class);
    }
}

/**
 * Small helper so tests can set statuses through paths the API does not expose.
 */
class InvoiceStatusHelper
{
    public static function forceStatus(\App\Models\Invoice $invoice, string $target): void
    {
        // Walk only through legal transitions to reach the target where possible;
        // for terminal targets reached via cancel/correct APIs we emulate the same
        // persisted outcome the service produces.
        match ($target) {
            'cancelled' => $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'test',
            ]),
            'corrected' => $invoice->update([
                'status' => 'corrected',
                'corrected_at' => now(),
                'correction_reason' => 'test',
            ]),
            default => $invoice->update(['status' => $target]),
        };
    }
}
