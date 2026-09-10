<?php

namespace Marvel\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Marvel\Http\Requests\CreateShippingRequest;
use Marvel\Http\Requests\UpdateShippingRequest;
use Marvel\Database\Repositories\ShippingRepository;
use Marvel\Exceptions\MarvelException;
use Prettus\Validator\Exceptions\ValidatorException;

class ShippingController extends CoreController
{
    public $repository;

    public function __construct(ShippingRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @OA\Get(
     *     path="/shippings",
     *     operationId="listShippings",
     *     tags={"Platform Configuration"},
     *     summary="List All Shipping Classes",
     *     description="Get list of all shipping classes/zones. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Response(response=200, description="Shippings retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN")
     * )
     */
    public function index(Request $request)
    {
        return $this->repository->all();
    }

    /**
     * @OA\Post(
     *     path="/shippings",
     *     operationId="createShipping",
     *     tags={"Platform Configuration"},
     *     summary="Create Shipping Class",
     *     description="Create a new shipping class/zone. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "amount", "type"},
     *             @OA\Property(property="name", type="string", example="Express Shipping"),
     *             @OA\Property(property="amount", type="number", example=10.99),
     *             @OA\Property(property="type", type="string", enum={"fixed", "percentage", "free"}, example="fixed"),
     *             @OA\Property(property="is_global", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Shipping created successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(CreateShippingRequest $request)
    {
        try {
            $validateData = $request->validated();
            return $this->repository->create($validateData);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Get(
     *     path="/shippings/{id}",
     *     operationId="getShipping",
     *     tags={"Platform Configuration"},
     *     summary="Get Shipping Details",
     *     description="Get a single shipping class by ID. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Shipping ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Shipping retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Shipping not found")
     * )
     */
    public function show($id)
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Put(
     *     path="/shippings/{id}",
     *     operationId="updateShipping",
     *     tags={"Platform Configuration"},
     *     summary="Update Shipping Class",
     *     description="Update an existing shipping class. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Shipping ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="amount", type="number"),
     *         @OA\Property(property="type", type="string")
     *     )),
     *     @OA\Response(response=200, description="Shipping updated successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Shipping not found")
     * )
     */
    public function update(UpdateShippingRequest $request, $id)
    {
        try {
            $validateData = $request->validated();
            return $this->repository->findOrFail($id)->update($validateData);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Delete(
     *     path="/shippings/{id}",
     *     operationId="deleteShipping",
     *     tags={"Platform Configuration"},
     *     summary="Delete Shipping Class",
     *     description="Delete a shipping class. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Shipping ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Shipping deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Shipping not found")
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
