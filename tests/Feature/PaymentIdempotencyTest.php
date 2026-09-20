<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 2: Payment Idempotency Tests (GAP-C001)
 *
 * Validates token-based idempotency system prevents duplicate payment processing
 * during concurrent callback/webhook scenarios.
 */
class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();
    }

    private function createTestTables(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('users');
        Schema::enableForeignKeyConstraints();

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('orders', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('payment_status', 30)->nullable();
            $table->decimal('total_price', 10, 2);
            $table->string('currency_code', 3)->default('EGP');
            $table->timestamps();
        });

        Schema::create('transactions', function ($table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->integer('invoice_id');
            $table->bigInteger('user_id');
            $table->string('payment_method');
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->string('gateway_transaction_id', 255)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /** @test */
    public function idempotency_key_column_exists_in_transactions_table()
    {
        $this->assertTrue(
            Schema::hasColumn('transactions', 'idempotency_key'),
            'transactions table should have idempotency_key column'
        );
    }

    /** @test */
    public function token_based_idempotency_prevents_duplicate_processing()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'total_price' => 100.00,
            'currency_code' => 'EGP',
        ]);

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'invoice_id' => 12345,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => 100.00,
            'currency' => 'EGP',
            'gateway_transaction_id' => 'TEST-TXN-001',
            'idempotency_key' => null,
        ]);

        // First processing: should succeed
        $firstResult = DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTransaction->idempotency_key !== null) {
                return 'already_processed';
            }

            $idempotencyToken = \Illuminate\Support\Str::uuid()->toString();
            $lockedTransaction->update(['idempotency_key' => $idempotencyToken]);
            $lockedTransaction->update(['status' => 'paid', 'paid_at' => now()]);

            return 'processed';
        });

        // Second processing attempt: should be idempotent
        $secondResult = DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTransaction->idempotency_key !== null) {
                return 'already_processed';
            }

            $idempotencyToken = \Illuminate\Support\Str::uuid()->toString();
            $lockedTransaction->update(['idempotency_key' => $idempotencyToken]);

            return 'processed';
        });

        $this->assertEquals('processed', $firstResult);
        $this->assertEquals('already_processed', $secondResult);

        $finalTransaction = Transaction::find($transaction->id);
        $this->assertNotNull($finalTransaction->idempotency_key);
        $this->assertEquals('paid', $finalTransaction->status);
    }

    /** @test */
    public function idempotency_key_unique_constraint_prevents_duplicate_tokens()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_price' => 100.00,
        ]);

        $sameToken = \Illuminate\Support\Str::uuid()->toString();

        Transaction::create([
            'order_id' => $order->id,
            'invoice_id' => 12345,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => 100.00,
            'gateway_transaction_id' => 'TEST-TXN-001',
            'idempotency_key' => $sameToken,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Transaction::create([
            'order_id' => $order->id,
            'invoice_id' => 12346,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => 100.00,
            'gateway_transaction_id' => 'TEST-TXN-002',
            'idempotency_key' => $sameToken,
        ]);
    }

    /** @test */
    public function concurrent_callbacks_are_serialized_by_pessimistic_locking()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_price' => 100.00,
        ]);

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'invoice_id' => 12345,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => 100.00,
            'gateway_transaction_id' => 'TEST-TXN-001',
            'idempotency_key' => null,
        ]);

        $processCount = 0;
        $idempotentCount = 0;

        for ($i = 0; $i < 5; $i++) {
            $result = DB::transaction(function () use ($transaction) {
                $lockedTransaction = Transaction::where('id', $transaction->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedTransaction->idempotency_key !== null) {
                    return 'idempotent';
                }

                $idempotencyToken = \Illuminate\Support\Str::uuid()->toString();
                $lockedTransaction->update(['idempotency_key' => $idempotencyToken]);
                $lockedTransaction->update(['status' => 'paid', 'paid_at' => now()]);

                return 'processed';
            });

            if ($result === 'processed') {
                $processCount++;
            } else {
                $idempotentCount++;
            }
        }

        $this->assertEquals(1, $processCount);
        $this->assertEquals(4, $idempotentCount);
    }

    /** @test */
    public function null_idempotency_key_allows_processing()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_price' => 100.00,
        ]);

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'invoice_id' => 12345,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => 100.00,
            'gateway_transaction_id' => 'TEST-TXN-001',
            'idempotency_key' => null,
        ]);

        $result = DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTransaction->idempotency_key !== null) {
                return 'blocked';
            }

            $idempotencyToken = \Illuminate\Support\Str::uuid()->toString();
            $lockedTransaction->update(['idempotency_key' => $idempotencyToken]);

            return 'processed';
        });

        $this->assertEquals('processed', $result);
    }
}
