<?php

namespace Tests\Unit;

use Database\Seeders\OrderFlowSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Tests\TestCase;

/**
 * Every newly created order carries its Order Number (ORD-XXXXXXXX,
 * derived from the row id) — the column shipped with a backfill for
 * historical rows, but no creation path ever wrote it, so all orders
 * created since the column landed carry NULL while a dozen readers
 * (invoice snapshot, notifications, tracking, claim, WMS) display it.
 *
 * The generation is a model backstop (same discipline as the flow-id
 * creation backstop): it covers every creation path — checkout service,
 * Marvel repository, seeder, fixture, future code — and never overwrites
 * an explicitly set number.
 *
 * The discriminating assertion is the RAW column read: the
 * getOrderNumberAttribute accessor derives the same string for a NULL
 * column, so accessor-level assertions pass even when the backstop is
 * dead — only the database itself proves persistence.
 */
class OrderNumberGenerationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The creating backstop resolves the order flow; the catalog must
        // exist for creation to succeed on a freshly migrated database.
        (new OrderFlowSeeder())->run();
    }

    /** @test */
    public function a_newly_created_order_persists_its_order_number_in_the_database(): void
    {
        $order = $this->createOrder();

        // Ids above 99,999,999 are legal (string(20) column): the backstop
        // pads to a MINIMUM of 8 digits and never truncates.
        $this->assertMatchesRegularExpression('/^ORD-\d{8,}$/', $order->order_number);
        $prefixLength = strlen('ORD-');
        $this->assertSame($order->id, (int) substr($order->order_number, $prefixLength), 'The number derives from the row id, matching the historical backfill format.');

        // Persistence, not the accessor: the column itself must carry the
        // value. Raw consumers — the order metrics VIEW, SQL joins,
        // where('order_number') searches — read this column directly; an
        // accessor-only derivation would leave it NULL for them.
        $raw = DB::table('orders')->where('id', $order->id)->value('order_number');
        $this->assertSame($order->order_number, $raw, 'The order number must be persisted in the column, not derived by the accessor.');
    }

    /** @test */
    public function an_explicitly_set_order_number_is_never_overwritten(): void
    {
        $order = Order::create($this->orderAttributes() + ['order_number' => 'ORD-CUSTOM-1']);

        $this->assertSame('ORD-CUSTOM-1', $order->fresh()->order_number);
    }

    /** @test */
    public function an_empty_explicit_order_number_is_still_backstopped(): void
    {
        // empty() semantics on the RAW attribute: an explicitly EMPTY string
        // is not a real number — the backstop fills it instead of persisting
        // an empty column value that the accessor would then return as ''.
        $order = Order::create($this->orderAttributes() + ['order_number' => '']);

        $this->assertMatchesRegularExpression('/^ORD-\d{8,}$/', DB::table('orders')->where('id', $order->id)->value('order_number'));
    }

    /** @test */
    public function distinct_orders_receive_distinct_numbers(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();

        $this->assertNotSame($first->order_number, $second->order_number);
    }

    private function createOrder(): Order
    {
        return Order::create($this->orderAttributes());
    }

    private function orderAttributes(): array
    {
        return [
            'name' => 'Order Number Test',
            'user_phone' => '+201000000099',
            'user_email' => 'order-number-test@example.test',
            'address' => ['street' => '1 Test Street', 'city' => 'Test City'],
            'shipping_price' => 0,
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
        ];
    }
}
