<?php

namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\AttributeValueRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AttributeValueRequest;
use Marvel\Http\Resources\AttributeValueResource;
use Marvel\Traits\ApiResponse;

class AttributeValueController extends CoreController
{
    use ApiResponse;
    public $repository;

    public function __construct(AttributeValueRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_ATTRIBUTES, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_ATTRIBUTE, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_ATTRIBUTE, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_ATTRIBUTE, ["only" => ["destroy"]]);
    }

    public function index(Request $request)
    {
        $limit = $request->limit ?? 15;
        $order = $request->order;
        $sortedBy = $request->sortedBy ?? 'asc';

        $attributesValue = $this->repository->with('attribute');

        if ($order && in_array($order, ['id', 'value', 'attribute_id', 'slug', 'created_at', 'updated_at'])) {
            $attributesValue = $attributesValue->orderBy($order, $sortedBy === 'desc' ? 'desc' : 'asc');
        }

        $attributesValue = $attributesValue->paginate($limit)->withQueryString();
        $attributeValueData = AttributeValueResource::collection($attributesValue)->response()->getData(true);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $attributeValueData['data'] ?? [],
            "page" => $attributeValueData['meta']['current_page'] ?? 0,
            "current_page" => $attributeValueData['meta']['current_page'] ?? 0,
            "from" => $attributeValueData['meta']['from'] ?? 0,
            "to" => $attributeValueData['meta']['to'] ?? 0,
            "last_page" => $attributeValueData['meta']['last_page'] ?? 0,
            "path" => $attributeValueData['meta']['path'] ?? "",
            "per_page" => $attributeValueData['meta']['per_page'] ?? 0,
            "total" => $attributeValueData['meta']['total'] ?? 0,
            "next_page_url" => $attributeValueData['links']['next'] ?? "",
            "prev_page_url" => $attributeValueData['links']['prev'] ?? "",
            "last_page_url" => $attributeValueData['links']['last'] ?? "",
            "first_page_url" => $attributeValueData['links']['first'] ?? "",
        ]);
    }

    public function store(AttributeValueRequest $request)
    {
        try {

            $validatedData = $request->validated();
            $attributeValue = $this->repository->create($validatedData);
            return $this->apiResponse(ATTRIBUTE_VALUE_CREATED_SUCCESSFULLY, 201, true, AttributeValueResource::make($attributeValue));

        } catch (MarvelException $th) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function show($id)
    {
        try {
            $attributeValue = $this->repository->with('attribute')->findOrFail($id);
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, AttributeValueResource::make($attributeValue));
        } catch (MarvelException $th) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }

    public function update(AttributeValueRequest $request, $id)
    {
        try {
            $request->id = $id;
            $attributeValue = $this->updateAttributeValues($request);
            return $this->apiResponse(ATTRIBUTE_VALUE_UPDATED_SUCCESSFULLY, 200, true, AttributeValueResource::make($attributeValue));
        } catch (MarvelException $th) {
            return $this->apiResponse(COULD_NOT_UPDATE_THE_RESOURCE, 400, false);
        }
    }

    public function updateAttributeValues(AttributeValueRequest $request)
    {
        try {
            $attributeValue = $this->repository->findOrFail($request->id);
            $attributeValue->update($request->except('id'));
            return $attributeValue->fresh();
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
    }

    public function destroy($id, Request $request)
    {
        try {
            $request->id = $id;
            $this->destroyAttributeValues($request);
            return $this->apiResponse(ATTRIBUTE_VALUE_DELETED_SUCCESSFULLY, 200, true);
        } catch (MarvelException $th) {
            return $this->apiResponse(COULD_NOT_DELETE_THE_RESOURCE, 400, false);
        }
    }

    public function destroyAttributeValues(Request $request)
    {
        $attributesValue = $this->repository->findOrFail($request->id);
        $attributesValue->delete();
    }
}
