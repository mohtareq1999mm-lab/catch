<?php

namespace Marvel\Http\Controllers;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Manufacturer;
use Marvel\Database\Repositories\ManufacturerRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\ManufacturerRequest;
use Marvel\Http\Resources\ManufacturerResource;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @OA\Tag(name="Manufacturers", description="Manufacturer/Brand management - browse and manage product brands")
 *
 * @OA\Schema(
 *     schema="Manufacturer",
 *     type="object",
 *     description="Manufacturer details",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Apple"),
 *     @OA\Property(property="slug", type="string", example="apple"),
 *     @OA\Property(property="description", type="string", example="Apple Inc. is an American multinational technology company."),
 *     @OA\Property(property="website", type="string", example="https://www.apple.com"),
 *     @OA\Property(property="is_approved", type="boolean", example=true),
 *     @OA\Property(property="products_count", type="integer", example=50),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="translated_languages", type="array", @OA\Items(type="string"), example={"en"}),
 *     @OA\Property(property="socials", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="image", type="object", nullable=true),
 *     @OA\Property(property="cover_image", type="object", nullable=true),
 *     @OA\Property(property="type", ref="#/components/schemas/Type")
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedManufacturers",
 *     type="object",
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Manufacturer")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=15),
 *     @OA\Property(property="total", type="integer", example=100),
 *     @OA\Property(property="last_page", type="integer", example=7)
 * )
 */
class ManufacturerController extends CoreController
{
    use ApiResponse;
    public $repository;

    public function __construct(ManufacturerRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * @OA\Get(
     *     path="/manufacturers",
     *     operationId="listManufacturers",
     *     tags={"Manufacturers"},
     *     summary="List all manufacturers",
     *     description="Retrieve a paginated list of manufacturers/brands.",
     *     @OA\Parameter(name="language", in="query", description="Language code", required=false, @OA\Schema(type="string", default="en", example="en")),
     *     @OA\Parameter(name="limit", in="query", description="Items per page", required=false, @OA\Schema(type="integer", default=15, example=15)),
     *     @OA\Response(response=200, description="Manufacturers retrieved successfully", @OA\JsonContent(ref="#/components/schemas/PaginatedManufacturers"))
     * )
     */
    public function index(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $limit = $request->limit ? $request->limit : 15;
        $manufacturers = $this->repository->where('language', $language)->with('type')->paginate($limit);
        $manufacturerData = ManufacturerResource::collection($manufacturers)->response()->getData(true);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $manufacturerData['data'] ?? [],
            "page" => $manufacturerData['meta']['current_page'] ?? 0,
            "current_page" => $manufacturerData['meta']['current_page'] ?? 0,
            "from" => $manufacturerData['meta']['from'] ?? 0,
            "to" => $manufacturerData['meta']['to'] ?? 0,
            "last_page" => $manufacturerData['meta']['last_page'] ?? 0,
            "path" => $manufacturerData['meta']['path'] ?? "",
            "per_page" => $manufacturerData['meta']['per_page'] ?? 0,
            "total" => $manufacturerData['meta']['total'] ?? 0,
            "next_page_url" => $manufacturerData['links']['next'] ?? "",
            "prev_page_url" => $manufacturerData['links']['prev'] ?? "",
            "last_page_url" => $manufacturerData['links']['last'] ?? "",
            "first_page_url" => $manufacturerData['links']['first'] ?? "",
        ]);
    }

    /**
     * @OA\Post(
     *     path="/manufacturers",
     *     operationId="createManufacturer",
     *     tags={"Manufacturers"},
     *     summary="Create a new manufacturer",
     *     description="Create a new manufacturer. Requires permissions for the associated shop.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "type_id"},
     *             @OA\Property(property="name", type="string", example="Apple"),
     *             @OA\Property(property="type_id", type="integer", example=1),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="website", type="string", format="url"),
     *             @OA\Property(property="socials", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="image", type="object"),
     *             @OA\Property(property="cover_image", type="object")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Manufacturer created successfully", @OA\JsonContent(ref="#/components/schemas/Manufacturer")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(ManufacturerRequest $request)
    {
        try {
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                return $this->repository->storeManufacturer($request);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
    }

    /**
     * @OA\Get(
     *     path="/manufacturers/{slug}",
     *     operationId="getManufacturer",
     *     tags={"Manufacturers"},
     *     summary="Get a single manufacturer",
     *     description="Retrieve detailed manufacturer information by slug or ID.",
     *     @OA\Parameter(name="slug", in="path", description="Manufacturer slug or ID", required=true, @OA\Schema(type="string", example="apple")),
     *     @OA\Parameter(name="language", in="query", description="Language code", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(response=200, description="Manufacturer retrieved successfully", @OA\JsonContent(ref="#/components/schemas/Manufacturer")),
     *     @OA\Response(response=404, description="Manufacturer not found")
     * )
     */
    public function show(Request $request, $slug)
    {
        try {
            $request['slug'] = $slug;
            $manufacturer = $this->fetchManufacturer($request);
            return new ManufacturerResource($manufacturer);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    public function fetchManufacturer(Request $request)
    {

        try {
            $slug = $request->slug;
            $language = $request->language ?? DEFAULT_LANGUAGE;
            if (is_numeric($slug)) {
                $slug = (int) $slug;
                return $this->repository->with('type')->where('id', $slug)->firstOrFail();
            }
            return $this->repository->with('type')->where('slug', $slug)->where('language', $language)->firstOrFail();
        } catch (Exception $th) {
            throw new HttpException(404, NOT_FOUND);
        }
    }

    /**
     * @OA\Put(
     *     path="/manufacturers/{id}",
     *     operationId="updateManufacturer",
     *     tags={"Manufacturers"},
     *     summary="Update a manufacturer",
     *     description="Update manufacturer details. Requires permissions for the associated shop.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", description="Manufacturer ID", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/Manufacturer")),
     *     @OA\Response(response=200, description="Manufacturer updated successfully", @OA\JsonContent(ref="#/components/schemas/Manufacturer")),
     *     @OA\Response(response=404, description="Manufacturer not found")
     * )
     */
    public function update(ManufacturerRequest $request, $id)
    {
        try {
            $request['id'] = $id;
            return $this->updateManufacturer($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function updateManufacturer(Request $request)
    {
        if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
            try {
                $Manufacturer = $this->repository->findOrFail($request->id);
            } catch (\Exception $e) {
                throw new HttpException(404, NOT_FOUND);
            }
            return $this->repository->updateManufacturer($request, $Manufacturer);
        }
        throw new AuthorizationException(NOT_AUTHORIZED);
    }

    /**
     * @OA\Delete(
     *     path="/manufacturers/{id}",
     *     operationId="deleteManufacturer",
     *     tags={"Manufacturers"},
     *     summary="Delete a manufacturer",
     *     description="Delete a manufacturer. Requires shop permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", description="Manufacturer ID to delete", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Response(response=200, description="Manufacturer deleted successfully", @OA\JsonContent(ref="#/components/schemas/Manufacturer")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Manufacturer not found")
     * )
     */
    public function destroy($id, Request $request)
    {
        try {
            $request['id'] = $id;
            return $this->deleteManufacturer($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    public function deleteManufacturer(Request $request)
    {
        if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
            $manufacturer = $this->repository->findOrFail($request->id);
            $manufacturer->delete();
            return $manufacturer;
        }
        throw new MarvelException(NOT_AUTHORIZED);
    }

    /**
     * @OA\Get(
     *     path="/top-manufacturers",
     *     operationId="getTopManufacturers",
     *     tags={"Manufacturers"},
     *     summary="Get top manufacturers",
     *     description="Retrieve list of manufacturers with the most products.",
     *     @OA\Parameter(name="language", in="query", description="Language code", required=false, @OA\Schema(type="string", default="en", example="en")),
     *     @OA\Parameter(name="limit", in="query", description="Number of results", required=false, @OA\Schema(type="integer", default=10, example=10)),
     *     @OA\Response(response=200, description="Top manufacturers retrieved successfully", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Manufacturer")))
     * )
     */
    public function topManufacturer(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        return $this->repository->where('language', $language)->withCount('products')->orderBy('products_count', 'desc')->take($limit)->get();
    }
}
