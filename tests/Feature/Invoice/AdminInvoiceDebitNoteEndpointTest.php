<?php

namespace Tests\Feature\Invoice;

use App\Models\DebitNote;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithAdminInvoiceContext;
use Tests\TestCase;

/**
 * CATEGORY C — VALIDATION (amount/reason)
 * CATEGORY D — HAPPY PATH (issue debit note) with DB side effects
 * CATEGORY E — STATE GUARD (status allowlist)
 *
 * Response contract note: the controller returns the raw DebitNote model
 * (no Resource) inside the envelope's data key.
 */
class AdminInvoiceDebitNoteEndpointTest extends TestCase
{
    use WithAdminInvoiceContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminInvoiceContext();

        $this->actingAsStaff($this->createStaffUser(['issue-debit-note']));
    }

    public function test_issues_debit_note_with_dn_series_and_persists_record(): void
    {
        $invoice = $this->seedInvoice();
        Queue::fake(); // silence seeding job

        $response = $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/debit-note', [
            'amount' => 25.5,
            'reason' => 'Undercharged shipping',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Debit note issued successfully');

        // Raw-model contract: fields exist on data.
        $this->assertSame($invoice->id, $response->json('data.invoice_id'));
        $this->assertEqualsWithDelta(25.5, (float) $response->json('data.amount'), 0.001);
        $this->assertSame($invoice->currency, $response->json('data.currency'));
        $this->assertSame('Undercharged shipping', $response->json('data.reason'));

        $number = $response->json('data.debit_note_number');
        $this->assertStringStartsWith('DN-', $number);

        // Persisted row matches.
        $note = DebitNote::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($note);
        $this->assertSame($number, $note->debit_note_number);
        $this->assertSame(1, $note->sequence_number);
    }

    public function test_multiple_debit_notes_allowed_with_sequential_numbers(): void
    {
        $invoice = $this->seedInvoice();

        Queue::fake();
        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/debit-note', [
            'amount' => 10, 'reason' => 'first',
        ])->assertStatus(201);

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/debit-note', [
            'amount' => 5, 'reason' => 'second',
        ])->assertStatus(201);

        $notes = DebitNote::where('invoice_id', $invoice->id)->orderBy('sequence_number')->get();
        $this->assertCount(2, $notes);
        $this->assertSame([1, 2], $notes->pluck('sequence_number')->all());
        $this->assertNotSame($notes[0]->debit_note_number, $notes[1]->debit_note_number);

        // Invoice itself is NOT mutated by a debit note.
        $invoice->refresh();
        $this->assertSame('generated', $invoice->status);
    }

    // ─── C: validation ──────────────────────────────────────────────────────

    public function test_amount_is_required(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/debit-note', [
            'reason' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_zero_and_negative_amount_rejected(): void
    {
        $invoice = $this->seedInvoice();

        foreach ([0, -1] as $amount) {
            $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/debit-note', [
                'amount' => $amount,
                'reason' => 'x',
            ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        }
    }

    public function test_reason_required_and_capped_at_500(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/debit-note', [
            'amount' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/debit-note', [
            'amount' => 5,
            'reason' => str_repeat('z', 501),
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    // ─── E: status guard ─────────────────────────────────────────────────────

    public function test_debit_note_rejected_for_non_issued_statuses(): void
    {
        foreach (['cancelled'] as $status) {
            $invoice = $this->seedInvoice();
            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'test guard',
            ]);

            Queue::fake();
            $response = $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/debit-note', [
                'amount' => 10,
                'reason' => 'should fail',
            ]);

            $response->assertStatus(422)->assertJsonPath('success', false);
            $this->assertDatabaseCount('debit_notes', 0);
        }
    }

    public function test_nonexistent_invoice_returns_404(): void
    {
        Queue::fake();

        $this->postJson(self::ADMIN_PREFIX . '/777777/debit-note', [
            'amount' => 10,
            'reason' => 'x',
        ])->assertStatus(404);
    }
}
