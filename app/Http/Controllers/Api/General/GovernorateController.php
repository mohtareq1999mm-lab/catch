<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Repositories\GovernorateRepository;
use Marvel\Http\Resources\GovernorateResource;
use Marvel\Traits\ApiResponse;

class GovernorateController extends Controller
{
    use ApiResponse , HasCache;

    public function __construct(
        private GovernorateRepository $governorateRepository
    ) {}

    public function index(): JsonResponse
    {
        // Lazy closure: the allActive() query must run only on cache miss.
        $governorates = $this->remember(
            FrontendResource::GOVERNORATES->value,
            md5(request()->fullUrl()),
            fn () => $this->governorateRepository->allActive()
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, GovernorateResource::collection($governorates));
    }

    public function show(int $id): JsonResponse
    {
        $governorate = $this->governorateRepository->findById($id, ['country', 'shippingPrice']);
        if (!$governorate || !$governorate->status) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, GovernorateResource::make($governorate));
    }
}
