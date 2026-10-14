<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Marvel\Database\Repositories\CityRepository;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\CityStoreRequest;
use Marvel\Http\Requests\CityUpdateRequest;
use Marvel\Http\Resources\CityResource;
use Marvel\Traits\ApiResponse;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Cities",
 *     description="City management"
 * )
 */
class CityController extends CoreController
{
    use ApiResponse, HasCache;

    public function __construct(private readonly CityRepository $repository)
    {
        $this->middleware("permission:" . Permission::VIEW_CITY, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_CITY, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_CITY, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_CITY, ["only" => ["destroy"]]);
    }


    public function index(Request $request): JsonResponse
    {
        $cities = $this->repository->paginate(
            (int)$request->get('per_page', 15),
            $request->get('search'),
            $request->get('governorate_id') ? (int)$request->get('governorate_id') : null
        );
        $citiesCache = $this->remember(FrontendResource::CITIES->value, md5($request->fullUrl()), $cities);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CityResource::collection($citiesCache));
    }


    public function store(CityStoreRequest $request): JsonResponse
    {
        $city = $this->repository->create($request->validated());
        $this->flushTag(FrontendResource::CITIES->value);
        return $this->apiResponse(CITY_CREATED_SUCCESSFULLY, 201, true, CityResource::make($city));
    }


    public function show(int $id): JsonResponse
    {
        $city = $this->repository->findById($id, ['governorate']);
        if (!$city) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CityResource::make($city));
    }


    public function update(CityUpdateRequest $request, int $id): JsonResponse
    {
        $city = $this->repository->findById($id);
        if (!$city) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        $city = $this->repository->update($city, $request->validated());
        $this->flushTag(FrontendResource::CITIES->value);
        return $this->apiResponse(CITY_UPDATED_SUCCESSFULLY, 200, true, CityResource::make($city));
    }


    public function destroy(int $id): JsonResponse
    {
        $city = $this->repository->findById($id);
        if (!$city) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        $this->repository->delete($city);
        $this->flushTag(FrontendResource::CITIES->value);
        return $this->apiResponse(CITY_DELETED_SUCCESSFULLY, 200, true);
    }
}