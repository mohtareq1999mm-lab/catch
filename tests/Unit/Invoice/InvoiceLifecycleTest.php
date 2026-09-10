<?php

namespace Tests\Unit\Invoice;

use App\Enums\InvoiceStatus;
use App\Enums\ShipmentStatus;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Models\Shipment;
use App\Services\Invoice\CreditNoteService;
use App\Services\Invoice\DebitNoteService;
use App\Services\Invoice\InvoiceNumberService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\InvoiceSnapshotService;
use App\Services\Invoice\InvoiceSnapshotValidator;
use App\Services\Invoice\InvoiceTimelineService;
use App\Services\Invoice\SnapshotIntegrityService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use CreatesTestTables, DatabaseTransactions;

    private InvoiceService $invoiceService;
    private CreditNoteService $creditNoteService;
    private DebitNoteService $debitNoteService;
    private ShipmentService $shipmentService;
    private InvoiceTimelineService $timelineService;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAllTestTables();

        $invoiceNumberService = new InvoiceNumberService();
        $snapshotService = new InvoiceSnapshotService();
        $snapshotValidator = new InvoiceSnapshotValidator();
        $integrityService = new SnapshotIntegrityService();
        $this->timelineService = new InvoiceTimelineService();
        $this->creditNoteService = new CreditNoteService($invoiceNumberService);
        $this->debitNoteService = new DebitNoteService($invoiceNumberService);

        $this->invoiceService = new InvoiceService(
            $snapshotService,
            $snapshotValidator,
            $integrityService,
            $invoiceNumberService,
            $this->timelineService,
        );

        $this->shipmentService = new ShipmentService();

        $this->createOrder();
    }

    private function createOrder(): void
    {
        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->order = Order::create([
            'user_id' => 1,
            'name' => 'Test Customer',
            'user_phone' => '01000000000',
            'user_email' => 'test@example.com',
            'status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'price' => 100.00,
            'total_price' => 100.00,
            'shipping_price' => 10.00,
            'payment_method' => 'online',
            'payment_gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'shipping_method' => 'SCHEDULED',
            'address' => json_encode(['street' => 'Test St', 'city' => 'Cairo']),
        ]);

        Transaction::create([
            'order_id' => $this->order->id,
            'user_id' => 1,
            'status' => 'paid',
            'amount' => 100.00,
            'currency' => 'EGP',
            'payment_method' => 'online',
            'paid_at' => now(),
        ]);
    }

    // ─── InvoiceService Tests ───────────────────────────────────────────────

    public function test_generates_invoice_from_order(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);

        $this->assertNotNull($invoice);
        $this->assertEquals($this->order->id, $invoice->order_id);
        $this->assertEquals('generated', $invoice->status);
        $this->assertEquals(100.00, (float) $invoice->total);
        $this->assertNotNull($invoice->uuid);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertNotNull($invoice->snapshot_hash);
        $this->assertNotNull($invoice->verification_hash);
        $this->assertStringStartsWith('INV-', $invoice->invoice_number);
    }

    public function test_prevents_duplicate_invoice_generation(): void
    {
        $first = $this->invoiceService->generateFromOrder($this->order);
        $second = $this->invoiceService->generateFromOrder($this->order);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
    }

    public function test_generates_invoice_with_correct_snapshot_data(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);
        $data = $invoice->data;

        $this->assertArrayHasKey('snapshot_version', $data);
        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('customer', $data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('pricing_breakdown', $data);
        $this->assertArrayHasKey('payment', $data);
        $this->assertEquals($this->order->id, $data['order']['id']);
    }

    public function test_verification_hash_is_consistent(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);

        $result = $this->invoiceService->verifyInvoice($invoice->uuid);
        $this->assertNotNull($result);
        $this->assertTrue($result['authentic']);
        $this->assertFalse($result['tampered']);
    }

    public function test_verify_returns_null_for_nonexistent_invoice(): void
    {
        $result = $this->invoiceService->verifyInvoice('nonexistent-uuid');
        $this->assertNull($result);
    }

    // ─── Invoice Correction Tests ──────────────────────────────────────────

    public function test_corrects_invoice(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);

        $correction = $this->invoiceService->correctInvoice(
            $invoice->id,
            ['customer' => ['name' => 'Corrected Name']],
            'Wrong customer name',
            1,
        );

        $this->assertNotNull($correction);
        $this->assertTrue($correction->is_correction);
        $this->assertEquals($invoice->id, $correction->correction_to_id);
        $this->assertEquals('generated', $correction->status);
        $this->assertEquals('Corrected Name', $correction->data['customer']['name']);

        $invoice->refresh();
        $this->assertEquals('corrected', $invoice->status);
    }

    public function test_corrected_invoice_has_unique_number(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);
        $correction = $this->invoiceService->correctInvoice($invoice->id, [], 'Test correction');

        $this->assertNotSame($invoice->invoice_number, $correction->invoice_number);
    }

    public function test_prevents_correction_of_cancelled_invoice(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);
        $this->invoiceService->cancelInvoice($invoice->id, 'Test cancel');

        $this->expectException(\RuntimeException::class);
        $this->invoiceService->correctInvoice($invoice->id, [], 'Should fail');
    }

    // ─── Invoice Cancellation Tests ────────────────────────────────────────

    public function test_cancels_invoice(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);
        $cancelled = $this->invoiceService->cancelInvoice($invoice->id, 'Test reason', 1);

        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertEquals('Test reason', $cancelled->cancellation_reason);
    }

    public function test_prevents_cancellation_from_pdf_generating_invoice(): void
    {
        // VERIFIED → CANCELLED is a legal transition per InvoiceStatus, so the
        // service correctly ALLOWS it (the previous expectation here was stale).
        // The real guard: 'pdf_generating' is NOT in the cancelInvoice allowlist.
        $invoice = Invoice::create([
            'order_id' => $this->order->id,
            'user_id' => 1,
            'transaction_id' => 1,
            'invoice_number' => 'INV-TEST-001',
            'invoice_series' => 'INV',
            'sequence_number' => 1,
            'sequence_year' => (int) now()->year,
            'subtotal' => 100,
            'total' => 100,
            'amount_paid' => 100,
            'status' => 'pdf_generating',
            'data' => ['test' => true],
            'generated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->invoiceService->cancelInvoice($invoice->id, 'Should fail');
    }

    // ─── Credit Note Tests ─────────────────────────────────────────────────

    public function test_generates_credit_note_for_refund(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);

        $creditNote = $this->creditNoteService->generateForRefund(
            $invoice,
            50.00,
            'Partial refund',
        );

        $this->assertInstanceOf(CreditNote::class, $creditNote);
        $this->assertEquals($invoice->id, $creditNote->invoice_id);
        $this->assertEquals(50.00, (float) $creditNote->amount);
        $this->assertEquals('refund', $creditNote->type);
        $this->assertStringStartsWith('CN-', $creditNote->credit_note_number);
    }

    public function test_generates_credit_note_for_cancellation(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);

        $creditNote = $this->creditNoteService->generateForCancellation(
            $invoice,
            100.00,
            'Order cancelled',
        );

        $this->assertInstanceOf(CreditNote::class, $creditNote);
        $this->assertEquals($invoice->id, $creditNote->invoice_id);
        $this->assertEquals('cancellation', $creditNote->type);
    }

    public function test_credit_notes_have_unique_numbers(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);

        $cn1 = $this->creditNoteService->generateForRefund($invoice, 10, 'Reason 1');
        $cn2 = $this->creditNoteService->generateForRefund($invoice, 20, 'Reason 2');

        $this->assertNotSame($cn1->credit_note_number, $cn2->credit_note_number);
    }

    // ─── Debit Note Tests ──────────────────────────────────────────────────

    public function test_generates_debit_note(): void
    {
        $invoice = $this->invoiceService->generateFromOrder($this->order);

        $debitNote = $this->debitNoteService->generate(
            $invoice,
            25.00,
            'Additional charge',
            1,
        );

        $this->assertInstanceOf(DebitNote::class, $debitNote);
        $this->assertEquals($invoice->id, $debitNote->invoice_id);
        $this->assertEquals(25.00, (float) $debitNote->amount);
        $this->assertStringStartsWith('DN-', $debitNote->debit_note_number);
    }

    // ─── Invoice Status Transition Tests ───────────────────────────────────

    public function test_invoice_status_transitions_are_valid(): void
    {
        $this->assertTrue(InvoiceStatus::GENERATED->canTransitionTo(InvoiceStatus::CORRECTED));
        $this->assertTrue(InvoiceStatus::READY->canTransitionTo(InvoiceStatus::DOWNLOADED));
        $this->assertTrue(InvoiceStatus::VERIFIED->canTransitionTo(InvoiceStatus::ARCHIVED));
        $this->assertTrue(InvoiceStatus::CORRECTED->canTransitionTo(InvoiceStatus::ARCHIVED));
    }

    public function test_invoice_status_transitions_are_invalid(): void
    {
        $this->assertFalse(InvoiceStatus::CANCELLED->canTransitionTo(InvoiceStatus::GENERATED));
        $this->assertFalse(InvoiceStatus::ARCHIVED->canTransitionTo(InvoiceStatus::READY));
        // VERIFIED → CANCELLED is LEGAL per InvoiceStatus::allowedTransitions()
        // (and the cancelInvoice allowlist agrees). Assert a genuinely illegal
        // terminal-state escape instead.
        $this->assertFalse(InvoiceStatus::CANCELLED->canTransitionTo(InvoiceStatus::READY));
        $this->assertFalse(InvoiceStatus::CORRECTED->canTransitionTo(InvoiceStatus::GENERATED));
    }

    // ─── Invoice Status Transition Validation (Model Saving) ───────────────

    public function test_invoice_status_transition_is_enforced_on_save(): void
    {
        $invoice = Invoice::create([
            'order_id' => $this->order->id,
            'user_id' => 1,
            'transaction_id' => 1,
            'invoice_number' => 'INV-TEST-002',
            'invoice_series' => 'INV',
            'sequence_number' => 2,
            'sequence_year' => (int) now()->year,
            'subtotal' => 100,
            'total' => 100,
            'amount_paid' => 100,
            'status' => 'archived',
            'data' => ['test' => true],
            'generated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $invoice->update(['status' => 'generated']);
    }

    // ─── Shipment Tests ────────────────────────────────────────────────────

    public function test_creates_shipment(): void
    {
        $shipment = $this->shipmentService->create([
            'order_id' => $this->order->id,
            'courier' => 'DHL',
            'tracking_number' => 'TRACK123',
        ]);

        $this->assertInstanceOf(Shipment::class, $shipment);
        $this->assertEquals($this->order->id, $shipment->order_id);
        $this->assertEquals('pending', $shipment->status);
    }

    public function test_shipment_status_transitions(): void
    {
        $shipment = $this->shipmentService->create([
            'order_id' => $this->order->id,
            'courier' => 'DHL',
        ]);

        $shipment = $this->shipmentService->updateStatus($shipment->id, 'label_created');
        $this->assertEquals('label_created', $shipment->status);

        $shipment = $this->shipmentService->updateStatus($shipment->id, 'picked_up');
        $this->assertEquals('picked_up', $shipment->status);

        $shipment = $this->shipmentService->updateStatus($shipment->id, 'in_transit');
        $this->assertEquals('in_transit', $shipment->status);

        $shipment = $this->shipmentService->updateStatus($shipment->id, 'out_for_delivery');
        $this->assertEquals('out_for_delivery', $shipment->status);

        $shipment = $this->shipmentService->updateStatus($shipment->id, 'delivered');
        $this->assertEquals('delivered', $shipment->status);
        $this->assertNotNull($shipment->delivered_at);
    }

    public function test_shipment_illegal_transition_throws(): void
    {
        $shipment = $this->shipmentService->create([
            'order_id' => $this->order->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->shipmentService->updateStatus($shipment->id, 'delivered');
    }

    public function test_shipment_cancellation_from_pending(): void
    {
        $shipment = $this->shipmentService->create([
            'order_id' => $this->order->id,
        ]);

        $shipment = $this->shipmentService->updateStatus($shipment->id, 'cancelled');
        $this->assertEquals('cancelled', $shipment->status);
    }

    public function test_shipment_delivered_sets_timestamp(): void
    {
        $shipment = Shipment::create([
            'order_id' => $this->order->id,
            'status' => 'out_for_delivery',
        ]);

        $shipment = $this->shipmentService->updateStatus($shipment->id, 'delivered');
        $this->assertNotNull($shipment->delivered_at);
    }

    // ─── Shipment Status Enum Tests ────────────────────────────────────────

    public function test_shipment_status_enum_transitions(): void
    {
        $this->assertTrue(ShipmentStatus::PENDING->canTransitionTo(ShipmentStatus::LABEL_CREATED));
        $this->assertTrue(ShipmentStatus::IN_TRANSIT->canTransitionTo(ShipmentStatus::OUT_FOR_DELIVERY));
        $this->assertTrue(ShipmentStatus::OUT_FOR_DELIVERY->canTransitionTo(ShipmentStatus::DELIVERED));
        $this->assertTrue(ShipmentStatus::FAILED_DELIVERY->canTransitionTo(ShipmentStatus::RETURNED));
    }

    public function test_shipment_status_enum_illegal_transitions(): void
    {
        $this->assertFalse(ShipmentStatus::PENDING->canTransitionTo(ShipmentStatus::DELIVERED));
        $this->assertFalse(ShipmentStatus::DELIVERED->canTransitionTo(ShipmentStatus::CANCELLED));
        $this->assertFalse(ShipmentStatus::CANCELLED->canTransitionTo(ShipmentStatus::PENDING));
    }
}
