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
        $governorates = $this->governorateRepository->allActive();
        $governorateCahce = $this->remember(FrontendResource::GOVERNORATES->value, md5(request()->fullUrl()), $governorates);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, GovernorateResource::collection($governorateCahce));
    }

    public function show(int $id): JsonResponse
    {
        $governorate = $this->governorateRepository->findById($id, ['country', 'shippingPrice']);
        if (!$governorate) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, GovernorateResource::make($governorate));
    }
}
