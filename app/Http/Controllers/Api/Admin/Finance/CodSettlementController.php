<?php

namespace App\Http\Controllers\Api\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\ListCodSettlementStatementsRequest;
use App\Http\Resources\Settlement\CodSettlementStatementResource;
use App\Services\Settlement\CodSettlementService;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;

/**
 * #135: read-only finance adapter for COD settlement evidence.
 *
 * Thin by design: there is no create/update/delete route and no payment or
 * lifecycle command on this surface.
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
}
