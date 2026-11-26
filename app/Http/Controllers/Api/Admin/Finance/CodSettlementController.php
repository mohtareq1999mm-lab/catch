<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\AcceptCodSettlementStatementRequest;
use App\Http\Requests\Admin\Finance\CloseCodSettlementRequest;
use App\Http\Requests\Admin\Finance\ListCodSettlementStatementsRequest;
use App\Http\Requests\Admin\Finance\ManualMatchCodSettlementLineRequest;
use App\Http\Requests\Admin\Finance\RejectCodSettlementStatementRequest;
use App\Http\Resources\Settlement\CodSettlementLineResource;
use App\Http\Resources\Settlement\CodSettlementStatementResource;
use App\Exceptions\CodSettlementVarianceException;
use App\Services\Settlement\CodSettlementService;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;

class CodSettlementController extends Controller
{
    use ApiResponse;

    public function __construct(private CodSettlementService $settlements) {}

    public function index(ListCodSettlementStatementsRequest $request): JsonResponse
    {
        return $this->apiResponse(
            __('cod_settlement.fetched_many'),
            200,
            true,
            CodSettlementStatementResource::collection($this->settlements->paginateStatements($request->validated()))
        );
    }

    public function show(int $id): JsonResponse
    {
        return $this->apiResponse(
            __('cod_settlement.fetched_one'),
            200,
            true,
            CodSettlementStatementResource::make($this->settlements->findStatement($id))
        );
    }

    public function manualMatch(ManualMatchCodSettlementLineRequest $request, int $id, int $lineId): JsonResponse
    {
        $actorId = (int) $request->user()->id;

        try {
            $line = $this->settlements->manualMatch(
                $id,
                $lineId,
                (int) $request->validated('order_id'),
                $actorId,
                (string) $request->validated('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(
            __('cod_settlement.line_matched'),
            200,
            true,
            CodSettlementLineResource::make($line)
        );
    }

    public function close(CloseCodSettlementRequest $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;

        try {
            $statement = $this->settlements->closeStatement($id, $actorId, $request->validated('reason'));
        } catch (\InvalidArgumentException|CodSettlementVarianceException $e) {
            // #191: a blocked closure (unmatched evidence, variance beyond the
            // approved tolerance, missing approval reference) is a client-
            // correctable finance state, not a server failure.
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(
            __('cod_settlement.closed'),
            200,
            true,
            CodSettlementStatementResource::make($statement)
        );
    }

    /**
     * #182: explicit finance acceptance is a controlled write. The actor is
     * the authenticated session; the request never carries business state.
     */
    public function accept(AcceptCodSettlementStatementRequest $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;

        try {
            $statement = $this->settlements->acceptStatement($id, $actorId, $request->validated('reason'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(
            __('cod_settlement.accepted'),
            200,
            true,
            CodSettlementStatementResource::make($statement)
        );
    }

    /** #182: rejecting a statement never mutates the previously accepted one. */
    public function reject(RejectCodSettlementStatementRequest $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;

        try {
            $statement = $this->settlements->rejectStatement($id, $actorId, (string) $request->validated('reason'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }

        return $this->apiResponse(
            __('cod_settlement.rejected'),
            200,
            true,
            CodSettlementStatementResource::make($statement)
        );
    }
}
