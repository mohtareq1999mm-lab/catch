<?php

namespace Tests\Concerns;

use App\Models\Invoice;
use App\Services\Invoice\DebitNoteService;
use App\Services\Invoice\InvoiceNumberService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\InvoiceSnapshotService;
use App\Services\Invoice\InvoiceSnapshotValidator;
use App\Services\Invoice\InvoiceTimelineService;
use App\Services\Invoice\SnapshotIntegrityService;use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait WithAdminInvoiceContext
{
    use CreatesTestTables;

    protected const ADMIN_PREFIX = '/api/v1/invoices';

    protected InvoiceService $invoiceService;
    protected DebitNoteService $debitNoteService;
    protected User $customer;

    /**
     * Boots tables, schema fixes, queue fake and invoice services.
     * Call at the top of setUp() after parent::setUp().
     */
    protected function setUpAdminInvoiceContext(): void
    {
        $this->createAllTestTables();

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        // Production migration 2026_07_28_000007 adds this column; the shared
        // test schema predates it but InvoiceController@index searches on it.
        if (!Schema::hasColumn('orders', 'order_number')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('order_number', 20)->nullable();
            });
        }

        Queue::fake();

        $numberService = new InvoiceNumberService();
        $this->invoiceService = new InvoiceService(
            new InvoiceSnapshotService(),
            new InvoiceSnapshotValidator(),
            new SnapshotIntegrityService(),
            $numberService,
            new InvoiceTimelineService(),
        );
        $this->debitNoteService = new DebitNoteService($numberService);
    }

    protected function createCustomer(string $email = 'customer@invoice.test'): User
    {
        return User::create([
            'name' => 'Test Customer',
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
    }

    protected function createOrderFor(User $user, float $total = 150.00): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Test Customer',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'price' => $total,
            'total_price' => $total,
            'shipping_price' => 10.00,
            'payment_method' => 'online',
            'payment_gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'shipping_method' => 'SCHEDULED',
            'address' => json_encode(['street' => 'Test St', 'city' => 'Cairo']),
        ]);

        if (empty($order->order_number)) {
            $order->update(['order_number' => 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT)]);
        }

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'status' => 'paid',
            'amount' => $total,
            'currency' => 'EGP',
            'payment_method' => 'online',
            'paid_at' => now(),
        ]);

        return $order->refresh();
    }

    protected function seedInvoice(User|string|null $for = null): Invoice
    {
        $user = $for instanceof User
            ? $for
            : ($for !== null ? $this->createCustomer($for) : ($this->customer ??= $this->createCustomer()));

        $order = $this->createOrderFor($user);

        return $this->invoiceService->generateFromOrder($order);
    }

    protected function createStaffUser(array $permissions = [], string $email = 'staff@invoice.test'): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        $user = User::create([
            'name' => 'Staff ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    protected function actingAsStaff(User $user): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user, ['*']);
    }
}
