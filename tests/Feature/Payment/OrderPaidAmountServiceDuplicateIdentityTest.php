<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\CodAmountEvidenceException;
use App\Models\Currency;
use App\Services\Payment\OrderPaidAmountService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * #163: duplicate idempotency evidence is prevented by the live unique index.
 * These regression tests remove that index after a single fresh migration.
 * Rollback is skipped because unrelated legacy migrations cannot roll back
 * foreign-key-backed indexes; the next migrate:fresh resets the schema anyway.
 */
final class OrderPaidAmountServiceDuplicateIdentityTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        DB::statement('ALTER TABLE `transactions` DROP INDEX `transactions_idempotency_key_unique`');
    }

    private function usd(): Currency
    {
        return Currency::create([
            'code' => 'USD',
            'name' => ['en' => 'USD Currency', 'ar' => 'USD'],
            'symbol' => ['en' => '$', 'ar' => '$'],
            'country_name' => ['en' => 'Country', 'ar' => 'دولة'],
            'numeric_code' => '840',
            'decimal_places' => 2,
            'icon' => 'usd',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function order(): Order
    {
        $customer = User::create([
            'name' => 'Test Customer',
            'email' => 'customer-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
        ]);

        return Order::create([
            'user_id' => $customer->id,
            'name' => 'COD Evidence Customer',
            'user_email' => $customer->email,
            'user_phone' => '01000000000',
            'status' => Order::ORDER_STATUS_PENDING,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
    }

    private function capture(Order $order, float $amount, string $idempotencyKey): void
    {
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => $amount,
            'currency' => $order->currency_code,
            'capture_state' => 'succeeded',
            'gateway_transaction_id' => null,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function test_idempotency_key_dedupe_dedupes_equal_amounts_and_fails_closed_on_conflicts(): void
    {
        $this->usd();
        $service = app(OrderPaidAmountService::class);

        $sameOrder = $this->order();
        $this->capture($sameOrder, 30, 'idem-same');
        $this->capture($sameOrder, 30, 'idem-same');

        $amount = $service->forOrder($sameOrder);
        $this->assertSame(3000, $amount->capturedMinor);
        $this->assertSame(7000, $amount->collectibleMinor);

        $conflictOrder = $this->order();
        $this->capture($conflictOrder, 20, 'idem-conflict');
        $this->capture($conflictOrder, 30, 'idem-conflict');

        try {
            $service->forOrder($conflictOrder);
            $this->fail('Expected duplicate idempotency identity with conflicting amounts to fail closed.');
        } catch (CodAmountEvidenceException $exception) {
            $this->assertSame(__('cod_amount.inconsistent_evidence'), $exception->getMessage());
        }
    }
}
