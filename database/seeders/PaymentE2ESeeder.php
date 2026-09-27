<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\DTOs\CheckoutTotals;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;

/**
 * Stripe + PayPal end-to-end verification seeder (MySQL `catch`).
 *
 * SAFETY CONTRACT:
 * - Additive only. Uses firstOrCreate / find-first by deterministic markers.
 *   Never updates or deletes pre-existing rows (users, settings, currencies,
 *   coupons, promotions, or any order/transaction created outside this seeder).
 * - Idempotent: running twice reuses the same rows (unique keys: customer
 *   email, product slug, address title+customer, order notes marker).
 * - The catalog currency is NEVER permanently changed. The single KWD
 *   precision order is created inside a set/restore block (finally-guaranteed).
 * - No secrets, card data, or provider credentials are written anywhere.
 */
class PaymentE2ESeeder extends Seeder
{
    public const MARKER = 'PAYMENT_E2E_20260927';

    public const CUSTOMER_EMAIL = 'payment-e2e-customer@example.test';

    public const PRODUCT_SLUG = 'stripe-paypal-e2e-test-product';

    public function run(): void
    {
        // NOTE: `orders.idx_orders_user_pending_unique` enforces ONE pending
        // order per user, so each scenario owns a deterministic customer
        // (and, by carts.user_id unique, its own cart).
        $stripeCustomer = $this->customer('payment-e2e-customer@example.test', 'Payment E2E Customer');
        $paypalCustomer = $this->customer('payment-e2e-paypal@example.test', 'Payment E2E PayPal');
        $genericCustomer = $this->customer('payment-e2e-generic@example.test', 'Payment E2E Generic');
        $kwdCustomer = $this->customer('payment-e2e-kwd@example.test', 'Payment E2E KWD');

        $product = Product::firstOrCreate(
            ['slug' => self::PRODUCT_SLUG],
            [
                'name' => ['en' => 'Stripe PayPal E2E Test Product', 'ar' => 'منتج اختبار الدفع'],
                'description' => ['en' => 'Deterministic E2E verification product', 'ar' => 'منتج التحقق'],
                'sku' => 'PAYE2E-001',
                'price' => 100.00,
                'product_type' => 'simple',
                'item_type' => 'PHYSICAL',
                'quantity' => 500,
                'stock_quantity' => 500,
                'reserved_quantity' => 0,
                'in_stock' => 1,
                'status' => 1,
            ]
        );
        $this->command->info("product id={$product->id} slug={$product->slug} stock_quantity={$product->stock_quantity}");

        $governorateId = DB::table('governorates')->orderBy('id')->value('id');

        Address::firstOrCreate(
            ['title' => self::MARKER, 'customer_id' => $stripeCustomer->id],
            [
                'address' => ['street' => '123 E2E Street', 'city' => 'Kuwait City', 'postal' => '00000'],
                'governorate_id' => $governorateId,
            ]
        );
        $this->command->info("address ensured for customer id={$stripeCustomer->id}");

        $service = app(OrderCreationService::class);

        foreach ([
            'stripe-pending' => [$stripeCustomer, 'stripe'],
            'paypal-pending' => [$paypalCustomer, 'paypal'],
            'generic-pending' => [$genericCustomer, null],
        ] as $scenario => [$scenarioCustomer, $gateway]) {
            $notes = self::MARKER . ':' . $scenario;
            $existing = Order::query()
                ->where('user_id', $scenarioCustomer->id)
                ->where('notes', $notes)
                ->where('status', 'pending')
                ->first();

            if ($existing) {
                $this->command->info("order reuse scenario={$scenario} id={$existing->id} total={$existing->total_price} currency={$existing->currency_code}");
                continue;
            }

            $order = $this->createRealOrder($service, $scenarioCustomer, 100.0, $notes, $gateway);
            $this->command->info("order created scenario={$scenario} id={$order->id} number={$order->order_number} total={$order->total_price} currency={$order->currency_code} catalog={$order->catalog_currency_code}");
        }

        $this->createKwdPrecisionOrder($service, $kwdCustomer);
    }

    private function customer(string $email, string $name): User
    {
        $customer = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('payment-e2e'),
                'email_verified_at' => now(),
                'is_active' => true,
                'type' => 'user',
            ]
        );
        $this->command->info("customer id={$customer->id} email={$customer->email}");

        return $customer;
    }

    private function orderData(User $customer, float $subtotal, string $notes, ?string $gateway): array
    {
        return [
            'user_id' => $customer->id,
            'name' => 'Payment E2E Customer',
            'user_phone' => '01000000000',
            'user_email' => $customer->email,
            'address' => '123 E2E Street, Kuwait City',
            'notes' => $notes,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'payment_gateway' => $gateway,
        ];
    }

    private function createRealOrder(
        OrderCreationService $service,
        User $customer,
        float $subtotal,
        string $notes,
        ?string $gateway,
    ): Order {
        // carts.user_id is UNIQUE (one cart row per user): reuse it and
        // refresh the working total instead of inserting a second cart.
        $cart = Cart::firstOrCreate(
            ['user_id' => $customer->id],
            ['status' => 'active', 'total_price' => $subtotal],
        );
        if ((float) $cart->total_price !== (float) $subtotal) {
            $cart->update(['total_price' => $subtotal, 'status' => 'active']);
        }

        $order = $service->createOrder(
            orderData: $this->orderData($customer, $subtotal, $notes, $gateway),
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        if (!$order) {
            throw new \RuntimeException("OrderCreationService returned null for {$notes}");
        }

        return $order;
    }

    /**
     * KWD 13.255 precision order. The catalog switch is restored in `finally`
     * so the shared `catch` settings row is never permanently altered.
     */
    private function createKwdPrecisionOrder(OrderCreationService $service, User $customer): void
    {
        $notes = self::MARKER . ':kwd-precision';
        $existing = Order::query()
            ->where('user_id', $customer->id)
            ->where('notes', $notes)
            ->first();

        if ($existing) {
            $this->command->info("order reuse scenario=kwd-precision id={$existing->id} total={$existing->total_price} currency={$existing->currency_code}");
            return;
        }

        $currencyService = app(CurrencyService::class);
        $previousCode = $currencyService->getCatalogCode();
        $kwd = Currency::query()->where('code', 'KWD')->firstOrFail();

        // setCatalogCurrency() invalidates tagged caches; the local .env uses
        // CACHE_DRIVER=file (no tags support). Override to array for THIS
        // process only — the settings-row write itself still hits MySQL.
        $previousCacheDriver = config('cache.default');
        config(['cache.default' => 'array']);

        try {
            $currencyService->setCatalogCurrency($kwd);
            $order = $this->createRealOrder($service, $customer, 13.255, $notes, null);
            $this->command->info("order created scenario=kwd-precision id={$order->id} total={$order->total_price} currency={$order->currency_code} catalog={$order->catalog_currency_code}");
        } finally {
            try {
                $restore = Currency::query()->where('code', $previousCode)->first();
                if ($restore) {
                    $currencyService->setCatalogCurrency($restore);
                }
            } finally {
                config(['cache.default' => $previousCacheDriver]);
                app()->forgetInstance(CurrencyService::class);
            }
        }

        $after = app(CurrencyService::class)->getCatalogCode();
        $this->command->info("catalog restored to {$after} (was {$previousCode})");
    }
}
