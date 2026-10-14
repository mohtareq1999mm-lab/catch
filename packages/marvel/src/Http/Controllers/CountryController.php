<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\CountryRepository;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\BulkStatusRequest;
use Marvel\Http\Requests\CountryStoreRequest;
use Marvel\Http\Requests\CountryUpdateRequest;
use Marvel\Http\Resources\CountryResource;
use Marvel\Traits\ApiResponse;

/**
 * @OA\Tag(
 *     name="Countries",
 *     description="Country management"
 * )
 */
class CountryController extends CoreController
{
    use ApiResponse, HasCache;

    public function __construct(private readonly CountryRepository $repository)
    {
        $this->middleware("permission:" . Permission::VIEW_COUNTRY, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_COUNTRY, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_COUNTRY, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_COUNTRY, ["only" => ["destroy"]]);
    }


    public function index(Request $request): JsonResponse
    {
        $countries = $this->repository->paginate(
            (int)$request->get('per_page', 15),
            $request->get('search'),
            $request->has('status') ? (bool)$request->get('status') : null
        );
        $categoryCache = $this->remember(FrontendResource::COUNTRIES->value, md5($request->fullUrl()), $countries);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CountryResource::collection($categoryCache));
    }


    public function store(CountryStoreRequest $request): JsonResponse
    {
        $country = $this->repository->create($request->validated());
        $this->flushTag(FrontendResource::COUNTRIES->value);
        return $this->apiResponse(COUNTRY_CREATED_SUCCESSFULLY, 201, true, CountryResource::make($country));
    }

    public function show(int $id): JsonResponse
    {
        $country = $this->repository->findById($id, ['governorates']);

        if (!$country) {
            return $this->apiResponse(COUNTRY_NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CountryResource::make($country));
    }


    public function update(CountryUpdateRequest $request, int $id): JsonResponse
    {
        $country = $this->repository->findById($id);
        if (!$country) {
            return $this->apiResponse(COUNTRY_NOT_FOUND, 404, false);
        }
        $country = $this->repository->update($country, $request->validated());
        $this->flushTag(FrontendResource::COUNTRIES->value);
        return $this->apiResponse(COUNTRY_UPDATED_SUCCESSFULLY, 200, true, CountryResource::make($country));
    }

    public function destroy(int $id): JsonResponse
    {
        $country = $this->repository->findById($id);
        if (!$country) {
            return $this->apiResponse(COUNTRY_NOT_FOUND, 404, false);
        }
        $this->repository->delete($country);
        $this->flushTag(FrontendResource::COUNTRIES->value);
        return $this->apiResponse(COUNTRY_DELETED_SUCCESSFULLY, 200, true);
    }

    public function governorates(int $country): JsonResponse
    {
        $countryModel = $this->repository->findById($country, ['governorates']);

        if (!$countryModel) {
            return $this->apiResponse(COUNTRY_NOT_FOUND, 404, false);
        }
        return $this->apiResponse(GOVERNORATES_FETCHED_SUCCESSFULLY, 200, true, CountryResource::make($countryModel));
    }

    public function bulkStatus(BulkStatusRequest $request): JsonResponse
    {
        $count = $this->repository->bulkStatus($request->input('ids', []), (bool)$request->input('status'));
        $this->flushTag(FrontendResource::COUNTRIES->value);
        return $this->apiResponse(BULK_STATUS_UPDATED_SUCCESSFULLY, 200, true, ['updated' => $count]);
    }
}