<?php

namespace Marvel\Http\Controllers;

use App\Events\RefundApproved;
use App\Events\QuestionAnswered;
use App\Exceptions\UnsupportedGatewayException;
use App\Services\Payment\PaymentGatewayFactory;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Wallet;
use Marvel\Database\Repositories\RefundRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Enums\RefundStatus;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\RefundRequest;
use Marvel\Http\Resources\GetSingleRefundResource;
use Marvel\Http\Resources\RefundResource;
use Marvel\Traits\ApiResponse;
use Marvel\Traits\WalletsTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @OA\Tag(name="Refunds", description="Refund requests management")
 *
 * @OA\Schema(
 *     schema="Refund",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="title", type="string", example="Damaged product"),
 *     @OA\Property(property="description", type="string", example="The product arrived with a broken screen."),
 *     @OA\Property(property="images", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="status", type="string", enum={"pending", "approved", "rejected", "processing"}, example="pending"),
 *     @OA\Property(property="amount", type="number", format="float", example=50.00),
 *     @OA\Property(property="order_id", type="integer", example=1),
 *     @OA\Property(property="customer_id", type="integer", example=10),
 *     @OA\Property(property="shop_id", type="integer", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class RefundController extends CoreController
{
    use ApiResponse;
    use WalletsTrait;

    public $repository;

    public function __construct(
        RefundRepository $repository,
        private PaymentGatewayFactory $paymentGatewayFactory,
    ) {
        $this->repository = $repository;
    }


    /**
     * @OA\Get(
     *     path="/refunds",
     *     operationId="getRefunds",
     *     tags={"Refunds"},
     *     summary="List Refund Requests",
     *     description="Retrieve a paginated list of refund requests. Customers see their own; Admin/Owners see relevant ones.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="shop_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Refund requests retrieved",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Refund")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit;
        $refunds = $this->fetchRefunds($request)->paginate($limit);
        $refundData = RefundResource::collection($refunds)->response()->getData(true);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $refundData['data'] ?? [],
            "page" => $refundData['meta']['current_page'] ?? 0,
            "current_page" => $refundData['meta']['current_page'] ?? 0,
            "from" => $refundData['meta']['from'] ?? 0,
            "to" => $refundData['meta']['to'] ?? 0,
            "last_page" => $refundData['meta']['last_page'] ?? 0,
            "path" => $refundData['meta']['path'] ?? "",
            "per_page" => $refundData['meta']['per_page'] ?? 0,
            "total" => $refundData['meta']['total'] ?? 0,
            "next_page_url" => $refundData['links']['next'] ?? "",
            "prev_page_url" => $refundData['links']['prev'] ?? "",
            "last_page_url" => $refundData['links']['last'] ?? "",
            "first_page_url" => $refundData['links']['first'] ?? "",
        ]);
    }

    public function fetchRefunds(Request $request)
    {
        try {
            $language = $request->language ?? DEFAULT_LANGUAGE;
            $user = $request->user();
            if (!$user) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }

            $orderQuery = $this->repository->whereHas('order', function ($q) use ($language) {
                $q->where('language', $language);
            });

            switch ($user) {
                case $user->hasRole(Role::SUPER_ADMIN):
                    if ((!isset($request->shop_id) || $request->shop_id === 'undefined')) {
                        return $orderQuery->where('id', '!=', null)->where('shop_id', '=', null);
                    }
                    return $orderQuery->where('shop_id', '=', $request->shop_id);
                    break;

                case $this->repository->hasPermission($user, $request->shop_id):
                    return $orderQuery->where('shop_id', '=', $request->shop_id);
                    break;

                case $user->hasPermissionTo(Permission::CUSTOMER):
                    return $orderQuery->where('customer_id', $user->id)->where('shop_id', null);
                    break;

                default:
                    return $orderQuery->where('customer_id', $user->id)->where('shop_id', null);
                    break;
            }
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Post(
     *     path="/refunds",
     *     operationId="createRefund",
     *     tags={"Refunds"},
     *     summary="Create Refund Request",
     *     description="Submit a new refund request for an order. Requires CUSTOMER permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "description", "order_id", "amount"},
     *             @OA\Property(property="title", type="string", example="Wrong item"),
     *             @OA\Property(property="description", type="string", example="I received a different item than ordered."),
     *             @OA\Property(property="order_id", type="integer", example=1),
     *             @OA\Property(property="amount", type="number", format="float", example=25.00),
     *             @OA\Property(property="images", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=201, description="Refund request submitted", @OA\JsonContent(ref="#/components/schemas/Refund")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(RefundRequest $request)
    {
        try {
            if (!$request->user()) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
            return $this->repository->storeRefund($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Get(
     *     path="/refunds/{id}",
     *     operationId="getRefund",
     *     tags={"Refunds"},
     *     summary="Get Refund details",
     *     description="Retrieve details of a single refund request.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Refund details retrieved", @OA\JsonContent(ref="#/components/schemas/Refund")),
     *     @OA\Response(response=404, description="Refund not found")
     * )
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }

            $refundQuery = $this->repository->with(['shop', 'order', 'customer', 'refund_policy', 'refund_reason']);

            if ($user->hasRole(Role::SUPER_ADMIN) || $this->repository->hasPermission($user)) {
                $refund = $refundQuery->findOrFail($id);
            } else {
                $refund = $refundQuery->where('customer_id', $user->id)->findOrFail($id);
            }

            return new GetSingleRefundResource($refund);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Put(
     *     path="/refunds/{id}",
     *     operationId="updateRefund",
     *     tags={"Content Moderation"},
     *     summary="Update Refund Status",
     *     description="Update a refund request status (approve, reject, processing). When approved, credits customer wallet and deducts from shop balance. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Refund ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"approved", "rejected", "processing", "pending"}, example="approved")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Refund updated successfully"),
     *     @OA\Response(response=400, description="Already refunded"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Refund not found")
     * )
     */
    public function update(Request $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            return $this->updateRefund($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function updateRefund(Request $request)
    {
        $user = $request->user();

        if ($this->repository->hasPermission($user)) {
            try {
                $refund = $this->repository->with(['shop', 'order', 'customer'])->findOrFail($request->id);
            } catch (\Exception $e) {
                throw new ModelNotFoundException(NOT_FOUND);
            }
            if ($refund->status == RefundStatus::APPROVED) {
                throw new HttpException(400, ALREADY_REFUNDED);
            }

            if ($request->status == RefundStatus::APPROVED) {
                // Call gateway refund BEFORE database transaction
                if ($refund->order && $refund->order->payment_gateway) {
                    try {
                        $gateway = $this->paymentGatewayFactory->make($refund->order->payment_gateway);
                        $result = $gateway->refund($refund->order, (float) $refund->amount);

                        if (!$result->success) {
                            throw new HttpException(400, $result->errorMessage ?? 'Refund failed at payment gateway');
                        }
                    } catch (UnsupportedGatewayException $e) {
                        // Offline or unsupported payment method — skip gateway refund
                    }
                }

                // Wrap entire refund approval in a transaction with proper locking
                // to prevent race conditions and ensure data consistency
                return DB::transaction(function () use ($request, $refund) {
                    // Update refund status first
                    $this->repository->updateRefund($request, $refund);

                    try {
                        $order = Order::findOrFail($refund->order_id);
                        foreach ($order->children as $childOrder) {
                            // Lock balance record to prevent concurrent updates
                            $balance = Balance::where('shop_id', $childOrder->shop_id)
                                ->lockForUpdate()
                                ->first();

                            if ($balance) {
                                // Use decrement for atomic operations
                                $balance->decrement('total_earnings', $childOrder->amount);
                                $balance->decrement('current_balance', $childOrder->amount);
                            }
                        }
                    } catch (Exception $e) {
                        throw new ModelNotFoundException(NOT_FOUND);
                    }

                    // Lock wallet for update to prevent race conditions
                    $wallet = Wallet::where('customer_id', $refund->customer_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$wallet) {
                        $wallet = Wallet::create(['customer_id' => $refund->customer_id]);
                    }

                    $walletPoints = $this->currencyToWalletPoints($refund->amount);
                    // Use increment for atomic operations
                    $wallet->increment('total_points', $walletPoints);
                    $wallet->increment('available_points', $walletPoints);

                    $refreshed = $refund->fresh();

                    event(new RefundApproved($refreshed));

                    return $refreshed;
                });
            }

            // Non-approved status updates don't need transaction
            $this->repository->updateRefund($request, $refund);
            return $refund;
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    /**
     * @OA\Delete(
     *     path="/refunds/{id}",
     *     operationId="deleteRefund",
     *     tags={"Content Moderation"},
     *     summary="Delete Refund Request",
     *     description="Delete a refund request. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Refund ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Refund deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Refund not found")
     * )
     */
    public function destroy(Request $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            return $this->deleteRefund($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    public function deleteRefund(Request $request)
    {
        try {
            $refund = $this->repository->findOrFail($request->id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
        if ($this->repository->hasPermission($request->user())) {
            $refund->delete();
            return $refund;
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }
}
