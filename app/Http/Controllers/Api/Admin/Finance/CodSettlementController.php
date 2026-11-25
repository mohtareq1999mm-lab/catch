<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\AcceptCodSettlementStatementRequest;
use App\Http\Requests\Admin\Finance\ListCodSettlementStatementsRequest;
use App\Http\Requests\Admin\Finance\RejectCodSettlementStatementRequest;
use App\Http\Resources\Settlement\CodSettlementStatementResource;
use App\Services\Settlement\CodSettlementService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Marvel\Traits\ApiResponse;

/**
 * #135: finance adapter for COD settlement evidence.
 * #182: explicit accept/reject decisions are the only write surface here;
 * there is no payment, order, or adjustment command on this endpoint.
 *
 * Thin by design: the service owns every business decision.
 */
class CodSettlementController extends Controller
{
    use ApiResponse;

    public function __construct(private CodSettlementService $settlements) {}

    /**
     * GET /api/v1/admin/cod-settlements/statements
     */
    public function index(ListCodSettlementStatementsRequest $request): JsonResponse
    {
        return $this->apiResponse(
            __('cod_settlement.fetched_many'),
            200,
            true,
            CodSettlementStatementResource::collection($this->settlements->paginateStatements($request->validated()))
        );
    }

    /**
     * GET /api/v1/admin/cod-settlements/statements/{id}
     */
    public function show(int $id): JsonResponse
    {
        return $this->apiResponse(
            __('cod_settlement.fetched_one'),
            200,
            true,
            CodSettlementStatementResource::make($this->settlements->findStatement($id))
        );
    }

    /**
     * POST /api/v1/admin/cod-settlements/statements/{id}/accept
     */
    public function accept(AcceptCodSettlementStatementRequest $request, int $id): JsonResponse
    {
        try {
            $statement = $this->settlements->acceptStatement(
                $id,
                (int) $request->user()->getKey(),
                $request->validated('supersedes_statement_id') === null
                    ? null
                    : (int) $request->validated('supersedes_statement_id'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->apiResponse($exception->getMessage(), 422, false);
        }

        return $this->apiResponse(
            __('cod_settlement.accepted'),
            200,
            true,
            CodSettlementStatementResource::make($statement)
        );
    }

    /**
     * POST /api/v1/admin/cod-settlements/statements/{id}/reject
     */
    public function reject(RejectCodSettlementStatementRequest $request, int $id): JsonResponse
    {
        try {
            $statement = $this->settlements->rejectStatement($id, (int) $request->user()->getKey());
        } catch (InvalidArgumentException $exception) {
            return $this->apiResponse($exception->getMessage(), 422, false);
        }

        return $this->apiResponse(
            __('cod_settlement.rejected'),
            200,
            true,
            CodSettlementStatementResource::make($statement)
        );
    }
}
