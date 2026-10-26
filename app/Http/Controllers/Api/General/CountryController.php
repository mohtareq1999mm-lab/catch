<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Repositories\CountryRepository;
use Marvel\Http\Resources\CountryResource;
use Marvel\Traits\ApiResponse;

class CountryController extends Controller
{
    use ApiResponse, HasCache;

    public function __construct(
        private CountryRepository $countryRepository
    ) {}

    public function index(): JsonResponse
    {
        // Lazy closure: the allActive() query must run only on cache miss.
        $countries = $this->remember(
            FrontendResource::COUNTRIES->value,
            md5(request()->fullUrl()),
            fn () => $this->countryRepository->allActive()
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CountryResource::collection($countries));
    }

    public function show(int $id): JsonResponse
    {
        $country = $this->countryRepository->findById($id, ['governorates']);
        if (!$country || !$country->status) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CountryResource::make($country));
    }
}