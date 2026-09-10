<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Http\Resources\PickupLocation\PickupLocationResource;
use App\Services\General\PickupLocationService;
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
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PickupLocationResource::make($pickupLocation));
        } catch (\Exception $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }
}
