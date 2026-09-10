<?php


namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Withdraw;
use Marvel\Database\Repositories\WithdrawRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Enums\WithdrawStatus;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\UpdateWithdrawRequest;
use Marvel\Http\Requests\WithdrawRequest;
use Prettus\Validator\Exceptions\ValidatorException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @OA\Tag(name="Withdrawal Management", description="Vendor payout requests and approval [SUPER_ADMIN, STORE_OWNER]")
 *
 * @OA\Schema(
 *     schema="Withdraw",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="amount", type="number", format="float", example=500.00),
 *     @OA\Property(property="shop_id", type="integer", example=10),
 *     @OA\Property(property="payment_method", type="string", example="bank_transfer"),
 *     @OA\Property(property="details", type="string", example="Bank Acct: 12345678"),
 *     @OA\Property(property="note", type="string", nullable=true, example="Monthly payout"),
 *     @OA\Property(property="status", type="string", enum={"approved", "pending", "rejected", "processing", "on_hold"}, example="pending"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="shop", ref="#/components/schemas/Shop")
 * )
 */
class WithdrawController extends CoreController
{
    public $repository;

    public function __construct(WithdrawRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @OA\Get(
     *     path="/withdraws",
     *     operationId="getWithdraws",
     *     tags={"Withdrawal Management"},
     *     summary="List withdrawals",
     *     description="List withdrawal requests. Store Owners see their own. Super Admins see all.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="shop_id", in="query", description="Filter by Shop ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="limit", in="query", description="Items per page", @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="page", in="query", description="Page number", @OA\Schema(type="integer", default=1)),
     *     @OA\Response(
     *         response=200,
     *         description="Withdrawals retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Withdraw")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $withdraw = $this->fetchWithdraws($request);
        return $withdraw->paginate($limit);
    }

    public function fetchWithdraws(Request $request)
    {
        try {
            $user = $request->user();
            $shop_id = isset($request['shop_id']) && $request['shop_id'] != 'undefined' ? $request['shop_id'] : false;
            if ($shop_id) {
                if ($user->shops->contains('id', $shop_id)) {
                    return $this->repository->with(['shop'])->where('shop_id', '=', $shop_id);
                } elseif ($user && $user->hasRole(Role::SUPER_ADMIN)) {
                    return $this->repository->with(['shop'])->where('shop_id', '=', $shop_id);
                } else {
                    throw new AuthorizationException(NOT_AUTHORIZED);
                }
            } else {
                if ($user && $user->hasRole(Role::SUPER_ADMIN)) {
                    return $this->repository->with(['shop'])->where('id', '!=', null);
                } else {
                    throw new AuthorizationException(NOT_AUTHORIZED);
                }
            }
        } catch (MarvelException $e) {
            throw new MarvelException($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/withdraws",
     *     operationId="createWithdraw",
     *     tags={"Withdrawal Management"},
     *     summary="Request a withdrawal",
     *     description="Create a new withdrawal request for a shop. Requires STORE_OWNER permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount", "shop_id", "payment_method"},
     *             @OA\Property(property="amount", type="number", format="float", example=500.00),
     *             @OA\Property(property="shop_id", type="integer", example=10),
     *             @OA\Property(property="payment_method", type="string", example="Bank Transfer"),
     *             @OA\Property(property="details", type="string", example="Account info..."),
     *             @OA\Property(property="note", type="string", example="Monthly payout")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Withdrawal requested successfully",
     *         @OA\JsonContent(ref="#/components/schemas/Withdraw")
     *     ),
     *     @OA\Response(response=400, description="Bad Request (Insufficient balance or invalid shop)"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function store(WithdrawRequest $request)
    {
        try {
            if ($request->user() && ($request->user()->hasRole(Role::SUPER_ADMIN) || $request->user()->shops->contains('id', $request->shop_id))) {
                $validatedData = $request->validated();
                if (!isset($validatedData['shop_id'])) {
                    throw new BadRequestHttpException(WITHDRAW_MUST_BE_ATTACHED_TO_SHOP);
                }
                $balance = Balance::where('shop_id', '=', $validatedData['shop_id'])->first();
                if (isset($balance->current_balance) && $balance->current_balance < $validatedData['amount']) {
                    throw new BadRequestHttpException(INSUFFICIENT_BALANCE);
                }
                $withdraw = $this->repository->create($validatedData);
                $balance->withdrawn_amount = $balance->withdrawn_amount + $validatedData['amount'];
                $balance->current_balance = $balance->current_balance - $validatedData['amount'];
                $balance->save();
                $withdraw->status = WithdrawStatus::PENDING;
                return $withdraw;
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Get(
     *     path="/withdraws/{id}",
     *     operationId="getWithdraw",
     *     tags={"Withdrawal Management"},
     *     summary="Get withdrawal details",
     *     description="Get details of a specific withdrawal. Requires STORE_OWNER or SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Withdraw ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Withdraw details retrieved",
     *         @OA\JsonContent(ref="#/components/schemas/Withdraw")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Withdraw not found")
     * )
     */
    public function show(Request $request, $id)
    {
        $request->id = $id;
        return $this->fetchSingleWithdraw($request);
    }

    public function fetchSingleWithdraw(Request $request)
    {
        try {
            $id = $request->id;
            $withdraw = $this->repository->with(['shop'])->findOrFail($id);
            if ($request->user() && ($request->user()->hasRole(Role::SUPER_ADMIN) || $request->user()->shops->contains('id', $withdraw->shop_id))) {
                return $withdraw;
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param WithdrawRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateWithdrawRequest $request, $id)
    {
        throw new HttpException(400, ACTION_NOT_VALID);
    }

    /**
     * @OA\Delete(
     *     path="/withdraws/{id}",
     *     operationId="deleteWithdraw",
     *     tags={"Withdrawal Management"},
     *     summary="Delete Withdrawal Request",
     *     description="Delete a withdrawal request. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Withdraw ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Withdraw deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Withdraw not found")
     * )
     */
    public function destroy(Request $request, $id)
    {
        try {
            if ($request->user() && $request->user()->hasRole(Role::SUPER_ADMIN)) {
                return $this->repository->findOrFail($id)->delete();
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Post(
     *     path="/approve-withdraw",
     *     operationId="approveWithdraw",
     *     tags={"Withdrawal Management"},
     *     summary="Approve/Reject Withdrawal",
     *     description="Change withdrawal request status (approved, rejected, on_hold, processing). Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "status"},
     *             @OA\Property(property="id", type="integer", example=5, description="Withdraw ID"),
     *             @OA\Property(property="status", type="string", enum={"approved", "rejected", "on_hold", "processing", "pending"}, example="approved")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Withdrawal status updated"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Withdraw not found")
     * )
     */
    public function approveWithdraw(Request $request)
    {
        try {
            if ($request->user() && $request->user()->hasRole(Role::SUPER_ADMIN)) {
                $id = $request->id;
                $status = $request->status->value ?? $request->status;
                $withdraw = $this->repository->findOrFail($id);
                $withdraw->status = $status;
                $withdraw->save();
                return $withdraw;
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
}
