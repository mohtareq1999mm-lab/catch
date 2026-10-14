<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\BrandRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\BrandCreateRequest;
use Marvel\Http\Requests\BrandsReorderRequest;
use Marvel\Http\Requests\BrandUpdateRequest;
use Marvel\Http\Resources\BrandResource;
use Marvel\Traits\ApiResponse;

class BrandController extends CoreController
{
    use ApiResponse, HasCache;

    public $repository;

    public function __construct(BrandRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware('permission:' . Permission::VIEW_BRANDS, ['only' => ['index']]);
        $this->middleware('permission:' . Permission::VIEW_BRANDS, ['only' => ['show']]);
        $this->middleware('permission:' . Permission::CREATE_BRAND, ['only' => ['store']]);
        $this->middleware('permission:' . Permission::UPDATE_BRAND, ['only' => ['update']]);
        $this->middleware('permission:' . Permission::DELETE_BRAND, ['only' => ['destroy']]);
        $this->middleware('permission:' . Permission::UPDATE_BRAND, ['only' => ['reorder']]);
    }

    public function index(Request $request)
    {
        $limit = $request->per_page ?? $request->limit ?? 15;
        $active = $request->active ?? null;
        $inactive = $request->inactive ?? null;
        $search = $request->search ?? null;
        $order = $request->order;
        $sortedBy = $request->sortedBy ?? 'asc';

        $brandsQuery = $this->repository;

        if ($active) {
            $brandsQuery = $brandsQuery->active();
        }
        if ($inactive) {
            $brandsQuery = $brandsQuery->inactive();
        }
        if ($search) {
            $brandsQuery = $brandsQuery->search('name', $search, app()->getLocale());
        }
        if ($order && in_array($order, ['id', 'name', 'slug', 'status', 'created_at', 'updated_at'])) {
            $brandsQuery = $brandsQuery->orderBy($order, $sortedBy === 'desc' ? 'desc' : 'asc');
        }

        $brands = $brandsQuery->ordered()->paginate($limit);
        $data = BrandResource::collection($brands)->response()->getData(true);
        $dataCache = $this->remember(FrontendResource::BRANDS->value, md5($request->fullUrl()), $data);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $dataCache['data'] ?? [],
            "page" => $dataCache['meta']['current_page'] ?? 0,
            "current_page" => $dataCache['meta']['current_page'] ?? 0,
            "from" => $dataCache['meta']['from'] ?? 0,
            "to" => $dataCache['meta']['to'] ?? 0,
            "last_page" => $dataCache['meta']['last_page'] ?? 0,
            "path" => $dataCache['meta']['path'] ?? "",
            "per_page" => $dataCache['meta']['per_page'] ?? 0,
            "total" => $dataCache['meta']['total'] ?? 0,
            "next_page_url" => $dataCache['links']['next'] ?? "",
            "prev_page_url" => $dataCache['links']['prev'] ?? "",
            "last_page_url" => $dataCache['links']['last'] ?? "",
            "first_page_url" => $dataCache['links']['first'] ?? "",
        ]);
    }

    public function store(BrandCreateRequest $request)
    {
        try {
            $brand = $this->repository->saveBrand($request);
            $brand->load('products');
            $this->flushTag(frontendResource::BRANDS->value);
            return $this->apiResponse(BRAND_CREATED_SUCCESSFULLY, 201, true, BrandResource::make($brand));
        } catch (\Exception $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    public function show(Request $request, $params)
    {
        try {
            if (is_numeric($params)) {
                $params = (int) $params;
                $brand = $this->repository->with('products')->where('id', $params)->firstOrFail();
            } else {
                $brand = $this->repository->with('products')->where('slug', $params)->firstOrFail();
            }
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, BrandResource::make($brand));
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    public function update(BrandUpdateRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            $brand = $this->brandUpdate($request);
            $brand->load('products');
            $this->flushTag(frontendResource::BRANDS->value);
            return $this->apiResponse(BRAND_UPDATED_SUCCESSFULLY, 200, true, BrandResource::make($brand));
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    private function brandUpdate(BrandUpdateRequest $request)
    {
        $brand = $this->repository->findOrFail($request->id);
        return $this->repository->updateBrand($request, $brand);
    }

    public function destroy($id)
    {
        try {
            $this->repository->findOrFail($id)->delete();
            $this->flushTag(frontendResource::BRANDS->value);
            return $this->apiResponse(BRAND_DELETED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    public function reorder(BrandsReorderRequest $request)
    {
        $this->repository->reorder($request->brands);
        $this->flushTag(frontendResource::BRANDS->value);
        return $this->apiResponse(BRANDS_REORDERED_SUCCESSFULLY, 200, true);
    }
}
