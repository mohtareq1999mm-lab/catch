<?php

namespace Marvel\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Dompdf\Options;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\OrderCreateRequest;
use Marvel\Http\Requests\OrderUpdateRequest;
use Marvel\Traits\OrderManagementTrait;
use Marvel\Traits\PaymentStatusManagerWithOrderTrait;
use Marvel\Traits\PaymentTrait;
use Marvel\Traits\TranslationTrait;
use Marvel\Traits\WalletsTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


/**
 * @OA\Tag(name="Orders", description="Order management endpoints [ALL ROLES]")
 *
 * @OA\Schema(
 *     schema="Order",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="tracking_number", type="string", example="ORD-123456"),
 *     @OA\Property(property="customer_id", type="integer", example=10),
 *     @OA\Property(property="customer_contact", type="string", example="+123456789"),
 *     @OA\Property(property="status", type="string", example="order-pending"),
 *     @OA\Property(property="amount", type="number", format="float", example=100.00),
 *     @OA\Property(property="sales_tax", type="number", format="float", example=5.00),
 *     @OA\Property(property="paid_total", type="number", format="float", example=105.00),
 *     @OA\Property(property="total", type="number", format="float", example=105.00),
 *     @OA\Property(property="coupon_id", type="integer", nullable=true),
 *     @OA\Property(property="shop_id", type="integer", nullable=true, description="If generic order, this is null. If sub-order, contains shop ID."),
 *     @OA\Property(property="discount", type="number", format="float", example=0.00),
 *     @OA\Property(property="payment_gateway", type="string", example="CASH_ON_DELIVERY"),
 *     @OA\Property(property="shipping_address", type="object"),
 *     @OA\Property(property="billing_address", type="object"),
 *     @OA\Property(property="logistics_provider", type="integer", nullable=true),
 *     @OA\Property(property="delivery_fee", type="number", format="float", example=10.00),
 *     @OA\Property(property="delivery_time", type="string", example="Express"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="products", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="children", type="array", @OA\Items(ref="#/components/schemas/Order"))
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedOrders",
 *     type="object",
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Order")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="first_page_url", type="string"),
 *     @OA\Property(property="last_page", type="integer", example=10),
 *     @OA\Property(property="per_page", type="integer", example=15),
 *     @OA\Property(property="total", type="integer", example=150)
 * )
 */
class OrderController extends CoreController
{
    use WalletsTrait,
        OrderManagementTrait,
        TranslationTrait,
        PaymentStatusManagerWithOrderTrait,
        PaymentTrait;

    public OrderRepository $repository;
    public ?Settings $settings;

    public function __construct(OrderRepository $repository)
    {
        $this->repository = $repository;
        $this->settings = Settings::first();
    }

    /**
     * @OA\Get(
     *     path="/orders",
     *     operationId="getOrders",
     *     tags={"Orders"},
     *     summary="List all orders",
     *     description="Retrieve a paginated list of orders. Customers see their own orders. Store Owners/Staff see orders for their shop. Super Admins see all.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of orders per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=10, example=15)
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *         @OA\Schema(type="integer", default=1, example=1)
     *     ),
     *     @OA\Parameter(
     *         name="shop_id",
     *         in="query",
     *         description="Filter by Shop ID (for Store Owners/Staff)",
     *         required=false,
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\Parameter(
     *         name="tracking_number",
     *         in="query",
     *         description="Search by tracking number",
     *         required=false,
     *         @OA\Schema(type="string", example="ORD-123")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Orders retrieved successfully",
     *         @OA\JsonContent(ref="#/components/schemas/PaginatedOrders")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        return $this->fetchOrders($request)->paginate($limit)->withQueryString();
    }

    /**
     * fetchOrders
     *
     * @param mixed $request
     * @return object
     */
    public function fetchOrders(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        switch ($user) {
            case $user->hasRole(Role::SUPER_ADMIN):
                return $this->repository->with('children')->where('id', '!=', null)->where('parent_id', '=', null);
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):
                if ($this->repository->hasPermission($user, $request->shop_id)) {
                    return $this->repository->with('children')->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null);
                } else {
                    $orders = $this->repository->with('children')->where('parent_id', '!=', null)->whereIn('shop_id', $user->shops->pluck('id'));
                    return $orders;
                }
                break;

            case $user->hasPermissionTo(Permission::STAFF):
                if ($this->repository->hasPermission($user, $request->shop_id)) {
                    return $this->repository->with('children')->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null);
                } else {
                    $orders = $this->repository->with('children')->where('parent_id', '!=', null)->where('shop_id', '=', $user->shop_id);
                    return $orders;
                }
                break;

            default:
                return $this->repository->with('children')->where('customer_id', '=', $user->id)->where('parent_id', '=', null);
                break;
        }

        // ********************* Old code *********************

        // if ($user && $user->hasRole(Role::SUPER_ADMIN) && (!isset($request->shop_id) || $request->shop_id === 'undefined')) {
        //     return $this->repository->with('children')->where('id', '!=', null)->where('parent_id', '=', null); //->paginate($limit);
        // } else if ($this->repository->hasPermission($user, $request->shop_id)) {
        //     // if ($user && $user->hasPermissionTo(Permission::STORE_OWNER)) {
        //     return $this->repository->with('children')->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null); //->paginate($limit);
        //     // } elseif ($user && $user->hasPermissionTo(Permission::STAFF)) {
        //     //     return $this->repository->with('children')->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null); //->paginate($limit);
        //     // }
        // } else {
        //     return $this->repository->with('children')->where('customer_id', '=', $user->id)->where('parent_id', '=', null); //->paginate($limit);
        // }
    }


    /**
     * @OA\Post(
     *     path="/orders",
     *     operationId="createOrder",
     *     tags={"Orders"},
     *     summary="Create a new order",
     *     description="Create specific order with products. Primarily for customers.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"products", "amount", "total", "paid_total", "payment_gateway"},
     *             @OA\Property(property="customer_id", type="integer", example=1),
     *             @OA\Property(
     *                 property="products",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="product_id", type="integer", example=1),
     *                     @OA\Property(property="order_quantity", type="integer", example=2),
     *                     @OA\Property(property="unit_price", type="number", format="float", example=50.00),
     *                     @OA\Property(property="subtotal", type="number", format="float", example=100.00)
     *                 )
     *             ),
     *             @OA\Property(property="amount", type="number", format="float", example=100.00),
     *             @OA\Property(property="sales_tax", type="number", format="float", example=5.00),
     *             @OA\Property(property="delivery_fee", type="number", format="float", example=10.00),
     *             @OA\Property(property="total", type="number", format="float", example=115.00),
     *             @OA\Property(property="paid_total", type="number", format="float", example=115.00),
     *             @OA\Property(property="payment_gateway", type="string", enum={"CASH_ON_DELIVERY", "STRIPE", "PAYPAL"}, example="CASH_ON_DELIVERY"),
     *             @OA\Property(property="billing_address", type="object"),
     *             @OA\Property(property="shipping_address", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Order created successfully",
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(OrderCreateRequest $request)
    {
        try {
            // decision need
            // if(!($this->settings->options['useCashOnDelivery'] && $this->settings->options['useEnableGateway'])){
            //     throw new HttpException(400, PLEASE_ENABLE_PAYMENT_OPTION_FROM_THE_SETTINGS);
            // }

            return DB::transaction(fn() => $this->repository->storeOrder($request, $this->settings));
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $th->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/orders/{id}",
     *     operationId="getOrder",
     *     tags={"Orders"},
     *     summary="Get a single order",
     *     description="Retrieve order details by ID or Tracking Number. Access restricted to the Customer who owns it, or Store Owner/Staff of the shop.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Order ID or Tracking Number",
     *         @OA\Schema(type="string", example="1")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order details retrieved",
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - not authorized to view this order"),
     *     @OA\Response(response=404, description="Order not found")
     * )
     */
    public function show(Request $request, $params)
    {
        $request["tracking_number"] = $params;
        try {
            return $this->fetchSingleOrder($request);
        } catch (MarvelException $e) {
            throw new MarvelException($e->getMessage());
        }
    }

    /**
     * fetchSingleOrder
     *
     * @param mixed $request
     * @return void
     * @throws MarvelException
     */
    public function fetchSingleOrder(Request $request)
    {
        $user = $request->user() ?? null;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $orderParam = $request->tracking_number ?? $request->id;
        try {
            $order = $this->repository->where('language', $language)->with([
                'products',
                'shop',
                'children.shop',
                'wallet_point',
            ])->where('id', $orderParam)->orWhere('tracking_number', $orderParam)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }

        // Create Intent
        if (
            !in_array($order->payment_gateway, [
                PaymentGatewayType::CASH,
                PaymentGatewayType::CASH_ON_DELIVERY,
                PaymentGatewayType::FULL_WALLET_PAYMENT
            ])
        ) {
            // $order['payment_intent'] = $this->processPaymentIntent($request, $this->settings);
            $order['payment_intent'] = $this->attachPaymentIntent($orderParam);
        }

        if (!$order->customer_id) {
            return $order;
        }
        if ($user && $user->hasRole(Role::SUPER_ADMIN)) {
            return $order;
        } elseif (isset($order->shop_id)) {
            if ($user && ($this->repository->hasPermission($user, $order->shop_id) || $user->id == $order->customer_id)) {
                return $order;
            }
        } elseif ($user && $user->id == $order->customer_id) {
            return $order;
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    /**
     * findByTrackingNumber
     *
     * @param mixed $request
     * @param mixed $tracking_number
     * @return void
     */
    /**
     * @OA\Get(
     *     path="/orders/tracking-number/{tracking_number}",
     *     operationId="getOrderByTracking",
     *     tags={"Orders"},
     *     summary="Track order by tracking number",
     *     description="Retrieve order details using tracking number. Publicly accessible for valid tracking numbers.",
     *     @OA\Parameter(
     *         name="tracking_number",
     *         in="path",
     *         required=true,
     *         description="Order tracking number",
     *         @OA\Schema(type="string", example="ORD-123456")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order details retrieved",
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     ),
     *     @OA\Response(response=404, description="Order not found")
     * )
     */
    public function findByTrackingNumber(Request $request, $tracking_number)
    {
        $user = $request->user() ?? null;
        try {
            $order = $this->repository->with(['products', 'children.shop', 'wallet_point', 'payment_intent'])
                ->findOneByFieldOrFail('tracking_number', $tracking_number);

            if ($order->customer_id === null) {
                return $order;
            }
            if ($user && ($user->id === $order->customer_id || $user->can('super_admin'))) {
                return $order;
            } else {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Put(
     *     path="/orders/{id}",
     *     operationId="updateOrder",
     *     tags={"Orders"},
     *     summary="Update order status",
     *     description="Update the status of an order. Requires STORE_OWNER or STAFF permission for the related shop.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Order ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"order-pending", "order-processing", "order-completed", "order-cancelled", "order-failed", "order-at-local-facility", "order-out-for-delivery"}, example="order-processing")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order updated successfully",
     *         @OA\JsonContent(ref="#/components/schemas/Order")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Order not found")
     * )
     */
    public function update(OrderUpdateRequest $request, $id)
    {
        try {
            $request["id"] = $id;
            return $this->updateOrder($request);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE, $e->getMessage());
        }
    }

    public function updateOrder(OrderUpdateRequest $request)
    {
        return $this->repository->updateOrder($request);
    }

    /**
     * @OA\Delete(
     *     path="/orders/{id}",
     *     operationId="deleteOrder",
     *     tags={"Orders"},
     *     summary="Delete an order",
     *     description="Delete an order. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Order ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(response=200, description="Order deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Order not found")
     * )
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * submitPayment
     *
     * @param mixed $request
     * @return void
     * @throws Exception
     */
    /**
     * @OA\Post(
     *     path="/orders/payment",
     *     operationId="submitOrderPayment",
     *     tags={"Orders"},
     *     summary="Submit order payment",
     *     description="Process payment for an existing order",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"tracking_number"},
     *             @OA\Property(property="tracking_number", type="string", example="ORD-123456"),
     *             @OA\Property(property="nonce", type="string", description="Payment nonce/token from gateway"),
     *             @OA\Property(property="token", type="string", description="Payment token")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Payment processed successfully"),
     *     @OA\Response(response=400, description="Payment failed"),
     *     @OA\Response(response=404, description="Order not found")
     * )
     */
    public function submitPayment(Request $request): void
    {
        $tracking_number = $request->tracking_number ?? null;
        try {
            $order = $this->repository->with(['products', 'children.shop', 'wallet_point', 'payment_intent'])
                ->findOneByFieldOrFail('tracking_number', $tracking_number);

            $isFinal = $this->checkOrderStatusIsFinal($order);
            if ($isFinal)
                return;

            switch ($order->payment_gateway) {

                case PaymentGatewayType::STRIPE:
                    $this->stripe($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::PAYPAL:
                    $this->paypal($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::MOLLIE:
                    $this->mollie($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::RAZORPAY:
                    $this->razorpay($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::PAYSTACK:
                    $this->paystack($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::XENDIT:
                    $this->xendit($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::IYZICO:
                    $this->iyzico($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::BKASH:
                    $this->bkash($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::PAYMONGO:
                    $this->paymongo($order, $request, $this->settings);
                    break;

                case PaymentGatewayType::FLUTTERWAVE:
                    $this->flutterwave($order, $request, $this->settings);
                    break;
            }
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }
}
