<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Http\Resources\PickupLocation\PickupLocationResource;
use App\Services\General\PickupLocationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class PickupLocationController extends Controller
{
    use ApiResponse;

    private PickupLocationService $pickupLocationService;

    public function __construct(PickupLocationService $pickupLocationService)
    {
        $this->pickupLocationService = $pickupLocationService;
    }

    public function index(Request $request)
    {
        $pickupLocations = $this->pickupLocationService->getPickupLocations($request);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PickupLocationResource::collection($pickupLocations));
    }

    public function show($id)
    {
        try {
            $pickupLocation = $this->pickupLocationService->getPickupLocationById($id);
        } catch (ModelNotFoundException) {
            // Missing or inactive location: this endpoint's 404 contract.
            // Infrastructure failures (DB down, etc.) must NOT be masked as
            // 404 — they bubble to the global handler and surface with the
            // platform's 409 DB-error contract instead.
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PickupLocationResource::make($pickupLocation));
    }
}
