<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Marvel\Database\Repositories\ShippingPriceRepository;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\BulkStatusRequest;
use Marvel\Http\Requests\ShippingPriceStoreRequest;
use Marvel\Http\Requests\ShippingPriceUpdateRequest;
use Marvel\Http\Resources\ShippingPriceResource;
use Marvel\Traits\ApiResponse;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="ShippingPrices",
 *     description="Shipping price management"
 * )
 */
class ShippingPriceController extends CoreController
{
    use ApiResponse;
    public function __construct(private readonly ShippingPriceRepository $repository)
    {
        $this->middleware("permission:" . Permission::MANAGE_SHIPPING_PRICES, ['only' => ['store', 'update', 'destroy', 'bulkStatus']]);
    }

    /**
     * @OA\Get(
     *     path="/api/shipping-prices",
     *     tags={"ShippingPrices"},
     *     summary="List shipping prices",
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="governorate_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $prices = $this->repository->paginate(
            (int) $request->get('per_page', 15),
            $request->has('status') ? (bool) $request->get('status') : null,
            $request->get('governorate_id') ? (int) $request->get('governorate_id') : null
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ShippingPriceResource::collection($prices));
    }

    /**
     * @OA\Post(
     *     path="/api/shipping-prices",
     *     tags={"ShippingPrices"},
     *     summary="Create shipping price",
     *     @OA\Response(response=201, description="Created")
     * )
     */
    public function store(ShippingPriceStoreRequest $request): JsonResponse
    {

        $price = $this->repository->create($request->validated());
        return $this->apiResponse(CREATE_DATA_SUCCESSFULLY, 201, true,  ShippingPriceResource::make($price));
    }

    /**
     * @OA\Get(
     *     path="/api/shipping-prices/{id}",
     *     tags={"ShippingPrices"},
     *     summary="Get shipping price",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $price = $this->repository->findById($id, ['governorate']);

        if (!$price) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ShippingPriceResource::make($price));
    }

    /**
     * @OA\Put(
     *     path="/api/shipping-prices/{id}",
     *     tags={"ShippingPrices"},
     *     summary="Update shipping price",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function update(ShippingPriceUpdateRequest $request, int $id): JsonResponse
    {
        $price = $this->repository->findById($id);

        if (!$price) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        $price = $this->repository->update($price, $request->validated());
        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ShippingPriceResource::make($price));
    }

    /**
     * @OA\Delete(
     *     path="/api/shipping-prices/{id}",
     *     tags={"ShippingPrices"},
     *     summary="Delete shipping price",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $price = $this->repository->findById($id);

        if (!$price) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        $this->repository->delete($price);

        return $this->apiResponse(DELETE_DATA_SUCCESSFULLY, 200, true);
    }

    public function bulkStatus(BulkStatusRequest $request): JsonResponse
    {
        $count = $this->repository->bulkStatus($request->input('ids', []), (bool) $request->input('status'));

        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ['updated' => $count]);
    }
}