<?php


namespace Marvel\Database\Repositories;

use Carbon\Carbon;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderWalletPoint;
use Marvel\Database\Models\Wallet;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Variation;
use Marvel\Enums\CouponType;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Enums\ProductType;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\PaymentStatus;
use Marvel\Events\OrderProcessed;
use Marvel\Events\OrderReceived;
use Marvel\Exceptions\MarvelBadRequestException;
use Marvel\Traits\CalculatePaymentTrait;
use Marvel\Traits\OrderManagementTrait;
use Marvel\Traits\OrderStatusManagerWithPaymentTrait;
use Marvel\Traits\PaymentTrait;
use Marvel\Traits\WalletsTrait;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class OrderRepository extends BaseRepository
{
    use WalletsTrait,
        CalculatePaymentTrait,
        OrderManagementTrait,
        OrderStatusManagerWithPaymentTrait,
        PaymentTrait;
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'tracking_number' => 'like',
        'shop_id',
        'language',
        'shipping_method',
    ];
    /**
     * @var string[]
     */
    protected array $dataArray = [
        'tracking_number',
        'customer_id',
        'shop_id',
        'language',
        'order_status',
        'payment_status',
        'amount',
        'sales_tax',
        'paid_total',
        'total',
        'delivery_time',
        'payment_gateway',
        'altered_payment_gateway',
        'discount',
        'coupon_id',
        'logistics_provider',
        'billing_address',
        'shipping_address',
        'delivery_fee',
        'customer_contact',
        'customer_name',
        'note',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Order::class;
    }

    /**
     * Store order
     *
     * @param $request
     * @param $settings
     * @return LengthAwarePaginator|JsonResponse|Collection|mixed
     * @throws Exception
     */
    public function storeOrder($request, $settings): mixed
    {
        $request['tracking_number'] = $this->generateTrackingNumber();
        // $request->merge([
        //     'payable'         => $request['paid_total'], // amount to be paid through paymentGateway
        //     'wallet_currency' => 0
        // ]);

        $fullWalletOrCODPayment = $request?->isFullWalletPayment ? PaymentGatewayType::FULL_WALLET_PAYMENT : PaymentGatewayType::CASH_ON_DELIVERY;
        $payment_gateway_type = !empty($request->payment_gateway) ? $request->payment_gateway : $fullWalletOrCODPayment;

        switch ($payment_gateway_type) {
            case PaymentGatewayType::CASH_ON_DELIVERY:
                $request['order_status'] = OrderStatus::PROCESSING;
                $request['payment_status'] = PaymentStatus::CASH_ON_DELIVERY;
                break;

            case PaymentGatewayType::CASH:
                $request['order_status'] = OrderStatus::PROCESSING;
                $request['payment_status'] = PaymentStatus::CASH;
                break;

            // case PaymentGatewayType::FULL_WALLET_PAYMENT:
            //     $request['order_status'] = OrderStatus::PROCESSING;
            //     $request['payment_status'] = PaymentStatus::WALLET;
            //     break;

            default:
                $request['order_status'] = OrderStatus::PENDING;
                $request['payment_status'] = PaymentStatus::PENDING;
                break;
        }

        $useWalletPoints = isset($request->use_wallet_points) ? $request->use_wallet_points : false;
        if ($request->user() && $request->user()->hasRole(Role::SUPER_ADMIN) && isset($request['customer_id'])) {
            $request['customer_id'] = $request['customer_id'];
        } else {
            $request['customer_id'] = $request->user()->id ?? null;
        }
        try {
            $user = User::findOrFail($request['customer_id']);
            if ($user) {
                $request['customer_name'] = $user->name;
            }
        } catch (Exception $e) {
            $user = null;
        }

        if (!$user) {
            $settings = Settings::getData($request->language);
            if (isset($settings->options['guestCheckout']) && !$settings->options['guestCheckout']) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
        }
        $request['amount'] = $this->calculateSubtotal($request['products']);

        // STOCK VALIDATION: Lock and validate stock BEFORE proceeding
        // This prevents overselling via race conditions
        $this->validateAndLockStock($request['products']);

        if (isset($request->coupon_id)) {
            try {
                $coupon = Coupon::findOrFail($request['coupon_id']);

                // Validate coupon usage limits and conditions
                $this->validateCouponUsage($coupon, $request['amount'], $request->user());

                $request['discount'] = $this->calculateDiscount($coupon, $request['amount']);
            } catch (Exception $th) {
                throw $th;
            }
        }

        if (isset($coupon) && $coupon->type === CouponType::FREE_SHIPPING_COUPON) {
            $request['delivery_fee'] = 0;
        } else {
            $request['delivery_fee'] = $request['delivery_fee'];
        }

        $request['paid_total'] = $request['amount'] + $request['sales_tax'] + $request['delivery_fee'] - $request['discount'];
        $request['total'] = $request['paid_total'];
        if (($useWalletPoints || $request->isFullWalletPayment) && $user) {
            $wallet = $user->wallet;
            $amount = null;
            if (isset($wallet->available_points)) {
                $amount = round($request['paid_total'], 2) - $this->walletPointsToCurrency($wallet->available_points);
            }

            if ($amount !== null && $amount <= 0) {
                $request['order_status'] = OrderStatus::COMPLETED;
                $request['payment_gateway'] = PaymentGatewayType::FULL_WALLET_PAYMENT;
                $request['payment_status'] = PaymentStatus::SUCCESS;
                $order = $this->createOrder($request);
                // Deduct stock after order creation
                $this->deductStock($request['products']);
                // Record coupon usage
                if (isset($request['coupon_id'])) {
                    $this->recordCouponUsage($request['coupon_id'], $order->id, $user?->id);
                }
                $this->storeOrderWalletPoint($request['paid_total'], $order->id);
                $this->manageWalletAmount($request['paid_total'], $user->id);
                return $order;
            }
        } else {
            $amount = round($request['paid_total'], 2);
        }

        $order = $this->createOrder($request);

        // Deduct stock after successful order creation
        $this->deductStock($request['products']);

        // Record coupon usage
        if (isset($request['coupon_id'])) {
            $this->recordCouponUsage($request['coupon_id'], $order->id, $request->user()?->id);
        }

        if (($useWalletPoints || $request->isFullWalletPayment) && $user) {
            $this->storeOrderWalletPoint(round($request['paid_total'], 2) - $amount, $order->id);
            $this->manageWalletAmount(round($request['paid_total'], 2), $user->id);
        }

        $eligible = $this->checkOrderEligibility();
        if (!$eligible) {
            throw new MarvelBadRequestException('COULD_NOT_PROCESS_THE_ORDER_PLEASE_CONTACT_WITH_THE_ADMIN');
        }
        // Create Intent
        if (
            !in_array($order->payment_gateway, [
                PaymentGatewayType::CASH,
                PaymentGatewayType::CASH_ON_DELIVERY,
                PaymentGatewayType::FULL_WALLET_PAYMENT
            ])
        ) {
            $order['payment_intent'] = $this->processPaymentIntent($request, $settings);
        }


        if ($payment_gateway_type === PaymentGatewayType::CASH_ON_DELIVERY || $payment_gateway_type === PaymentGatewayType::CASH) {
            $this->orderStatusManagementOnCOD($order, OrderStatus::PENDING, OrderStatus::PROCESSING);
        } else {
            $this->orderStatusManagementOnPayment($order, OrderStatus::PENDING, PaymentStatus::PENDING);
        }

        event(new OrderProcessed($order));

        return $order;
    }

    /**
     * Validate stock availability and lock products for update.
     * This prevents race conditions where multiple orders could oversell.
     *
     * @param array $products
     * @throws MarvelBadRequestException
     */
    protected function validateAndLockStock(array $products): void
    {
        // Get unique product IDs
        $productIds = collect($products)->pluck('product_id')->unique()->toArray();

        // Lock products for update to prevent race conditions
        $dbProducts = Product::whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($products as $item) {
            $productId = $item['product_id'];
            $orderQuantity = $item['order_quantity'] ?? 1;
            $variationId = $item['variation_option_id'] ?? null;

            $product = $dbProducts->get($productId);

            if (!$product) {
                throw new MarvelBadRequestException("Product not found: {$productId}");
            }

            // Check if product is a rental product (no stock check needed)
            if ($product->is_rental) {
                continue;
            }

            // For variable products, check variation stock
            if ($variationId) {
                $variation = Variation::where('id', $variationId)
                    ->lockForUpdate()
                    ->first();

                if (!$variation) {
                    throw new MarvelBadRequestException("Product variation not found");
                }

                if ($variation->quantity < $orderQuantity) {
                    throw new MarvelBadRequestException(
                        "Insufficient stock for {$product->name} (variation). " .
                        "Available: {$variation->quantity}, Requested: {$orderQuantity}"
                    );
                }
            } else {
                // Simple product - check main product stock
                if ($product->quantity < $orderQuantity) {
                    throw new MarvelBadRequestException(
                        "Insufficient stock for {$product->name}. " .
                        "Available: {$product->quantity}, Requested: {$orderQuantity}"
                    );
                }
            }
        }
    }

    /**
     * Deduct stock for ordered products.
     * Should be called within a transaction after order creation.
     *
     * @param array $products
     */
    protected function deductStock(array $products): void
    {
        foreach ($products as $item) {
            $productId = $item['product_id'];
            $orderQuantity = $item['order_quantity'] ?? 1;
            $variationId = $item['variation_option_id'] ?? null;

            $product = Product::find($productId);

            // Skip stock deduction for rental products
            if (!$product || $product->is_rental) {
                continue;
            }

            if ($variationId) {
                Variation::where('id', $variationId)
                    ->decrement('stock_quantity', $orderQuantity);
            } else {
                Product::where('id', $productId)
                    ->decrement('stock_quantity', $orderQuantity);
            }
        }
    }

    /**
     * Validate coupon usage limits, date validity, and minimum cart amount.
     * Throws exception if coupon cannot be used.
     *
     * @param Coupon $coupon
     * @param float $cartAmount
     * @param User|null $user
     * @throws MarvelBadRequestException
     */
    protected function validateCouponUsage(Coupon $coupon, float $cartAmount, ?User $user): void
    {
        // Check if coupon has expired
        if ($coupon->expire_at && now()->gt($coupon->expire_at)) {
            throw new MarvelBadRequestException('This coupon has expired');
        }

        // Check if coupon is not yet active
        if ($coupon->active_from && now()->lt($coupon->active_from)) {
            throw new MarvelBadRequestException('This coupon is not yet active');
        }

        // Check minimum cart amount
        if ($coupon->minimum_cart_amount && $cartAmount < $coupon->minimum_cart_amount) {
            throw new MarvelBadRequestException(
                "Minimum cart amount of {$coupon->minimum_cart_amount} required for this coupon"
            );
        }

        // Check total usage limit (max_uses field on coupon)
        if ($coupon->max_uses) {
            $usageCount = CouponUsage::where('coupon_id', $coupon->id)->count();
            if ($usageCount >= $coupon->max_uses) {
                throw new MarvelBadRequestException('This coupon has reached its usage limit');
            }
        }

        // Check per-user usage limit (one coupon per user)
        if ($user) {
            $userAlreadyUsed = CouponUsage::where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($userAlreadyUsed) {
                throw new MarvelBadRequestException('You have already used this coupon');
            }
        }
    }

    /**
     * Record coupon usage after order creation.
     *
     * @param int $couponId
     * @param int $orderId
     * @param int|null $userId
     */
    protected function recordCouponUsage(int $couponId, int $orderId, ?int $userId): void
    {
        CouponUsage::create([
            'coupon_id' => $couponId,
            'order_id' => $orderId,
            'user_id' => $userId,
            'used_at' => now(),
        ]);
    }


    /**
     * updateOrder
     *
     * @param  mixed $request
     * @return void
     */
    public function updateOrder($request)
    {
        $order = Order::findOrFail($request->id);
        $user = $request->user();
        if (isset($order->shop_id)) {
            if ($this->hasPermission($user, $order->shop_id)) {
                $result = $this->changeOrderStatus($order, $request->order_status);
                $this->syncOrderStatusColumn($order, $request->order_status);
                return $result;
            }
        } else if ($user->hasRole(Role::SUPER_ADMIN)) {
            $result = $this->changeOrderStatus($order, $request->order_status);
            $this->syncOrderStatusColumn($order, $request->order_status);
            return $result;
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    /**
     * Sync the modern `status` column with the legacy `order_status` column.
     * The Marvel trait writes to order_status (prefixed values like 'order-completed')
     * but the app reads `status` (short values like 'completed').
     */
    private function syncOrderStatusColumn(Order $order, string $orderStatus): void
    {
        $statusMap = [
            OrderStatus::PENDING           => 'pending',
            OrderStatus::PROCESSING        => 'processing',
            OrderStatus::COMPLETED         => 'completed',
            OrderStatus::CANCELLED         => 'cancelled',
            OrderStatus::REFUNDED          => 'refunded',
            OrderStatus::FAILED            => 'failed',
            OrderStatus::AT_LOCAL_FACILITY => 'at_local_facility',
            OrderStatus::OUT_FOR_DELIVERY  => 'out_for_delivery',
            OrderStatus::READY_FOR_PICKUP  => 'ready_for_pickup',
        ];

        if (isset($statusMap[$orderStatus])) {
            Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => $statusMap[$orderStatus]]);
        }
    }

    /**
     * storeOrderWalletPoint
     *
     * @param  mixed $amount
     * @param  mixed $order_id
     * @return void
     */
    public function storeOrderWalletPoint($amount, $order_id)
    {
        if ($amount > 0) {
            OrderWalletPoint::create(['amount' => $amount, 'order_id' => $order_id]);
        }
    }


    /**
     * manageWalletAmount
     * Deducts wallet points for order payment with proper locking and validation.
     *
     * @param  mixed $total - The currency amount to deduct
     * @param  mixed $customer_id
     * @return void
     * @throws MarvelBadRequestException if insufficient wallet points
     */
    public function manageWalletAmount($total, $customer_id)
    {
        try {
            $pointsToDeduct = $this->currencyToWalletPoints($total);

            // Lock wallet for update to prevent race conditions
            $wallet = Wallet::where('customer_id', $customer_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new MarvelBadRequestException('Wallet not found');
            }

            // Validate sufficient points before deduction
            if ($wallet->available_points < $pointsToDeduct) {
                throw new MarvelBadRequestException(
                    "Insufficient wallet points. Available: {$wallet->available_points}, Required: {$pointsToDeduct}"
                );
            }

            // Use atomic operations for consistency
            $wallet->decrement('available_points', $pointsToDeduct);
            $wallet->increment('points_used', $pointsToDeduct);

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $request
     * @return array|LengthAwarePaginator|Collection|mixed
     */
    protected function createOrder($request)
    {
        try {
            $orderInput = $request->only($this->dataArray);
            $order = $this->create($orderInput);
            $products = $this->processProducts($request['products'], $request['customer_id'], $order);
            $order->products()->attach($products);
            $this->createChildOrder($order->id, $request);
            return $order;
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * This function creates an array of data for an email invoice, including order information,
     * settings, translated text, and URL.
     * 
     * @param request This is an HTTP request object that contains information about the current
     * request being made to the server. It is used to retrieve data from the request, such as the
     * language and whether the text should be displayed right-to-left (RTL).
     * @param order The order object that contains information about the order, such as the customer
     * details, order items, and total amount.
     * 
     * @return array An array containing order data, settings data, translated text, RTL status,
     * language, and a URL.
     */
    public function createInvoiceDataForEmail($request, $order): array
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $isRTL = $request->is_rtl ?? false;

        $translatedText = $this->formatInvoiceTranslateText($request->invoice_translated_text);
        $settings = Settings::getData($language);
        return [
            'order' => $order,
            'settings' => $settings,
            'translated_text' => $translatedText,
            'is_rtl' => $isRTL,
            'language' => $language,
            'url' => config('shop.shop_url') . '/orders/' . $order->tracking_number
        ];
    }

    /**
     * calculateShopIncome
     *
     * @param  mixed $parent_order
     * @return void
     */
    protected function calculateShopIncome($parent_order)
    {
        foreach ($parent_order->children as $order) {
            $balance = Balance::where('shop_id', '=', $order->shop_id)->first();
            $adminCommissionRate = $balance->admin_commission_rate;
            $shop_earnings = ($order->total * (100 - $adminCommissionRate)) / 100;
            $balance->total_earnings = $balance->total_earnings + $shop_earnings;
            $balance->current_balance = $balance->current_balance + $shop_earnings;
            $balance->save();
        }
    }

    /**
     * processProducts
     *
     * @param  mixed $products
     * @param  mixed $customer_id
     * @param  mixed $order
     * @return void
     */
    protected function processProducts($products, $customer_id, $order)
    {
        foreach ($products as $key => $product) {
            if (!isset($product['variation_option_id'])) {
                $product['variation_option_id'] = null;
                $products[$key] = $product;
            }
            try {
                if ($order->parent_id === null) {
                    $productData = Product::findOrFail($product['product_id']);

                    // if rental product
                    $isRentalProduct = $productData->is_rental;
                    if ($isRentalProduct) {
                        $this->processRentalProduct($product, $order->id);
                    }
                }
            } catch (Exception $e) {
                throw $e;
            }
        }
        return $products;
    }

    /**
     * processRentalProduct
     *
     * @param  mixed $product
     * @param  mixed $orderId
     * @return void
     */
    protected function processRentalProduct($product, $orderId)
    {
        $product['from'] = Carbon::parse($product['from']);
        $product['to'] = Carbon::parse($product['to']);
        $product['booking_duration'] = $product['from']->diffAsCarbonInterval($product['to']);
        $product['order_id'] = $orderId;
        $product['language'] = $orderId;
        unset($product['unit_price']);
        unset($product['subtotal']);
        try {
            if ($product['variation_option_id'] === null) {
                $productData = Product::findOrFail($product['product_id']);
                unset($product['variation_option_id']);
                $product['language'] = $productData->language;
                if (TRANSLATION_ENABLED) {
                    $this->processAllTranslatedProducts($productData, $product);
                } else {
                    $productData->availabilities()->create($product);
                }
            } else {
                $variation_option = Variation::findOrFail($product['variation_option_id']);
                unset($product['variation_option_id']);
                if (TRANSLATION_ENABLED) {
                    $this->processAllTranslatedVariations($variation_option, $product);
                } else {
                    $variation_option->availabilities()->create($product);
                }
            }
        } catch (\Throwable $th) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
    }

    /**
     * processAllTranslatedProducts
     *
     * @param  mixed $product
     * @param  mixed $orderedItem
     * @return void
     */
    public function processAllTranslatedProducts($product, $orderedItem)
    {
        $translatedProducts = Product::where('sku', $product->sku)->get();
        foreach ($translatedProducts as $translatedProduct) {
            $orderedItem['language'] = $translatedProduct->language;
            $orderedItem['product_id'] = $translatedProduct->id;
            $translatedProduct->availabilities()->create($orderedItem);
        }
    }

    /**
     * processAllTranslatedVariations
     *
     * @param  mixed $variation
     * @param  mixed $orderedItem
     * @return void
     */
    public function processAllTranslatedVariations($variation, $orderedItem)
    {
        $translatedVariations = Variation::where('sku', $variation->sku)->get();
        foreach ($translatedVariations as $translatedVariation) {
            $orderedItem['language'] = $translatedVariation->language;
            $translatedVariation->availabilities()->create($orderedItem);
        }
    }


    /**
     * createChildOrder
     *
     * @param  mixed $id
     * @param  mixed $request
     * @return void
     * @throws Exception
     */
    public function createChildOrder($id, $request): void
    {
        $products = $request->products;
        $productsByShop = [];
        $language = $request->language ?? DEFAULT_LANGUAGE;

        foreach ($products as $key => $cartProduct) {
            $product = Product::findOrFail($cartProduct['product_id']);
            $productsByShop[$product->shop_id][] = $cartProduct;
        }

        foreach ($productsByShop as $shop_id => $cartProduct) {
            $amount = array_sum(array_column($cartProduct, 'subtotal'));
            $orderInput = [
                'tracking_number' => $this->generateTrackingNumber(),
                'shop_id' => $shop_id,
                'order_status' => $request->order_status,
                'payment_status' => $request->payment_status,
                'customer_id' => $request->customer_id,
                'shipping_address' => $request->shipping_address,
                'billing_address' => $request->billing_address,
                'customer_contact' => $request->customer_contact,
                'customer_name' => $request->customer_name,
                'delivery_time' => $request->delivery_time,
                'delivery_fee' => 0,
                'sales_tax' => 0,
                'discount' => 0,
                'parent_id' => $id,
                'amount' => $amount,
                'total' => $amount,
                'paid_total' => $amount,
                'language' => $language,
                "payment_gateway" => $request->payment_gateway,
            ];

            $order = $this->create($orderInput);
            $order->products()->attach($this->processProducts($cartProduct, $request['customer_id'], $order));
            event(new OrderReceived($order));
        }
    }

    /**
     * Helper method to generate unique tracking number
     *
     * @return string
     * @throws Exception
     */
    public function generateTrackingNumber(): string
    {
        $today = date('Ymd');
        $trackingNumbers = Order::where('tracking_number', 'like', $today . '%')->pluck('tracking_number');

        do {
            $trackingNumber = $today . random_int(100000, 999999);
        } while ($trackingNumbers->contains($trackingNumber));

        return $trackingNumber;
    }

    public function checkOrderEligibility(): bool
    {
        $settings = Settings::getData();
        $useMustVerifyLicense = isset($settings->options['app_settings']['trust']) ? $settings->options['app_settings']['trust'] : false;
        return $useMustVerifyLicense;
    }
}
