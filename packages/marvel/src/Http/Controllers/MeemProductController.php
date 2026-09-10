<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\MeemProduct;
use Marvel\Database\Repositories\MeemProductRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\MeemProductCreateRequest;
use Marvel\Http\Requests\MeemProductUpdateRequest;

/**
 * @OA\Tag(name="Meem Products", description="Meem product catalog endpoints - browse and manage Meem integration products")
 *
 * @OA\Schema(
 *     schema="MeemProduct",
 *     type="object",
 *     description="Meem product resource",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Meem Premium Plan"),
 *     @OA\Property(property="category", type="string", nullable=true, example="Insurance"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Comprehensive coverage plan for individuals."),
 *     @OA\Property(property="image_url", type="string", format="url", nullable=true, example="https://example.com/images/product.jpg"),
 *     @OA\Property(property="price", type="number", format="float", nullable=true, example=199.99),
 *     @OA\Property(property="url", type="string", format="url", nullable=true, example="https://meem.com/products/premium-plan"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedMeemProducts",
 *     type="object",
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/MeemProduct")),
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=15),
 *     @OA\Property(property="total", type="integer", example=30),
 *     @OA\Property(property="last_page", type="integer", example=2)
 * )
 */
class MeemProductController extends CoreController
{
    public $repository;

    public function __construct(MeemProductRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @OA\Get(
     *     path="/meem-products",
     *     operationId="getMeemProducts",
     *     tags={"Meem Products"},
     *     summary="List all Meem products",
     *     description="Retrieve a paginated list of Meem products.",
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of products per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15, minimum=1, maximum=100, example=15)
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number for pagination",
     *         required=false,
     *         @OA\Schema(type="integer", default=1, minimum=1, example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Meem products retrieved successfully",
     *         @OA\JsonContent(ref="#/components/schemas/PaginatedMeemProducts")
     *     )
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $products = $this->repository->paginate($limit);
        return $products;
    }

    /**
     * @OA\Post(
     *     path="/meem-products",
     *     operationId="createMeemProduct",
     *     tags={"Meem Products"},
     *     summary="Create a new Meem product",
     *     description="Create a new Meem product. Requires SUPER_ADMIN permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", maxLength=255, example="Meem Premium Plan"),
     *             @OA\Property(property="category", type="string", nullable=true, maxLength=255, example="Insurance"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Comprehensive coverage plan."),
     *             @OA\Property(property="image_url", type="string", format="url", nullable=true, example="https://example.com/images/product.jpg"),
     *             @OA\Property(property="price", type="number", format="float", nullable=true, example=199.99),
     *             @OA\Property(property="url", type="string", format="url", nullable=true, example="https://meem.com/products/premium-plan")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Meem product created successfully",
     *         @OA\JsonContent(ref="#/components/schemas/MeemProduct")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - insufficient permissions"),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ValidationError")
     *     )
     * )
     */
    public function store(MeemProductCreateRequest $request)
    {
        try {
            $validatedData = $request->validated();
            return $this->repository->create($validatedData);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Get(
     *     path="/meem-products/{id}",
     *     operationId="getMeemProduct",
     *     tags={"Meem Products"},
     *     summary="Get a single Meem product",
     *     description="Retrieve a Meem product by its ID.",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Meem product ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Meem product retrieved successfully",
     *         @OA\JsonContent(ref="#/components/schemas/MeemProduct")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Meem product not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="MARVEL_ERROR.NOT_FOUND")
     *         )
     *     )
     * )
     */
    public function show(Request $request, $id)
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Put(
     *     path="/meem-products/{id}",
     *     operationId="updateMeemProduct",
     *     tags={"Meem Products"},
     *     summary="Update a Meem product",
     *     description="Update an existing Meem product. Requires SUPER_ADMIN permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Meem product ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", maxLength=255, example="Updated Meem Plan"),
     *             @OA\Property(property="category", type="string", nullable=true, maxLength=255, example="Health Insurance"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Updated plan description."),
     *             @OA\Property(property="image_url", type="string", format="url", nullable=true, example="https://example.com/images/updated.jpg"),
     *             @OA\Property(property="price", type="number", format="float", nullable=true, example=249.99),
     *             @OA\Property(property="url", type="string", format="url", nullable=true, example="https://meem.com/products/updated-plan")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Meem product updated successfully",
     *         @OA\JsonContent(ref="#/components/schemas/MeemProduct")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - insufficient permissions"),
     *     @OA\Response(response=404, description="Meem product not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(MeemProductUpdateRequest $request, $id)
    {
        try {
            $validatedData = $request->validated();
            $product = $this->repository->findOrFail($id);
            $product->update($validatedData);
            return $product;
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Delete(
     *     path="/meem-products/{id}",
     *     operationId="deleteMeemProduct",
     *     tags={"Meem Products"},
     *     summary="Delete a Meem product",
     *     description="Delete a Meem product. Requires SUPER_ADMIN permissions.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Meem product ID to delete",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Meem product deleted successfully"
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - insufficient permissions"),
     *     @OA\Response(response=404, description="Meem product not found")
     * )
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }
}
