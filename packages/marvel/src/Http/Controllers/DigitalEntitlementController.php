<?php

namespace Marvel\Http\Controllers;

use App\Models\DigitalEntitlement;
use App\Services\Digital\DigitalEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\DigitalEntitlementLimitRequest;
use Marvel\Http\Resources\DigitalEntitlementResource;
use Marvel\Traits\ApiResponse;

/**
 * Admin entitlement management (Workstream 6).
 * CRUD/interface only — every rule lives in DigitalEntitlementService.
 */
class DigitalEntitlementController extends CoreController
{
    use ApiResponse;

    private DigitalEntitlementService $service;

    public function __construct(DigitalEntitlementService $service)
    {
        $this->service = $service;
        $this->middleware("permission:" . Permission::VIEW_ORDERS, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::MANAGE_DIGITAL_ACCESS, ["only" => ["setLimit", "revoke", "restore"]]);
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->service->paginate(
            $request->only(['uuid', 'status', 'order_id', 'user_id', 'search']),
            (int) ($request->query('per_page', 25))
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'data' => DigitalEntitlementResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $entitlement = DigitalEntitlement::query()
            ->where('uuid', $uuid)
            ->with(['orderItem.product', 'user'])
            ->firstOrFail();

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new DigitalEntitlementResource($entitlement));
    }

    public function setLimit(DigitalEntitlementLimitRequest $request, string $uuid): JsonResponse
    {
        $entitlement = DigitalEntitlement::query()->where('uuid', $uuid)->firstOrFail();

        $actor = User::find($request->user()?->getAuthIdentifier());

        $result = $this->service->setDownloadLimit(
            $entitlement,
            array_key_exists('limit', $request->validated()) ? (int) $request->validated('limit') : null,
            $actor
        );

        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, [
            'uuid'           => $entitlement->uuid,
            'previous_limit' => $result['previous'],
            'download_limit' => $result['new'],
            'unlimited'      => $result['new'] === DigitalEntitlementService::UNLIMITED,
        ]);
    }

    public function revoke(Request $request, string $uuid): JsonResponse
    {
        $entitlement = DigitalEntitlement::query()->where('uuid', $uuid)->firstOrFail();

        $actor = User::find($request->user()?->getAuthIdentifier());
        $this->service->revoke($entitlement, $actor);

        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, new DigitalEntitlementResource($entitlement->refresh()));
    }

    public function restore(Request $request, string $uuid): JsonResponse
    {
        $entitlement = DigitalEntitlement::query()->where('uuid', $uuid)->firstOrFail();

        $actor = User::find($request->user()?->getAuthIdentifier());
        $this->service->restore($entitlement, $actor);

        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, new DigitalEntitlementResource($entitlement->refresh()));
    }
}
