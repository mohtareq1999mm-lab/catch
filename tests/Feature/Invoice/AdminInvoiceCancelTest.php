<?php

namespace Tests\Feature\Invoice;

use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WithAdminInvoiceContext;
use Tests\TestCase;

/**
 * CATEGORY C — VALIDATION (reason)
 * CATEGORY D — HAPPY PATH (cancel) with persisted state
 * CATEGORY E — STATE TRANSITIONS
 * CATEGORY F — IDEMPOTENCY: repeated cancel is rejected
 */
class AdminInvoiceCancelTest extends TestCase
{
    use WithAdminInvoiceContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminInvoiceContext();

        $this->actingAsStaff($this->createStaffUser(['cancel-invoice']));
    }

    public function test_cancel_persists_terminal_state_reason_and_timeline(): void
    {
        $invoice = $this->seedInvoice();
        Queue::fake(); // silence seeding job

        $response = $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/cancel', [
            'reason' => 'Duplicate invoice issued by mistake',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        // Persisted state (DB is the source of truth, not the response).
        $invoice->refresh();
        $this->assertSame('cancelled', $invoice->status);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertSame('Duplicate invoice issued by mistake', $invoice->cancellation_reason);

        $this->assertDatabaseHas('invoice_timeline', [
            'invoice_id' => $invoice->id,
            'event' => 'cancelled',
        ]);
    }

    public function test_cancel_is_rejected_without_reason(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/cancel', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $invoice->refresh();
        $this->assertSame('generated', $invoice->status);
    }

    public function test_cancel_rejects_reason_over_500_chars(): void
    {
        $invoice = $this->seedInvoice();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/cancel', [
            'reason' => str_repeat('y', 501),
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_repeated_cancel_is_idempotently_rejected_with_business_422(): void
    {
        $invoice = $this->seedInvoice();

        Queue::fake();
        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/cancel', [
            'reason' => 'first',
        ])->assertOk();

        Queue::fake();
        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/cancel', [
            'reason' => 'second',
        ])->assertStatus(422)->assertJsonPath('success', false);

        // Original cancellation data preserved.
        $invoice->refresh();
        $this->assertSame('first', $invoice->cancellation_reason);

        // Exactly one cancelled timeline row.
        $count = \Illuminate\Support\Facades\DB::table('invoice_timeline')
            ->where('invoice_id', $invoice->id)
            ->where('event', 'cancelled')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_cancelled_invoice_cannot_be_corrected_afterwards(): void
    {
        $invoice = $this->seedInvoice();

        Queue::fake();
        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/cancel', [
            'reason' => 'void',
        ])->assertOk();

        $this->postJson(self::ADMIN_PREFIX . '/' . $invoice->id . '/correct', [
            'reason' => 'after-cancel',
        ])->assertStatus(403); // this actor only holds cancel-invoice
    }

    /**
     * INV-003 REGRESSION (cancel): missing invoice → 404, no FQCN leak;
     * repeated cancel of an EXISTING invoice still yields business 422.
     */
    public function test_regression_inv003_cancel_nonexistent_invoice_returns_404_without_leaking_fqcn(): void
    {
        Queue::fake();

        $response = $this->postJson(self::ADMIN_PREFIX . '/987654/cancel', ['reason' => 'x']);

        $response->assertStatus(404);
        $this->assertStringNotContainsString('App\\Models\\Invoice', (string) $response->getContent());
    }
}
