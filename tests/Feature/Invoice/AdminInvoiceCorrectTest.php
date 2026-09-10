<?php

namespace Tests\Feature\Invoice;

use App\Events\InvoiceCreated;
use App\Jobs\GenerateInvoicePdfJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithAdminInvoiceContext;
use Tests\TestCase;

/**
 * CATEGORY C — VALIDATION (per-rule, focused)
 * CATEGORY D — HAPPY PATH (correct) incl. every DB side effect
 * CATEGORY E — STATE TRANSITIONS
 * CATEGORY G/I — Event + Job after successful correction only
 */
class AdminInvoiceCorrectTest extends TestCase
{
    use WithAdminInvoiceContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminInvoiceContext();

        $this->actingAsStaff($this->createStaffUser(['correct-invoice']));
    }

    // ─── D + E: happy path with full side-effect verification ──────────────

    public function test_correct_creates_new_invoice_and_marks_original_corrected(): void
    {
        $original = $this->seedInvoice();

        $response = $this->postJson(self::ADMIN_PREFIX . '/' . $original->id . '/correct', [
            'reason' => 'Wrong total printed',
            'overrides' => ['total' => 199.99],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $correctionId = $response->json('data.id');
        $this->assertNotNull($correctionId);
        $this->assertNotSame($original->id, $correctionId);

        // New invoice persisted with correction markers.
        $this->assertDatabaseHas('invoices', [
            'id' => $correctionId,
            'is_correction' => true,
            'correction_to_id' => $original->id,
            'status' => 'generated',
        ]);

        // Original transitioned to corrected.
        $original->refresh();
        $this->assertSame('corrected', $original->status);
        $this->assertNotNull($original->corrected_at);
        $this->assertSame('Wrong total printed', $original->correction_reason);

        // Override applied; untouched fields copied from original.
        $correction = \App\Models\Invoice::findOrFail($correctionId);
        $this->assertEqualsWithDelta(199.99, (float) $correction->total, 0.001);
        $this->assertEqualsWithDelta(199.99, (float) $correction->subtotal, 0.001);
        $this->assertSame($original->currency, $correction->currency);
        $this->assertTrue((bool) $correction->is_correction);

        // Unique sequential invoice number from the same series.
        $this->assertNotSame($original->invoice_number, $correction->invoice_number);

        // Timeline: 'corrected' on original + 'generated' on correction.
        $this->assertDatabaseHas('invoice_timeline', [
            'invoice_id' => $original->id,
            'event' => 'corrected',
        ]);
        $this->assertDatabaseHas('invoice_timeline', [
            'invoice_id' => $correctionId,
            'event' => 'generated',
        ]);

        // Snapshot audit carries the reason and actor.
        $this->assertSame('Wrong total printed', $correction->data['audit']['correction_reason']);
    }

    public function test_correct_dispatches_event_and_pdf_job_for_the_correction(): void
    {
        $original = $this->seedInvoice();

        Event::fake([InvoiceCreated::class]);
        Queue::fake();

        $this->postJson(self::ADMIN_PREFIX . '/' . $original->id . '/correct', [
            'reason' => 'reprint',
        ])->assertOk();

        Event::assertDispatchedTimes(InvoiceCreated::class, 1);
        Event::assertDispatched(InvoiceCreated::class, fn ($e) => $e->invoice->is_correction === true);

        Queue::assertPushedOn('meem-medium', GenerateInvoicePdfJob::class);
    }

    // ─── C: focused validation tests ────────────────────────────────────────

    public function test_correct_requires_reason(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/correct', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_correct_rejects_reason_over_500_chars(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/correct', [
            'reason' => str_repeat('x', 501),
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_correct_rejects_negative_override_total(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/correct', [
            'reason' => 'neg',
            'overrides' => ['total' => -5],
        ])->assertStatus(422)->assertJsonValidationErrors(['overrides.total']);
    }

    public function test_correct_rejects_invalid_customer_email_override(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/correct', [
            'reason' => 'bad email',
            'overrides' => ['customer' => ['email' => 'not-an-email']],
        ])->assertStatus(422)->assertJsonValidationErrors(['overrides.customer.email']);
    }

    public function test_correct_rejects_non_array_overrides(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/correct', [
            'reason' => 'x',
            'overrides' => 'total',
        ])->assertStatus(422)->assertJsonValidationErrors(['overrides']);
    }

    // ─── N + F: business rule / repeated request ────────────────────────────

    public function test_cannot_correct_an_already_corrected_invoice(): void
    {
        $original = $this->seedInvoice();

        Queue::fake(); // silence seeding job

        $this->postJson(self::ADMIN_PREFIX . '/' . $original->id . '/correct', [
            'reason' => 'first',
        ])->assertOk();

        Queue::fake();

        $second = $this->postJson(self::ADMIN_PREFIX . '/' . $original->id . '/correct', [
            'reason' => 'second',
        ]);

        $second->assertStatus(422)->assertJsonPath('success', false);
        $original->refresh();
        $this->assertSame('corrected', $original->status);

        // Exactly one correction exists.
        $this->assertDatabaseCount('invoices', 2); // original + one correction
    }

    public function test_cannot_correct_cancelled_invoice(): void
    {
        $invoice = $this->seedInvoice();
        $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'r']);

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/correct', [
            'reason' => 'late',
        ])->assertStatus(422);
    }

    /**
     * INV-003 REGRESSION (correct): missing invoice → 404, no FQCN leak.
     * Business-rule failures on EXISTING invoices must still return 422
     * (covered by cannot_correct_an_already_corrected_invoice).
     */
    public function test_regression_inv003_correct_nonexistent_invoice_returns_404_without_leaking_fqcn(): void
    {
        $response = $this->postJson(self::ADMIN_PREFIX . '/424242/correct', ['reason' => 'x']);

        $response->assertStatus(404);
        $this->assertStringNotContainsString('App\\Models\\Invoice', (string) $response->getContent());
    }
}
