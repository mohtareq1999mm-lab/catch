<?php

namespace Tests\Feature\Order;

use App\Models\Invoice;
use App\Services\Invoice\InvoiceNumberService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\InvoiceSnapshotService;
use App\Services\Invoice\InvoiceSnapshotValidator;
use App\Services\Invoice\InvoiceTimelineService;
use App\Services\Invoice\SnapshotIntegrityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

/**
 * Canonical Order-ID based customer invoice lookup:
 *   GET /api/v1/general/orders/{orderId}/invoice
 *
 * Contract under test:
 *   - pending order            → 404 (no invoice yet)
 *   - first valid leave-pending → invoice created once → 200
 *   - later transitions        → same invoice, count stays 1
 *   - ownership scoped in query (foreign order = same clean 404)
 */
class OrderIdInvoiceEndpointTest extends TestCase
{
    use CreatesTestTables, WithInvoiceTables, DatabaseTransactions;

    private const URI = '/api/v1/general/orders/%d/invoice';

    private InvoiceService $invoiceService;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Config::set('scout.driver', 'null');

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
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
    }

    private function createPendingOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_status' => null,
            'price' => 200.00,
            'total_price' => 230.00,
            'shipping_price' => 30.00,
            'payment_method' => 'online',
            'payment_gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'shipping_method' => 'SCHEDULED',
        ]);
    }

    /** Simulates the approved lifecycle: status change + exactly-once invoice. */
    private function transition(Order $order, string $status): void
    {
        $order->update(['status' => $status]);
        $this->invoiceService->generateFromOrder($order->refresh());
    }

    // ─── 1 + 10: pending order → no invoice → 404 ──────────────────────────

    public function test_pending_order_returns_404(): void
    {
        $user = $this->createUser('p1@oid.test');
        $order = $this->createPendingOrder($user);
        Sanctum::actingAs($user);

        $this->getJson(sprintf(self::URI, $order->id))
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── 2: pending → processing → invoice exists → 200 ───────────────────

    public function test_processing_order_returns_invoice(): void
    {
        $user = $this->createUser('p2@oid.test');
        $order = $this->createPendingOrder($user);
        $invoice = $this->invoiceService->generateFromOrder($order);
        $order->update(['status' => 'processing']);
        Sanctum::actingAs($user);

        $response = $this->getJson(sprintf(self::URI, $order->id));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $invoice->uuid)
            ->assertJsonPath('data.invoice_number', $invoice->invoice_number);
    }

    // ─── 3 + 4 + 7: later transitions keep the SAME invoice; repeated GETs stable

    public function test_lifecycle_transitions_never_change_or_duplicate_the_invoice(): void
    {
        $user = $this->createUser('p3@oid.test');
        $order = $this->createPendingOrder($user);

        $this->transition($order, 'processing');          // first leave-pending → invoice x1
        Sanctum::actingAs($user);

        $first = $this->getJson(sprintf(self::URI, $order->id))->assertOk();
        $uuid = $first->json('data.uuid');

        // idempotent creation attempt (service must not duplicate)
        $this->invoiceService->generateFromOrder($order->refresh());

        $this->transition($order, 'completed');
        $second = $this->getJson(sprintf(self::URI, $order->id))->assertOk();

        $this->transition($order, 'delivered');
        $third = $this->getJson(sprintf(self::URI, $order->id))->assertOk();

        $this->assertSame($uuid, $second->json('data.uuid'));
        $this->assertSame($uuid, $third->json('data.uuid'));

        // Repeated requests return the identical payload.
        $again = $this->getJson(sprintf(self::URI, $order->id))->assertOk();
        $this->assertSame($third->json(), $again->json());

        $this->assertSame(1, Invoice::where('order_id', $order->id)->whereNull('correction_to_id')->count());
    }

    // ─── 5: pending → cancelled → invoice once → 200 ───────────────────────

    public function test_cancelled_first_leave_creates_invoice_once(): void
    {
        $user = $this->createUser('p4@oid.test');
        $order = $this->createPendingOrder($user);

        $this->transition($order, 'cancelled');
        Sanctum::actingAs($user);

        $this->getJson(sprintf(self::URI, $order->id))
            ->assertOk()
            ->assertJsonPath('data.status', 'ready'); // sync queue runs the PDF job before response

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
    }

    // ─── 6: pending → completed directly → invoice once ────────────────────

    public function test_completed_direct_transition_creates_invoice_once(): void
    {
        $user = $this->createUser('p5@oid.test');
        $order = $this->createPendingOrder($user);

        $this->transition($order, 'completed');
        Sanctum::actingAs($user);

        $this->getJson(sprintf(self::URI, $order->id))->assertOk();
        $this->assertSame(1, Invoice::where('order_id', $order->id)->whereNull('correction_to_id')->count());
    }

    // ─── 8: ownership — foreign order indistinguishable from missing (404) ─

    public function test_user_cannot_read_another_users_order_invoice(): void
    {
        $owner = $this->createUser('owner@oid.test');
        $intruder = $this->createUser('intruder@oid.test');
        $order = $this->createPendingOrder($owner);
        $this->invoiceService->generateFromOrder($order);
        $order->update(['status' => 'processing']);

        Sanctum::actingAs($intruder);
        $response = $this->getJson(sprintf(self::URI, $order->id));

        // Handler envelope for findOrFail misses: {message, status:false} — no success key.
        $response->assertStatus(404);
        $this->assertFalse($response->json('status'));
        $this->assertStringNotContainsString('invoice_number', $response->getContent());
    }

    // ─── 9: missing order → 404 ─────────────────────────────────────────────

    public function test_missing_order_returns_404(): void
    {
        Sanctum::actingAs($this->createUser('p6@oid.test'));

        $response = $this->getJson(sprintf(self::URI, 987654));
        $response->assertStatus(404);
        $this->assertFalse($response->json('status'));
    }

    // ─── 11 + 12 + 13: uuid validity, old endpoint parity, corrections ─────

    public function test_returned_payload_matches_customer_invoice_resource_contract(): void
    {
        $user = $this->createUser('p7@oid.test');
        $order = $this->createPendingOrder($user);
        $invoice = $this->invoiceService->generateFromOrder($order);
        $order->update(['status' => 'processing']);
        Sanctum::actingAs($user);

        $response = $this->getJson(sprintf(self::URI, $order->id))->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'uuid',
                'invoice_number',
                'status',
                'subtotal',
                'shipping_price',
                'total_discount',
                'total',
                'currency',
                'payment_method',
                'payment_gateway',
                'generated_at',
                'pdf_generated_at',
                'verification_url',
                'view_url',
            ],
        ]);
        $response->assertJsonPath('data.uuid', $invoice->uuid);
        $response->assertJsonPath('data.invoice_number', $invoice->invoice_number);
        // Ready-made viewer link — customer signed PDF-view route.
        $this->assertStringContainsString(
            '/api/v1/general/invoices/view/' . $invoice->uuid,
            (string) $response->json('data.view_url')
        );
        $this->assertStringContainsString('signature=', (string) $response->json('data.view_url'));
    }

    public function test_correction_exists_still_resolves_latest_invoice(): void
    {
        $user = $this->createUser('p8@oid.test');
        $order = $this->createPendingOrder($user);
        $original = $this->invoiceService->generateFromOrder($order);
        $order->update(['status' => 'processing']);

        $correction = $this->invoiceService->correctInvoice(
            $original->id,
            ['total' => 99.99],
            'price fix',
            null
        );

        Sanctum::actingAs($user);

        // Canonical endpoint resolves the LATEST document — the same invoice_id the list advertises.
        $response = $this->getJson(sprintf(self::URI, $order->id))->assertOk();
        $this->assertSame($correction->uuid, $response->json('data.uuid'));

        // Original untouched; correction linked.
        $this->assertSame(1, Invoice::where('order_id', $order->id)->whereNull('correction_to_id')->count());
        $this->assertTrue($correction->refresh()->is_correction);
    }

    // ─── ported from the removed OrderInvoiceEndpointTest: list indicators ──

    public function test_order_list_exposes_invoice_indicator_fields(): void
    {
        $user = $this->createUser('p9@oid.test');
        $order = $this->createPendingOrder($user);
        $invoice = $this->invoiceService->generateFromOrder($order);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/general/orders')->assertOk();

        $target = collect($response->json('data.data'))->firstWhere('id', $order->id);
        $this->assertNotNull($target);
        $this->assertTrue($target['order_has_invoice']);
        $this->assertSame($invoice->uuid, $target['invoice_id']);
    }
}
