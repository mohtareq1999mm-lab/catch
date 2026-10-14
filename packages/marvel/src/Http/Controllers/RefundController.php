<?php

namespace Marvel\Http\Controllers;

use App\Services\Refund\RefundService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\RefundRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Enums\RefundStatus;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\RefundRequest;
use Marvel\Http\Resources\GetSingleRefundResource;
use Marvel\Http\Resources\RefundResource;
use Marvel\Traits\ApiResponse;
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

    public $repository;

    public function __construct(
        RefundRepository $repository,
        private RefundService $refunds,
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
        // Phase 10 unification: thin adapter over the canonical refund list.
        // Customers receive their own refunds + summary; staff keep the
        // existing paginated shape with an additive summary block.
        $user = $request->user();

        if (!$user) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        $limit = (int) ($request->limit ?? 15);

        if ($user->hasRole(Role::SUPER_ADMIN) || $this->repository->hasPermission($user, $request->shop_id)) {
            $result = $this->refunds->listForAdmin($request->only(['status', 'order_id']), $limit);
        } else {
            $result = $this->refunds->listForCustomer((int) $user->id, $limit);
        }

        $refunds = RefundResource::collection(collect($result['data']))->resolve($request);
        $refundData = ['data' => $refunds] + $result['meta'];

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $refundData['data'] ?? [],
            "page" => $refundData['current_page'] ?? 0,
            "current_page" => $refundData['current_page'] ?? 0,
            "from" => null,
            "to" => null,
            "last_page" => $refundData['last_page'] ?? 0,
            "path" => "",
            "per_page" => $refundData['per_page'] ?? 0,
            "total" => $refundData['total'] ?? 0,
            "next_page_url" => "",
            "prev_page_url" => "",
            "last_page_url" => "",
            "first_page_url" => "",
            "summary" => $result['summary'],
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
        // Phase 10 unification: thin adapter. The canonical service creates
        // PENDING and stops — no approval, inventory, gateway or wallet.
        try {
            $user = $request->user();

            if (!$user) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }

            $refund = $this->refunds->request((int) $user->id, $request->only([
                'order_id', 'amount', 'currency', 'title', 'description', 'images', 'refund_reason_id',
            ]));

            return $this->apiResponse(REFUND_REQUEST_SUBMITTED, 201, true, [
                'id' => $refund->id,
                'order_id' => $refund->order_id,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'status' => $refund->status,
                'title' => $refund->title,
                'created_at' => $refund->created_at,
            ]);
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), $this->refundErrorStatus($e->getMessage()), false);
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
                // Phase 10 unification: refunds key the customer by user_id
                // (customer_id was never a real column).
                $refund = $refundQuery->where('user_id', $user->id)->findOrFail($id);
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
     *     description="Approve or reject a refund request. Approval records the business refund locally (no payment-provider call, no wallet movement). Requires refund permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Refund ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"approved", "rejected"}, example="approved")
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
        // Phase 10 unification: thin adapter over the canonical
        // RefundService. APPROVED means business-approved/recorded — never
        // "money returned". No gateway call, no wallet/balance mutation and
        // no payment-status change happens on this path anymore.
        $user = $request->user();

        if (!$this->repository->hasPermission($user)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        try {
            $status = strtolower(trim((string) $request->status));
            $note = $request->get('decision_note', $request->get('description'));

            if ($status === RefundStatus::APPROVED) {
                $refund = $this->refunds->approve((int) $request->id, (int) $user->id, $note);
            } elseif ($status === RefundStatus::REJECTED) {
                $refund = $this->refunds->reject((int) $request->id, (int) $user->id, $note);
            } else {
                throw new HttpException(422, WRONG_REFUND);
            }

            return $refund->load(['shop', 'order', 'customer', 'refund_policy', 'refund_reason']);
        } catch (HttpException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new HttpException($this->refundErrorStatus($e->getMessage()), $e->getMessage());
        }
    }

    /**
     * Map canonical service failures to HTTP. Fail-closed: duplicate or
     * late decisions are 400, missing rows 404, forbidden 403 and every
     * validation/cap refusal 422 — none of them with side effects.
     */
    private function refundErrorStatus(string $message): int
    {
        if ($message === __('message.ERROR.NOT_FOUND')) {
            return 404;
        }

        if ($message === __('message.ERROR.NOT_AUTHORIZED')) {
            return 403;
        }

        if ($message === __('message.ERROR.ALREADY_REFUNDED')) {
            return 400;
        }

        return 422;
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
            // Phase 10 unification: decided refunds are ledger history and
            // must never be deleted (approved sums drive over-refund
            // protection). Only pending requests can be withdrawn.
            if ($refund->status !== \App\Services\Refund\RefundService::STATUS_PENDING) {
                throw new HttpException(422, WRONG_REFUND);
            }
            $refund->delete();
            return $refund;
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }
}
