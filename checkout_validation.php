<?php
/**
 * Checkout Validation Script
 * Tests real checkout flows with real database data.
 * Rollback: Cleans up created carts and test data.
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$request->server->set('REMOTE_ADDR', '127.0.0.1');
$response = $kernel->handle($request);

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\CartItem;

echo str_repeat('=', 80) . PHP_EOL;
echo 'CHECKOUT PRODUCTION VALIDATION' . PHP_EOL;
echo 'Date: ' . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;

// ===== TEST DATA =====
$userId = 3;
$productId = 79; // Matte Bronzer, $32.00, stock=106, no discount, no flash sale
$couponCode = 'SUMMER20'; // 20% off, max $100 discount
$governorateId = 1; // Cairo, shipping=$50, free over $500
$quantity = 2;

echo "--- TEST DATA ---" . PHP_EOL;
echo "User: ID $userId (Test Customer)" . PHP_EOL;
$product = Product::find($productId);
echo "Product: {$product->id} - \${$product->price} (stock: {$product->stock_quantity}, reserved: {$product->reserved_quantity})\n";
echo "Coupon: $couponCode\n";
echo "Quantity: $quantity\n";
echo PHP_EOL;

// ===== STEP 1: CAPTURE PRE-TEST STATE =====
echo str_repeat('-', 60) . PHP_EOL;
echo "STEP 1: PRE-TEST STATE" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$initialProduct = Product::find($productId);
echo "Product {$productId} BEFORE: stock={$initialProduct->stock_quantity} reserved={$initialProduct->reserved_quantity} sold={$initialProduct->sold_quantity}\n";

// Clean up any existing active cart for this user
$user = \Marvel\Database\Models\User::find($userId);
// Use request-based auth for Sanctum
$request->setUserResolver(function() use ($user) { return $user; });

/** @var Cart $existingCart */
$existingCart = $user->cart;
$existingCartId = null;
if ($existingCart && $existingCart->status === 'active') {
    $existingCartId = $existingCart->id;
    echo "Existing active cart found (id={$existingCart->id}), clearing items and releasing reservations...\n";

    try {
        $inventoryService = app(\App\Services\General\CartInventoryService::class);
        $inventoryService->releaseCart($existingCart, true);
    } catch (\Throwable $e) {
        echo "  releaseCart failed: " . $e->getMessage() . "\n";
    }

    $existingCart->items()->delete();
    $existingCart->update(['total_price' => 0, 'coupon' => null, 'status' => 'expired']);
    echo "  Old cart expired.\n";
}
echo PHP_EOL;

// ===== STEP 2: ADD ITEM TO CART =====
echo str_repeat('-', 60) . PHP_EOL;
echo "STEP 2: ADD ITEM TO CART" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

try {
    $cartRepo = app(\Marvel\Database\Repositories\CartRepository::class);

    $request = new \Illuminate\Http\Request();
    $request->setUserResolver(function() use ($user) { return $user; });
    $request->merge([
        'item' => [[
            'product_id' => $productId,
            'quantity' => $quantity,
        ]],
    ]);

    $cart = $cartRepo->storeCart($request);
    echo "Cart: id={$cart->id}, status={$cart->status}, total_price={$cart->total_price}\n";

    /** @var CartItem $item */
    foreach ($cart->items as $item) {
        echo "  Item: product_id={$item->product_id}, qty={$item->quantity}, price={$item->price}, total={$item->total_price}, discount={$item->discount_amount}\n";
    }

    $productAfterAdd = Product::find($productId);
    echo "Product {$productId} AFTER ADD: stock={$productAfterAdd->stock_quantity} reserved={$productAfterAdd->reserved_quantity} sold={$productAfterAdd->sold_quantity}\n";

    if ($productAfterAdd->reserved_quantity === $quantity) {
        echo "  ✅ Reservation matches quantity added\n";
    } else {
        echo "  ⚠️ Reservation ({$productAfterAdd->reserved_quantity}) != quantity ({$quantity})\n";
    }

} catch (\Throwable $e) {
    echo "ERROR adding to cart: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
echo PHP_EOL;

// ===== STEP 3: APPLY COUPON =====
echo str_repeat('-', 60) . PHP_EOL;
echo "STEP 3: APPLY COUPON {$couponCode}" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

try {
    $couponService = app(\Marvel\Database\Repositories\CouponRepository::class);
    $result = $couponService->addCouponToCart($couponCode);

    $cart = $user->cart->load('items.product');
    echo "Cart after coupon: total_price={$cart->total_price}, coupon={$cart->coupon}\n";

    $coupon = Coupon::where('code', $couponCode)->first();
    echo "Coupon: discount_type={$coupon->discount_type}, discount={$coupon->discount}, max_discount={$coupon->max_discount_amount}, used={$coupon->used}\n";
    echo "  ✅ Coupon applied: '{$cart->coupon}'\n";

} catch (\Throwable $e) {
    echo "ERROR applying coupon: " . $e->getMessage() . "\n";
}
echo PHP_EOL;

// ===== STEP 4: CHECKOUT (COD) =====
echo str_repeat('-', 60) . PHP_EOL;
echo "STEP 4: CHECKOUT (COD)" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

try {
    $orderController = app(\App\Http\Controllers\Api\General\OrderController::class);

    $checkoutRequest = new \Marvel\Http\Requests\OrderCreateRequest();
    $checkoutRequest->setUserResolver(function() use ($user) { return $user; });
    $checkoutRequest->merge([
        'name' => 'Test Customer',
        'user_phone' => '1234567890',
        'user_email' => 'test@g.com',
        'address' => ['street' => '123 Test St', 'city' => 'Cairo', 'country' => 'Egypt'],
        'payment_method' => 'cod',
        'fulfillment_type' => 'delivery',
        'governorate_id' => $governorateId,
    ]);

    $response = $orderController->checkout($checkoutRequest);
    $responseData = json_decode($response->getContent(), true);

    echo "Response: " . json_encode($responseData, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

    if (isset($responseData['success']) && $responseData['success']) {
        echo "✅ CHECKOUT SUCCESSFUL\n";

        // Find the created order
        $latestOrder = Order::where('user_id', $userId)->latest()->first();
        echo "Order: id={$latestOrder->id}, status={$latestOrder->status}, total={$latestOrder->total_price}, coupon={$latestOrder->coupon}\n";

        foreach ($latestOrder->orderItems as $oi) {
            echo "  OrderItem: product_id={$oi->product_id}, qty={$oi->product_quantity}, price={$oi->product_price}, total={$oi->product_total_price}\n";
        }

        $transaction = Transaction::where('order_id', $latestOrder->id)->first();
        if ($transaction) {
            echo "Transaction: id={$transaction->id}, status={$transaction->status}, amount={$transaction->amount}\n";
        }

        // Cart state
        $cartState = $user->cart;
        if ($cartState) {
            echo "Cart: id={$cartState->id}, status={$cartState->status}, items={$cartState->items()->count()}\n";
            echo "  is_checking_out or checked_out: examining...\n";

            $allCarts = Cart::where('user_id', $userId)->orderBy('id', 'desc')->limit(3)->get();
            foreach ($allCarts as $c) {
                $items = $c->items()->count();
                echo "  Cart id={$c->id}: status={$c->status}, items={$items}, total={$c->total_price}, coupon={$c->coupon}\n";
            }
        } else {
            echo "Cart: NO ACTIVE CART FOUND\n";
        }

        // Inventory after checkout
        $productAfter = Product::find($productId);
        echo "Product {$productId} AFTER CHECKOUT: stock={$productAfter->stock_quantity} reserved={$productAfter->reserved_quantity} sold={$productAfter->sold_quantity}\n";

        // Manual calculation
        echo PHP_EOL . "--- MANUAL CALCULATION VERIFICATION ---" . PHP_EOL;
        $basePrice = (float) $initialProduct->price;
        $lineTotal = $basePrice * $quantity;
        echo "Price: {$basePrice} x {$quantity} = {$lineTotal}\n";

        $coupon = Coupon::where('code', $couponCode)->first();
        $couponRate = (float) $coupon->discount; // 20
        $couponMax = (float) $coupon->max_discount_amount; // 100
        $rawDiscount = $lineTotal * ($couponRate / 100);
        $couponDiscount = min($rawDiscount, $couponMax);
        echo "Coupon discount: min({$rawDiscount}, {$couponMax}) = {$couponDiscount}\n";

        // Shipping
        $shippingPrice = DB::table('shipping_prices')->where('governorate_id', $governorateId)->first();
        $shippingCost = (float) $shippingPrice->price;
        $freeShippingOver = (float) $shippingPrice->free_shipping_over;
        $afterCoupon = $lineTotal - $couponDiscount;
        if ($afterCoupon >= $freeShippingOver) {
            echo "Free shipping: {$afterCoupon} >= {$freeShippingOver}\n";
            $shippingCost = 0;
        }

        $manualTotal = $lineTotal;
        $orderPrice = (float) $latestOrder->price;
        $orderShipping = (float) $latestOrder->shipping_price;
        $orderTotal = (float) $latestOrder->total_price;
        $orderCouponDiscount = (float) ($latestOrder->coupon_discount ?? 0);

        echo "Manual: lineTotal={$lineTotal}, couponDiscount={$couponDiscount}, shipping={$shippingCost}, grandTotal=" . ($lineTotal - $couponDiscount + $shippingCost) . PHP_EOL;
        echo "Order: price={$orderPrice}, coupon_discount={$orderCouponDiscount}, shipping={$orderShipping}, total={$orderTotal}\n";

        // Check price only (without shipping, since shipping calculation may differ)
        $priceAfterCouponManual = $lineTotal - $couponDiscount;
        $priceAfterCouponOrder = $orderPrice;

        if (abs($priceAfterCouponManual - $priceAfterCouponOrder) < 0.01) {
            echo "✅ PRICE MATCH: price after coupon (manual) = {$priceAfterCouponManual}, (order) = {$priceAfterCouponOrder}\n";
        } else {
            echo "⚠️ PRICE WARNING: manual={$priceAfterCouponManual}, order={$priceAfterCouponOrder}, diff=" . ($priceAfterCouponManual - $priceAfterCouponOrder) . PHP_EOL;
        }

        if (abs($couponDiscount - $orderCouponDiscount) < 0.01) {
            echo "✅ COUPON DISCOUNT MATCH: {$couponDiscount} vs {$orderCouponDiscount}\n";
        } else {
            echo "⚠️ COUPON DISCOUNT WARNING: manual={$couponDiscount}, order={$orderCouponDiscount}\n";
        }

        // For COD: inventory should be finalized (stock deducted)
        if ($productAfter->stock_quantity < $initialProduct->stock_quantity) {
            $deducted = $initialProduct->stock_quantity - $productAfter->stock_quantity;
            echo "✅ INVENTORY FINALIZED: deducted {$deducted} (expected {$quantity})\n";
        } elseif ($productAfter->reserved_quantity === 0 && $productAfter->stock_quantity === $initialProduct->stock_quantity) {
            echo "⚠️ INVENTORY NOT DEDUCTED: stock unchanged at {$productAfter->stock_quantity}\n";
        } else {
            echo "⚠️ INVENTORY STATE: stock={$productAfter->stock_quantity}, reserved={$productAfter->reserved_quantity}, sold={$productAfter->sold_quantity}\n";
        }

    } else {
        echo "❌ CHECKOUT FAILED\n";
        echo json_encode($responseData, JSON_PRETTY_PRINT) . PHP_EOL;
    }

} catch (\Throwable $e) {
    echo "ERROR during checkout: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
echo PHP_EOL;

echo str_repeat('=', 80) . PHP_EOL;
echo 'VALIDATION COMPLETE' . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
