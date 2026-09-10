<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Repositories\CityRepository;
use Marvel\Http\Resources\CityResource;
use Marvel\Traits\ApiResponse;

class CityController extends Controller
{
    use ApiResponse, HasCache;

    public function __construct(
        private CityRepository $cityRepository
    ) {}

    public function index(): JsonResponse
    {
        $cities = $this->cityRepository->all();
        $citiesCache = $this->remember(FrontendResource::CITIES->value, md5(request()->fullUrl()), $cities);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CityResource::collection($cities));
    }

    public function show(int $id): JsonResponse
    {
        $city = $this->cityRepository->findById($id, ['governorate']);
        if (!$city) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CityResource::make($city));
    }
}
