<?php

namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Address;
use Marvel\Database\Repositories\AddressRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AddressRequest;
use Marvel\Http\Requests\AddressRequestUpdate;
use Marvel\Http\Resources\AddressResource;
use Marvel\Traits\ApiResponse;
use Prettus\Validator\Exceptions\ValidatorException;

/**
 * @OA\Tag(name="Addresses", description="User address management")
 *
 * @OA\Schema(
 *     schema="Address",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="title", type="string", example="Home"),
 *     @OA\Property(property="type", type="string", enum={"billing", "shipping"}, example="shipping"),
 *     @OA\Property(property="default", type="boolean", example=true),
 *     @OA\Property(property="address", type="object", description="JSON object containing street, city, state, zip, country"),
 *     @OA\Property(property="customer_id", type="integer", example=10),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class AddressController extends CoreController
{
    use ApiResponse;
    public $repository;

    public function __construct(AddressRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * @OA\Get(
     *     path="/addresses",
     *     operationId="getAddresses",
     *     tags={"Addresses"},
     *     summary="List User Addresses",
     *     description="Retrieve all addresses for the authenticated user.",
     *     security={{"sanctum": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Addresses retrieved",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Address"))
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request)
    {
        $addresses = $this->repository->where('customer_id', $request->user()->id)->get();
        return $this->apiResponse("success", 200, true, AddressResource::collection($addresses));
    }

    /**
     * @OA\Post(
     *     path="/addresses",
     *     operationId="createAddress",
     *     tags={"Addresses"},
     *     summary="Create Address",
     *     description="Add a new address to the user's profile.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "type", "address", "customer_id"},
     *             @OA\Property(property="title", type="string", example="Work"),
     *             @OA\Property(property="type", type="string", enum={"billing", "shipping"}, example="billing"),
     *             @OA\Property(property="address", type="object"),
     *             @OA\Property(property="customer_id", type="integer", example=10),
     *             @OA\Property(property="default", type="boolean", example=false)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Address created", @OA\JsonContent(ref="#/components/schemas/Address")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(AddressRequest $request)
    {
        try {
            $validatedData = $request->merge(['customer_id' => $request->user()->id])->all();
            $address = $this->repository->create($validatedData);
            return $this->apiResponse(COULD_NOT_CREATE_THE_RESOURCE, 201, true, AddressResource::make($address));
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Get(
     *     path="/addresses/{id}",
     *     operationId="getAddress",
     *     tags={"Addresses"},
     *     summary="Get Address Details",
     *     description="Retrieve details of a specific address.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Address details", @OA\JsonContent(ref="#/components/schemas/Address")),
     *     @OA\Response(response=404, description="Address not found")
     * )
     */
    public function show($id)
    {
        $address = $this->repository->where('customer_id', request()->user()->id)->find($id);

        if (!$address) {
            return $this->apiResponse(ADDRESS_NOT_FOUND, 404, false);
        }

        return $this->apiResponse(ADDRESS_FOUND, 200, true, AddressResource::make($address));
    }

    /**
     * @OA\Put(
     *     path="/addresses/{id}",
     *     operationId="updateAddress",
     *     tags={"Addresses"},
     *     summary="Update Address",
     *     description="Update an existing address.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Address")
     *     ),
     *     @OA\Response(response=200, description="Address updated", @OA\JsonContent(ref="#/components/schemas/Address")),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Address not found")
     * )
     */
    public function update(AddressRequestUpdate $request, $id)
    {
        try {
            $validatedData = $request->except('customer_id');
            $address = $this->repository->where('customer_id', request()->user()->id)->find($id);
            if (!$address) {
                return $this->apiResponse(ADDRESS_NOT_FOUND, 404, false);
            }
            $address->update($validatedData);
            return $this->apiResponse(ADDRESS_UPDATED, 200, true, AddressResource::make($address));
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Delete(
     *     path="/addresses/{id}",
     *     operationId="deleteAddress",
     *     tags={"Addresses"},
     *     summary="Delete Address",
     *     description="Remove an address from the user profile.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Address deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Address not found")
     * )
     */
    public function destroy($id, Request $request)
    {
        try {
            $user = $request->user();
            $address = $this->repository->where('customer_id', $user->id)->find($id);
            if (!$address) {
                return $this->apiResponse(ADDRESS_NOT_FOUND, 404, false);
            }
            $address->delete();
            return $this->apiResponse(ADDRESS_DELETED, 200, true);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
