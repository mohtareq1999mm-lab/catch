<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;

class DashboardDataSeeder extends Seeder
{
    private array $paymentMethods = [
        'cash_on_delivery', 'card', 'paypal', 'wallet', 'bank_transfer',
    ];

    private array $paymentWeights = [40, 25, 15, 12, 8];

    private array $orderStatuses = ['completed', 'pending', 'cancelled', 'delivered'];

    private array $statusWeights = [60, 15, 10, 15];

    private const COMPLETED_REFUND_RATE = 5;

    private const COUPON_USAGE_RATE = 15;

    public function run(): void
    {
        $this->command?->info('Seeding dashboard data...');

        $products = Product::all();
        $customers = User::where('type', 'user')->where('email', 'not like', '%@demo.com')->where('email', 'not like', '%@cms.com')->get();
        $coupons = Coupon::all();

        if ($products->isEmpty()) {
            $this->command?->warn('No products found. Skipping dashboard data.');
            return;
        }

        if ($customers->count() < 50) {
            $needed = 150 - $customers->count();
            $this->command?->warn("Only {$customers->count()} customer users found. Creating {$needed} sample customers.");
            $newCustomers = $this->createCustomers($needed);
            $customers = $customers->merge($newCustomers);
        }

        $orders = $this->createOrders($customers, $coupons, $products);
        $this->createTransactions($orders);
        $this->createRefunds($orders);
        $this->updateProducts($orders);
        $this->updateCouponUsageCounts();
        $this->createCarts($customers, $products);

        $this->command?->info('Dashboard data seeded successfully.');
    }

    private function createCustomers(int $count = 150): \Illuminate\Support\Collection
    {
        $customers = collect();
        $now = Carbon::now();

        $registrationDates = [];
        for ($i = 0; $i < $count; $i++) {
            $daysAgo = $this->weightedRandom(
                [0, 10, 30, 90, 180, 365, 540],
                [25, 20, 20, 15, 10, 5, 5],
                $i
            );
            $registrationDates[] = (clone $now)->subDays($daysAgo);
        }
        sort($registrationDates);

        foreach ($registrationDates as $index => $date) {
            $user = User::create([
                'name' => fake()->name(),
                'email' => 'dashboard_customer_' . ($index + 1) . '@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => $date->copy()->addMinutes(rand(1, 60)),
                'is_active' => $index < 130,
                'type' => 'user',
                'phone_number' => fake()->phoneNumber(),
                'created_at' => $date,
                'updated_at' => $date,
            ]);
            $user->assignRole('customer');
            $customers->push($user);
        }

        return $customers;
    }

    private function createOrders(\Illuminate\Support\Collection $customers, \Illuminate\Support\Collection $coupons, \Illuminate\Support\Collection $products): \Illuminate\Support\Collection
    {
        $orders = collect();
        $now = Carbon::now();
        $startDate = (clone $now)->subMonths(18);
        $totalOrders = 500;

        // Track users that already have a pending order to respect
        // partial unique index idx_orders_user_pending_unique (one pending per user).
        $pendingUserIds = Order::where('status', 'pending')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->flip()
            ->toArray();

        $this->command?->info("Creating {$totalOrders} orders over 18 months...");

        $orderDates = [];
        $ordersPerMonth = [
            40, 35, 45, 50, 42, 38, 55, 60, 48, 52, 58, 65,
            70, 62, 55, 68, 75, 80,
        ];

        $monthIndex = 0;
        foreach ($ordersPerMonth as $count) {
            $monthStart = (clone $startDate)->addMonths($monthIndex);
            $monthEnd = (clone $monthStart)->endOfMonth();
            for ($i = 0; $i < $count; $i++) {
                $orderDates[] = Carbon::createFromTimestamp(
                    rand($monthStart->timestamp, $monthEnd->timestamp)
                );
            }
            $monthIndex++;
        }

        $orderDates = collect($orderDates)->sort()->values();

        $bar = $this->command?->getOutput()?->createProgressBar($orderDates->count());

        foreach ($orderDates as $index => $date) {
            $customer = $customers->random();
            $status = $this->weightedPick($this->orderStatuses, $this->statusWeights, $index);

            // Respect partial unique index idx_orders_user_pending_unique (one pending per user)
            if ($status === 'pending') {
                if (isset($pendingUserIds[$customer->id])) {
                    // User already has a pending order – pick a non-pending status instead
                    $status = $this->weightedPick(
                        ['completed', 'cancelled', 'delivered'],
                        [60, 10, 15],
                        $index + 999
                    );
                } else {
                    $pendingUserIds[$customer->id] = true;
                }
            }

            $itemCount = $this->weightedRandom([1, 2, 3, 4, 5, 6], [20, 30, 25, 15, 7, 3], $index);

            $shippingPrice = round(rand(20, 150) + rand(0, 99) / 100, 2);
            $fastShipping = rand(0, 3) === 0 ? round(rand(30, 80) + rand(0, 99) / 100, 2) : 0;

            $totalPrice = 0;
            $orderProductsData = [];

            $governorateId = \Marvel\Database\Models\Governorate::inRandomOrder()->value('id');

            $usedProductIds = [];
            for ($j = 0; $j < $itemCount; $j++) {
                $product = $products->random();
                if (in_array($product->id, $usedProductIds)) {
                    continue;
                }
                $usedProductIds[] = $product->id;

                $qty = $this->weightedRandom([1, 2, 3, 4, 5], [50, 25, 15, 7, 3], $index * 10 + $j);
                $unitPrice = $product->price > 0 ? $product->price : round(rand(500, 50000) + rand(0, 99) / 100, 2);
                $lineTotal = round($unitPrice * $qty, 2);
                $totalPrice += $lineTotal;

                $productName = is_array($product->name) ? ($product->name['en'] ?? 'Product') : $product->name;

                $orderProductsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $productName,
                    'product_sku' => $product->sku ?? ('SKU-' . $product->id),
                    'product_quantity' => $qty,
                    'product_price' => $unitPrice,
                    'product_total_price' => $lineTotal,
                ];
            }

            $couponDiscount = 0;
            $couponCode = null;
            if ($coupons->isNotEmpty() && rand(1, 100) <= self::COUPON_USAGE_RATE) {
                $coupon = $coupons->random();
                $couponCode = $coupon->code;
                $couponDiscount = round($totalPrice * (rand(5, 25) / 100), 2);
                if ($coupon->max_discount_amount && $couponDiscount > $coupon->max_discount_amount) {
                    $couponDiscount = (float) $coupon->max_discount_amount;
                }
                $totalPrice = round($totalPrice - $couponDiscount, 2);

                try {
                    DB::table('coupon_usages')->insert([
                        'coupon_id' => $coupon->id,
                        'user_id' => $customer->id,
                        'order_id' => null,
                        'used_at' => $date,
                        'created_at' => $date,
                        'updated_at' => $date,
                    ]);
                } catch (\Exception $e) {
                    // Unique constraint violation: skip
                }
            }

            $totalPrice = round($totalPrice + $shippingPrice + $fastShipping, 2);

            try {
                $order = Order::create([
                    'user_id' => $customer->id,
                    'governorate_id' => $governorateId,
                    'name' => $customer->name,
                    'user_phone' => $customer->phone_number ?? '01000000000',
                    'user_email' => $customer->email,
                    'address' => json_encode([
                        'street' => fake()->streetAddress(),
                        'city' => fake()->city(),
                        'country' => 'Egypt',
                    ]),
                    'notes' => rand(0, 3) === 0 ? fake()->sentence() : null,
                    'shipping_method' => $fastShipping > 0 ? 'FAST' : 'SCHEDULED',
                    'expected_delivery_at' => (clone $date)->addDays(rand(1, 7)),
                    'fast_shipping_fee' => $fastShipping,
                    'shipping_price' => $shippingPrice,
                    'price' => round($totalPrice - $shippingPrice - $fastShipping, 2),
                    'total_price' => $totalPrice,
                    'coupon' => $couponCode,
                    'coupon_discount' => $couponDiscount > 0 ? $couponDiscount : null,
                    'status' => $status,
                    'created_at' => $date,
                    'updated_at' => $status === 'completed' ? (clone $date)->addHours(rand(1, 48)) : $date,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'idx_orders_user_pending_unique') || str_contains($e->getMessage(), 'pending_user_id')) {
                    // Race or stale pendingUserIds: mark user as pending and retry as completed
                    $pendingUserIds[$customer->id] = true;
                    $status = 'completed';
                    $order = Order::create([
                        'user_id' => $customer->id,
                        'governorate_id' => $governorateId,
                        'name' => $customer->name,
                        'user_phone' => $customer->phone_number ?? '01000000000',
                        'user_email' => $customer->email,
                        'address' => json_encode([
                            'street' => fake()->streetAddress(),
                            'city' => fake()->city(),
                            'country' => 'Egypt',
                        ]),
                        'notes' => rand(0, 3) === 0 ? fake()->sentence() : null,
                        'shipping_method' => $fastShipping > 0 ? 'FAST' : 'SCHEDULED',
                        'expected_delivery_at' => (clone $date)->addDays(rand(1, 7)),
                        'fast_shipping_fee' => $fastShipping,
                        'shipping_price' => $shippingPrice,
                        'price' => round($totalPrice - $shippingPrice - $fastShipping, 2),
                        'total_price' => $totalPrice,
                        'coupon' => $couponCode,
                        'coupon_discount' => $couponDiscount > 0 ? $couponDiscount : null,
                        'status' => $status,
                        'created_at' => $date,
                        'updated_at' => (clone $date)->addHours(rand(1, 48)),
                    ]);
                } else {
                    throw $e;
                }
            }

            foreach ($orderProductsData as $opData) {
                DB::table('order_products')->insert([
                    'order_id' => $order->id,
                    'product_id' => $opData['product_id'],
                    'product_name' => $opData['product_name'],
                    'product_sku' => $opData['product_sku'],
                    'product_quantity' => $opData['product_quantity'],
                    'product_price' => $opData['product_price'],
                    'product_total_price' => $opData['product_total_price'],
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }

            $orders->push($order);
            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine();

        return $orders;
    }

    private function createTransactions(\Illuminate\Support\Collection $orders): void
    {
        $this->command?->info('Creating transactions...');

        $completedOrders = $orders->whereIn('status', ['completed', 'delivered']);

        foreach ($completedOrders as $order) {
            $method = $this->weightedPick($this->paymentMethods, $this->paymentWeights, $order->id);
            Transaction::create([
                'order_id' => $order->id,
                'invoice_id' => 'INV-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT),
                'payment_method' => $method,
                'user_id' => $order->user_id,
                'status' => 'paid',
                'amount' => $order->total_price,
                'currency' => config('payment.default_currency', 'EGP'),
            ]);
        }
    }

    private function createRefunds(\Illuminate\Support\Collection $orders): void
    {
        $this->command?->info('Creating refunds...');

        $completedOrders = $orders->where('status', 'completed');
        $refundCount = (int) ceil($completedOrders->count() * self::COMPLETED_REFUND_RATE / 100);

        $refundableOrders = $completedOrders->random(min($refundCount, $completedOrders->count()));

        foreach ($refundableOrders as $index => $order) {
            $refundAmount = round($order->total_price * (rand(30, 100) / 100), 2);
            $statusRoll = rand(1, 100);
            $status = match (true) {
                $statusRoll <= 60 => 'approved',
                $statusRoll <= 85 => 'pending',
                default => 'rejected',
            };

            DB::table('refunds')->insert([
                'order_id' => $order->id,
                'amount' => $refundAmount,
                'title' => 'Refund for order #' . $order->id,
                'description' => fake()->sentence(),
                'status' => $status,
                'user_id' => $order->user_id,
                'created_at' => (clone $order->created_at)->addDays(rand(1, 14)),
                'updated_at' => (clone $order->created_at)->addDays(rand(1, 14)),
            ]);
        }
    }

    private function updateProducts(\Illuminate\Support\Collection $orders): void
    {
        $this->command?->info('Updating product quantities...');

        $completedOrderIds = $orders->whereIn('status', ['completed', 'delivered'])->pluck('id');

        $soldCounts = DB::table('order_products')
            ->whereIn('order_id', $completedOrderIds)
            ->selectRaw('product_id, SUM(product_quantity) as total_sold')
            ->groupBy('product_id')
            ->pluck('total_sold', 'product_id');

        foreach ($soldCounts as $productId => $totalSold) {
            $product = Product::find($productId);
            if ($product) {
                $remainingStock = max(0, ($product->stock_quantity ?? 50) - (int) $totalSold);
                $product->sold_quantity = (int) $totalSold;
                $product->quantity = $remainingStock;
                $product->save();
                DB::table('products')->where('id', $productId)->update([
                    'quantity' => $remainingStock,
                ]);
            }
        }

        Product::whereNull('sold_quantity')->orWhere('sold_quantity', 0)->chunk(100, function ($unsoldProducts) {
            foreach ($unsoldProducts as $product) {
                $defaultStock = $product->stock_quantity ?? random_int(10, 100);
                DB::table('products')->where('id', $product->id)->update([
                    'quantity' => $defaultStock,
                ]);
            }
        });
    }

    private function updateCouponUsageCounts(): void
    {
        $this->command?->info('Updating coupon usage counts...');

        $usageCounts = DB::table('coupon_usages')
            ->selectRaw('coupon_id, COUNT(*) as count')
            ->groupBy('coupon_id')
            ->pluck('count', 'coupon_id');

        foreach ($usageCounts as $couponId => $count) {
            Coupon::where('id', $couponId)->update(['used' => $count]);
        }
    }

    private function createCarts(\Illuminate\Support\Collection $customers, \Illuminate\Support\Collection $products): void
    {
        $this->command?->info('Creating carts...');

        $totalCarts = 200;
        $now = Carbon::now();
        $cartStatuses = ['active', 'expired', 'checked_out'];
        $cartWeights = [30, 40, 30];

        $usedUserIds = Cart::pluck('user_id')->toArray();

        for ($i = 0; $i < $totalCarts; $i++) {
            $customer = $customers->random();
            if (in_array($customer->id, $usedUserIds)) {
                continue;
            }
            $usedUserIds[] = $customer->id;

            $status = $this->weightedPick($cartStatuses, $cartWeights, $i);
            $createdAt = (clone $now)->subDays(rand(0, 60));
            $itemCount = rand(1, 5);
            $totalPrice = 0;

            $cart = Cart::create([
                'user_id' => $customer->id,
                'total_price' => 0,
                'status' => $status,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            for ($j = 0; $j < $itemCount; $j++) {
                $product = $products->random();
                $qty = rand(1, 3);
                $price = $product->price > 0 ? $product->price : round(rand(500, 50000) + rand(0, 99) / 100, 2);
                $lineTotal = round($price * $qty, 2);
                $totalPrice += $lineTotal;

                DB::table('cart_items')->insert([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'price' => $price,
                    'total_price' => $lineTotal,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            $cart->total_price = $totalPrice;
            $cart->save();
        }
    }

    private function weightedPick(array $items, array $weights, int $seed): mixed
    {
        $totalWeight = array_sum($weights);
        $random = rand(1, $totalWeight);
        $cumulative = 0;

        foreach ($items as $index => $item) {
            $cumulative += $weights[$index];
            if ($random <= $cumulative) {
                return $item;
            }
        }

        return $items[0];
    }

    private function weightedRandom(array $values, array $weights, int $seed): int
    {
        $totalWeight = array_sum($weights);
        $random = rand(1, $totalWeight);
        $cumulative = 0;

        foreach ($values as $index => $value) {
            $cumulative += $weights[$index];
            if ($random <= $cumulative) {
                return $value;
            }
        }

        return $values[0];
    }
}
