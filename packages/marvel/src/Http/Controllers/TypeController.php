<?php

namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Type;
use Marvel\Database\Repositories\TypeRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\TypeRequest;
use Marvel\Http\Resources\TypeResource;
use Prettus\Validator\Exceptions\ValidatorException;

/**
 * @OA\Tag(name="Types", description="Product types/collections management - e.g., Grocery, Bakery, Furniture")
 *
 * @OA\Schema(
 *     schema="Type",
 *     type="object",
 *     description="Product type details",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Grocery"),
 *     @OA\Property(property="slug", type="string", example="grocery"),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="translated_languages", type="array", @OA\Items(type="string"), example={"en"}),
 *     @OA\Property(property="icon", type="string", nullable=true, example="FruitsVegetable"),
 *     @OA\Property(property="images", type="array", @OA\Items(type="object"), nullable=true),
 *     @OA\Property(property="banners", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="promotional_sliders", type="array", @OA\Items(type="object"), nullable=true),
 *     @OA\Property(property="settings", type="object", @OA\Property(property="isFullWidth", type="boolean", example=true), @OA\Property(property="layoutType", type="string", example="classic"))
 * )
 */
class TypeController extends CoreController
{
    public $repository;

    public function __construct(TypeRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * @OA\Get(
     *     path="/types",
     *     operationId="listTypes",
     *     tags={"Types"},
     *     summary="List all product types",
     *     description="Retrieve all product types/collections like Grocery, Bakery, Clothing, etc.",
     *     @OA\Parameter(name="language", in="query", description="Language code", required=false, @OA\Schema(type="string", default="en", example="en")),
     *     @OA\Parameter(name="limit", in="query", description="Items per page", required=false, @OA\Schema(type="integer", default=1000, example=15)),
     *     @OA\Response(response=200, description="Types retrieved successfully", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Type")))
     * )
     */
    public function index(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;

        $limit = isset($request->limit) ? $request->limit : 10000;
        $types = $this->repository->where('language', $language)->paginate($limit);
        // $types = $this->repository->where('language', $language)->get();
        return TypeResource::collection($types);
    }

    /**
     * @OA\Post(
     *     path="/types",
     *     operationId="createType",
     *     tags={"Types"},
     *     summary="Create a new product type",
     *     description="Create a new type. Requires admin permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "settings"},
     *             @OA\Property(property="name", type="string", example="Bakery"),
     *             @OA\Property(property="icon", type="string", example="BakeryIcon"),
     *             @OA\Property(property="settings", type="object", @OA\Property(property="isFullWidth", type="boolean", example=true), @OA\Property(property="layoutType", type="string", example="classic")),
     *             @OA\Property(property="banners", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="promotional_sliders", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=201, description="Type created successfully", @OA\JsonContent(ref="#/components/schemas/Type")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(TypeRequest $request)
    {
        try {
            return $this->repository->storeType($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Get(
     *     path="/types/{slug}",
     *     operationId="getType",
     *     tags={"Types"},
     *     summary="Get a single product type",
     *     description="Retrieve detailed information about a type by slug or ID.",
     *     @OA\Parameter(name="slug", in="path", description="Type slug or ID", required=true, @OA\Schema(type="string", example="grocery")),
     *     @OA\Parameter(name="language", in="query", description="Language code", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(response=200, description="Type retrieved successfully", @OA\JsonContent(ref="#/components/schemas/Type")),
     *     @OA\Response(response=404, description="Type not found")
     * )
     */
    public function show(Request $request, $params)
    {

        try {
            $language = $request->language ?? DEFAULT_LANGUAGE;
            if (is_numeric($params)) {
                $params = (int) $params;
                $type = $this->repository->where('id', $params)->with('banners')->firstOrFail();
                return new TypeResource($type);
            }
            $type = $this->repository->where('slug', $params)->where('language', $language)->with('banners')->firstOrFail();
            return new TypeResource($type);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Put(
     *     path="/types/{id}",
     *     operationId="updateType",
     *     tags={"Types"},
     *     summary="Update a product type",
     *     description="Update type details. Requires admin permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", description="Type ID", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/Type")),
     *     @OA\Response(response=200, description="Type updated successfully", @OA\JsonContent(ref="#/components/schemas/Type")),
     *     @OA\Response(response=404, description="Type not found")
     * )
     */
    public function update(TypeRequest $request, $id)
    {
        $request->id = $id;
        return $this->updateType($request);
    }

    public function updateType(TypeRequest $request)
    {
        try {
            $type = $this->repository->with('banners')->findOrFail($request->id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
        return $this->repository->updateType($request, $type);
    }

    /**
     * @OA\Delete(
     *     path="/types/{id}",
     *     operationId="deleteType",
     *     tags={"Types"},
     *     summary="Delete a product type",
     *     description="Delete a product type. Requires admin permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", description="Type ID to delete", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Response(response=200, description="Type deleted successfully", @OA\JsonContent(type="boolean", example=true)),
     *     @OA\Response(response=404, description="Type not found")
     * )
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
