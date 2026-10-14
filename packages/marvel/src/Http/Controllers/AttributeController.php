<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AttributeCreateRequest;
use Marvel\Http\Requests\AttributeUpdateRequest;
use Marvel\Database\Repositories\AttributeRepository;
use Marvel\Http\Resources\AttributeResource;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;


class AttributeController extends CoreController
{
    use ApiResponse, HasCache;

    public $repository;

    public function __construct(AttributeRepository $repository)
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

        $attributes = $this->repository->with('values');

        if ($order && in_array($order, ['id', 'name', 'slug', 'created_at', 'updated_at'])) {
            $attributes = $attributes->orderBy($order, $sortedBy === 'desc' ? 'desc' : 'asc');
        }

        $attributes = $attributes->with('values')->paginate($limit)->withQueryString();
        $attributeData = AttributeResource::collection($attributes)->response()->getData(true);
        $attributeDataCache = $this->remember(FrontendResource::ATTRIBUTES->value, md5($request->fullUrl()), $attributeData);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $attributeDataCache['data'] ?? [],
            "page" => $attributeDataCache['meta']['current_page'] ?? 0,
            "current_page" => $attributeDataCache['meta']['current_page'] ?? 0,
            "from" => $attributeDataCache['meta']['from'] ?? 0,
            "to" => $attributeDataCache['meta']['to'] ?? 0,
            "last_page" => $attributeDataCache['meta']['last_page'] ?? 0,
            "path" => $attributeDataCache['meta']['path'] ?? "",
            "per_page" => $attributeDataCache['meta']['per_page'] ?? 0,
            "total" => $attributeDataCache['meta']['total'] ?? 0,
            "next_page_url" => $attributeDataCache['links']['next'] ?? "",
            "prev_page_url" => $attributeDataCache['links']['prev'] ?? "",
            "last_page_url" => $attributeDataCache['links']['last'] ?? "",
            "first_page_url" => $attributeDataCache['links']['first'] ?? "",
        ]);
    }


    public function store(AttributeCreateRequest $request)
    {
        try {

            $attribute = $this->repository->storeAttribute($request);
            $this->flushTag(FrontendResource::ATTRIBUTES->value);
            return $this->apiResponse(ATTRIBUTE_CREATED_SUCCESSFULLY, 201, true, AttributeResource::make($attribute));
        } catch (MarvelException $e) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    public function show(Request $request, $params)
    {

        try {
            if (is_numeric($params)) {
                $params = (int)$params;
                $attribute = $this->repository->with('values')->where('id', $params)->firstOrFail();
                return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, AttributeResource::make($attribute));
            }
            $attribute = $this->repository->with('values')
                ->where('slug', $params)
                ->firstOrFail();
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, AttributeResource::make($attribute));
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    public function update(AttributeUpdateRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            $attributeUpdates = $this->updateAttribute($request);
            $this->flushTag(FrontendResource::ATTRIBUTES->value);
            return $this->apiResponse(ATTRIBUTE_UPDATED_SUCCESSFULLY, 200, true, AttributeResource::make($attributeUpdates));
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    private function updateAttribute(AttributeUpdateRequest $request)
    {
        try {
            $attribute = $this->repository->with('values')->findOrFail($request->id);
            return $this->repository->updateAttribute($request, $attribute);
        } catch (\Exception $e) {
            throw new HttpException(404, NOT_FOUND);
        }
    }


    public function destroy($id)
    {
        try {
            $this->repository->findOrFail($id)->delete();
            $this->flushTag(FrontendResource::ATTRIBUTES->value);
            return $this->apiResponse(ATTRIBUTE_DELETED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    public function exportAttributes(Request $request, $shop_id)
    {
        $filename = 'attributes-for-shop-id-' . $shop_id . '.csv';
        $headers = [
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Expires' => '0',
            'Pragma' => 'public'
        ];

        $list = $this->repository->where('shop_id', $shop_id)->with(['values'])->get()->toArray();

        if (!count($list)) {
            return response()->stream(function () {
            }, 200, $headers);
        }
        # add headers for each column in the CSV download
        array_unshift($list, array_keys($list[0]));

        $callback = function () use ($list) {
            $FH = fopen('php://output', 'w');
            foreach ($list as $key => $row) {
                if ($key === 0) {
                    $exclude = ['id', 'created_at', 'updated_at', 'slug', 'translated_languages'];
                    $row = array_diff($row, $exclude);
                }
                unset($row['id']);
                unset($row['updated_at']);
                unset($row['slug']);
                unset($row['created_at']);
                unset($row['translated_languages']);
                if (isset($row['values'])) {
                    $row['values'] = implode(',', Arr::pluck($row['values'], 'value'));
                }

                fputcsv($FH, $row);
            }
            fclose($FH);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function importAttributes(Request $request)
    {
        $requestFile = $request->file();
        $user = $request->user();
        $shop_id = $request->shop_id;

        if (empty($requestFile)) {
            throw new MarvelException(NOT_FOUND);
        }

        if (isset($requestFile['csv'])) {
            $uploadedCsv = $requestFile['csv'];
        } else {
            $uploadedCsv = current($requestFile);
        }

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        if (isset($shop_id)) {
            $file = $uploadedCsv->storePubliclyAs('csv-files', 'attributes-' . $shop_id . '.' . $uploadedCsv->getClientOriginalExtension(), 'public');

            $attributes = $this->repository->csvToArray(storage_path() . '/app/public/' . $file);

            foreach ($attributes as $key => $attribute) {
                if (!isset($attribute['name'])) {
                    throw new MarvelException('MESSAGE.WRONG_CSV');
                }
                unset($attribute['id']);
                $attribute['shop_id'] = $shop_id;
                $values = [];
                if (isset($attribute['values'])) {
                    $values = explode(',', $attribute['values']);
                }
                unset($attribute['values']);
                $newAttribute = $this->repository->firstOrCreate($attribute);
                foreach ($values as $key => $value) {
                    $newAttribute->values()->create(['value' => $value]);
                }
            }
            return true;
        }
    }
}
